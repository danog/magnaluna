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

use Amp\ByteStream\ReadableStream;
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

final class MyEventHandler extends SimpleEventHandler
{
    public const ADMINS = [101_374_607]; // @danogentili, creator of MadelineProto
    /** The video played in every call. */
    private const VIDEO = 'oncall.webm';
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
     */
    private function broadcast(Call $call): void
    {
        $call->playOnHold(MediaDestination::Camera, new LocalFile(self::VIDEO));
    }

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
     * Parse the URL of a file to play.
     */
    private static function parseUrl(string $url): RemoteUrl
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false
            || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
        ) {
            throw new InvalidArgumentException("$url is not a valid http(s) URL!");
        }
        return new RemoteUrl($url);
    }

    /**
     * How a Telegram file will be played: '' if entirely, a note if only partially.
     */
    private static function describeMedia(Media $media): string
    {
        $mime = $media->mimeType;
        $ext = strtolower($media->fileExt);
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
    private function getFile(Message $message): ?array
    {
        if (isset($message->commandArgs[0])) {
            return [self::parseUrl($message->commandArgs[0]), ''];
        }
        $media = $message->media;
        if ($media === null && $message->replyToMsgId !== null) {
            $media = $message->getReply(Message::class)?->media
                ?? throw new InvalidArgumentException('The message you replied to has no file!');
        }
        if ($media === null) {
            return null;
        }
        $note = self::describeMedia($media);
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
        try {
            $file = $this->getFile($message);
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
        try {
            $file = ($message->commandArgs[0] ?? null) === 'default'
                ? [new LocalFile(self::VIDEO), '']
                : $this->getFile($message);
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
                    : $this->enqueue($call, self::parseUrl($arg), ''),
                'loop' => $this->setLoop($call, match ($arg) {
                    null => null,
                    'default' => new LocalFile(self::VIDEO),
                    default => self::parseUrl($arg),
                }, ''),
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
            $this->broadcast($message->requestCall()->join());
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
        try {
            $call->leave();
            $message->reply('Left the video chat! Send /join to make me join it again.');
        } catch (Throwable $e) {
            $message->reply("Could not leave the video chat: {$e->getMessage()}");
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
                $call = $this->joinGroupCall('@'.$matches[1], inviteHash: $matches[2] ?? null);
            } else {
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

Send me the link of a video chat or a conference call, and I'll join it too!
Add me to your group and send /join to make me join its video chat (or start one, if I'm allowed to)!

During a call, change what I play (in our private chat, in the group, or in the chat of the call):
/play - reply to a video or audio file (or send /play <URL>) to add it to the playlist
/skip - skip the file being played
/loop - reply to a file (or send /loop <URL>) to loop it instead of my video when the playlist is empty, send just /loop to loop nothing, /loop default to loop my video again

Send /leave in the group or in the chat of the call to make me leave a video chat or conference call.

You can also program a phone call with /program:

/program 29 August 2018 - call me the 29th of august 2018
/program +1 hour 30 minutes - call me in one hour and thirty minutes
/program next Thursday - call me next Thursday at midnight

Send /start to see this message again.

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
