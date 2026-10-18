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
    use Closure;
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
         * The ID of the video a YouTube link points to, or null if it isn't one.
         */
        public static function parseLink(string $link): ?string
        {
            return preg_match(self::LINK, $link, $matches) ? $matches[1] : null;
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
                        // The URLs expired, were requested from another IP address (they're bound to
                        // it), or are refused (sometimes for no reason): get new ones, from the next
                        // client if they're refused again.
                        $this->urls = null;
                        if ($attempt % 2 === 0) {
                            $this->client++;
                        }
                    }
                    throw new YouTubeException("HTTP status $status, got ".\strlen($body).' bytes');
                } catch (CancelledException $e) {
                    throw $e;
                } catch (Throwable $e) {
                    $errors[] = $e->getMessage();
                    if ($attempt >= self::ATTEMPTS) {
                        throw new YouTubeException("Could not fetch the $kind of $this->videoId: ".implode('; ', array_unique($errors)), 0, $e);
                    }
                    delay($attempt, cancellation: $this->cancellation);
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
         * @throws YouTubeException If it can't be played.
         */
        public static function open(string $videoId, int $maxHeight = 1080, ?Cancellation $cancellation = null): self
        {
            [$player, $userAgent, $client] = YouTube::getPlayer($videoId, $cancellation);
            if ($player['videoDetails']['isLive'] ?? false) {
                throw new YouTubeException("Live streams can't be played");
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
         * @throws YouTubeException If it can't be played.
         */
        public static function open(string $videoId, int $maxHeight = 720, ?Cancellation $cancellation = null): self
        {
            [$player, $userAgent, $client] = YouTube::getPlayer($videoId, $cancellation);
            if ($player['videoDetails']['isLive'] ?? false) {
                throw new YouTubeException("Live streams can't be played");
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
}

namespace {
    use Amp\ByteStream\ReadableStream;
    use danog\DialogId\DialogId;
    use danog\MadelineProto\Broadcast\Progress;
    use danog\MadelineProto\Broadcast\Status;
    use danog\MadelineProto\CallNotAllowedException;
    use danog\MadelineProto\EventHandler\Attributes\Cron;
    use danog\MadelineProto\EventHandler\Attributes\Handler;
    use danog\MadelineProto\EventHandler\Call;
    use danog\MadelineProto\EventHandler\Calls\MultiCallMessage;
    use danog\MadelineProto\EventHandler\Calls\PrivateCall;
    use danog\MadelineProto\EventHandler\Filter\Combinator\FiltersOr;
    use danog\MadelineProto\EventHandler\Filter\FilterCommand;
    use danog\MadelineProto\EventHandler\Media;
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
    use danog\MadelineProto\MediaDestination;
    use danog\MadelineProto\ParseMode;
    use danog\MadelineProto\RemoteUrl;
    use danog\MadelineProto\RPCErrorException;
    use danog\MadelineProto\Settings;
    use danog\MadelineProto\SimpleEventHandler;
    use danog\MadelineProto\Tools;
    use danog\MadelineProto\VoIP\CallState;
    use Magnaluna\YouTube;
    use Magnaluna\YouTubeException;
    use Magnaluna\YouTubeStream;

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
        /**
         * The video chat or conference call each user last made us join with a link, to control it from their private chat.
         *
         * @var array<int, int>
         */
        private array $linkedCalls = [];
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
        private function broadcast(Call $call): void
        {
            try {
                $call->playOnHold(MediaDestination::Camera, self::defaultVideo($call));
            } catch (Throwable $e) {
                if (self::isRtmp($call)) {
                    // Don't stay in a livestream we can't publish to, only watching it.
                    $call->leave();
                }
                throw $e;
            }
        }

        /**
         * Whether a call is an RTMP livestream, which only carries H.264 video and AAC audio.
         */
        private static function isRtmp(Call $call): bool
        {
            return $call instanceof MultiCall && $call->isRtmpMode();
        }

        /**
         * The video looped in a call by default.
         */
        private static function defaultVideo(Call $call): LocalFile
        {
            return new LocalFile(self::isRtmp($call) ? self::VIDEO_RTMP : self::VIDEO);
        }

        /**
         * Whether a user may change what we play in a call.
         *
         * What we play in an RTMP livestream replaces its broadcast, so only the admins of its group or channel
         * (or the owner of its live story) may choose it, as they could themselves.
         */
        private function mayControl(Call $call, int $userId): bool
        {
            if (!self::isRtmp($call) || in_array($userId, self::ADMINS, true)) {
                return true;
            }
            $peer = $call->peerId ?? null;
            if ($peer === null) {
                return false;
            }
            if ($peer > 0) {
                return $peer === $userId;
            }
            // Getting the RTMP key of a livestream takes the right to manage its calls.
            return $this->getRole($peer, $userId) === self::ROLE_CALLS;
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
        /** A member of a group or channel who may manage its calls: its creator, or an admin with that right. */
        private const ROLE_CALLS = 'calls';

        /**
         * The role of a user in a group or channel (one of the ROLE_* constants), or null if they aren't in it.
         *
         * @return self::ROLE_*|null
         */
        private function getRole(int $peer, int $userId): ?string
        {
            try {
                if (DialogId::isSupergroupOrChannel($peer)) {
                    $participant = $this->channels->getParticipant(channel: $peer, participant: $userId)['participant'];
                    return match ($participant['_']) {
                        'channelParticipantCreator' => self::ROLE_CALLS,
                        'channelParticipantAdmin' => ($participant['admin_rights']['manage_call'] ?? false) ? self::ROLE_CALLS : self::ROLE_MEMBER,
                        'channelParticipantLeft' => null,
                        'channelParticipantBanned' => ($participant['left'] ?? false) ? null : self::ROLE_MEMBER,
                        default => self::ROLE_MEMBER,
                    };
                }
                foreach ($this->messages->getFullChat(chat_id: $peer)['full_chat']['participants']['participants'] ?? [] as $participant) {
                    if ($participant['user_id'] === $userId) {
                        // The admins of basic groups have every right.
                        return $participant['_'] === 'chatParticipant' ? self::ROLE_MEMBER : self::ROLE_CALLS;
                    }
                }
            } catch (RPCErrorException) {
            }
            return null;
        }

        private const NOT_LIVESTREAM_ADMIN = "Only the admins of this livestream's group or channel can choose what I play in it.";

        /**
         * Get a video chat or conference call we're in, by its ID.
         */
        private function getMultiCall(int $id): ?MultiCall
        {
            $call = $this->getConferenceCall($id) ?? $this->getAllGroupCalls()[$id] ?? null;
            return $call?->isJoined() ? $call : null;
        }

        /**
         * The call controlled by the commands sent in a chat: our call with a user (or else the call they
         * last made us join with a link), or the video chat of a group.
         */
        private function getCallFor(Message $message): ?Call
        {
            if ($message->chatId > 0) {
                if ($call = $this->getCallByPeer($message->chatId)) {
                    return $call;
                }
                $id = $this->linkedCalls[$message->chatId] ?? null;
                return $id === null ? null : $this->getMultiCall($id);
            }
            $call = $this->getGroupCall($message->chatId);
            return $call?->isJoined() ? $call : null;
        }

        /**
         * Parse the URL of a file to play (or of a YouTube video), along with a note on how it will be played.
         *
         * @param bool $rtmp Whether it's played in an RTMP livestream, which only carries H.264 video and AAC audio.
         *
         * @return array{RemoteUrl|YouTubeStream|YouTubeMp4Stream, string}
         */
        private static function parseUrl(string $url, bool $rtmp): array
        {
            if ($videoId = YouTube::parseLink($url)) {
                try {
                    $video = $rtmp ? YouTubeMp4Stream::open($videoId) : YouTubeStream::open($videoId);
                } catch (YouTubeException $e) {
                    throw new InvalidArgumentException("I can't play this YouTube video: {$e->getMessage()}");
                }
                return [$video, $video->isAudioOnly() ? " ({$video->title}, just its audio)" : " ({$video->title})"];
            }
            if (filter_var($url, FILTER_VALIDATE_URL) === false
                || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            ) {
                throw new InvalidArgumentException("$url is not a valid http(s) URL!");
            }
            return [new RemoteUrl($url), $rtmp ? ' (in a livestream, it must be an MP4 or MKV file with H.264 video and AAC audio)' : ''];
        }

        /**
         * How a Telegram file will be played: '' if entirely, a note if only partially.
         */
        private static function describeMedia(Media $media, bool $rtmp): string
        {
            $mime = $media->mimeType;
            $ext = strtolower($media->fileExt);
            if ($rtmp) {
                // Only H.264 and AAC can be published, as they are.
                if (in_array($mime, ['video/mp4', 'video/x-matroska', 'audio/mp4'], true) || in_array($ext, ['.mp4', '.mkv', '.m4a'], true)) {
                    return ' (in a livestream, it must have H.264 video and AAC audio)';
                }
                throw new InvalidArgumentException("I can't play this file in a livestream: send me an MP4 or MKV video with H.264 video and AAC audio.");
            }
            if (in_array($mime, ['video/webm', 'video/x-matroska', 'audio/webm', 'audio/ogg', 'audio/opus'], true)
                || in_array($ext, ['.webm', '.mkv', '.ogg', '.opus'], true)
            ) {
                return '';
            }
            $isVideo = str_starts_with($mime, 'video/');
            // Anything else is converted with ffmpeg, which only plays its audio.
            if (($isVideo || str_starts_with($mime, 'audio/')) && Tools::canConvertOgg()) {
                return $isVideo ? ' (just its audio: send a WebM or MKV file with VP8, VP9, H.264 or H.265 video to play the video, too)' : '';
            }
            throw new InvalidArgumentException("I can't play this file: send me a WebM or MKV video (VP8, VP9, H.264 or H.265 with OPUS audio), or an audio file.");
        }

        /**
         * The file a playlist command refers to, along with a note on how it will be played: the URL
         * passed to it, the file it was sent with or the file it replies to (streamed, not downloaded);
         * null if none.
         *
         * @return array{RemoteUrl|ReadableStream, string}|null
         */
        private function getFile(Message $message, bool $rtmp): ?array
        {
            if (isset($message->commandArgs[0])) {
                return self::parseUrl($message->commandArgs[0], $rtmp);
            }
            $media = $message->media;
            if ($media === null && $message->replyToMsgId !== null) {
                $media = $message->getReply(Message::class)?->media
                    ?? throw new InvalidArgumentException('The message you replied to has no file!');
            }
            if ($media === null) {
                return null;
            }
            $note = self::describeMedia($media, $rtmp);
            return [$media->getStream(), $note];
        }

        /**
         * Add a file to the playlist of a call, interrupting the looped file.
         */
        private function enqueue(Call $call, LocalFile|RemoteUrl|ReadableStream $file, string $note): string
        {
            $call->then($file);
            return "Added to the playlist$note! Send /skip to skip the file being played.";
        }

        /**
         * Change (or remove, if null) the file looped in a call while its playlist is empty.
         */
        private function setLoop(Call $call, LocalFile|RemoteUrl|ReadableStream|null $file, string $note): string
        {
            if ($file === null) {
                $call->playOnHold();
                return 'Not looping anything anymore: nothing plays when the playlist is empty. Send /loop default to loop the default video again.';
            }
            $call->playOnHold(MediaDestination::Camera, $file);
            return "Now looping this file$note when the playlist is empty!";
        }

        #[FilterCommand('play')]
        public function playCommand(Incoming&Message $message): void
        {
            if (!$call = $this->getCallFor($message)) {
                $message->reply($this->notInCall($message));
                return;
            }
            if (!$this->mayControl($call, $message->senderId)) {
                $message->reply(self::NOT_LIVESTREAM_ADMIN);
                return;
            }
            try {
                $file = $this->getFile($message, self::isRtmp($call));
                $message->reply($file === null
                    ? 'Reply to a video or audio file with /play, or send /play <URL>, to add it to the playlist.'
                    : $this->enqueue($call, ...$file));
            } catch (InvalidArgumentException $e) {
                $message->reply($e->getMessage());
            }
        }

        #[FilterCommand('loop')]
        public function loopCommand(Incoming&Message $message): void
        {
            if (!$call = $this->getCallFor($message)) {
                $message->reply($this->notInCall($message));
                return;
            }
            if (!$this->mayControl($call, $message->senderId)) {
                $message->reply(self::NOT_LIVESTREAM_ADMIN);
                return;
            }
            try {
                $file = ($message->commandArgs[0] ?? null) === 'default'
                    ? [self::defaultVideo($call), '']
                    : $this->getFile($message, self::isRtmp($call));
                $message->reply($this->setLoop($call, $file[0] ?? null, $file[1] ?? ''));
            } catch (InvalidArgumentException $e) {
                $message->reply($e->getMessage());
            }
        }

        #[FilterCommand('skip')]
        public function skipCommand(Incoming&Message $message): void
        {
            if (!$call = $this->getCallFor($message)) {
                $message->reply($this->notInCall($message));
                return;
            }
            if (!$this->mayControl($call, $message->senderId)) {
                $message->reply(self::NOT_LIVESTREAM_ADMIN);
                return;
            }
            $message->reply($this->skip($call));
        }

        private function skip(Call $call): string
        {
            $call->skip();
            return 'Skipped!';
        }

        private function notInCall(Message $message): string
        {
            return $message->chatId > 0
                ? "We're not in a call: call me, send /call, or send me the link of a video chat or conference call first!"
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
            if (!$this->mayControl($call, $message->fromId)) {
                $call->sendMessage(self::NOT_LIVESTREAM_ADMIN);
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
                        : $this->enqueue($call, ...self::parseUrl($arg, self::isRtmp($call))),
                    'loop' => $this->setLoop($call, ...match ($arg) {
                        null => [null, ''],
                        'default' => [self::defaultVideo($call), ''],
                        default => self::parseUrl($arg, self::isRtmp($call)),
                    }),
                    'skip' => $this->skip($call),
                };
            } catch (InvalidArgumentException $e) {
                $reply = $e->getMessage();
            }
            $call->sendMessage($reply);
        }

        /**
         * Join the video chat of a group (starting one if none is running and we may), and play the video.
         */
        #[FiltersOr(new FilterCommand('join'), new FilterCommand('videochat'))]
        public function joinCommand(Incoming&GroupMessage $message): void
        {
            try {
                $joined = $this->getGroupCall($message->chatId)?->isJoined() ?? false;
                $call = $message->requestCall()->join();
                if (!$this->mayControl($call, $message->senderId)) {
                    if (!$joined) {
                        $call->leave();
                    }
                    $message->reply(self::NOT_LIVESTREAM_ADMIN);
                    return;
                }
                $this->broadcast($call);
                $message->reply('Playing in the video chat!');
            } catch (CallNotAllowedException $e) {
                $message->reply("I can't start a video chat here: {$e->reason->value}");
            } catch (Throwable $e) {
                $message->reply("Could not join the video chat: {$e->getMessage()}");
            }
        }

        /**
         * Leave the video chat of a group.
         */
        #[FilterCommand('leave')]
        public function leaveCommand(Incoming&GroupMessage $message): void
        {
            $call = $this->getGroupCall($message->chatId);
            if (!$call?->isJoined()) {
                $message->reply($this->notInCall($message));
                return;
            }
            if (!$this->mayControl($call, $message->senderId)) {
                $message->reply(self::NOT_LIVESTREAM_ADMIN);
                return;
            }
            try {
                $call->leave();
                $message->reply('Left the video chat! Send /join to make me join it again.');
            } catch (Throwable $e) {
                $message->reply("Could not leave the video chat: {$e->getMessage()}");
            }
        }

        /**
         * Join the video chat or livestream of a group or channel from our private chat, to keep the commands out
         * of it (starting one if none is running, and both we and the user may), and play the video.
         *
         * The commands sent in our private chat then control it.
         */
        #[FiltersOr(new FilterCommand('join'), new FilterCommand('videochat'))]
        public function joinPrivateCommand(Incoming&PrivateMessage $message): void
        {
            $arg = $message->commandArgs[0] ?? null;
            if ($arg === null) {
                $message->reply('Send /join <username or link of a group or channel> to make me join its video chat or livestream, then send me /play, /loop, /skip and /leave here to control it.');
                return;
            }
            try {
                $peer = $this->getId($arg);
            } catch (Throwable) {
                $peer = null;
            }
            if ($peer === null || $peer > 0) {
                $message->reply("$arg is not a group or channel I'm in.");
                return;
            }
            // Only those who could send /join in the group, or watch the video chat of the channel: anyone, if it's
            // public, as they could send its link (and only admins can check who is in a channel).
            $role = in_array($message->senderId, self::ADMINS, true)
                ? self::ROLE_CALLS
                : $this->getRole($peer, $message->senderId);
            if ($role === null && self::isPublic($this->getInfo($peer)['Chat'] ?? [])) {
                $role = self::ROLE_MEMBER;
            }
            if ($role === null) {
                $message->reply("Only the members of $arg can make me join its video chat.");
                return;
            }
            try {
                $call = $this->getGroupCall($peer);
                $joined = $call?->isJoined() ?? false;
                if ($call === null) {
                    if ($role !== self::ROLE_CALLS) {
                        $message->reply("There's no video chat in $arg: only its admins who can manage video chats can make me start one.");
                        return;
                    }
                    $call = $this->createGroupCall($peer);
                }
                $call->join();
                if (!$this->mayControl($call, $message->senderId)) {
                    if (!$joined) {
                        $call->leave();
                    }
                    $message->reply(self::NOT_LIVESTREAM_ADMIN);
                    return;
                }
                $this->broadcast($call);
                $this->linkedCalls[$message->chatId] = $call->id;
                $message->reply('Playing in the video chat! Send me /play, /loop, /skip and /leave here to control it.');
            } catch (Throwable $e) {
                $message->reply("Could not join the video chat: {$e->getMessage()}");
            }
        }

        /**
         * Leave the video chat or conference call controlled from our private chat.
         */
        #[FilterCommand('leave')]
        public function leavePrivateCommand(Incoming&PrivateMessage $message): void
        {
            $id = $this->linkedCalls[$message->chatId] ?? null;
            $call = $id === null ? null : $this->getMultiCall($id);
            if ($call === null) {
                unset($this->linkedCalls[$message->chatId]);
                $message->reply("I'm not in a video chat or conference call controlled from here: send /join <group or channel>, or the link of a call, first!");
                return;
            }
            if (!$this->mayControl($call, $message->senderId)) {
                $message->reply(self::NOT_LIVESTREAM_ADMIN);
                return;
            }
            try {
                $call->leave();
                unset($this->linkedCalls[$message->chatId]);
                $message->reply('Left the call!');
            } catch (Throwable $e) {
                $message->reply("Could not leave the call: {$e->getMessage()}");
            }
        }

        /**
         * Join the conference calls and video chats whose links we receive.
         */
        #[Handler]
        public function callLink(Incoming&PrivateMessage $message): void
        {
            try {
                if (preg_match(self::CONFERENCE_LINK, $message->message, $matches)) {
                    $message->reply('Joining the conference call...');
                    $call = $this->joinConferenceCallBySlug($matches[1]);
                } elseif (preg_match(self::GROUP_CALL_LINK, $message->message, $matches)) {
                    $message->reply('Joining the video chat...');
                    $joined = $this->getGroupCall('@'.$matches[1])?->isJoined() ?? false;
                    $call = $this->joinGroupCall('@'.$matches[1], inviteHash: $matches[2] ?? null);
                } else {
                    return;
                }
                if (!$this->mayControl($call, $message->senderId)) {
                    // Leave it, unless we were already in it (conference calls are never livestreams, they don't get here).
                    if (!($joined ?? true)) {
                        $call->leave();
                    }
                    $message->reply(self::NOT_LIVESTREAM_ADMIN);
                    return;
                }
                $this->broadcast($call);
                $this->linkedCalls[$message->chatId] = $call->id;
                $message->reply('Joined! Send me /play, /loop and /skip here, or in the chat of the call, to change what I play there.');
            } catch (Throwable $e) {
                $message->reply("Could not join the call: {$e->getMessage()}");
            }
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
                $message->reply("You should reply to the message you want to broadcast.");
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
                $message->reply("You should reply to the message you want to broadcast.");
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
        /**
         * Forget the calls we left.
         */
        #[Cron(period: 3600)]
        public function cleanup(): void
        {
            foreach ($this->linkedCalls as $user => $id) {
                if (!$this->getMultiCall($id)) {
                    unset($this->linkedCalls[$user]);
                }
            }
        }

        #[Cron(period: 10)]
        public function statusLoop(): void
        {
            foreach ($this->calls as $user => $call) {
                if ($call->getCallState() === CallState::ENDED) {
                    unset($this->calls[$call->otherID], $this->messageIds[$call->otherID]);

                    continue;
                }
                try {
                    $message = 'Total running calls: '.count($this->calls).PHP_EOL.PHP_EOL;
                    $message .= "Emojis: ".implode('', $call->getVisualization() ?? []);

                    $this->messages->editMessage(['id' => $this->messageIds[$call->otherID], 'peer' => $user, 'message' => $message]);
                } catch (RPCErrorException $e) {
                    $this->logger($e);
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
                $isCallLink = preg_match(self::CONFERENCE_LINK, $message->message) || preg_match(self::GROUP_CALL_LINK, $message->message);
                if (!isset($this->my_users[$message->chatId]) || $message->message === '/start') {
                    $runCall = true;
                    $this->my_users[$message->chatId] = true;
                    $message->reply(
                        message: "Hi, I'm {$this->me} the video webradio.

Call _me_ to watch an **awesome** video, or send /call to make _me_ call _you_ (don't forget to disable call privacy settings!).

Send me the link of a video chat, livestream or conference call, and I'll join it too (I can play in livestreams only if I'm an admin of their group or channel)!
Add me to your group and send /join to make me join its video chat.

Commands:
/start - show this message
/call - make me call you
/program <time> - program a call, for example:
  /program 29 August 2018 - call me the 29th of august 2018
  /program +1 hour 30 minutes - call me in one hour and thirty minutes
  /program next Thursday - call me next Thursday at midnight
/join (or /videochat) - in a group, join its video chat (or start one, if I'm allowed to); here, send /join <username or link of a group or channel> to do the same without sending commands there
/leave - leave the video chat or conference call (in the group, in the chat of the call, or here if you made me join it from here)

During a call, change what I play (in our private chat, in the group, or in the chat of the call), only as an admin in livestreams:
/play - reply to a video or audio file (or send /play <URL>, even of a YouTube video) to add it to the playlist
/skip - skip the file being played
/loop - reply to a file (or send /loop <URL>, even of a YouTube video) to loop it instead of my video when the playlist is empty, send just /loop to loop nothing, /loop default to loop my video again

Admin only:
/restart - restart me
/broadcast - reply to a message to send a copy of it to all my users, and pin it
/bforward - reply to a message to forward it to all my users, and pin it

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
                        $message->reply('Invalid time provided');
                    } elseif ($time - time() <= 0) {
                        $message->reply('Invalid time provided');
                    } else {
                        $message->reply('OK');
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
                    $message->reply((string) $e);
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
            unset($this->calls[$call->otherID], $this->messageIds[$call->otherID]);
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
            return ['programmed_call', 'my_users', 'messageIds', 'calls', 'linkedCalls'];
        }
    }

    $settings = new Settings;
    $settings->getSecretChats()->setAccept(false);

    MyEventHandler::startAndLoop('magna.madeline', $settings);
}
