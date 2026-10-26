<?php
/* Copyright 2016-2019 Daniil Gentili
 * (https://daniil.it)
 * This file is part of MadelineProto.
 * MadelineProto is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * MadelineProto is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Affero General Public License for more details.
 * You should have received a copy of the GNU General Public License along with MadelineProto.
 * If not, see <http://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

// The YouTube downloader: it plays YouTube videos in calls, without ffmpeg.
// The autoloader is loaded first, as its classes (and MyEventHandler) implement interfaces of the dependencies.

namespace Magnaluna {
    use Amp\ByteStream\ReadableStream;
    use Amp\ByteStream\ReadableStreamIteratorAggregate;
    use Amp\Cancellation;
    use Amp\CancelledException;
    use Amp\DeferredFuture;
    use Amp\ForbidCloning;
    use Amp\Http\Client\HttpClient;
    use Amp\Http\Client\HttpClientBuilder;
    use Amp\Http\Client\Request;
    use Amp\Http\Client\Response;
    use Closure;
    use Amp\ByteStream\ReadableBuffer;
    use danog\MadelineProto\Logger;
    use danog\MadelineProto\Matroska;
    use danog\MadelineProto\Mp4;
    use danog\MadelineProto\ResumableStream;
    use Generator;
    use IteratorAggregate;
    use RuntimeException;
    use Throwable;

    use function Amp\delay;

    if (file_exists('vendor/autoload.php')) {
        require 'vendor/autoload.php';
    } else {
        if (!file_exists('madeline.php')) {
            copy('https://phar.madelineproto.xyz/madeline.php', 'madeline.php');
        }
        include 'madeline.php';
    }

    // The video played in every call: a WebM file, sent as-is (no ffmpeg needed).
    if (!file_exists('oncall.webm')) {
        copy('https://paste.daniil.it/oncall.webm', 'oncall.webm');
    }
    // The same video for RTMP livestreams, which only carry H.264 and AAC (padded to 16:9, as Telegram
    // stretches every livestream to 1280x720).
    if (!file_exists('oncall.mp4')) {
        copy('https://paste.daniil.it/oncall.mp4', 'oncall.mp4');
    }

    /**
     * A YouTube video could not be played.
     */
    final class YouTubeException extends RuntimeException
    {
    }

    /**
     * Reading and writing EBML, the binary format of Matroska and WebM files.
     *
     * Element IDs are handled with their length marker, as they appear in files.
     *
     * @internal
     */
    final class Ebml
    {
        /**
         * Read a variable-size integer.
         *
         * @param bool $keepMarker Whether to keep the length marker, as in element IDs.
         *
         * @return array{int, int} The integer and its length.
         */
        public static function readVint(string $data, int $offset, bool $keepMarker = false): array
        {
            if (!isset($data[$offset])) {
                throw new YouTubeException('Truncated EBML element');
            }
            $first = \ord($data[$offset]);
            if ($first === 0) {
                throw new YouTubeException('Invalid EBML variable-size integer');
            }
            $length = 9 - \strlen(decbin($first));
            if (\strlen($data) < $offset + $length) {
                throw new YouTubeException('Truncated EBML element');
            }
            $value = $keepMarker ? $first : $first & (0xFF >> $length);
            for ($i = 1; $i < $length; $i++) {
                $value = ($value << 8) | \ord($data[$offset + $i]);
            }
            return [$value, $length];
        }

        /**
         * Read the size of an element.
         *
         * @return array{?int, int} The size (null if unknown) and its length.
         */
        public static function readSize(string $data, int $offset): array
        {
            [$size, $length] = self::readVint($data, $offset);
            return [$size === (1 << (7 * $length)) - 1 ? null : $size, $length];
        }

        /**
         * Read an unsigned integer element.
         */
        public static function readUint(string $data): int
        {
            $value = 0;
            foreach (str_split($data) as $byte) {
                $value = ($value << 8) | \ord($byte);
            }
            return $value;
        }

        /**
         * Iterate over the child elements of a master element.
         *
         * @return Generator<int, array{int, string, string}> The ID, payload and bytes of each element.
         */
        public static function children(string $data): Generator
        {
            $offset = 0;
            $end = \strlen($data);
            while ($offset < $end) {
                [$id, $idLength] = self::readVint($data, $offset, true);
                [$size, $sizeLength] = self::readSize($data, $offset + $idLength);
                if ($size === null) {
                    throw new YouTubeException('Unexpected EBML element of unknown size');
                }
                $length = $idLength + $sizeLength + $size;
                yield [$id, substr($data, $offset + $idLength + $sizeLength, $size), substr($data, $offset, $length)];
                $offset += $length;
            }
        }

        /**
         * Write an element.
         */
        public static function element(int $id, string $payload): string
        {
            return ltrim(pack('J', $id), "\0").self::size(\strlen($payload)).$payload;
        }

        /**
         * Write an unsigned integer element.
         */
        public static function uint(int $id, int $value): string
        {
            return self::element($id, ltrim(pack('J', $value), "\0") ?: "\0");
        }

        /**
         * Write the size of an element, in as few bytes as possible.
         */
        public static function size(int $size): string
        {
            // All bits set would mean an unknown size.
            for ($length = 1; $length < 8 && $size >= (1 << (7 * $length)) - 1; $length++);
            return substr(pack('J', $size | (1 << (7 * $length))), 8 - $length);
        }
    }

    /**
     * Reads the blocks of a WebM file one at a time, fetching it a range at a time.
     *
     * Only what is needed to remux the file is parsed: its timestamp scale, its first track and its
     * blocks, along with the timestamps of their clusters. The file can be reopened at any block (see
     * {@see self::getPosition()}), to resume reading there.
     *
     * @internal
     */
    final class WebmReader
    {
        private const ID_SEGMENT = 0x18538067;
        private const ID_INFO = 0x1549A966;
        private const ID_TIMESTAMP_SCALE = 0x2AD7B1;
        private const ID_TRACKS = 0x1654AE6B;
        private const ID_TRACK_ENTRY = 0xAE;
        private const ID_TRACK_NUMBER = 0xD7;
        private const ID_TRACK_UID = 0x73C5;
        private const ID_TRACK_TYPE = 0x83;
        private const ID_CODEC_ID = 0x86;
        private const ID_CLUSTER = 0x1F43B675;
        private const ID_TIMESTAMP = 0xE7;
        public const ID_SIMPLE_BLOCK = 0xA3;
        public const ID_BLOCK_GROUP = 0xA0;
        public const ID_BLOCK = 0xA1;
        private const ID_REFERENCE_BLOCK = 0xFB;

        public const TRACK_TYPE_VIDEO = 1;
        public const TRACK_TYPE_AUDIO = 2;

        /** How much is fetched at a time, in bytes. */
        private const CHUNK_SIZE = 2 * 1024 * 1024;

        /**
         * The first track of the file, once its header is read: its type, codec, and its elements
         * except for its number and UID.
         *
         * @var array{type: int, codec: string, elements: string}|null
         */
        public ?array $track = null;

        /**
         * The next block, if already read.
         *
         * @var array{id: int, payload: string, timestamp: int, keyframe: bool, end: int}|null
         */
        private ?array $next = null;
        private string $buffer = '';
        private int $bufferOffset;

        /**
         * @param Closure(int, int): string $fetch          Fetches the bytes of the file between two offsets, inclusive.
         * @param int                       $offset         The offset to start reading at: 0, or a position returned by {@see self::getPosition()}.
         * @param int                       $clusterTimestamp The timestamp of the cluster at that offset.
         * @param int                       $timestampScale Nanoseconds per timestamp tick.
         */
        public function __construct(
            private readonly Closure $fetch,
            private readonly int $size,
            private int $offset = 0,
            private int $clusterTimestamp = 0,
            private int $timestampScale = 1_000_000,
        ) {
            $this->bufferOffset = $offset;
        }

        /**
         * Where to reopen the file to read the next block again.
         *
         * @return array{int, int, int} The offset, cluster timestamp and timestamp scale.
         */
        public function getPosition(): array
        {
            return [$this->offset, $this->clusterTimestamp, $this->timestampScale];
        }

        /**
         * Read the next block, without consuming it.
         *
         * @return array{id: int, payload: string, timestamp: int, keyframe: bool, end: int}|null The
         *         element ID and payload of the block (a SimpleBlock or BlockGroup), its timestamp in
         *         milliseconds, whether it's a keyframe; null at the end of the file.
         */
        public function peek(): ?array
        {
            return $this->next ??= $this->readBlock();
        }

        /**
         * Consume the block returned by {@see self::peek()}.
         */
        public function consume(): void
        {
            \assert($this->next !== null);
            $this->offset = $this->next['end'];
            $this->next = null;
        }

        /**
         * @return array{id: int, payload: string, timestamp: int, keyframe: bool, end: int}|null
         */
        private function readBlock(): ?array
        {
            while ($this->offset < $this->size) {
                $header = $this->read($this->offset, min(12, $this->size - $this->offset));
                [$id, $idLength] = Ebml::readVint($header, 0, true);
                [$size, $sizeLength] = Ebml::readSize($header, $idLength);
                $start = $this->offset + $idLength + $sizeLength;
                if ($id === self::ID_SEGMENT || $id === self::ID_CLUSTER) {
                    // Walk into them: their size may be unknown.
                    $this->offset = $start;
                    continue;
                }
                if ($size === null) {
                    throw new YouTubeException("Unexpected element of unknown size at offset {$this->offset}");
                }
                $end = $start + $size;
                if ($id === self::ID_SIMPLE_BLOCK || $id === self::ID_BLOCK_GROUP) {
                    return $this->parseBlock($id, $this->read($start, $size), $end);
                }
                match ($id) {
                    self::ID_TIMESTAMP => $this->clusterTimestamp = Ebml::readUint($this->read($start, $size)),
                    self::ID_INFO => $this->parseInfo($this->read($start, $size)),
                    self::ID_TRACKS => $this->parseTracks($this->read($start, $size)),
                    default => null,
                };
                $this->offset = $end;
            }
            return null;
        }

        /**
         * @return array{id: int, payload: string, timestamp: int, keyframe: bool, end: int}
         */
        private function parseBlock(int $id, string $payload, int $end): array
        {
            $block = $payload;
            $keyframe = true;
            if ($id === self::ID_BLOCK_GROUP) {
                $block = null;
                foreach (Ebml::children($payload) as [$childId, $child]) {
                    if ($childId === self::ID_BLOCK) {
                        $block = $child;
                    } elseif ($childId === self::ID_REFERENCE_BLOCK) {
                        $keyframe = false;
                    }
                }
                if ($block === null) {
                    throw new YouTubeException('BlockGroup without a Block');
                }
            }
            [, $trackLength] = Ebml::readVint($block, 0);
            if (\strlen($block) < $trackLength + 3) {
                throw new YouTubeException('Truncated block');
            }
            $relative = unpack('n', $block, $trackLength)[1];
            if ($relative >= 0x8000) {
                $relative -= 0x10000;
            }
            if ($id === self::ID_SIMPLE_BLOCK) {
                $keyframe = (\ord($block[$trackLength + 2]) & 0x80) !== 0;
            }
            return [
                'id' => $id,
                'payload' => $payload,
                'timestamp' => intdiv(($this->clusterTimestamp + $relative) * $this->timestampScale, 1_000_000),
                'keyframe' => $keyframe,
                'end' => $end,
            ];
        }

        private function parseInfo(string $info): void
        {
            foreach (Ebml::children($info) as [$id, $payload]) {
                if ($id === self::ID_TIMESTAMP_SCALE) {
                    $this->timestampScale = Ebml::readUint($payload);
                }
            }
        }

        private function parseTracks(string $tracks): void
        {
            foreach (Ebml::children($tracks) as [$id, $entry]) {
                if ($id !== self::ID_TRACK_ENTRY || $this->track !== null) {
                    continue;
                }
                $track = ['type' => 0, 'codec' => '', 'elements' => ''];
                foreach (Ebml::children($entry) as [$childId, $payload, $element]) {
                    if ($childId === self::ID_TRACK_NUMBER || $childId === self::ID_TRACK_UID) {
                        continue;
                    }
                    if ($childId === self::ID_TRACK_TYPE) {
                        $track['type'] = Ebml::readUint($payload);
                    } elseif ($childId === self::ID_CODEC_ID) {
                        $track['codec'] = rtrim($payload, "\0");
                    }
                    $track['elements'] .= $element;
                }
                $this->track = $track;
            }
        }

        /**
         * Read bytes of the file, fetching them (and a chunk ahead) if needed.
         */
        private function read(int $offset, int $length): string
        {
            if ($offset + $length > $this->size) {
                throw new YouTubeException('The file is truncated');
            }
            $bufferEnd = $this->bufferOffset + \strlen($this->buffer);
            if ($offset < $this->bufferOffset || $offset > $bufferEnd) {
                // Skipping ahead: drop the buffer.
                $this->buffer = '';
                $this->bufferOffset = $bufferEnd = $offset;
            }
            if ($offset + $length > $bufferEnd) {
                // Drop what was read already, then fetch what's missing.
                $this->buffer = substr($this->buffer, $offset - $this->bufferOffset);
                $this->bufferOffset = $offset;
                $end = min($this->size, max($offset + $length, $bufferEnd + self::CHUNK_SIZE));
                $this->buffer .= ($this->fetch)($bufferEnd, $end - 1);
            }
            return substr($this->buffer, $offset - $this->bufferOffset, $length);
        }
    }

    /**
     * Gets the formats of YouTube videos, from the InnerTube API, like yt-dlp does.
     *
     * @internal
     */
    final class YouTube
    {
        private const PLAYER_URL = 'https://www.youtube.com/youtubei/v1/player?prettyPrint=false';
        private const WATCH_URL = 'https://www.youtube.com/watch?bpctr=9999999999&has_verified=1&v=';
        private const WEB_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
        /**
         * The InnerTube clients to try, in order, from yt-dlp: their formats have plain URLs, without
         * a signature (or an n parameter) to decipher with the JavaScript player of YouTube.
         *
         * The formats of the VISIONOS client can be fetched whole without a PO token, those of the
         * ANDROID client often can't be fetched past their first megabyte.
         */
        private const CLIENTS = [
            [
                'id' => 101,
                'context' => [
                    'clientName' => 'VISIONOS',
                    'clientVersion' => '1.02',
                    'deviceMake' => 'Apple',
                    'deviceModel' => 'RealityDevice17,1',
                    'userAgent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 15_7_3) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0 Safari/605.1.15',
                    'osName' => 'visionOS',
                    'osVersion' => '26.5.23O471',
                ],
            ],
            [
                'id' => 3,
                'context' => [
                    'clientName' => 'ANDROID',
                    'clientVersion' => '21.26.364',
                    'androidSdkVersion' => 30,
                    'userAgent' => 'com.google.android.youtube/21.26.364 (Linux; U; Android 11) gzip',
                    'osName' => 'Android',
                    'osVersion' => '11',
                ],
            ],
        ];
        /** How long to use the same visitor ID for, in seconds. */
        private const VISITOR_DATA_TTL = 6 * 3600;
        /** YouTube video links: https://www.youtube.com/watch?v=<id>, https://youtu.be/<id>, https://www.youtube.com/shorts/<id>, and so on. */
        private const LINK = '~^(?:https?://)?(?:(?:www|m|music)\.)?(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:\S*?&)?v=|shorts/|live/|embed/|v/)|youtu\.be/)([\w-]{11})(?![\w-])~i';
        /** YouTube playlist links: https://www.youtube.com/playlist?list=<id>, or the link of a video in it, https://www.youtube.com/watch?v=<video>&list=<id>. */
        private const PLAYLIST_LINK = '~^(?:https?://)?(?:(?:www|m|music)\.)?(?:youtube(?:-nocookie)?\.com|youtu\.be)/\S*?[?&]list=([\w-]+)~i';
        private const NEXT_URL = 'https://www.youtube.com/youtubei/v1/next?prettyPrint=false';
        /** The client to get playlists with: they're listed the same to every client. */
        private const WEB_CLIENT = ['clientName' => 'WEB', 'clientVersion' => '2.20250925.01.00'];

        private static ?HttpClient $client = null;
        /** @var array{string, int}|null The visitor ID, and when it was got. */
        private static ?array $visitorData = null;

        public static function getClient(): HttpClient
        {
            return self::$client ??= HttpClientBuilder::buildDefault();
        }

        /**
         * The number of clients, to try them all.
         */
        public static function getClientCount(): int
        {
            return \count(self::CLIENTS);
        }

        /**
         * Log the details of a refused format request, to find out why YouTube refused it, and get a new visitor ID
         * for the next player requests, in case it was flagged.
         *
         * Only the VISIONOS client has formats that can be fetched past their start without a PO token, so new URLs
         * come from it again.
         */
        public static function logRefusal(string $what, Response $response, int $client): void
        {
            self::$visitorData = null;
            $headers = [];
            foreach ($response->getHeaders() as $name => $values) {
                $headers[] = "$name: ".implode(', ', $values);
            }
            $clientName = self::CLIENTS[$client % self::getClientCount()]['context']['clientName'];
            Logger::log("YouTube refused $what (client $clientName, HTTP {$response->getStatus()}): ".implode('; ', $headers), Logger::WARNING);
        }

        /**
         * The ID of the video a YouTube link points to, or null if it isn't one.
         */
        public static function parseLink(string $link): ?string
        {
            return preg_match(self::LINK, $link, $matches) ? $matches[1] : null;
        }

        /**
         * The ID of the playlist a YouTube link points to, or null if it isn't one.
         *
         * The link of a video in a Mix (an endless playlist made by YouTube, "RD..."), as YouTube links the videos
         * opened from its recommendations, is just the link of the video.
         */
        public static function parsePlaylistLink(string $link): ?string
        {
            if (!preg_match(self::PLAYLIST_LINK, $link, $matches)) {
                return null;
            }
            return str_starts_with($matches[1], 'RD') && self::parseLink($link) !== null ? null : $matches[1];
        }

        /**
         * Get the title and the videos of a playlist, from the playlist panel of its watch page (which, unlike
         * the playlist page, also lists the videos of Mixes): at most the first 200 videos, or the first 25
         * of a Mix, starting from the given video if it's in it.
         *
         * @return array{string, list<string>} The title, and the IDs of the videos.
         *
         * @throws YouTubeException If it can't be got.
         */
        public static function getPlaylist(string $playlistId, ?string $videoId = null, ?Cancellation $cancellation = null): array
        {
            $request = new Request(self::NEXT_URL, 'POST', json_encode([
                'context' => ['client' => self::WEB_CLIENT + ['hl' => 'en', 'gl' => 'US']],
                'playlistId' => $playlistId,
            ] + ($videoId === null ? [] : ['videoId' => $videoId]), JSON_THROW_ON_ERROR));
            $request->setHeader('Content-Type', 'application/json');
            $request->setHeader('User-Agent', self::WEB_USER_AGENT);
            $request->setHeader('Origin', 'https://www.youtube.com');
            $response = self::getClient()->request($request, $cancellation);
            $next = json_decode($response->getBody()->buffer($cancellation), true);
            $playlist = $next['contents']['twoColumnWatchNextResults']['playlist']['playlist'] ?? null;
            if (!\is_array($playlist)) {
                throw new YouTubeException(\is_array($next)
                    ? "This playlist doesn't exist or is private"
                    : "HTTP error {$response->getStatus()}");
            }
            $videoIds = [];
            foreach ($playlist['contents'] ?? [] as $item) {
                if (isset($item['playlistPanelVideoRenderer']['videoId'])) {
                    $videoIds[] = $item['playlistPanelVideoRenderer']['videoId'];
                }
            }
            $start = $videoId === null ? false : array_search($videoId, $videoIds, true);
            if ($start !== false) {
                $videoIds = \array_slice($videoIds, $start);
            }
            if ($videoIds === []) {
                throw new YouTubeException('This playlist is empty');
            }
            return [$playlist['title'] ?? $playlistId, $videoIds];
        }

        /**
         * Get a visitor ID from the watch page of a video: without one, player requests are refused
         * ("Sign in to confirm you're not a bot").
         */
        private static function getVisitorData(string $videoId, ?Cancellation $cancellation): string
        {
            if (self::$visitorData !== null && time() - self::$visitorData[1] < self::VISITOR_DATA_TTL) {
                return self::$visitorData[0];
            }
            $request = new Request(self::WATCH_URL.$videoId);
            $request->setHeader('User-Agent', self::WEB_USER_AGENT);
            $request->setHeader('Accept-Language', 'en-US,en');
            $page = self::getClient()->request($request, $cancellation)->getBody()->buffer($cancellation);
            if (!preg_match('~"VISITOR_DATA":"([^"]+)"~', $page, $matches)) {
                throw new YouTubeException('Could not get a visitor ID from YouTube');
            }
            self::$visitorData = [$matches[1], time()];
            return $matches[1];
        }

        /**
         * Get the player response of a video.
         *
         * @param int $firstClient The client to try first: the next ones are tried if it fails.
         *
         * @return array{array, string, int} The player response, the user agent to fetch its formats
         *                                   with, and the client it was got with.
         */
        public static function getPlayer(string $videoId, ?Cancellation $cancellation = null, int $firstClient = 0): array
        {
            $reason = 'unknown error';
            $count = self::getClientCount();
            for ($i = 0; $i < $count; $i++) {
                $index = ($firstClient + $i) % $count;
                $client = self::CLIENTS[$index];
                try {
                    $request = new Request(self::PLAYER_URL, 'POST', json_encode([
                        'context' => ['client' => $client['context'] + ['hl' => 'en', 'gl' => 'US']],
                        'videoId' => $videoId,
                        'contentCheckOk' => true,
                        'racyCheckOk' => true,
                    ], JSON_THROW_ON_ERROR));
                    $request->setHeader('Content-Type', 'application/json');
                    $request->setHeader('User-Agent', $client['context']['userAgent']);
                    $request->setHeader('Origin', 'https://www.youtube.com');
                    $request->setHeader('X-YouTube-Client-Name', (string) $client['id']);
                    $request->setHeader('X-YouTube-Client-Version', $client['context']['clientVersion']);
                    $request->setHeader('X-Goog-Visitor-Id', self::getVisitorData($videoId, $cancellation));
                    $response = self::getClient()->request($request, $cancellation);
                    $player = json_decode($response->getBody()->buffer($cancellation), true);
                } catch (CancelledException $e) {
                    throw $e;
                } catch (Throwable $e) {
                    $reason = $e->getMessage();
                    continue;
                }
                if (!\is_array($player)) {
                    $reason = "HTTP error {$response->getStatus()}";
                    continue;
                }
                $status = $player['playabilityStatus'] ?? [];
                if (($status['status'] ?? null) === 'OK' && isset($player['streamingData'])) {
                    return [$player, $client['context']['userAgent'], $index];
                }
                if (($status['status'] ?? null) === 'LOGIN_REQUIRED') {
                    // The visitor ID may have been flagged: get a new one next time.
                    self::$visitorData = null;
                }
                $reason = $status['reason'] ?? $status['status'] ?? $reason;
            }
            throw new YouTubeException($reason);
        }

        /**
         * The best WebM VP9 video format no higher than the given height, if any.
         */
        public static function pickVideo(array $player, int $maxHeight): ?array
        {
            $formats = array_filter(
                self::getFormats($player),
                // "vp9" alone excludes HDR, which is "vp09.02...".
                static fn (array $format): bool => $format['mimeType'] === 'video/webm; codecs="vp9"'
                    && ($format['height'] ?? PHP_INT_MAX) <= $maxHeight,
            );
            usort($formats, static fn (array $a, array $b): int => [$b['height'] ?? 0, $b['fps'] ?? 0, $b['bitrate'] ?? 0]
                <=> [$a['height'] ?? 0, $a['fps'] ?? 0, $a['bitrate'] ?? 0]);
            return $formats[0] ?? null;
        }

        /**
         * The best WebM OPUS audio format, in the original language, if any.
         */
        public static function pickAudio(array $player): ?array
        {
            $formats = array_filter(
                self::getFormats($player),
                static fn (array $format): bool => $format['mimeType'] === 'audio/webm; codecs="opus"'
                    && !($format['isDrc'] ?? false),
            );
            usort($formats, static fn (array $a, array $b): int => [$b['audioTrack']['audioIsDefault'] ?? true, $b['bitrate'] ?? 0]
                <=> [$a['audioTrack']['audioIsDefault'] ?? true, $a['bitrate'] ?? 0]);
            return $formats[0] ?? null;
        }

        /**
         * The best MP4 H.264 video format no higher than the given height, if any (for RTMP livestreams).
         */
        public static function pickH264Video(array $player, int $maxHeight): ?array
        {
            $formats = array_filter(
                self::getFormats($player),
                static fn (array $format): bool => str_starts_with($format['mimeType'], 'video/mp4; codecs="avc1.')
                    && ($format['height'] ?? PHP_INT_MAX) <= $maxHeight,
            );
            usort($formats, static fn (array $a, array $b): int => [$b['height'] ?? 0, $b['fps'] ?? 0, $b['bitrate'] ?? 0]
                <=> [$a['height'] ?? 0, $a['fps'] ?? 0, $a['bitrate'] ?? 0]);
            return $formats[0] ?? null;
        }

        /**
         * The best MP4 AAC audio format, in the original language, if any (for RTMP livestreams): AAC-LC first.
         */
        public static function pickAacAudio(array $player): ?array
        {
            $formats = array_filter(
                self::getFormats($player),
                static fn (array $format): bool => str_starts_with($format['mimeType'], 'audio/mp4; codecs="mp4a.')
                    && !($format['isDrc'] ?? false),
            );
            usort($formats, static fn (array $a, array $b): int => [
                $b['audioTrack']['audioIsDefault'] ?? true,
                $b['mimeType'] === 'audio/mp4; codecs="mp4a.40.2"',
                $b['bitrate'] ?? 0,
            ] <=> [
                $a['audioTrack']['audioIsDefault'] ?? true,
                $a['mimeType'] === 'audio/mp4; codecs="mp4a.40.2"',
                $a['bitrate'] ?? 0,
            ]);
            return $formats[0] ?? null;
        }

        /**
         * Find a format again, by its itag and size.
         */
        public static function findFormat(array $player, int $itag, int $size): ?array
        {
            foreach (self::getFormats($player) as $format) {
                if ($format['itag'] === $itag && $format['size'] === $size) {
                    return $format;
                }
            }
            return null;
        }

        /**
         * The formats that can be fetched, along with their size.
         *
         * @return list<array>
         */
        private static function getFormats(array $player): array
        {
            $formats = [];
            foreach ($player['streamingData']['adaptiveFormats'] ?? [] as $format) {
                if (isset($format['url'], $format['contentLength'], $format['itag'], $format['mimeType'])) {
                    $format['size'] = (int) $format['contentLength'];
                    $formats[] = $format;
                }
            }
            return $formats;
        }
    }

    /**
     * Fetching the formats of a YouTube video a range at a time, with new URLs when they expire.
     *
     * Needs the videoId, video and audio (the formats: itag and size) properties, the urls and client ones, and the
     * cancellation of the current read.
     */
    trait FetchesYouTubeFormats
    {
        /** How many times to try fetching a range. */
        private const ATTEMPTS = 5;

        /**
         * Fetch a range of the video or audio file, getting new URLs if needed.
         *
         * @param 'video'|'audio' $kind
         */
        private function fetch(string $kind, int $start, int $end): string
        {
            $errors = [];
            for ($attempt = 1; ; $attempt++) {
                try {
                    $this->urls ??= $this->getUrls();
                    $request = new Request($this->urls[$kind]);
                    $request->setHeader('Range', "bytes=$start-$end");
                    $request->setHeader('User-Agent', $this->urls['userAgent']);
                    $request->setTransferTimeout(120);
                    $request->setInactivityTimeout(30);
                    $response = YouTube::getClient()->request($request, $this->cancellation);
                    $body = $response->getBody()->buffer($this->cancellation);
                    $status = $response->getStatus();
                    if ($status === 206 && \strlen($body) === $end - $start + 1) {
                        return $body;
                    }
                    if ($status === 403 || $status === 410) {
                        // The URLs expired, were requested from another IP address (they're bound to it), or are
                        // refused: get new ones.
                        $this->urls = null;
                        YouTube::logRefusal("the $kind of $this->videoId (itag {$this->{$kind}['itag']}, bytes $start-$end)", $response, $this->client);
                    }
                    throw new YouTubeException("HTTP status $status, got ".\strlen($body).' bytes', $status);
                } catch (CancelledException $e) {
                    throw $e;
                } catch (Throwable $e) {
                    $errors[] = $e->getMessage();
                    if ($attempt >= self::ATTEMPTS) {
                        throw new YouTubeException("Could not fetch the $kind of $this->videoId: ".implode('; ', array_unique($errors)), 0, $e);
                    }
                    // Back off for longer when refused.
                    delay(\in_array($e->getCode(), [403, 410], true) ? 2 ** $attempt : $attempt, cancellation: $this->cancellation);
                }
            }
        }

        /**
         * Get new URLs for the formats, from the first client (starting from the current one) that has them.
         *
         * @return array{video?: string, audio: string, userAgent: string}
         */
        private function getUrls(): array
        {
            $missing = null;
            for ($i = 0; $i < YouTube::getClientCount(); $i++) {
                [$player, $userAgent, $client] = YouTube::getPlayer($this->videoId, $this->cancellation, $this->client + $i);
                $urls = ['userAgent' => $userAgent];
                foreach (['video' => $this->video, 'audio' => $this->audio] as $kind => $format) {
                    if ($format === null) {
                        continue;
                    }
                    $found = YouTube::findFormat($player, $format['itag'], $format['size']);
                    if ($found === null) {
                        $missing = $kind;
                        continue 2;
                    }
                    $urls[$kind] = $found['url'];
                }
                $this->client = $client;
                return $urls;
            }
            throw new YouTubeException("The $missing of $this->videoId isn't available anymore");
        }
    }

    /**
     * A YouTube video, as a WebM file with VP9 video and OPUS audio, that can be played in calls as it is.
     *
     * YouTube serves the video and the audio of a video as separate WebM files: they are fetched a
     * range at a time, and muxed into a single file on the fly, without ffmpeg.
     *
     * The stream is resumable: once unserialized, it continues after the last chunk that was read,
     * with new URLs (those of YouTube expire).
     *
     * @implements IteratorAggregate<int, string>
     */
    final class YouTubeStream implements ReadableStream, ResumableStream, IteratorAggregate
    {
        use ReadableStreamIteratorAggregate;
        use ForbidCloning;
        use FetchesYouTubeFormats;

        private const ID_EBML = 0x1A45DFA3;
        private const ID_EBML_VERSION = 0x4286;
        private const ID_EBML_READ_VERSION = 0x42F7;
        private const ID_EBML_MAX_ID_LENGTH = 0x42F2;
        private const ID_EBML_MAX_SIZE_LENGTH = 0x42F3;
        private const ID_DOC_TYPE = 0x4282;
        private const ID_DOC_TYPE_VERSION = 0x4287;
        private const ID_DOC_TYPE_READ_VERSION = 0x4285;
        private const ID_SEGMENT = 0x18538067;
        private const ID_INFO = 0x1549A966;
        private const ID_TIMESTAMP_SCALE = 0x2AD7B1;
        private const ID_MUXING_APP = 0x4D80;
        private const ID_WRITING_APP = 0x5741;
        private const ID_TITLE = 0x7BA9;
        private const ID_TRACKS = 0x1654AE6B;
        private const ID_TRACK_ENTRY = 0xAE;
        private const ID_TRACK_NUMBER = 0xD7;
        private const ID_TRACK_UID = 0x73C5;
        private const ID_CLUSTER = 0x1F43B675;
        private const ID_TIMESTAMP = 0xE7;

        private const VIDEO_TRACK = 1;
        private const AUDIO_TRACK = 2;
        /** The longest cluster, in milliseconds: a new one is also started at every video keyframe. */
        private const MAX_CLUSTER_LENGTH = 10_000;
        /** @var array{video?: WebmReader, audio?: WebmReader} */
        private array $readers = [];
        /**
         * Where the readers were, before serialization: their offset, cluster timestamp and timestamp scale.
         *
         * @var array{video?: array{int, int, int}, audio?: array{int, int, int}}
         */
        private array $positions = [];
        /**
         * The URLs of the formats (which expire), and the user agent to fetch them with.
         *
         * @var array{video?: string, audio: string, userAgent: string}|null
         */
        private ?array $urls = null;
        /** The InnerTube client to get the URLs with, see {@see YouTube::getPlayer()}. */
        private int $client = 0;
        private ?Cancellation $cancellation = null;
        private bool $headerRead = false;
        private bool $eof = false;
        private bool $closed = false;
        private DeferredFuture $onClose;

        /**
         * @param array{itag: int, size: int}|null $video The video format, if any.
         * @param array{itag: int, size: int}      $audio The audio format.
         */
        private function __construct(
            public readonly string $videoId,
            public readonly string $title,
            private readonly ?array $video,
            private readonly array $audio,
        ) {
            $this->onClose = new DeferredFuture;
        }

        /**
         * Open a YouTube video.
         *
         * @param string $videoId   The ID of the video, see {@see YouTube::parseLink()}.
         * @param int    $maxHeight The highest resolution to play.
         *
         * @return self|YouTubeLiveStream A live stream is played as a YouTubeLiveStream, just its video.
         *
         * @throws YouTubeException If it can't be played.
         */
        public static function open(string $videoId, int $maxHeight = 1080, ?Cancellation $cancellation = null): self|YouTubeLiveStream
        {
            [$player, $userAgent, $client] = YouTube::getPlayer($videoId, $cancellation);
            if ($player['videoDetails']['isLive'] ?? false) {
                return YouTubeLiveStream::fromPlayer($videoId, $player, $userAgent, $client, audio: false, maxHeight: $maxHeight);
            }
            $video = YouTube::pickVideo($player, $maxHeight);
            $audio = YouTube::pickAudio($player)
                ?? throw new YouTubeException('This video has no OPUS audio');
            $stream = new self(
                $videoId,
                $player['videoDetails']['title'] ?? $videoId,
                $video === null ? null : ['itag' => $video['itag'], 'size' => $video['size']],
                ['itag' => $audio['itag'], 'size' => $audio['size']],
            );
            $stream->client = $client;
            $stream->urls = ['audio' => $audio['url'], 'userAgent' => $userAgent];
            if ($video !== null) {
                $stream->urls['video'] = $video['url'];
            }
            return $stream;
        }

        /**
         * Whether just the audio will be played, as there's no WebM VP9 video.
         */
        public function isAudioOnly(): bool
        {
            return $this->video === null;
        }

        #[\Override]
        public function read(?Cancellation $cancellation = null): ?string
        {
            if ($this->closed || $this->eof) {
                return null;
            }
            $this->cancellation = $cancellation;
            try {
                if (!$this->headerRead) {
                    $header = $this->readHeader();
                    $this->headerRead = true;
                    return $header;
                }
                $cluster = $this->readCluster();
            } finally {
                $this->cancellation = null;
            }
            if ($cluster === null) {
                $this->eof = true;
                $this->readers = [];
            }
            return $cluster;
        }

        /**
         * Read the EBML header, the info and the tracks of the file.
         */
        private function readHeader(): string
        {
            $tracks = '';
            $readers = ['video' => self::VIDEO_TRACK, 'audio' => self::AUDIO_TRACK];
            foreach ($readers as $kind => $number) {
                if ($kind === 'video' && $this->video === null) {
                    continue;
                }
                $reader = $this->getReader($kind);
                // The tracks precede the first block.
                $reader->peek();
                $track = $reader->track ?? throw new YouTubeException("The $kind file has no tracks");
                $expected = $kind === 'video' ? [WebmReader::TRACK_TYPE_VIDEO, 'V_VP9'] : [WebmReader::TRACK_TYPE_AUDIO, 'A_OPUS'];
                if ([$track['type'], $track['codec']] !== $expected) {
                    throw new YouTubeException("Unexpected $kind track: {$track['codec']}");
                }
                $tracks .= Ebml::element(
                    self::ID_TRACK_ENTRY,
                    Ebml::uint(self::ID_TRACK_NUMBER, $number).Ebml::uint(self::ID_TRACK_UID, $number).$track['elements'],
                );
            }
            return Ebml::element(
                self::ID_EBML,
                Ebml::uint(self::ID_EBML_VERSION, 1)
                .Ebml::uint(self::ID_EBML_READ_VERSION, 1)
                .Ebml::uint(self::ID_EBML_MAX_ID_LENGTH, 4)
                .Ebml::uint(self::ID_EBML_MAX_SIZE_LENGTH, 8)
                .Ebml::element(self::ID_DOC_TYPE, 'webm')
                .Ebml::uint(self::ID_DOC_TYPE_VERSION, 4)
                .Ebml::uint(self::ID_DOC_TYPE_READ_VERSION, 2),
            )
            // A segment of unknown size, as the file is streamed.
            .pack('N', self::ID_SEGMENT)."\x01\xFF\xFF\xFF\xFF\xFF\xFF\xFF"
            .Ebml::element(
                self::ID_INFO,
                // Timestamps are in milliseconds.
                Ebml::uint(self::ID_TIMESTAMP_SCALE, 1_000_000)
                .Ebml::element(self::ID_MUXING_APP, 'magnaluna')
                .Ebml::element(self::ID_WRITING_APP, 'magnaluna')
                .Ebml::element(self::ID_TITLE, $this->title),
            )
            .Ebml::element(self::ID_TRACKS, $tracks);
        }

        /**
         * Read the next cluster: the blocks of the video and audio files, in timestamp order, up to the next video keyframe.
         */
        private function readCluster(): ?string
        {
            $video = $this->video === null ? null : $this->getReader('video');
            $audio = $this->getReader('audio');
            $clusterTimestamp = null;
            $blocks = '';
            while (true) {
                $videoBlock = $video?->peek();
                $audioBlock = $audio->peek();
                if ($videoBlock === null && $audioBlock === null) {
                    break;
                }
                $isVideo = $videoBlock !== null && ($audioBlock === null || $videoBlock['timestamp'] <= $audioBlock['timestamp']);
                $block = $isVideo ? $videoBlock : $audioBlock;
                if ($clusterTimestamp === null) {
                    $clusterTimestamp = $block['timestamp'];
                } elseif (($isVideo && $block['keyframe']) || $block['timestamp'] - $clusterTimestamp > self::MAX_CLUSTER_LENGTH) {
                    break;
                }
                $blocks .= self::rewriteBlock($block, $isVideo ? self::VIDEO_TRACK : self::AUDIO_TRACK, $block['timestamp'] - $clusterTimestamp);
                ($isVideo ? $video : $audio)->consume();
            }
            if ($clusterTimestamp === null) {
                return null;
            }
            return Ebml::element(self::ID_CLUSTER, Ebml::uint(self::ID_TIMESTAMP, $clusterTimestamp).$blocks);
        }

        /**
         * Move a block to another track, and to a timestamp relative to another cluster.
         *
         * @param array{id: int, payload: string} $block
         */
        private static function rewriteBlock(array $block, int $track, int $relativeTimestamp): string
        {
            $rewrite = static function (string $payload) use ($track, $relativeTimestamp): string {
                [, $trackLength] = Ebml::readVint($payload, 0);
                return \chr(0x80 | $track).pack('n', $relativeTimestamp & 0xFFFF).substr($payload, $trackLength + 2);
            };
            if ($block['id'] === WebmReader::ID_SIMPLE_BLOCK) {
                return Ebml::element(WebmReader::ID_SIMPLE_BLOCK, $rewrite($block['payload']));
            }
            $group = '';
            foreach (Ebml::children($block['payload']) as [$id, $payload, $element]) {
                $group .= $id === WebmReader::ID_BLOCK ? Ebml::element($id, $rewrite($payload)) : $element;
            }
            return Ebml::element(WebmReader::ID_BLOCK_GROUP, $group);
        }

        /**
         * @param 'video'|'audio' $kind
         */
        private function getReader(string $kind): WebmReader
        {
            if (!isset($this->readers[$kind])) {
                $format = $kind === 'video' ? $this->video : $this->audio;
                \assert($format !== null);
                [$offset, $clusterTimestamp, $timestampScale] = $this->positions[$kind] ?? [0, 0, 1_000_000];
                $this->readers[$kind] = new WebmReader(
                    fn (int $start, int $end): string => $this->fetch($kind, $start, $end),
                    $format['size'],
                    $offset,
                    $clusterTimestamp,
                    $timestampScale,
                );
            }
            return $this->readers[$kind];
        }

        /** @psalm-mutation-free */
        #[\Override]
        public function isReadable(): bool
        {
            return !$this->closed && !$this->eof;
        }

        #[\Override]
        public function close(): void
        {
            $this->closed = true;
            $this->readers = [];
            if (!$this->onClose->isComplete()) {
                $this->onClose->complete();
            }
        }

        /** @psalm-mutation-free */
        #[\Override]
        public function isClosed(): bool
        {
            return !$this->isReadable();
        }

        #[\Override]
        public function onClose(Closure $onClose): void
        {
            $this->onClose->getFuture()->finally($onClose);
        }

        public function __serialize(): array
        {
            $positions = $this->positions;
            foreach ($this->readers as $kind => $reader) {
                $positions[$kind] = $reader->getPosition();
            }
            return [
                'videoId' => $this->videoId,
                'title' => $this->title,
                'video' => $this->video,
                'audio' => $this->audio,
                'positions' => $positions,
                'client' => $this->client,
                'headerRead' => $this->headerRead,
                'eof' => $this->eof,
            ];
        }

        public function __unserialize(array $data): void
        {
            $this->videoId = $data['videoId'];
            $this->title = $data['title'];
            $this->video = $data['video'];
            $this->audio = $data['audio'];
            $this->positions = $data['positions'];
            $this->client = $data['client'];
            $this->headerRead = $data['headerRead'];
            $this->eof = $data['eof'];
            $this->onClose = new DeferredFuture;
        }
    }

    /**
     * A file read a range at a time, from start to end.
     *
     * @implements IteratorAggregate<int, string>
     */
    final class RangeStream implements ReadableStream, IteratorAggregate
    {
        use ReadableStreamIteratorAggregate;
        use ForbidCloning;

        /** The size of the ranges. */
        private const CHUNK = 1 << 20;

        private int $offset = 0;
        private bool $closed = false;
        private DeferredFuture $onClose;

        /**
         * @param Closure(int, int): string $fetch Fetch a range of the file (first and last byte, included).
         */
        public function __construct(private readonly Closure $fetch, private readonly int $size)
        {
            $this->onClose = new DeferredFuture;
        }

        #[\Override]
        public function read(?Cancellation $cancellation = null): ?string
        {
            if ($this->closed || $this->offset >= $this->size) {
                return null;
            }
            $end = min($this->offset + self::CHUNK, $this->size) - 1;
            $chunk = ($this->fetch)($this->offset, $end);
            $this->offset = $end + 1;
            return $chunk;
        }

        /** @psalm-mutation-free */
        #[\Override]
        public function isReadable(): bool
        {
            return !$this->closed && $this->offset < $this->size;
        }

        #[\Override]
        public function close(): void
        {
            $this->closed = true;
            if (!$this->onClose->isComplete()) {
                $this->onClose->complete();
            }
        }

        /** @psalm-mutation-free */
        #[\Override]
        public function isClosed(): bool
        {
            return !$this->isReadable();
        }

        #[\Override]
        public function onClose(Closure $onClose): void
        {
            $this->onClose->getFuture()->finally($onClose);
        }
    }

    /**
     * A YouTube video for RTMP livestreams, which only carry H.264 and AAC: a Matroska file with the H.264 video
     * and the AAC audio of the video, which YouTube serves as separate fragmented MP4 files.
     *
     * The frames of both files are demuxed (with MadelineProto's MP4 demuxer) as they're fetched, a range at a time,
     * and muxed into a single file on the fly, without ffmpeg.
     *
     * The stream is resumable: once unserialized, it continues after the last chunk that was read (the files are
     * read again from their start up to there, with new URLs).
     *
     * @implements IteratorAggregate<int, string>
     */
    final class YouTubeMp4Stream implements ReadableStream, ResumableStream, IteratorAggregate
    {
        use ReadableStreamIteratorAggregate;
        use ForbidCloning;
        use FetchesYouTubeFormats;

        private const ID_EBML = 0x1A45DFA3;
        private const ID_EBML_VERSION = 0x4286;
        private const ID_EBML_READ_VERSION = 0x42F7;
        private const ID_EBML_MAX_ID_LENGTH = 0x42F2;
        private const ID_EBML_MAX_SIZE_LENGTH = 0x42F3;
        private const ID_DOC_TYPE = 0x4282;
        private const ID_DOC_TYPE_VERSION = 0x4287;
        private const ID_DOC_TYPE_READ_VERSION = 0x4285;
        private const ID_SEGMENT = 0x18538067;
        private const ID_INFO = 0x1549A966;
        private const ID_TIMESTAMP_SCALE = 0x2AD7B1;
        private const ID_MUXING_APP = 0x4D80;
        private const ID_WRITING_APP = 0x5741;
        private const ID_TITLE = 0x7BA9;
        private const ID_TRACKS = 0x1654AE6B;
        private const ID_TRACK_ENTRY = 0xAE;
        private const ID_TRACK_NUMBER = 0xD7;
        private const ID_TRACK_UID = 0x73C5;
        private const ID_TRACK_TYPE = 0x83;
        private const ID_CODEC_ID = 0x86;
        private const ID_CODEC_PRIVATE = 0x63A2;
        private const ID_VIDEO = 0xE0;
        private const ID_PIXEL_WIDTH = 0xB0;
        private const ID_PIXEL_HEIGHT = 0xBA;
        private const ID_AUDIO = 0xE1;
        private const ID_SAMPLING_FREQUENCY = 0xB5;
        private const ID_CHANNELS = 0x9F;
        private const ID_CLUSTER = 0x1F43B675;
        private const ID_TIMESTAMP = 0xE7;
        private const ID_SIMPLE_BLOCK = 0xA3;

        private const VIDEO_TRACK = 1;
        private const AUDIO_TRACK = 2;
        private const TRACK_TYPES = ['video' => 1, 'audio' => 2];
        /** The longest cluster, in milliseconds: a new one is also started at every video keyframe. */
        private const MAX_CLUSTER_LENGTH = 10_000;

        /** @var array{video?: Mp4, audio?: Mp4} */
        private array $demuxers = [];
        /** @var array{video?: \Iterator, audio?: \Iterator} The frames of the demuxers. */
        private array $frames = [];
        /**
         * The timestamp of the last frame read (in milliseconds), so that a resumed stream continues after it.
         */
        private int $readUntil = -1;
        /**
         * The URLs of the formats (which expire), and the user agent to fetch them with.
         *
         * @var array{video?: string, audio: string, userAgent: string}|null
         */
        private ?array $urls = null;
        /** The InnerTube client to get the URLs with, see {@see YouTube::getPlayer()}. */
        private int $client = 0;
        private ?Cancellation $cancellation = null;
        private bool $headerRead = false;
        private bool $eof = false;
        private bool $closed = false;
        private DeferredFuture $onClose;

        /**
         * @param array{itag: int, size: int}|null $video The video format, if any.
         * @param array{itag: int, size: int}      $audio The audio format.
         */
        private function __construct(
            public readonly string $videoId,
            public readonly string $title,
            private readonly ?array $video,
            private readonly array $audio,
        ) {
            $this->onClose = new DeferredFuture;
        }

        /**
         * Open a YouTube video.
         *
         * @param string $videoId   The ID of the video, see {@see YouTube::parseLink()}.
         * @param int    $maxHeight The highest resolution to play: Telegram shows livestreams in 720p.
         *
         * @return self|YouTubeLiveStream A live stream is played as a YouTubeLiveStream, with AAC audio.
         *
         * @throws YouTubeException If it can't be played.
         */
        public static function open(string $videoId, int $maxHeight = 720, ?Cancellation $cancellation = null): self|YouTubeLiveStream
        {
            [$player, $userAgent, $client] = YouTube::getPlayer($videoId, $cancellation);
            if ($player['videoDetails']['isLive'] ?? false) {
                return YouTubeLiveStream::fromPlayer($videoId, $player, $userAgent, $client, audio: true, maxHeight: $maxHeight);
            }
            $video = YouTube::pickH264Video($player, $maxHeight);
            $audio = YouTube::pickAacAudio($player)
                ?? throw new YouTubeException('This video has no AAC audio');
            $stream = new self(
                $videoId,
                $player['videoDetails']['title'] ?? $videoId,
                $video === null ? null : ['itag' => $video['itag'], 'size' => $video['size']],
                ['itag' => $audio['itag'], 'size' => $audio['size']],
            );
            $stream->client = $client;
            $stream->urls = ['audio' => $audio['url'], 'userAgent' => $userAgent];
            if ($video !== null) {
                $stream->urls['video'] = $video['url'];
            }
            return $stream;
        }

        /**
         * Whether just the audio will be played, as there's no MP4 H.264 video.
         */
        public function isAudioOnly(): bool
        {
            return $this->video === null;
        }

        #[\Override]
        public function read(?Cancellation $cancellation = null): ?string
        {
            if ($this->closed || $this->eof) {
                return null;
            }
            $this->cancellation = $cancellation;
            try {
                $this->startDemuxing();
                if (!$this->headerRead) {
                    $this->headerRead = true;
                    return $this->header();
                }
                $cluster = $this->readCluster();
            } finally {
                $this->cancellation = null;
            }
            if ($cluster === null) {
                $this->eof = true;
                $this->demuxers = $this->frames = [];
            }
            return $cluster;
        }

        /**
         * Start demuxing the formats (again, after resuming: from their start, skipping the frames already read).
         */
        private function startDemuxing(): void
        {
            if ($this->frames !== []) {
                return;
            }
            foreach (['video' => $this->video, 'audio' => $this->audio] as $kind => $format) {
                if ($format === null) {
                    continue;
                }
                $demuxer = new Mp4(
                    new RangeStream(fn (int $start, int $end): string => $this->fetch($kind, $start, $end), $format['size']),
                    $this->cancellation,
                );
                $expected = $kind === 'video' ? 'V_MPEG4/ISO/AVC' : 'A_AAC';
                if (!$demuxer->hasCodec($expected)) {
                    throw new YouTubeException("Unexpected $kind file: no $expected track");
                }
                $this->demuxers[$kind] = $demuxer;
                $frames = (static function () use ($demuxer, $expected): Generator {
                    foreach ($demuxer->frames as $frame) {
                        if ($frame['codec'] === $expected) {
                            yield $frame;
                        }
                    }
                })();
                $this->frames[$kind] = $frames;
                if ($this->readUntil >= 0) {
                    // Resuming: the decoder of the video needs to start from a keyframe.
                    while ($frames->valid() && ($frames->current()['timestamp'] <= $this->readUntil
                        || ($kind === 'video' && !$frames->current()['keyframe']))
                    ) {
                        $frames->next();
                    }
                }
            }
        }

        /**
         * The EBML header, the info and the tracks of the file.
         */
        private function header(): string
        {
            $tracks = '';
            foreach (['video' => self::VIDEO_TRACK, 'audio' => self::AUDIO_TRACK] as $kind => $number) {
                if (!isset($this->demuxers[$kind])) {
                    continue;
                }
                $expected = $kind === 'video' ? 'V_MPEG4/ISO/AVC' : 'A_AAC';
                foreach ($this->demuxers[$kind]->tracks as $track) {
                    if ($track['codec'] !== $expected) {
                        continue;
                    }
                    $elements = Ebml::uint(self::ID_TRACK_NUMBER, $number)
                        .Ebml::uint(self::ID_TRACK_UID, $number)
                        .Ebml::uint(self::ID_TRACK_TYPE, self::TRACK_TYPES[$kind])
                        .Ebml::element(self::ID_CODEC_ID, $track['codec'])
                        .($track['private'] === null ? '' : Ebml::element(self::ID_CODEC_PRIVATE, $track['private']));
                    $elements .= $kind === 'video'
                        ? Ebml::element(self::ID_VIDEO, Ebml::uint(self::ID_PIXEL_WIDTH, $track['width']).Ebml::uint(self::ID_PIXEL_HEIGHT, $track['height']))
                        : Ebml::element(self::ID_AUDIO, Ebml::element(self::ID_SAMPLING_FREQUENCY, pack('E', (float) $track['rate'])).Ebml::uint(self::ID_CHANNELS, $track['channels']));
                    $tracks .= Ebml::element(self::ID_TRACK_ENTRY, $elements);
                    break;
                }
            }
            return Ebml::element(
                self::ID_EBML,
                Ebml::uint(self::ID_EBML_VERSION, 1)
                .Ebml::uint(self::ID_EBML_READ_VERSION, 1)
                .Ebml::uint(self::ID_EBML_MAX_ID_LENGTH, 4)
                .Ebml::uint(self::ID_EBML_MAX_SIZE_LENGTH, 8)
                .Ebml::element(self::ID_DOC_TYPE, 'matroska')
                .Ebml::uint(self::ID_DOC_TYPE_VERSION, 4)
                .Ebml::uint(self::ID_DOC_TYPE_READ_VERSION, 2),
            )
            // A segment of unknown size, as the file is streamed.
            .pack('N', self::ID_SEGMENT)."\x01\xFF\xFF\xFF\xFF\xFF\xFF\xFF"
            .Ebml::element(
                self::ID_INFO,
                // Timestamps are in milliseconds.
                Ebml::uint(self::ID_TIMESTAMP_SCALE, 1_000_000)
                .Ebml::element(self::ID_MUXING_APP, 'magnaluna')
                .Ebml::element(self::ID_WRITING_APP, 'magnaluna')
                .Ebml::element(self::ID_TITLE, $this->title),
            )
            .Ebml::element(self::ID_TRACKS, $tracks);
        }

        /**
         * Read the next cluster: the frames of the video and audio files, in timestamp order, up to the next video keyframe.
         */
        private function readCluster(): ?string
        {
            $video = $this->frames['video'] ?? null;
            $audio = $this->frames['audio'];
            $clusterTimestamp = null;
            $blocks = '';
            while (true) {
                $videoFrame = $video?->valid() ? $video->current() : null;
                $audioFrame = $audio->valid() ? $audio->current() : null;
                if ($videoFrame === null && $audioFrame === null) {
                    break;
                }
                $isVideo = $videoFrame !== null && ($audioFrame === null || $videoFrame['timestamp'] <= $audioFrame['timestamp']);
                $frame = $isVideo ? $videoFrame : $audioFrame;
                if ($clusterTimestamp === null) {
                    $clusterTimestamp = $frame['timestamp'];
                } elseif (($isVideo && $frame['keyframe']) || $frame['timestamp'] - $clusterTimestamp > self::MAX_CLUSTER_LENGTH) {
                    break;
                }
                $blocks .= Ebml::element(
                    self::ID_SIMPLE_BLOCK,
                    \chr(0x80 | ($isVideo ? self::VIDEO_TRACK : self::AUDIO_TRACK))
                    .pack('n', ($frame['timestamp'] - $clusterTimestamp) & 0xFFFF)
                    .\chr($frame['keyframe'] || !$isVideo ? 0x80 : 0)
                    .$frame['data'],
                );
                $this->readUntil = max($this->readUntil, $frame['timestamp']);
                ($isVideo ? $video : $audio)->next();
            }
            if ($clusterTimestamp === null) {
                return null;
            }
            return Ebml::element(self::ID_CLUSTER, Ebml::uint(self::ID_TIMESTAMP, $clusterTimestamp).$blocks);
        }

        /** @psalm-mutation-free */
        #[\Override]
        public function isReadable(): bool
        {
            return !$this->closed && !$this->eof;
        }

        #[\Override]
        public function close(): void
        {
            $this->closed = true;
            $this->demuxers = $this->frames = [];
            if (!$this->onClose->isComplete()) {
                $this->onClose->complete();
            }
        }

        /** @psalm-mutation-free */
        #[\Override]
        public function isClosed(): bool
        {
            return !$this->isReadable();
        }

        #[\Override]
        public function onClose(Closure $onClose): void
        {
            $this->onClose->getFuture()->finally($onClose);
        }

        public function __serialize(): array
        {
            return [
                'videoId' => $this->videoId,
                'title' => $this->title,
                'video' => $this->video,
                'audio' => $this->audio,
                'readUntil' => $this->readUntil,
                'client' => $this->client,
                'headerRead' => $this->headerRead,
                'eof' => $this->eof,
            ];
        }

        public function __unserialize(array $data): void
        {
            $this->videoId = $data['videoId'];
            $this->title = $data['title'];
            $this->video = $data['video'];
            $this->audio = $data['audio'];
            $this->readUntil = $data['readUntil'];
            $this->client = $data['client'];
            $this->headerRead = $data['headerRead'];
            $this->eof = $data['eof'];
            $this->onClose = new DeferredFuture;
        }
    }

    /**
     * A video of a YouTube playlist, opened when it's first read: the videos of a playlist are queued all at
     * once, but each is only opened (with fresh URLs) when its turn comes.
     *
     * A video that can't be played makes the first read throw, which skips it.
     *
     * @implements IteratorAggregate<int, string>
     */
    final class YouTubePlaylistEntry implements ReadableStream, ResumableStream, IteratorAggregate
    {
        use ReadableStreamIteratorAggregate;
        use ForbidCloning;

        private YouTubeStream|YouTubeMp4Stream|YouTubeLiveStream|null $stream = null;
        private bool $closed = false;
        private DeferredFuture $onClose;

        /**
         * @param string $videoId The ID of the video.
         * @param bool   $rtmp    Whether it's played in an RTMP livestream, see {@see YouTubeMp4Stream}, or else
         *                        in a call, see {@see YouTubeStream}.
         */
        public function __construct(public readonly string $videoId, public readonly bool $rtmp)
        {
            $this->onClose = new DeferredFuture;
        }

        #[\Override]
        public function read(?Cancellation $cancellation = null): ?string
        {
            if ($this->closed) {
                return null;
            }
            if ($this->stream === null) {
                try {
                    $this->stream = $this->rtmp
                        ? YouTubeMp4Stream::open($this->videoId, cancellation: $cancellation)
                        : YouTubeStream::open($this->videoId, cancellation: $cancellation);
                } catch (YouTubeException $e) {
                    throw new YouTubeException("Can't play the playlist video $this->videoId: {$e->getMessage()}", 0, $e);
                }
                if ($this->closed) {
                    // Closed (skipped) while it was being opened.
                    $this->stream->close();
                    return null;
                }
            }
            return $this->stream->read($cancellation);
        }

        /** @psalm-mutation-free */
        #[\Override]
        public function isReadable(): bool
        {
            return !$this->closed && ($this->stream?->isReadable() ?? true);
        }

        #[\Override]
        public function close(): void
        {
            $this->closed = true;
            $this->stream?->close();
            if (!$this->onClose->isComplete()) {
                $this->onClose->complete();
            }
        }

        /** @psalm-mutation-free */
        #[\Override]
        public function isClosed(): bool
        {
            return !$this->isReadable();
        }

        #[\Override]
        public function onClose(Closure $onClose): void
        {
            $this->onClose->getFuture()->finally($onClose);
        }

        /**
         * The video, and where it was if it was opened.
         */
        public function __serialize(): array
        {
            return ['videoId' => $this->videoId, 'rtmp' => $this->rtmp, 'stream' => $this->stream, 'closed' => $this->closed];
        }

        public function __unserialize(array $data): void
        {
            $this->videoId = $data['videoId'];
            $this->rtmp = $data['rtmp'];
            $this->stream = $data['stream'];
            $this->closed = $data['closed'];
            $this->onClose = new DeferredFuture;
        }
    }

    /**
     * A YouTube live stream, as a Matroska file with H.264 video and AAC audio, that can be played in calls (just its
     * video: calls only carry OPUS audio) and in RTMP livestreams.
     *
     * YouTube serves the video and the audio of a live stream as separate formats, a self-contained fragmented MP4
     * segment at a time (of about 5 seconds, numbered, with the same clock): the segments are fetched as they're
     * published, and muxed into a single file on the fly, without ffmpeg.
     *
     * The stream is resumable: once unserialized, it continues from the live edge.
     *
     * @implements IteratorAggregate<int, string>
     */
    final class YouTubeLiveStream implements ReadableStream, ResumableStream, IteratorAggregate
    {
        use ReadableStreamIteratorAggregate;
        use ForbidCloning;

        private const ID_EBML = 0x1A45DFA3;
        private const ID_EBML_VERSION = 0x4286;
        private const ID_EBML_READ_VERSION = 0x42F7;
        private const ID_EBML_MAX_ID_LENGTH = 0x42F2;
        private const ID_EBML_MAX_SIZE_LENGTH = 0x42F3;
        private const ID_DOC_TYPE = 0x4282;
        private const ID_DOC_TYPE_VERSION = 0x4287;
        private const ID_DOC_TYPE_READ_VERSION = 0x4285;
        private const ID_SEGMENT = 0x18538067;
        private const ID_INFO = 0x1549A966;
        private const ID_TIMESTAMP_SCALE = 0x2AD7B1;
        private const ID_MUXING_APP = 0x4D80;
        private const ID_WRITING_APP = 0x5741;
        private const ID_TITLE = 0x7BA9;
        private const ID_TRACKS = 0x1654AE6B;
        private const ID_TRACK_ENTRY = 0xAE;
        private const ID_TRACK_NUMBER = 0xD7;
        private const ID_TRACK_UID = 0x73C5;
        private const ID_TRACK_TYPE = 0x83;
        private const ID_CODEC_ID = 0x86;
        private const ID_CODEC_PRIVATE = 0x63A2;
        private const ID_CLUSTER = 0x1F43B675;
        private const ID_TIMESTAMP = 0xE7;
        private const ID_SIMPLE_BLOCK = 0xA3;

        /** Our track numbers and types, and the codecs of the formats. */
        private const TRACKS = [
            'video' => ['number' => 1, 'type' => Matroska::TRACK_TYPE_VIDEO, 'codec' => 'V_MPEG4/ISO/AVC'],
            'audio' => ['number' => 2, 'type' => Matroska::TRACK_TYPE_AUDIO, 'codec' => 'A_AAC'],
        ];
        /** The longest cluster, in milliseconds: a new one is also started at every video keyframe. */
        private const MAX_CLUSTER_LENGTH = 10_000;
        /** How many segments behind the live edge to start from, so that the next ones are ready in time. */
        private const START_BEHIND = 2;
        /** How many times to try fetching a segment (on errors, not while waiting for it to be published). */
        private const ATTEMPTS = 5;
        /** How long to wait for a segment to be published, in seconds, before checking whether the live stream ended. */
        private const PUBLISH_TIMEOUT = 30;
        /** The gap left between the frames before and after a jump to the live edge, in milliseconds. */
        private const JUMP_GAP = 40;

        /**
         * The URLs of the formats (which expire), and the user agent to fetch them with.
         *
         * @var array{video?: string, audio?: string, userAgent: string}|null
         */
        private ?array $urls = null;
        /** The InnerTube client to get the URLs with, see {@see YouTube::getPlayer()}. */
        private int $client = 0;
        /** The next segment to read, or null to start from the live edge. */
        private ?int $sequence = null;
        /**
         * The codec private data of the formats (fixed by the header).
         *
         * @var array{video?: ?string, audio?: ?string}
         */
        private array $privates = [];
        /** What to add to the timestamps of the segments, to continue after the frames read before a jump. */
        private int $offset = 0;
        /** The timestamp of the last frame read, in milliseconds. */
        private int $readUntil = -1;
        /**
         * The frames of the current segment not read yet, in timestamp order.
         *
         * @var list<array{kind: 'video'|'audio', data: string, timestamp: int, keyframe: bool}>
         */
        private array $queue = [];
        private ?Cancellation $cancellation = null;
        private bool $headerRead = false;
        private bool $eof = false;
        private bool $closed = false;
        private DeferredFuture $onClose;

        /**
         * @param ?int $video The itag of the video format, if any.
         * @param ?int $audio The itag of the audio format, if any.
         */
        private function __construct(
            public readonly string $videoId,
            public readonly string $title,
            private readonly ?int $video,
            private readonly ?int $audio,
        ) {
            $this->onClose = new DeferredFuture;
        }

        /**
         * Open a YouTube live stream, from the player response of its video.
         *
         * @param bool $audio     Whether to play its audio too (AAC, which only RTMP livestreams carry).
         * @param int  $maxHeight The highest resolution to play.
         *
         * @throws YouTubeException If it can't be played.
         */
        public static function fromPlayer(string $videoId, array $player, string $userAgent, int $client, bool $audio, int $maxHeight): self
        {
            $video = self::pickFormat($player, 'video', $maxHeight);
            $audioFormat = $audio ? self::pickFormat($player, 'audio', $maxHeight) : null;
            if ($video === null && $audioFormat === null) {
                throw new YouTubeException($audio
                    ? 'This live stream has no H.264 video or AAC audio'
                    : 'This live stream has no H.264 video, and its audio (AAC) can only be played in RTMP livestreams');
            }
            $stream = new self($videoId, $player['videoDetails']['title'] ?? $videoId, $video['itag'] ?? null, $audioFormat['itag'] ?? null);
            $stream->client = $client;
            $stream->urls = ['userAgent' => $userAgent];
            foreach (['video' => $video, 'audio' => $audioFormat] as $kind => $format) {
                if ($format !== null) {
                    $stream->urls[$kind] = $format['url'];
                }
            }
            // Fail right away if it can't be played, and get the codec private data for the header.
            $stream->readSegment();
            return $stream;
        }

        /**
         * The best H.264 video format no higher than the given height, or the best AAC audio format, of a live stream.
         *
         * @param 'video'|'audio' $kind
         *
         * @return ?array{itag: int, url: string}
         */
        private static function pickFormat(array $player, string $kind, int $maxHeight): ?array
        {
            $formats = array_filter(
                $player['streamingData']['adaptiveFormats'] ?? [],
                static fn (array $format): bool => isset($format['url'], $format['itag'], $format['mimeType'])
                    && ($kind === 'video'
                        ? str_starts_with($format['mimeType'], 'video/mp4; codecs="avc1.') && ($format['height'] ?? PHP_INT_MAX) <= $maxHeight
                        : str_starts_with($format['mimeType'], 'audio/mp4; codecs="mp4a.')),
            );
            usort($formats, static fn (array $a, array $b): int => [
                $b['height'] ?? 0,
                $b['fps'] ?? 0,
                // AAC-LC first.
                $b['mimeType'] === 'audio/mp4; codecs="mp4a.40.2"',
                $b['bitrate'] ?? 0,
            ] <=> [
                $a['height'] ?? 0,
                $a['fps'] ?? 0,
                $a['mimeType'] === 'audio/mp4; codecs="mp4a.40.2"',
                $a['bitrate'] ?? 0,
            ]);
            return isset($formats[0]) ? ['itag' => $formats[0]['itag'], 'url' => $formats[0]['url']] : null;
        }

        /**
         * Get new URLs for the formats, from the first client (starting from the current one) that has them.
         *
         * @throws LiveStreamEndedException If the live stream ended.
         */
        private function refreshUrls(): void
        {
            for ($i = 0; $i < YouTube::getClientCount(); $i++) {
                [$player, $userAgent, $client] = YouTube::getPlayer($this->videoId, $this->cancellation, $this->client + $i);
                if (!($player['videoDetails']['isLive'] ?? false)) {
                    throw new LiveStreamEndedException('The live stream ended');
                }
                $urls = ['userAgent' => $userAgent];
                foreach (['video' => $this->video, 'audio' => $this->audio] as $kind => $itag) {
                    if ($itag === null) {
                        continue;
                    }
                    foreach ($player['streamingData']['adaptiveFormats'] ?? [] as $format) {
                        if (($format['itag'] ?? null) === $itag && isset($format['url'])) {
                            $urls[$kind] = $format['url'];
                            continue 2;
                        }
                    }
                    continue 2;
                }
                $this->urls = $urls;
                $this->client = $client;
                return;
            }
            throw new YouTubeException("The formats of the live stream $this->videoId aren't available anymore");
        }

        /**
         * Fetch a segment of a format: null if it isn't published yet.
         *
         * @param 'video'|'audio' $kind
         * @param ?int            $sequence The number of the segment, or null for the latest one.
         *
         * @return array{?string, int} The segment, and the number of the latest one.
         */
        private function fetchSegment(string $kind, ?int $sequence): array
        {
            $errors = [];
            for ($attempt = 1; ; $attempt++) {
                try {
                    if ($this->urls === null) {
                        $this->refreshUrls();
                    }
                    $request = new Request($this->urls[$kind].($sequence === null ? '' : "&sq=$sequence"));
                    $request->setHeader('User-Agent', $this->urls['userAgent']);
                    $request->setTransferTimeout(60);
                    $request->setInactivityTimeout(30);
                    $response = YouTube::getClient()->request($request, $this->cancellation);
                    $body = $response->getBody()->buffer($this->cancellation);
                    $status = $response->getStatus();
                    $head = (int) $response->getHeader('x-head-seqnum');
                    if ($status === 200 && $body !== '') {
                        return [$body, $head];
                    }
                    if (($status === 404 || $status === 204) && $head > 0 && $sequence !== null && $sequence > $head) {
                        // Not published yet.
                        return [null, $head];
                    }
                    if ($status === 404 && $head > 0 && $sequence !== null) {
                        // Too old: jump to the live edge.
                        throw new LiveEdgeException;
                    }
                    if ($status === 403 || $status === 410) {
                        // The URLs expired, or are refused: get new ones.
                        $this->urls = null;
                        YouTube::logRefusal("the $kind of the live stream $this->videoId (itag {$this->{$kind}}, segment ".($sequence ?? 'latest').')', $response, $this->client);
                    }
                    throw new YouTubeException("HTTP status $status, got ".\strlen($body).' bytes', $status);
                } catch (CancelledException|LiveStreamEndedException|LiveEdgeException $e) {
                    throw $e;
                } catch (Throwable $e) {
                    $errors[] = $e->getMessage();
                    if ($attempt >= self::ATTEMPTS) {
                        throw new YouTubeException("Could not fetch the $kind of the live stream $this->videoId: ".implode('; ', array_unique($errors)), 0, $e);
                    }
                    // Back off for longer when refused.
                    delay(\in_array($e->getCode(), [403, 410], true) ? 2 ** $attempt : $attempt, cancellation: $this->cancellation);
                }
            }
        }

        /**
         * Read the next segment of the formats into the queue, waiting for it to be published.
         *
         * @throws LiveStreamEndedException If the live stream ended.
         */
        private function readSegment(): void
        {
            $kinds = array_keys(array_filter(['video' => $this->video, 'audio' => $this->audio], static fn (?int $itag): bool => $itag !== null));
            while (true) {
                $jumped = false;
                if ($this->sequence === null) {
                    [, $head] = $this->fetchSegment($kinds[0], null);
                    $this->sequence = max(0, $head - self::START_BEHIND);
                    $jumped = true;
                }
                try {
                    $byKind = [];
                    foreach ($kinds as $kind) {
                        $waited = 0;
                        $since = microtime(true);
                        while (([$segment, $head] = $this->fetchSegment($kind, $this->sequence))[0] === null) {
                            if ($waited >= self::PUBLISH_TIMEOUT) {
                                // Ended, or stalled: check with new URLs.
                                $this->refreshUrls();
                                $waited = 0;
                            }
                            delay(1, cancellation: $this->cancellation);
                            $waited++;
                        }
                        Logger::log(sprintf("Got the segment %d (head %d) of the %s of the live stream %s: %d bytes in %.2f seconds, %d waiting", $this->sequence, $head, $kind, $this->videoId, \strlen($segment), microtime(true) - $since, $waited), Logger::ULTRA_VERBOSE);
                        $byKind[$kind] = $this->demux($kind, $segment);
                    }
                } catch (LiveEdgeException) {
                    $this->sequence = null;
                    continue;
                }
                $this->sequence++;
                break;
            }
            // Merged by timestamp, keeping the order of each track: video with B-frames is in decoding order, not in
            // timestamp (presentation) order.
            $video = $byKind['video'] ?? [];
            $audio = $byKind['audio'] ?? [];
            $frames = [];
            $v = $a = 0;
            while (isset($video[$v]) || isset($audio[$a])) {
                $frames[] = isset($video[$v]) && (!isset($audio[$a]) || $video[$v]['timestamp'] <= $audio[$a]['timestamp'])
                    ? $video[$v++]
                    : $audio[$a++];
            }
            if ($frames === []) {
                return;
            }
            if ($jumped) {
                // Continue right after the frames read before.
                $this->offset = $this->readUntil < 0 ? -$frames[0]['timestamp'] : $this->readUntil + self::JUMP_GAP - $frames[0]['timestamp'];
            }
            foreach ($frames as $frame) {
                $frame['timestamp'] += $this->offset;
                $this->queue[] = $frame;
            }
        }

        /**
         * The frames of a segment of a format.
         *
         * @param 'video'|'audio' $kind
         *
         * @return list<array{kind: 'video'|'audio', data: string, timestamp: int, keyframe: bool}>
         */
        private function demux(string $kind, string $segment): array
        {
            $codec = self::TRACKS[$kind]['codec'];
            $demuxer = new Mp4(new ReadableBuffer($segment), $this->cancellation);
            $private = null;
            foreach ($demuxer->tracks as $track) {
                if ($track['codec'] === $codec) {
                    $private = $track['private'];
                    break;
                }
            }
            if (!\array_key_exists($kind, $this->privates)) {
                $this->privates[$kind] = $private;
            } elseif ($private !== $this->privates[$kind]) {
                // The header can't change.
                throw new LiveStreamEndedException("The format of the $kind of the live stream changed");
            }
            $frames = [];
            foreach ($demuxer->frames as $frame) {
                if ($frame['codec'] === $codec) {
                    $frames[] = ['kind' => $kind, 'data' => $frame['data'], 'timestamp' => $frame['timestamp'], 'keyframe' => $frame['keyframe']];
                }
            }
            return $frames;
        }

        /**
         * Whether just the audio will be played, without the video.
         */
        public function isAudioOnly(): bool
        {
            return $this->video === null;
        }

        /**
         * Whether just the video will be played, as calls only carry OPUS audio.
         */
        public function isVideoOnly(): bool
        {
            return $this->audio === null;
        }

        #[\Override]
        public function read(?Cancellation $cancellation = null): ?string
        {
            if ($this->closed || $this->eof) {
                return null;
            }
            $this->cancellation = $cancellation;
            try {
                if (!$this->headerRead) {
                    $this->headerRead = true;
                    return $this->header();
                }
                $cluster = $this->readCluster();
            } finally {
                $this->cancellation = null;
            }
            if ($cluster === null) {
                $this->eof = true;
                $this->queue = [];
            }
            return $cluster;
        }

        /**
         * The EBML header, the info and the tracks of the file.
         */
        private function header(): string
        {
            $tracks = '';
            foreach ($this->privates as $kind => $private) {
                $track = self::TRACKS[$kind];
                $tracks .= Ebml::element(
                    self::ID_TRACK_ENTRY,
                    Ebml::uint(self::ID_TRACK_NUMBER, $track['number'])
                    .Ebml::uint(self::ID_TRACK_UID, $track['number'])
                    .Ebml::uint(self::ID_TRACK_TYPE, $track['type'])
                    .Ebml::element(self::ID_CODEC_ID, $track['codec'])
                    .($private === null ? '' : Ebml::element(self::ID_CODEC_PRIVATE, $private)),
                );
            }
            return Ebml::element(
                self::ID_EBML,
                Ebml::uint(self::ID_EBML_VERSION, 1)
                .Ebml::uint(self::ID_EBML_READ_VERSION, 1)
                .Ebml::uint(self::ID_EBML_MAX_ID_LENGTH, 4)
                .Ebml::uint(self::ID_EBML_MAX_SIZE_LENGTH, 8)
                .Ebml::element(self::ID_DOC_TYPE, 'matroska')
                .Ebml::uint(self::ID_DOC_TYPE_VERSION, 4)
                .Ebml::uint(self::ID_DOC_TYPE_READ_VERSION, 2),
            )
            // A segment of unknown size, as the file is streamed.
            .pack('N', self::ID_SEGMENT)."\x01\xFF\xFF\xFF\xFF\xFF\xFF\xFF"
            .Ebml::element(
                self::ID_INFO,
                // Timestamps are in milliseconds.
                Ebml::uint(self::ID_TIMESTAMP_SCALE, 1_000_000)
                .Ebml::element(self::ID_MUXING_APP, 'magnaluna')
                .Ebml::element(self::ID_WRITING_APP, 'magnaluna')
                .Ebml::element(self::ID_TITLE, $this->title),
            )
            .Ebml::element(self::ID_TRACKS, $tracks);
        }

        /**
         * Read the next cluster: the frames up to the next video keyframe, or MAX_CLUSTER_LENGTH; null at the end of
         * the live stream.
         */
        private function readCluster(): ?string
        {
            if ($this->queue === []) {
                try {
                    $this->readSegment();
                } catch (LiveStreamEndedException $e) {
                    Logger::log("Stopped playing the live stream $this->videoId: {$e->getMessage()}", Logger::NOTICE);
                    return null;
                }
            }
            $clusterTimestamp = null;
            $blocks = '';
            while ($this->queue !== []) {
                $frame = $this->queue[0];
                $isVideo = $frame['kind'] === 'video';
                if ($clusterTimestamp === null) {
                    $clusterTimestamp = $frame['timestamp'];
                } elseif (($isVideo && $frame['keyframe']) || $frame['timestamp'] - $clusterTimestamp > self::MAX_CLUSTER_LENGTH) {
                    break;
                }
                array_shift($this->queue);
                $blocks .= Ebml::element(
                    self::ID_SIMPLE_BLOCK,
                    \chr(0x80 | self::TRACKS[$frame['kind']]['number'])
                    .pack('n', ($frame['timestamp'] - $clusterTimestamp) & 0xFFFF)
                    .\chr($frame['keyframe'] || !$isVideo ? 0x80 : 0)
                    .$frame['data'],
                );
                $this->readUntil = max($this->readUntil, $frame['timestamp']);
            }
            if ($clusterTimestamp === null) {
                return null;
            }
            return Ebml::element(self::ID_CLUSTER, Ebml::uint(self::ID_TIMESTAMP, $clusterTimestamp).$blocks);
        }

        /** @psalm-mutation-free */
        #[\Override]
        public function isReadable(): bool
        {
            return !$this->closed && !$this->eof;
        }

        #[\Override]
        public function close(): void
        {
            $this->closed = true;
            $this->queue = [];
            if (!$this->onClose->isComplete()) {
                $this->onClose->complete();
            }
        }

        /** @psalm-mutation-free */
        #[\Override]
        public function isClosed(): bool
        {
            return !$this->isReadable();
        }

        #[\Override]
        public function onClose(Closure $onClose): void
        {
            $this->onClose->getFuture()->finally($onClose);
        }

        public function __serialize(): array
        {
            return [
                'videoId' => $this->videoId,
                'title' => $this->title,
                'video' => $this->video,
                'audio' => $this->audio,
                'client' => $this->client,
                'privates' => $this->privates,
                'readUntil' => $this->readUntil,
                'headerRead' => $this->headerRead,
                'eof' => $this->eof,
            ];
        }

        public function __unserialize(array $data): void
        {
            $this->videoId = $data['videoId'];
            $this->title = $data['title'];
            $this->video = $data['video'];
            $this->audio = $data['audio'];
            $this->client = $data['client'];
            $this->privates = $data['privates'];
            $this->readUntil = $data['readUntil'];
            $this->headerRead = $data['headerRead'];
            $this->eof = $data['eof'];
            $this->onClose = new DeferredFuture;
        }
    }

    /**
     * A live stream ended, or can't be continued.
     */
    final class LiveStreamEndedException extends RuntimeException
    {
    }

    /**
     * A segment of a live stream isn't available anymore: playback continues from the live edge.
     */
    final class LiveEdgeException extends RuntimeException
    {
    }
}

namespace {
    use Amp\ByteStream\ReadableStream;
    use Amp\Dns\DnsRecord;
    use danog\DialogId\DialogId;
    use danog\MadelineProto\Broadcast\Progress;
    use danog\MadelineProto\Broadcast\Status;
    use danog\MadelineProto\CallNotAllowedException;
    use danog\MadelineProto\EventHandler\Attributes\Cron;
    use danog\MadelineProto\EventHandler\Attributes\Handler;
    use danog\MadelineProto\EventHandler\Call;
    use danog\MadelineProto\EventHandler\Calls\GroupCall;
    use danog\MadelineProto\EventHandler\Calls\LiveStory;
    use danog\MadelineProto\EventHandler\Calls\RtmpStream;
    use danog\MadelineProto\EventHandler\Calls\MultiCallMessage;
    use danog\MadelineProto\EventHandler\Calls\PrivateCall;
    use danog\MadelineProto\EventHandler\Filter\Combinator\FiltersOr;
    use danog\MadelineProto\EventHandler\Filter\FilterCommand;
    use danog\MadelineProto\EventHandler\Media;
    use danog\MadelineProto\EventHandler\MediaPlayer;
    use danog\MadelineProto\EventHandler\Message;
    use danog\MadelineProto\EventHandler\Message\GroupMessage;
    use danog\MadelineProto\EventHandler\Message\PrivateMessage;
    use danog\MadelineProto\EventHandler\MultiCall;
    use danog\MadelineProto\EventHandler\SimpleFilter\Ended;
    use danog\MadelineProto\EventHandler\SimpleFilter\FromAdmin;
    use danog\MadelineProto\EventHandler\SimpleFilter\Incoming;
    use danog\MadelineProto\EventHandler\SimpleFilter\Running;
    use danog\MadelineProto\Exception;
    use danog\MadelineProto\LocalFile;
    use danog\MadelineProto\Logger;
    use danog\MadelineProto\MediaDestination;
    use danog\MadelineProto\ParseMode;
    use danog\MadelineProto\RemoteUrl;
    use danog\MadelineProto\RPCError\RateLimitError;
    use danog\MadelineProto\RPCErrorException;
    use danog\MadelineProto\Settings;
    use danog\MadelineProto\SimpleEventHandler;
    use danog\MadelineProto\Tools;
    use danog\MadelineProto\VoIP\CallState;
    use Magnaluna\YouTube;
    use Magnaluna\YouTubeException;
    use Magnaluna\YouTubeLiveStream;
    use Magnaluna\YouTubeMp4Stream;
    use Magnaluna\YouTubePlaylistEntry;
    use Magnaluna\YouTubeStream;

    use function Amp\Dns\resolve as dnsResolve;

    /**
     * A file can only be played in a group call of the other kind: an RTMP livestream (AAC audio), or a video chat
     * (OPUS audio).
     */
    final class WrongModeException extends InvalidArgumentException
    {
        /**
         * @param bool $rtmp Whether the file needs an RTMP livestream, or else a video chat.
         */
        public function __construct(string $message, public readonly bool $rtmp)
        {
            parent::__construct($message);
        }
    }

    final class MyEventHandler extends SimpleEventHandler
    {
        public const ADMINS = [101_374_607]; // @danogentili, creator of MadelineProto
        /** The video played in every call. */
        private const VIDEO = 'oncall.webm';
        /** The video played in RTMP livestreams, which only carry H.264 and AAC. */
        private const VIDEO_RTMP = 'oncall.mp4';
        /** Conference call links: https://t.me/call/<slug>. */
        private const CONFERENCE_LINK = '~(?:https?://)?(?:t|telegram)\.me/call/([\w-]+)~i';
        /** Video chat and livestream links: https://t.me/<username>?videochat (or ?livestream, ?voicechat), with an optional invite hash. */
        private const GROUP_CALL_LINK = '~(?:https?://)?(?:t|telegram)\.me/(\w{4,})\?(?:videochat|livestream|voicechat)(?:=([\w-]+))?~i';
        /** Commands sent in the chat of a video chat or conference call. */
        private const CALL_CHAT_COMMAND = '~^[/!.](play|loop|skip|leave)(?:@\w+)?(?:\s+(\S+))?~';

        private array $programmed_call;
        private array $my_users;
        private string $me;
        private int $myId;
        /** @var array<int, PrivateCall> */
        private array $calls = [];
        /** @var array<int, int> */
        private array $messageIds = [];
        /** @var array<int, string> The text of the status messages of the calls, by user. */
        private array $statusTexts = [];
        /**
         * The groups and channels whose video chats only their admins may control, see adminOnlyCommand().
         *
         * @var array<int, true>
         */
        private array $adminOnly = [];
        /**
         * The conference calls we joined, by ID, with the slugs of their links: MadelineProto doesn't list them, see
         * playingCommand().
         *
         * @var array<int, string>
         */
        private array $conferenceCalls = [];
        /**
         * Who started each RTMP stream (its ID): users may stream to a few at once, see getRtmpStreamFor().
         *
         * @var array<int, int>
         */
        private array $rtmpStarters = [];
        /**
         * The RTMP streams started with a URL other than the one they publish to (pinned to the address it was checked
         * with), by the SHA-256 of the URL sent to us, see getRtmpStreamFor().
         *
         * @var array<string, int>
         */
        private array $rtmpAliases = [];
        public function onStart(): void
        {
            $self = $this->getSelf();
            $this->me = '@' . ($self['username'] ?? 'magnaluna');
            $this->myId = $self['id'];

            $this->programmed_call ??= [];
            foreach ($this->programmed_call as $key => [$user, $time]) {
                $sleepTime = $time <= time() ? 0 : $time - time();
                Tools::callFork(function () use ($sleepTime, $key, $user): void {
                    Tools::sleep($sleepTime);
                    $this->makeCall($user);
                    unset($this->programmed_call[$key]);
                });
            }
        }

        /**
         * Play the video in a call, on loop.
         *
         * In an RTMP livestream, what is played is published to it, which only its admins can do.
         */
        private function broadcast(MediaPlayer $call): void
        {
            try {
                $call->playOnHold(MediaDestination::Camera, self::defaultVideo($call));
            } catch (Throwable $e) {
                if ($call instanceof RtmpStream) {
                    $call->close();
                } elseif ($call instanceof MultiCall && self::isRtmp($call)) {
                    // Don't stay in a livestream we can't publish to, only watching it.
                    $call->leave();
                }
                throw $e;
            }
        }

        /**
         * Whether a call is an RTMP livestream (or we publish to an RTMP server), which only carries H.264 video and
         * AAC audio.
         */
        private static function isRtmp(MediaPlayer $call): bool
        {
            return $call instanceof RtmpStream || ($call instanceof MultiCall && $call->isRtmpMode());
        }

        /**
         * The video looped in a call by default.
         */
        private static function defaultVideo(MediaPlayer $call): LocalFile
        {
            return new LocalFile(self::isRtmp($call) ? self::VIDEO_RTMP : self::VIDEO);
        }

        /** The errors of messages we may not send in a chat: they're not ours to report. */
        private const CANT_WRITE = [
            'CHAT_WRITE_FORBIDDEN', 'CHAT_GUEST_SEND_FORBIDDEN', 'CHAT_SEND_PLAIN_FORBIDDEN', 'CHAT_RESTRICTED',
            'USER_BANNED_IN_CHANNEL', 'CHANNEL_PRIVATE', 'TOPIC_CLOSED',
        ];

        /**
         * Reply to a message, unless we may not write in its chat (a group where we're restricted, a channel's
         * discussion group we didn't join): the command was carried out anyway.
         */
        private function respond(Message $to, mixed ...$args): void
        {
            // Not named $message, which callers pass as a named argument of reply().
            try {
                $to->reply(...$args);
            } catch (RPCErrorException $e) {
                if (!in_array($e->rpc, self::CANT_WRITE, true) && !str_starts_with($e->rpc, 'SLOWMODE_WAIT')) {
                    throw $e;
                }
                $this->logger("Could not reply in {$to->chatId}: {$e->rpc}", Logger::NOTICE);
            }
        }

        /**
         * Why a user may not change what we play in a call, or null if they may.
         *
         * What we play in an RTMP livestream replaces its broadcast, so only the admins of its group or channel
         * (or the owner of its live story) may choose it, as they could themselves. The admins of a group or channel
         * can also keep its video chats to themselves, see adminOnlyCommand().
         */
        private function controlDenial(MediaPlayer $call, int $userId): ?string
        {
            if ($call instanceof RtmpStream) {
                // Whoever has its stream key may publish to it themselves.
                return null;
            }
            $rtmp = self::isRtmp($call);
            $peer = $call->peerId ?? null;
            if (in_array($userId, self::ADMINS, true) || (!$rtmp && !isset($this->adminOnly[$peer ?? 0]))) {
                return null;
            }
            if ($peer === null) {
                $allowed = false;
            } elseif ($peer > 0) {
                $allowed = $peer === $userId;
            } else {
                $role = $this->getRole($peer, $userId);
                // Getting the RTMP key of a livestream takes the right to manage its calls.
                $allowed = $rtmp ? $role === self::ROLE_CALLS : ($role === self::ROLE_ADMIN || $role === self::ROLE_CALLS);
            }
            if ($allowed) {
                return null;
            }
            return $rtmp
                ? "Only the admins of this livestream's group or channel can choose what I play in it."
                : 'Only the admins of this group or channel can choose what I play in its video chat (one of them sent /adminonly on).';
        }

        /**
         * Whether a group or channel is public, i.e. has a username.
         */
        private static function isPublic(array $chat): bool
        {
            if (isset($chat['username'])) {
                return true;
            }
            foreach ($chat['usernames'] ?? [] as $username) {
                if ($username['active'] ?? false) {
                    return true;
                }
            }
            return false;
        }

        /** A member of a group or channel. */
        private const ROLE_MEMBER = 'member';
        /** An admin of a group or channel who may not manage its calls. */
        private const ROLE_ADMIN = 'admin';
        /** A member of a group or channel who may manage its calls: its creator, or an admin with that right. */
        private const ROLE_CALLS = 'calls';

        /**
         * The role of a user in a group or channel (one of the ROLE_* constants), or null if they aren't in it.
         *
         * @return self::ROLE_*|null
         */
        private function getRole(int $peer, int $userId): ?string
        {
            // Anonymous admins send messages as the group.
            if ($userId === $peer) {
                return self::ROLE_ADMIN;
            }
            try {
                if (DialogId::isSupergroupOrChannel($peer)) {
                    $participant = $this->channels->getParticipant(channel: $peer, participant: $userId)['participant'];
                    return match ($participant['_']) {
                        'channelParticipantCreator' => self::ROLE_CALLS,
                        'channelParticipantAdmin' => ($participant['admin_rights']['manage_call'] ?? false) ? self::ROLE_CALLS : self::ROLE_ADMIN,
                        'channelParticipantLeft' => null,
                        'channelParticipantBanned' => ($participant['left'] ?? false) ? null : self::ROLE_MEMBER,
                        default => self::ROLE_MEMBER,
                    };
                }
                // Fetched live: the full info is cached for an hour, and a removed or demoted admin must lose their rights now.
                $this->refreshFullPeerCache($peer);
                foreach ($this->getFullInfo($peer)['full']['participants']['participants'] ?? [] as $participant) {
                    if ($participant['user_id'] === $userId) {
                        // The admins of basic groups have every right.
                        return $participant['_'] === 'chatParticipant' ? self::ROLE_MEMBER : self::ROLE_CALLS;
                    }
                }
            } catch (RPCErrorException) {
            }
            return null;
        }


        /**
         * Get a video chat or conference call we're in, by its ID.
         */
        private function getMultiCall(int $id): ?MultiCall
        {
            $call = $this->getConferenceCall($id) ?? $this->getAllGroupCalls()[$id] ?? null;
            return $call?->isJoined() ? $call : null;
        }

        /** The username, link or ID of a group or channel. */
        private const CHAT_REFERENCE = '~^(?:@\w+|-?\d+|(?:https?://)?(?:t|telegram)\.me/\S+)$~i';
        /** Invite links of groups and channels: https://t.me/+<hash> or https://t.me/joinchat/<hash>. */
        private const INVITE_LINK = '~^(?:https?://)?(?:t|telegram)\.me/(?:\+|joinchat/)([\w-]+)$~i';

        /**
         * What the first argument of a command refers to, if it's a call rather than a file: the link of a video chat
         * or conference call, or the username, link (even an invite link) or ID of a group or channel.
         *
         * @param bool $joinChat Whether to join the group or channel of an invite link, if we're not in it.
         *
         * @return array{peer: ?int, slug: ?string, inviteHash: ?string, link: bool, invited: bool}|null
         *         invited: whether we just joined its group or channel with its invite link.
         *
         * @throws InvalidArgumentException If it's not a group or channel we can see.
         */
        private function parseTarget(string $arg, bool $joinChat = false): ?array
        {
            if (preg_match(self::CONFERENCE_LINK, $arg, $matches)) {
                return ['peer' => null, 'slug' => $matches[1], 'inviteHash' => null, 'link' => true, 'invited' => false];
            }
            $link = preg_match(self::GROUP_CALL_LINK, $arg, $matches) === 1;
            if (!$link && !preg_match(self::CHAT_REFERENCE, $arg)) {
                return null;
            }
            $invite = preg_match(self::INVITE_LINK, $arg, $inviteMatches) === 1;
            $invited = $invite && $joinChat && $this->joinChatByInvite($inviteMatches[1], $arg);
            try {
                $peer = $this->getId($link ? '@'.$matches[1] : (is_numeric($arg) ? (int) $arg : $arg));
            } catch (Throwable) {
                $peer = null;
            }
            if ($peer === null || $peer > 0) {
                throw new InvalidArgumentException($invite
                    ? "I'm not in the group or channel of $arg: send /join $arg first!"
                    : "$arg is not a group or channel I can see.");
            }
            return ['peer' => $peer, 'slug' => null, 'inviteHash' => $link ? ($matches[2] ?? null) : null, 'link' => $link, 'invited' => $invited];
        }

        /**
         * Join a group or channel with an invite link, unless we're already in it.
         *
         * @return bool Whether we joined it now: the link is valid, and lets in without the approval of an admin.
         *
         * @throws InvalidArgumentException If we can't join it.
         */
        private function joinChatByInvite(string $hash, string $link): bool
        {
            try {
                $this->messages->importChatInvite(hash: $hash);
                return true;
            } catch (RPCErrorException $e) {
                return match ($e->rpc) {
                    'USER_ALREADY_PARTICIPANT' => false,
                    'INVITE_REQUEST_SENT' => throw new InvalidArgumentException("I asked to join the group or channel of $link: send /join $link again once its admins accept me!"),
                    'INVITE_HASH_EXPIRED', 'INVITE_HASH_INVALID', 'INVITE_HASH_EMPTY' => throw new InvalidArgumentException("$link is not a valid invite link (any more)!"),
                    'CHANNELS_TOO_MUCH' => throw new InvalidArgumentException("I'm in too many groups and channels to join $link!"),
                    default => throw new InvalidArgumentException("Could not join the group or channel of $link: {$e->rpc}"),
                };
            }
        }

        /**
         * The role of a user in a group or channel whose calls they may control: as a member of it, or anyone if it's
         * public (as they could send the link of its video chat); null if they may not.
         *
         * @return self::ROLE_*|null
         */
        private function getAccess(int $peer, int $userId): ?string
        {
            if (in_array($userId, self::ADMINS, true)) {
                return self::ROLE_CALLS;
            }
            // Only admins can check who is in a channel.
            return $this->getRole($peer, $userId)
                ?? (self::isPublic($this->getInfo($peer)['Chat'] ?? []) ? self::ROLE_MEMBER : null);
        }

        /**
         * The call a command applies to, along with its other arguments: the call of the group, channel or call link
         * passed as its first argument, or else the call of the chat it was sent in (our call with a user, or the
         * video chat of a group).
         *
         * The first argument can also be an RTMP URL followed by its stream key, to publish to that RTMP server (e.g. a
         * user's live story, or YouTube), or @me, for our own live story (only for our admins).
         *
         * @param bool $join Whether to join the call passed to it, if we're not in it (or start the RTMP stream, or
         *                   our live story).
         *
         * @return array{MediaPlayer, list<string>}|string The call and the other arguments, or why there is none.
         */
        private function getCallFor(Message $message, bool $join = false): array|string
        {
            $args = $message->commandArgs ?? [];
            if (isset($args[0]) && preg_match(self::RTMP_URL, $args[0])) {
                return $this->getRtmpStreamFor($message, array_shift($args), $args, $join);
            }
            if (isset($args[0]) && strtolower($args[0]) === '@me') {
                array_shift($args);
                return $this->getOwnLiveStoryFor($message, $args, $join);
            }
            try {
                $target = isset($args[0]) ? $this->parseTarget($args[0], joinChat: $join) : null;
                if ($target === null) {
                    $call = $message->chatId > 0 ? $this->getCallByPeer($message->chatId) : $this->getGroupCall($message->chatId);
                    if (!$call?->isJoined()) {
                        return $this->notInCall($message);
                    }
                } else {
                    $where = array_shift($args);
                    $call = $target['slug'] !== null
                        ? $this->getConferenceCallBySlug($target['slug'])
                        : $this->getGroupCall($target['peer']);
                    if (!$call?->isJoined()) {
                        if (!$join) {
                            return "I'm not in the call of $where: send /join $where first!";
                        }
                        $call = $this->joinCall($message, $target, $where);
                        if (is_string($call)) {
                            return $call;
                        }
                    } elseif (!$target['link'] && $this->getAccess($target['peer'], $message->senderId) === null) {
                        return "Only the members of $where can change what I play there.";
                    }
                }
            } catch (InvalidArgumentException $e) {
                return $e->getMessage();
            } catch (Throwable $e) {
                return "Could not find the call: {$e->getMessage()}";
            }
            $denial = $this->controlDenial($call, $message->senderId);
            if ($denial !== null) {
                return $denial;
            }
            return [$call, array_values($args)];
        }

        /** RTMP(S) URLs. */
        private const RTMP_URL = '~^rtmps?://~i';

        /**
         * What we publish to an RTMP server, the first argument of a command being its URL followed by the stream key:
         * started if needed (and if $start), looping the default video while the playlist is empty.
         *
         * Anyone who has the stream key may choose what we publish, as they could themselves.
         *
         * @param list<string> $args The other arguments.
         *
         * @return array{RtmpStream, list<string>}|string The stream and the other arguments, or why there is none.
         */
        private function getRtmpStreamFor(Message $message, string $url, array $args, bool $start): array|string
        {
            try {
                $streams = $this->getRtmpStreams();
                $this->rtmpStarters = array_intersect_key($this->rtmpStarters, $streams);
                $this->rtmpAliases = array_filter($this->rtmpAliases, static fn (int $id): bool => isset($streams[$id]));
                $stream = $this->getRtmpStream($url) ?? $streams[$this->rtmpAliases[hash('sha256', $url)] ?? 0] ?? null;
                if ($stream === null) {
                    if (!$start) {
                        return "I'm not streaming to this RTMP URL: send /play <RTMP URL> <URL of a file> to start.";
                    }
                    $publishTo = $url;
                    if (!in_array($message->senderId, self::ADMINS, true)) {
                        if (count(array_keys($this->rtmpStarters, $message->senderId, true)) >= self::MAX_RTMP_STREAMS) {
                            return 'I already stream to '.self::MAX_RTMP_STREAMS.' RTMP servers for you: send /leave <RTMP URL> to stop one first.';
                        }
                        $publishTo = self::checkRtmpUrl($url);
                        if ($publishTo === null) {
                            return 'I only stream to RTMP servers on the internet.';
                        }
                    }
                    $stream = $this->startRtmpStream($publishTo);
                    $this->rtmpStarters[$stream->id] = $message->senderId;
                    if ($publishTo !== $url) {
                        $this->rtmpAliases[hash('sha256', $url)] = $stream->id;
                    }
                    $this->broadcast($stream);
                }
            } catch (AssertionError) {
                return self::RTMP_HOWTO;
            } catch (Throwable $e) {
                $this->logger($e);
                // Not why: it would tell what is listening where.
                return 'Could not stream to this RTMP server.';
            }
            return [$stream, array_values($args)];
        }

        /** How many RTMP streams a user (not one of our admins) may start at once. */
        private const MAX_RTMP_STREAMS = 3;

        /**
         * An RTMP URL (followed by its stream key) if its server is on the internet, not in our network: with the
         * address it was checked with, for an rtmp:// URL (so DNS can't point it elsewhere when connecting); null if
         * not, or if it can't be resolved.
         */
        private static function checkRtmpUrl(#[\SensitiveParameter] string $url): ?string
        {
            $host = parse_url($url, PHP_URL_HOST);
            if (!is_string($host) || $host === '') {
                return null;
            }
            $host = trim($host, '[]');
            if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
                $addresses = [$host];
            } else {
                try {
                    $addresses = array_map(static fn (DnsRecord $record): string => $record->getValue(), dnsResolve($host));
                } catch (Throwable) {
                    return null;
                }
            }
            if ($addresses === []) {
                return null;
            }
            foreach ($addresses as $address) {
                if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
                    return null;
                }
            }
            if (!str_starts_with(strtolower($url), 'rtmp://')) {
                // rtmps:// connects to the name, which the TLS certificate must match: no service of our network has one.
                return $url;
            }
            // IPv4 if possible: not every network has IPv6.
            $ipv4 = array_values(array_filter($addresses, static fn (string $address): bool => !str_contains($address, ':')));
            $pinned = $ipv4[0] ?? "[{$addresses[0]}]";
            return preg_replace('~^(rtmp://)(?:\[[^\]]+\]|[^/:]+)~i', '${1}'.$pinned, $url, 1);
        }

        /** How to send the URL of an RTMP server. */
        private const RTMP_HOWTO = 'Send the RTMP URL followed by the stream key, e.g. /play rtmps://dc4-1.rtmp.t.me/s/123456:AbCdEf <URL of a file> (to stream to your profile, start a live story "with another app" in the Telegram app, then copy its server URL and stream key). Send it to me privately: whoever has the stream key can stream there!';

        /**
         * Our own live story, streamed to our profile: started if needed (and if $start), looping the default video while
         * the playlist is empty. Only our admins may choose what we stream there.
         *
         * @param list<string> $args The other arguments.
         *
         * @return array{LiveStory, list<string>}|string The live story and the other arguments, or why there is none.
         */
        private function getOwnLiveStoryFor(Message $message, array $args, bool $start): array|string
        {
            if (!in_array($message->senderId, self::ADMINS, true)) {
                return 'Only my admins can choose what I stream to my profile.';
            }
            $live = $this->getOwnLiveStory();
            if ($live === null) {
                if (!$start) {
                    return "I'm not streaming to my profile: send /play @me <URL of a file> to start.";
                }
                try {
                    $live = $this->startLive('me');
                    $live->join();
                    $this->broadcast($live);
                } catch (Throwable $e) {
                    $this->logger($e);
                    return "Could not start a live story on my profile: {$e->getMessage()}";
                }
            }
            return [$live, array_values($args)];
        }

        /**
         * The live story we stream to our profile, if any.
         */
        private function getOwnLiveStory(): ?LiveStory
        {
            $me = $this->getSelf()['id'] ?? null;
            foreach ($this->getAllGroupCalls() as $call) {
                if ($call instanceof LiveStory && $call->peerId === $me && !$call->discarded && $call->isJoined()) {
                    return $call;
                }
            }
            return null;
        }

        /**
         * Parse the URL of a file to play (or of a YouTube video or playlist), along with a note on how it will be
         * played.
         *
         * @param bool $rtmp Whether it's played in an RTMP livestream, which only carries H.264 video and AAC audio.
         *
         * @return array{RemoteUrl|YouTubeStream|YouTubeMp4Stream|YouTubeLiveStream|non-empty-list<YouTubePlaylistEntry>, string, ?bool} The file (or the videos of a playlist), the note,
         *                                                                                      and whether it would
         *                                                                                      be played better in an
         *                                                                                      RTMP livestream (true)
         *                                                                                      or a video chat (false),
         *                                                                                      if not where requested.
         *
         * @throws WrongModeException If it can only be played in the other kind of group call.
         */
        private static function parseUrl(string $url, bool $rtmp): array
        {
            if ($playlistId = YouTube::parsePlaylistLink($url)) {
                $videoId = YouTube::parseLink($url);
                try {
                    [$title, $videoIds] = YouTube::getPlaylist($playlistId, $videoId);
                } catch (YouTubeException $e) {
                    throw new InvalidArgumentException("I can't play this YouTube playlist: {$e->getMessage()}");
                }
                $count = count($videoIds);
                $what = $count === 1 ? 'the video' : "the $count videos";
                $from = $videoId !== null && $videoIds[0] === $videoId && $count > 1
                    ? ", starting from the one you sent: send https://youtu.be/$videoId to play just that one"
                    : '';
                // Each video is opened when its turn comes: one that can't be played is skipped.
                return [
                    array_map(static fn (string $id): YouTubePlaylistEntry => new YouTubePlaylistEntry($id, $rtmp), $videoIds),
                    " ($what of the playlist $title$from)",
                    null,
                ];
            }
            if ($videoId = YouTube::parseLink($url)) {
                try {
                    $video = $rtmp ? YouTubeMp4Stream::open($videoId) : YouTubeStream::open($videoId);
                } catch (YouTubeException $e) {
                    throw new InvalidArgumentException("I can't play this YouTube video: {$e->getMessage()}");
                }
                if ($video instanceof YouTubeLiveStream && $video->isVideoOnly()) {
                    // Calls can't play the AAC audio of live streams, only RTMP livestreams.
                    return [$video, " ({$video->title}, live, just its video: YouTube live streams only have AAC audio, which only RTMP livestreams can play)", true];
                }
                return [$video, match (true) {
                    $video instanceof YouTubeLiveStream => " ({$video->title}, live)",
                    $video->isAudioOnly() => " ({$video->title}, just its audio)",
                    default => " ({$video->title})",
                }, null];
            }
            if (filter_var($url, FILTER_VALIDATE_URL) === false
                || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            ) {
                throw new InvalidArgumentException("$url is not a valid http(s) URL!");
            }
            $ext = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
            if ($rtmp) {
                if (in_array($ext, ['webm', 'ogg', 'opus'], true)) {
                    throw new WrongModeException("I can't play this file in an RTMP livestream, which only plays H.264 video and AAC audio: a .$ext file can only be played in a video chat.", false);
                }
                return [new RemoteUrl($url), ' (in an RTMP livestream, it must be an MP4 or MKV file with H.264 video and AAC audio)', null];
            }
            // MP4 files have AAC audio, which only RTMP livestreams can play.
            return [new RemoteUrl($url), '', in_array($ext, ['mp4', 'm4a', 'mov'], true) ? true : null];
        }

        /**
         * How a Telegram file will be played: '' if entirely, a note if only partially; and whether it would be played
         * better in an RTMP livestream (true) or a video chat (false), if not where requested.
         *
         * @return array{string, ?bool}
         *
         * @throws WrongModeException If it can only be played in the other kind of group call.
         */
        private static function describeMedia(Media $media, bool $rtmp): array
        {
            $mime = $media->mimeType;
            $ext = strtolower($media->fileExt);
            $opus = in_array($mime, ['video/webm', 'audio/webm', 'audio/ogg', 'audio/opus'], true) || in_array($ext, ['.webm', '.ogg', '.opus'], true);
            $aac = in_array($mime, ['video/mp4', 'audio/mp4', 'video/quicktime', 'audio/x-m4a', 'audio/aac'], true) || in_array($ext, ['.mp4', '.m4a', '.mov', '.aac'], true);
            if ($rtmp) {
                // Only H.264 and AAC can be published, as they are.
                if ($aac || $mime === 'video/x-matroska' || $ext === '.mkv') {
                    return [' (in an RTMP livestream, it must have H.264 video and AAC audio)', null];
                }
                if ($opus) {
                    throw new WrongModeException("I can't play this file in an RTMP livestream, which only plays H.264 video and AAC audio: it can only be played in a video chat.", false);
                }
                throw new InvalidArgumentException("I can't play this file in an RTMP livestream: send me an MP4 or MKV video with H.264 video and AAC audio.");
            }
            if ($opus || $mime === 'video/x-matroska' || $ext === '.mkv') {
                return ['', null];
            }
            $isVideo = str_starts_with($mime, 'video/');
            // Anything else is converted with ffmpeg, which only plays its audio.
            if (($isVideo || str_starts_with($mime, 'audio/')) && Tools::canConvertOgg()) {
                return [$isVideo ? ' (just its audio: send a WebM or MKV file with VP8, VP9, H.264 or H.265 video to play the video, too)' : '', $aac ? true : null];
            }
            if ($aac) {
                throw new WrongModeException("I can't play this file here: calls and video chats only play OPUS audio, and I can't convert its AAC audio without ffmpeg.", true);
            }
            throw new InvalidArgumentException("I can't play this file: send me a WebM or MKV video (VP8, VP9, H.264 or H.265 with OPUS audio), or an audio file.");
        }

        /**
         * The file a playlist command refers to, along with a note on how it will be played: the URL
         * passed to it (if any), the file it was sent with or the file it replies to (streamed, not downloaded);
         * null if none.
         *
         * @return array{RemoteUrl|ReadableStream|non-empty-list<ReadableStream>, string, ?bool}|null The file (or the videos of a playlist), the note, and whether it would be played
         *                                                         better in an RTMP livestream (true) or a video chat
         *                                                         (false), if not where requested.
         *
         * @throws WrongModeException If it can only be played in the other kind of group call.
         */
        private function getFile(Message $message, ?string $url, bool $rtmp): ?array
        {
            if ($url !== null) {
                return self::parseUrl($url, $rtmp);
            }
            $media = $message->media;
            if ($media === null && $message->replyToMsgId !== null) {
                $media = $message->getReply(Message::class)?->media
                    ?? throw new InvalidArgumentException('The message you replied to has no file!');
            }
            if ($media === null) {
                return null;
            }
            [$note, $better] = self::describeMedia($media, $rtmp);
            return [$media->getStream(), $note, $better];
        }

        /**
         * Add a file (or the videos of a YouTube playlist) to the playlist of a call, interrupting the looped file.
         *
         * @param LocalFile|RemoteUrl|ReadableStream|non-empty-list<ReadableStream> $file
         */
        private function enqueue(MediaPlayer $call, LocalFile|RemoteUrl|ReadableStream|array $file, string $note): string
        {
            foreach (is_array($file) ? $file : [$file] as $item) {
                $call->then($item);
            }
            return "Added to the playlist$note! Send /skip to skip the file being played.";
        }

        /**
         * Change (or remove, if null) the file (or the videos of a YouTube playlist) looped in a call while its
         * playlist is empty.
         *
         * @param LocalFile|RemoteUrl|ReadableStream|non-empty-list<ReadableStream>|null $file
         */
        private function setLoop(MediaPlayer $call, LocalFile|RemoteUrl|ReadableStream|array|null $file, string $note): string
        {
            if ($file === null) {
                $call->playOnHold();
                return 'Not looping anything anymore: nothing plays when the playlist is empty. Send /loop default to loop the default video again.';
            }
            $call->playOnHold(MediaDestination::Camera, ...(is_array($file) ? $file : [$file]));
            return (is_array($file) ? 'Now looping this playlist' : 'Now looping this file')."$note when the playlist is empty!";
        }

        #[FilterCommand('play')]
        public function playCommand(Incoming&Message $message): void
        {
            $target = $this->getCallFor($message, join: true);
            if (is_string($target)) {
                $this->respond($message, $target);
                return;
            }
            [$call, $args] = $target;
            $this->respond($message, $this->playFile($message, $call, $args[0] ?? null, loop: false)
                ?? 'Reply to a video or audio file with /play, or send /play <URL>, to add it to the playlist.');
        }

        #[FilterCommand('loop')]
        public function loopCommand(Incoming&Message $message): void
        {
            $target = $this->getCallFor($message, join: true);
            if (is_string($target)) {
                $this->respond($message, $target);
                return;
            }
            [$call, $args] = $target;
            if (($args[0] ?? null) === 'default') {
                $this->respond($message, $this->setLoop($call, self::defaultVideo($call), ''));
                return;
            }
            $this->respond($message, $this->playFile($message, $call, $args[0] ?? null, loop: true)
                ?? $this->setLoop($call, null, ''));
        }

        /**
         * Add the file a command refers to to the playlist of a call, or loop it.
         *
         * A file that can only be played (or is played better) in the other kind of group call, an RTMP livestream
         * (AAC audio) or a video chat (OPUS audio), makes us recreate the call as one, if both the user and we may;
         * the user is asked to, otherwise.
         *
         * @return ?string The reply, null if the command refers to no file.
         */
        private function playFile(Message $message, MediaPlayer $call, ?string $url, bool $loop): ?string
        {
            $rtmp = self::isRtmp($call);
            try {
                try {
                    $file = $this->getFile($message, $url, $rtmp);
                    $better = $file[2] ?? null;
                    $wrong = null;
                } catch (WrongModeException $e) {
                    $file = null;
                    $better = $e->rtmp;
                    $wrong = $e;
                }
                if ($file === null && $wrong === null) {
                    return null;
                }
                $prefix = '';
                if ($better !== null && $better !== $rtmp) {
                    [$switched, $why] = $this->switchMode($call, $better, $message->senderId);
                    if ($why === null) {
                        \assert($switched !== null);
                        $call = $switched;
                        $prefix = $better
                            ? "I restarted the video chat as an RTMP livestream, which can play this file's AAC audio. "
                            : "I restarted the livestream as a video chat, as RTMP livestreams can't play this file. ";
                        // Opened again, for the new kind of call.
                        $file = $this->getFile($message, $url, $better);
                    } elseif ($file === null || $switched === null) {
                        // Can't be played here, or there's no call to play it in anymore.
                        return ($wrong !== null ? "{$wrong->getMessage()}\n\n" : '').$why;
                    } else {
                        // The same call, or one of the same kind (if it couldn't be recreated as the other kind).
                        $call = $switched;
                        $prefix = "$why\n\n";
                    }
                }
                \assert($file !== null);
                return $prefix.($loop ? $this->setLoop($call, $file[0], $file[1]) : $this->enqueue($call, $file[0], $file[1]));
            } catch (InvalidArgumentException $e) {
                return $e->getMessage();
            }
        }

        /**
         * Recreate a group call as an RTMP livestream, or as a video chat, joining it and playing the default video.
         *
         * Ending a call cuts everyone off, so only the admins who may manage its calls can make us do it (and we
         * must be able to, too).
         *
         * @return array{?Call, ?string} The call to play in, and why it's not of the other kind (asking the user to
         *                               recreate it), if not: the same call, a new one of the same kind if it
         *                               couldn't be recreated as the other kind, or none if neither could be created.
         */
        private function switchMode(MediaPlayer $call, bool $rtmp, int $userId): array
        {
            $what = $rtmp
                ? 'an RTMP livestream (started with an external streaming app, then send /join again)'
                : 'a video chat';
            if ($call instanceof RtmpStream) {
                return [$call, $rtmp
                    ? 'Only RTMP streams can play it.'
                    : 'I can only stream H.264 video and AAC audio to an RTMP server (in MP4 or MKV files, or YouTube videos): this file can only be played in a video chat.'];
            }
            $peer = $call instanceof GroupCall ? $call->peerId : null;
            if ($peer === null || $peer > 0) {
                // Not the call of a group or channel, which can't be recreated as the other kind.
                if (!$rtmp) {
                    return [$call, 'Only video chats can play it.'];
                }
                $here = $call instanceof PrivateCall ? 'Calls with users' : 'Conference calls';
                return [$call, "$here only play OPUS audio (other audio is converted with ffmpeg, if installed, without the video): AAC audio and H.264 video are only played as they are in the RTMP livestreams of groups and channels. To play it in one, send the same command with the group or channel right after it, e.g. /play @mygroup <URL> (I must be an admin who can manage its video chats)."];
            }
            $role = in_array($userId, self::ADMINS, true) ? self::ROLE_CALLS : $this->getRole($peer, $userId);
            if ($role !== self::ROLE_CALLS) {
                return [$call, "To play it with its audio, an admin who can manage video chats has to end this call and start $what, or send me the same command to make me do it."];
            }
            $chat = $this->getInfo($peer)['Chat'] ?? [];
            if (!($chat['creator'] ?? false) && !($chat['admin_rights']['manage_call'] ?? false)) {
                return [$call, "To play it with its audio, end this call and start $what, or give me the right to manage video chats and send me the same command to make me do it."];
            }
            try {
                $call->discard();
            } catch (Throwable $e) {
                $this->logger($e);
                return [$call, "I couldn't end the call to restart it as $what: {$e->getMessage()}"];
            }
            try {
                return [$this->startCall($peer, $rtmp), null];
            } catch (Throwable $e) {
                $this->logger($e);
                $why = "I couldn't restart the call as $what: {$e->getMessage()}";
            }
            // Don't leave the group or channel without a call.
            try {
                return [$this->startCall($peer, !$rtmp), "$why. I started a call of the same kind again."];
            } catch (Throwable $e) {
                $this->logger($e);
                return [null, "$why, nor of the same kind again: {$e->getMessage()}"];
            }
        }

        /**
         * Start a call in a group or channel, join it and play the default video.
         *
         * Telegram refuses to create a call (CREATE_CALL_FAILED) for a few seconds after the previous one was ended.
         */
        private function startCall(int $peer, bool $rtmp): GroupCall
        {
            for ($attempt = 1; ; $attempt++) {
                try {
                    $call = $this->createGroupCall($peer, rtmpStream: $rtmp);
                    break;
                } catch (RPCErrorException $e) {
                    if ($e->rpc !== 'CREATE_CALL_FAILED' || $attempt >= 8) {
                        throw $e;
                    }
                    Tools::sleep(3);
                }
            }
            $call->join();
            $this->broadcast($call);
            return $call;
        }

        #[FilterCommand('skip')]
        public function skipCommand(Incoming&Message $message): void
        {
            $target = $this->getCallFor($message);
            $this->respond($message, is_string($target) ? $target : $this->skip($target[0]));
        }

        private function skip(MediaPlayer $call): string
        {
            $call->skip();
            return 'Skipped!';
        }

        private function notInCall(Message $message): string
        {
            return $message->chatId > 0
                ? "We're not in a call: call me or send /call first! To change what I play in a video chat or conference call, add its group, channel or link after the command, e.g. /play @group <URL>."
                : "I'm not in this group's video chat: send /join first!";
        }

        /**
         * Handle the commands sent in the chat of a video chat or conference call (only URLs can be sent there).
         */
        #[Handler]
        public function callChatCommand(MultiCallMessage $message): void
        {
            if ($message->fromId === $this->myId
                || !preg_match(self::CALL_CHAT_COMMAND, $message->message->text, $matches)
                || !$call = $this->getMultiCall($message->callId)
            ) {
                return;
            }
            $denial = $this->controlDenial($call, $message->fromId);
            if ($denial !== null) {
                $call->sendMessage($denial);
                return;
            }
            if ($matches[1] === 'leave') {
                $call->sendMessage('Bye!');
                $call->leave();
                return;
            }
            $arg = $matches[2] ?? null;
            try {
                $reply = match ($matches[1]) {
                    'play' => $arg === null
                        ? 'Send /play <URL> to add a file to the playlist.'
                        : $this->enqueue($call, ...array_slice(self::parseUrl($arg, self::isRtmp($call)), 0, 2)),
                    'loop' => $this->setLoop($call, ...match ($arg) {
                        null => [null, ''],
                        'default' => [self::defaultVideo($call), ''],
                        default => array_slice(self::parseUrl($arg, self::isRtmp($call)), 0, 2),
                    }),
                    'skip' => $this->skip($call),
                };
            } catch (InvalidArgumentException $e) {
                $reply = $e->getMessage();
            }
            $call->sendMessage($reply);
        }

        /**
         * Join a video chat, livestream or conference call, and play the video: the video chat of the group the command
         * is sent in (starting one if none is running and we may), or else the call of the group, channel or call link
         * passed to it, to keep the commands out of their chat.
         */
        #[FiltersOr(new FilterCommand('join'), new FilterCommand('videochat'))]
        public function joinCommand(Incoming&Message $message): void
        {
            $where = $message->commandArgs[0] ?? null;
            try {
                $target = $where === null ? null : $this->parseTarget($where, joinChat: true);
            } catch (InvalidArgumentException $e) {
                $this->respond($message, $e->getMessage());
                return;
            }
            if ($target === null && ($where !== null || !$message instanceof GroupMessage)) {
                $this->respond($message, 'Send /join <username or link of a group or channel, or link of a call> to make me join its video chat, livestream or conference call (or just /join in a group).');
                return;
            }
            $this->respond($message, self::joinedReply($this->joinCall($message, $target, $where), $where));
        }

        /**
         * The reply to a request to join a call.
         *
         * @param MultiCall|string $call The call joined, or why we couldn't join it.
         * @param ?string          $where How the call was referred to, if not as the group the request was sent in.
         */
        private static function joinedReply(MultiCall|string $call, ?string $where): string
        {
            if (is_string($call)) {
                return $call;
            }
            return $where === null
                ? 'Playing in the video chat!'
                : "Joined! Send /play $where, /loop $where, /skip $where or /leave $where (here, or anywhere else) to control what I play there.";
        }

        /**
         * Join the call of a group, channel or call link (see parseTarget()), or else of the group the message was
         * sent in, and play the video.
         *
         * @param ?array{peer: ?int, slug: ?string, inviteHash: ?string, link: bool} $target
         *
         * @return MultiCall|string The call, or why we couldn't join it.
         */
        private function joinCall(Message $message, ?array $target, ?string $where): MultiCall|string
        {
            try {
                if ($target === null) {
                    \assert($message instanceof GroupMessage);
                    $call = $message->requestCall();
                } elseif ($target['slug'] !== null) {
                    $call = $this->getConferenceCallBySlug($target['slug'])
                        ?? throw new InvalidArgumentException('This conference link is not valid any more!');
                } else {
                    $call = $this->getGroupCall($target['peer']);
                    // Anyone may use the link of a video chat, as in the app.
                    if (!$target['link']) {
                        // Who has an invite link that just let us in may join its group or channel too.
                        $role = $this->getAccess($target['peer'], $message->senderId)
                            ?? ($target['invited'] ? self::ROLE_MEMBER : null);
                        if ($role === null) {
                            return "Only the members of $where can make me join its video chat.";
                        }
                        if ($call === null && $role !== self::ROLE_CALLS) {
                            return "There's no video chat in $where: only its admins who can manage video chats can make me start one.";
                        }
                        if ($call === null) {
                            $chat = $this->getInfo($target['peer'])['Chat'] ?? [];
                            if (!($chat['creator'] ?? false) && !($chat['admin_rights']['manage_call'] ?? false)) {
                                return "There's no video chat in $where, and I can't start one: start it yourself (then send /join $where again), or make me an admin who can manage video chats.";
                            }
                            $call = $this->createGroupCall($target['peer']);
                        }
                    }
                    $call ?? throw new InvalidArgumentException("There's no video chat in $where!");
                }
                $joined = $call->isJoined();
                if ($target['inviteHash'] ?? null) {
                    $call = $this->joinGroupCall($target['peer'], inviteHash: $target['inviteHash']);
                } else {
                    $call->join();
                }
                $denial = $this->controlDenial($call, $message->senderId);
                if ($denial !== null) {
                    if (!$joined) {
                        $call->leave();
                    }
                    return $denial;
                }
                $this->broadcast($call);
                if ($target['slug'] ?? null) {
                    $this->conferenceCalls[$call->id] = $target['slug'];
                }
                return $call;
            } catch (CallNotAllowedException $e) {
                return "I can't start a video chat here: {$e->reason->value}";
            } catch (InvalidArgumentException $e) {
                return $e->getMessage();
            } catch (Throwable $e) {
                return "Could not join the call: {$e->getMessage()}";
            }
        }

        /**
         * Let only the admins of a group or channel change what we play in its video chats, or everyone again (livestreams
         * are always only for admins): /adminonly [group or channel] on|off, as one of its admins.
         */
        #[FilterCommand('adminonly')]
        public function adminOnlyCommand(Incoming&Message $message): void
        {
            $args = $message->commandArgs ?? [];
            try {
                $target = isset($args[0]) ? $this->parseTarget($args[0]) : null;
            } catch (InvalidArgumentException $e) {
                $this->respond($message, $e->getMessage());
                return;
            }
            $where = $target === null ? 'this group' : array_shift($args);
            $peer = $target === null ? ($message->chatId < 0 ? $message->chatId : null) : $target['peer'];
            $mode = $args[0] ?? null;
            if ($peer === null || !in_array($mode, [null, 'on', 'off'], true)) {
                $this->respond($message, 'Send /adminonly on (or off) in a group, or /adminonly <username, link or ID of a group or channel> on (or off) anywhere, to let only its admins (or everyone again) change what I play in its video chat.');
                return;
            }
            if ($mode === null) {
                $command = $target === null ? '/adminonly' : "/adminonly $where";
                $this->respond($message, isset($this->adminOnly[$peer])
                    ? "Only the admins of $where can change what I play in its video chat: an admin can send $command off to let everyone do it."
                    : "Everyone can change what I play in the video chat of $where: an admin can send $command on to keep it to the admins.");
                return;
            }
            $role = in_array($message->senderId, self::ADMINS, true) ? self::ROLE_CALLS : $this->getRole($peer, $message->senderId);
            if ($role !== self::ROLE_ADMIN && $role !== self::ROLE_CALLS) {
                $this->respond($message, "Only the admins of $where can change who controls what I play there.");
                return;
            }
            if ($mode === 'on') {
                $this->adminOnly[$peer] = true;
                $this->respond($message, "Only the admins of $where can change what I play in its video chat now.");
            } else {
                unset($this->adminOnly[$peer]);
                $this->respond($message, "Everyone can change what I play in the video chat of $where now.");
            }
        }

        /**
         * List the calls in which we play something, only for the admins of the bot: the video chats and livestreams
         * of groups and channels, the calls with users and the conference calls.
         */
        #[FilterCommand('playing')]
        public function playingCommand(Incoming&Message&FromAdmin $message): void
        {
            $lines = [];
            foreach ($this->getAllGroupCalls() as $call) {
                if (!$call->isJoined() || $call->getCurrent() === null || $call->peerId === null) {
                    continue;
                }
                try {
                    $info = $this->getInfo($call->peerId);
                    $where = self::chatName($info['Chat'] ?? $info['User'] ?? [], $call->peerId);
                } catch (Throwable) {
                    $where = (string) $call->peerId;
                }
                $kind = !$call instanceof GroupCall ? 'live story of' : ($call->rtmpStream ? 'livestream of' : 'video chat of');
                $lines[] = "$kind $where ({$call->participantsCount} participants): ".self::describeCurrent($call);
            }
            foreach ($this->getRtmpStreams() as $stream) {
                $lines[] = "RTMP stream to {$stream->url}".($stream->isConnected() ? '' : ' (not connected)').': '.self::describeCurrent($stream);
            }
            foreach ($this->getAllCalls() as $userId => $call) {
                if ($call->getCallState() !== CallState::RUNNING || $call->getCurrent() === null) {
                    continue;
                }
                try {
                    $user = $this->getInfo($userId)['User'] ?? [];
                    $who = isset($user['username']) ? '@'.$user['username'] : trim(($user['first_name'] ?? '').' '.($user['last_name'] ?? '')." ($userId)");
                } catch (Throwable) {
                    $who = (string) $userId;
                }
                $lines[] = "call with $who: ".self::describeCurrent($call);
            }
            foreach ($this->conferenceCalls as $id => $slug) {
                $call = $this->getConferenceCall($id);
                if (!$call?->isJoined()) {
                    unset($this->conferenceCalls[$id]);
                    continue;
                }
                if ($call->getCurrent() !== null) {
                    $lines[] = "conference call https://t.me/call/$slug ({$call->participantsCount} participants): ".self::describeCurrent($call);
                }
            }
            $this->respond(
                $message,
                $lines === []
                    ? "I'm not playing anything anywhere."
                    : 'Playing in '.count($lines)." calls:\n\n".implode("\n", $lines),
                noWebpage: true,
            );
        }

        /**
         * What a call plays: a file, a URL, or a stream (a Telegram file or a YouTube video).
         */
        private static function describeCurrent(MediaPlayer $call): string
        {
            $current = $call->getCurrent();
            return match (true) {
                $current instanceof LocalFile => in_array($current->file, [self::VIDEO, self::VIDEO_RTMP], true) ? 'my video' : basename($current->file),
                $current instanceof RemoteUrl => $current->url,
                default => 'a Telegram file or YouTube video',
            };
        }

        /** How many groups and channels the reply of /joinall names, per outcome. */
        private const JOIN_ALL_NAMES = 30;

        /**
         * Join the video chats and livestreams of all our groups and channels in which we play nothing, and loop the
         * default video there, playing a file first if one is passed (its URL, or the file the command is sent with
         * or replies to): /joinall [URL], only for the admins of the bot.
         *
         * Calls we're in that play something (even a file queued by a member) are left alone. Only the chats whose
         * call Telegram flags as active are checked, after refreshing the dialogs, so as not to fetch the full info
         * of every chat.
         */
        #[FilterCommand('joinall')]
        public function joinAllCommand(Incoming&Message&FromAdmin $message): void
        {
            $url = $message->commandArgs[0] ?? null;
            $withFile = $url !== null || $message->media !== null || $message->replyToMsgId !== null;
            $this->respond($message, 'Joining the video chats of my groups and channels in which I play nothing: it may take a few minutes, as Telegram makes me wait after every few calls I join...');
            $joined = $failed = $fileErrors = [];
            $busy = 0;
            foreach (array_keys($this->getFullDialogs()) as $peer) {
                if ($peer > 0) {
                    continue;
                }
                $chat = $this->getInfo($peer)['Chat'] ?? [];
                if (!($chat['call_active'] ?? false) || ($chat['left'] ?? false)) {
                    continue;
                }
                $name = self::chatName($chat, $peer);
                try {
                    $call = self::waitingOutFloods(fn () => $this->getGroupCall($peer));
                    if ($call === null) {
                        // The cached full info of the chat may predate its call.
                        $call = self::waitingOutFloods(function () use ($peer) {
                            $this->refreshFullPeerCache($peer);
                            return $this->getGroupCall($peer);
                        });
                    }
                    if ($call === null) {
                        continue;
                    }
                    if ($call->isJoined() && $call->getCurrent() !== null) {
                        $busy++;
                        continue;
                    }
                    if ($call->rtmpStream && !($chat['creator'] ?? false) && !($chat['admin_rights']['manage_call'] ?? false)) {
                        $failed[] = "$name: a livestream, and I'm not an admin who can manage its video chats";
                        continue;
                    }
                    self::waitingOutFloods($call->join(...));
                    $this->broadcast($call);
                } catch (Throwable $e) {
                    $this->logger($e);
                    $failed[] = "$name: ".mb_substr($e->getMessage(), 0, 200);
                    continue;
                }
                $joined[] = $name;
                if (!$withFile) {
                    continue;
                }
                // Opened for each call: a stream can only be played once.
                try {
                    $file = $this->getFile($message, $url, self::isRtmp($call));
                } catch (Throwable $e) {
                    $fileErrors[$e->getMessage()][] = $name;
                    continue;
                }
                \assert($file !== null);
                $this->enqueue($call, $file[0], '');
            }

            $list = static fn (array $names): string => implode(', ', array_slice($names, 0, self::JOIN_ALL_NAMES))
                .(count($names) > self::JOIN_ALL_NAMES ? ' and '.(count($names) - self::JOIN_ALL_NAMES).' more' : '');
            $reply = $joined === []
                ? 'There was no video chat to join.'
                : 'Joined '.count($joined).' video chats'.($withFile ? ', playing the file before looping my video' : ', looping my video').': '.$list($joined).'.';
            if ($busy) {
                $reply .= "\n\nAlready playing something in $busy video chats.";
            }
            foreach ($fileErrors as $error => $names) {
                $reply .= "\n\nCouldn't play the file in ".$list($names).", only looping my video there: $error";
            }
            if ($failed !== []) {
                $reply .= "\n\nCouldn't join ".count($failed).' video chats:'."\n".implode("\n", array_slice($failed, 0, self::JOIN_ALL_NAMES));
            }
            $this->respond($message, $reply);
        }

        /**
         * Run a request, waiting out the flood waits of Telegram instead of failing: joining many calls in a row
         * gets one after every few calls (FLOOD_WAIT_30 or so on phone.joinGroupCall), longer than MadelineProto
         * waits by itself.
         *
         * @template T
         *
         * @param Closure(): T $request
         *
         * @return T
         */
        private static function waitingOutFloods(Closure $request): mixed
        {
            while (true) {
                try {
                    return $request();
                } catch (RateLimitError $e) {
                    Logger::log("Waiting {$e->getWaitTimeLeft()} seconds for a flood wait: {$e->rpc}", Logger::NOTICE);
                    Tools::sleep($e->getWaitTimeLeft() + 1);
                }
            }
        }

        /**
         * The name of a group or channel for replies: its username, or else its title.
         */
        private static function chatName(array $chat, int $peer): string
        {
            $username = $chat['username'] ?? null;
            foreach ($chat['usernames'] ?? [] as $entry) {
                if ($entry['active'] ?? false) {
                    $username ??= $entry['username'];
                }
            }
            return $username !== null ? "@$username" : ($chat['title'] ?? (string) $peer);
        }

        /**
         * Leave a video chat or conference call: the one of the group, channel or call link passed to the command,
         * or else the video chat of the group it's sent in.
         */
        #[FilterCommand('leave')]
        public function leaveCommand(Incoming&Message $message): void
        {
            if ($message->chatId > 0 && !isset($message->commandArgs[0])) {
                $this->respond($message, 'Send /leave <username or link of a group or channel, or link of a call> to make me leave its call.');
                return;
            }
            $target = $this->getCallFor($message);
            if (is_string($target)) {
                $this->respond($message, $target);
                return;
            }
            $call = $target[0];
            try {
                if ($call instanceof RtmpStream) {
                    $call->close();
                    $this->respond($message, "Stopped streaming to {$call->url}!");
                    return;
                }
                if ($call instanceof LiveStory && $call->peerId === ($this->getSelf()['id'] ?? null)) {
                    $call->discard();
                    $this->respond($message, 'Ended my live story!');
                    return;
                }
                \assert($call instanceof MultiCall);
                $call->leave();
                $this->respond($message, 'Left the call!');
            } catch (Throwable $e) {
                $this->respond($message, "Could not leave the call: {$e->getMessage()}");
            }
        }

        /**
         * Join the conference calls and video chats whose links we receive.
         */
        #[Handler]
        public function callLink(Incoming&PrivateMessage $message): void
        {
            // Commands refer to calls with links too.
            if ($message->command !== null
                || !(preg_match(self::CONFERENCE_LINK, $message->message, $matches) || preg_match(self::GROUP_CALL_LINK, $message->message, $matches))
            ) {
                return;
            }
            $this->respond($message, 'Joining the call...');
            try {
                $target = $this->parseTarget($matches[0]);
            } catch (InvalidArgumentException $e) {
                $this->respond($message, $e->getMessage());
                return;
            }
            $this->respond($message, self::joinedReply($this->joinCall($message, $target, $matches[0]), $matches[0]));
        }

        private int $lastLog = 0;
        /**
         * Handles updates to an in-progress broadcast.
         */
        #[Handler]
        public function broadcastProgress(Progress $progress): void
        {
            if (time() - $this->lastLog > 5 || $progress->status === Status::FINISHED) {
                $this->lastLog = time();
                $this->sendMessageToAdmins((string) $progress);
            }
        }

        #[FilterCommand('restart')]
        public function restartCommand(Message & FromAdmin $message): void
        {
            $this->restart();
        }

        #[FilterCommand('broadcast')]
        public function broadcastCommand(Message & FromAdmin $message): void
        {
            // We can broadcast messages to all users with /broadcast
            if (!$message->replyToMsgId) {
                $this->respond($message, "You should reply to the message you want to broadcast.");
                return;
            }
            $this->broadcastForwardMessages(
                from_peer: $message->senderId,
                message_ids: [$message->replyToMsgId],
                drop_author: true,
                pin: true,
            );
        }

        #[FilterCommand('bforward')]
        public function broadcastForwardCommand(Message & FromAdmin $message): void
        {
            // We can broadcast messages to all users with /broadcast
            if (!$message->replyToMsgId) {
                $this->respond($message, "You should reply to the message you want to broadcast.");
                return;
            }
            $this->broadcastForwardMessages(
                from_peer: $message->senderId,
                message_ids: [$message->replyToMsgId],
                drop_author: false,
                pin: true,
            );
        }

        public function getMe(): string
        {
            return $this->me;
        }
        public function getReportPeers(): array
        {
            return self::ADMINS;
        }
        #[Cron(period: 10)]
        public function statusLoop(): void
        {
            foreach ($this->calls as $user => $call) {
                if ($call->getCallState() === CallState::ENDED) {
                    unset($this->calls[$call->otherID], $this->messageIds[$call->otherID], $this->statusTexts[$call->otherID]);

                    continue;
                }
                $message = 'Total running calls: '.count($this->calls).PHP_EOL.PHP_EOL;
                $message .= "Emojis: ".implode('', $call->getVisualization() ?? []);
                // Telegram refuses edits that don't change the message.
                if (($this->statusTexts[$call->otherID] ?? null) === $message) {
                    continue;
                }
                try {
                    $this->messages->editMessage(['id' => $this->messageIds[$call->otherID], 'peer' => $user, 'message' => $message]);
                    $this->statusTexts[$call->otherID] = $message;
                } catch (RPCErrorException $e) {
                    if ($e->rpc === 'MESSAGE_NOT_MODIFIED') {
                        // After a restart, the text sent before it isn't known.
                        $this->statusTexts[$call->otherID] = $message;
                    } else {
                        $this->logger($e);
                    }
                }
            }
        }

        private function makeCall(int $user): void
        {
            try {
                if ($this->getCallByPeer($user)) {
                    $this->messages->sendMessage(['peer' => $user, 'message' => "I'm already in a call with you!"]);
                    return;
                }
                $this->broadcast($this->requestCall($user, video: true));
            } catch (RPCErrorException $e) {
                try {
                    if ($e->rpc === 'USER_PRIVACY_RESTRICTED') {
                        $e = 'Please disable call privacy settings to make me call you (or call me yourself!)';
                    }
                    $this->messages->sendMessage(['peer' => $user, 'message' => (string) $e]);
                } catch (RPCErrorException) {
                }
            } catch (Throwable $e) {
                $this->messages->sendMessage(['peer' => $user, 'message' => (string) $e]);
            }
        }
        #[Handler]
        public function handleMessage(Incoming&Message $message): void
        {
            try {
                $runCall = $message->message === '/call';
                // Call links are joined by callLink(), instead.
                // So are the commands for other calls.
                $isCallLink = preg_match(self::CONFERENCE_LINK, $message->message) || preg_match(self::GROUP_CALL_LINK, $message->message)
                    || in_array($message->command, ['join', 'videochat', 'leave', 'play', 'loop', 'skip', 'adminonly', 'joinall', 'playing'], true);
                if (!isset($this->my_users[$message->chatId]) || in_array($message->message, ['/start', '/help'], true)) {
                    $runCall = true;
                    $this->my_users[$message->chatId] = true;
                    $this->respond($message, 
                        message: "Hi, I'm {$this->me} the video webradio.

Call _me_ to watch an **awesome** video, or send /call to make _me_ call _you_ (don't forget to disable call privacy settings!).

Send me the link of a video chat, livestream or conference call, and I'll join it too!
Add me to your group and send /join to make me join its video chat.

Commands:
/start (or /help) - show this message
/call - make me call you
/program <time> - program a call, for example:
  /program 29 August 2018 - call me the 29th of august 2018
  /program +1 hour 30 minutes - call me in one hour and thirty minutes
  /program next Thursday - call me next Thursday at midnight
/join (or /videochat) - join the video chat of the group (or start one, if I'm allowed to)
/leave - leave the video chat of the group
/play - reply to a video or audio file (or send /play <URL>, even of a YouTube video, playlist or live stream) to add it to the playlist
/skip - skip the file being played
/loop - reply to a file (or send /loop <URL>, even of a YouTube video, playlist or live stream) to loop it instead of my video when the playlist is empty, send just /loop to loop nothing, /loop default to loop my video again
/adminonly on - as an admin, let only the admins of the group change what I play in its video chat (/adminonly off to let everyone do it again)

The commands apply to our call, or to the video chat of the group they're sent in.
To use them for another video chat, livestream or conference call without sending them in its chat (even from here), put its group or channel (username, link, even an invite link, or ID) or its link right after the command, for example:
  /join @mygroup
  /join https://t.me/+AbCdEf (I join the group first)
  /play @mygroup https://youtu.be/dQw4w9WgXcQ (/play and /loop join the call first, if needed)
  /loop https://t.me/call/... default
  /leave @mygroup
  /adminonly @mygroup on
In the chat of a call, the commands apply to that call (/play and /loop only take URLs there).
In livestreams, only the admins of the group or channel can choose what I play, and I can play only if I'm an admin too.

I can also stream to any RTMP server: your profile (a live story), a livestream, YouTube, Twitch... Put its URL followed by the stream key right after the command, for example:
  /play rtmps://dc4-1.rtmp.t.me/s/123456:AbCdEf https://youtu.be/dQw4w9WgXcQ
To stream to your profile, start a live story 'with another app' in the Telegram app, then copy its server URL and stream key. Send them to me privately: whoever has the stream key can stream there!
/loop, /skip and /leave work the same way (/leave stops streaming). An RTMP stream only plays H.264 video and AAC audio (MP4 or MKV files, YouTube videos).

Admin only:
/restart - restart me
/broadcast - reply to a message to send a copy of it to all my users, and pin it
/bforward - reply to a message to forward it to all my users, and pin it
/playing - list the calls in which I play something
/joinall - join the video chats of all my groups and channels in which I play nothing, and loop my video there (send /joinall <URL>, or reply to a file, to play it first)
/play @me <URL> - stream to my own profile, as a live story (/loop, /skip too; /leave @me ends it)

I also provide advanced stats during calls!

I'm a userbot powered by @MadelineProto, created by @danogentili.

Source code: https://github.com/danog/MadelineProto
",
                        parseMode: ParseMode::MARKDOWN,
                        noWebpage: true
                    );
                }
                if (!$this->getCallByPeer($message->chatId) && $runCall && !$isCallLink && $message->chatId > 0) {
                    $this->makeCall($message->chatId);
                }
                if (str_starts_with($message->message, '/program') && $message->chatId > 0) {
                    $time = strtotime(str_replace('/program ', '', $message->message));
                    if ($time === false) {
                        $this->respond($message, 'Invalid time provided');
                    } elseif ($time - time() <= 0) {
                        $this->respond($message, 'Invalid time provided');
                    } else {
                        $this->respond($message, 'OK');
                        $this->programmed_call[] = [$message->chatId, $time];
                        $key = count($this->programmed_call) - 1;
                        Tools::sleep($time - time());
                        $this->makeCall($message->chatId);
                        unset($this->programmed_call[$key]);
                    }
                }
            } catch (RPCErrorException $e) {
                try {
                    if ($e->rpc === 'USER_PRIVACY_RESTRICTED') {
                        $e = 'Please disable call privacy settings to make me call you';
                    } elseif (str_starts_with($e->rpc, 'FLOOD_WAIT_')) {
                        $t = str_replace('FLOOD_WAIT_', '', $e->rpc);
                        $e = "Too many people used the /call function. I'll be able to call you in $t seconds.\nYou can also call me right now";
                    }
                    $this->respond($message, (string) $e);
                } catch (RPCErrorException $e) {
                }
                $this->logger($e);
            } catch (Exception $e) {
                $this->logger($e);
            }
        }

        #[Handler]
        public function incomingCall(PrivateCall&Incoming $call): void
        {
            $this->broadcast($call->join());
        }

        #[Handler]
        public function endedCall(PrivateCall&Ended $call): void
        {
            unset($this->calls[$call->otherID], $this->messageIds[$call->otherID], $this->statusTexts[$call->otherID]);
        }

        #[Handler]
        public function callRunning(PrivateCall&Running $call): void
        {
            if (isset($this->calls[$call->otherID])) {
                return;
            }
            try {
                $message = 'Total running calls: '.(count($this->calls)+1).PHP_EOL.PHP_EOL;
                $message .= "Emojis: ".implode('', $call->getVisualization() ?? []);

                $this->messageIds[$call->otherID] = $this->sendMessage(peer: $call->otherID, message: $message)->id;
                $this->calls[$call->otherID] = $call;
            } catch (Throwable $e) {
                $this->logger($e);
            }
        }

        public function __sleep(): array
        {
            return ['programmed_call', 'my_users', 'messageIds', 'calls', 'adminOnly', 'conferenceCalls', 'rtmpStarters', 'rtmpAliases'];
        }
    }

    $settings = new Settings;
    $settings->getSecretChats()->setAccept(false);

    MyEventHandler::startAndLoop('magna.madeline', $settings);
}
