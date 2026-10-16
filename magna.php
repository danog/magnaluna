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

use danog\MadelineProto\API;
use danog\MadelineProto\Broadcast\Progress;
use danog\MadelineProto\Broadcast\Status;
use danog\MadelineProto\EventHandler\Attributes\Cron;
use danog\MadelineProto\EventHandler\Attributes\Handler;
use danog\MadelineProto\EventHandler\Call;
use danog\MadelineProto\EventHandler\Calls\GroupCall;
use danog\MadelineProto\EventHandler\Calls\PrivateCall;
use danog\MadelineProto\EventHandler\Filter\FilterCommand;
use danog\MadelineProto\EventHandler\Message;
use danog\MadelineProto\EventHandler\Message\PrivateMessage;
use danog\MadelineProto\EventHandler\Message\Service\DialogGroupCall\GroupCall as GroupCallStarted;
use danog\MadelineProto\EventHandler\SimpleFilter\Ended;
use danog\MadelineProto\EventHandler\SimpleFilter\FromAdmin;
use danog\MadelineProto\EventHandler\SimpleFilter\Incoming;
use danog\MadelineProto\EventHandler\SimpleFilter\Running;
use danog\MadelineProto\Exception;
use danog\MadelineProto\LocalFile;
use danog\MadelineProto\MediaDestination;
use danog\MadelineProto\ParseMode;
use danog\MadelineProto\RPCError\RateLimitError;
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

    private array $programmed_call;
    private array $my_users;
    private string $me;
    /** @var array<int, PrivateCall> */
    private array $calls = [];
    /** @var array<int, int> */
    private array $messageIds = [];
    public function onStart(): void
    {
        $this->me = '@' . ((($this->getSelf())['username']) ?? 'magnaluna');

        $this->programmed_call ??= [];
        foreach ($this->programmed_call as $key => [$user, $time]) {
            $sleepTime = $time <= time() ? 0 : $time - time();
            Tools::callFork(function () use ($sleepTime, $key, $user): void {
                Tools::sleep($sleepTime);
                $this->makeCall($user);
                unset($this->programmed_call[$key]);
            });
        }

        // Join (or start) the video chats of our groups.
        Tools::callFork($this->startGroupCalls(...));
    }

    /**
     * Play the video in a call, on loop.
     */
    private function broadcast(Call $call): void
    {
        $call->playOnHold(MediaDestination::Camera, new LocalFile(self::VIDEO));
    }

    /**
     * Join a video chat (unless we are already in it, or nothing can be transmitted in it), and play the video.
     */
    private function autoJoinGroupCall(GroupCall $call): void
    {
        if ($call->discarded || $call->scheduleDate !== null || $call->rtmpStream || $call->isJoined()) {
            return;
        }
        $this->logger("Joining $call");
        $this->broadcast($call->join());
    }

    /**
     * Whether we may start a video chat in a group: we must be its creator, or an admin with the manage_call right.
     */
    private function canStartGroupCall(int $id): bool
    {
        $chat = $this->getInfo($id)['Chat'] ?? [];
        return !($chat['left'] ?? false)
            && !($chat['deactivated'] ?? false)
            && (($chat['creator'] ?? false) || ($chat['admin_rights']['manage_call'] ?? false));
    }

    /**
     * Join the video chat of every group we are a member of, starting one where none is running, if we may.
     *
     * Also run periodically, for the groups we are added to, and the video chats that ended.
     */
    #[Cron(period: 600)]
    public function startGroupCalls(): void
    {
        foreach ($this->getDialogIds() as $id) {
            for ($attempt = 0; $attempt < 3; $attempt++) {
                try {
                    if (!in_array($this->getType($id), [API::PEER_TYPE_GROUP, API::PEER_TYPE_SUPERGROUP], true)) {
                        break;
                    }
                    $call = $this->getGroupCall($id);
                    if ($call === null && $this->canStartGroupCall($id)) {
                        $this->logger("Starting a video chat in $id");
                        $call = $this->createGroupCall($id);
                    }
                    if ($call !== null) {
                        $this->autoJoinGroupCall($call);
                    }
                    break;
                } catch (RateLimitError $e) {
                    // Wait, and try again.
                    $e->wait();
                } catch (Throwable $e) {
                    $this->logger("Could not join the video chat of $id: $e");
                    break;
                }
            }
        }
    }

    /**
     * Join video chats as soon as they start in our groups.
     */
    #[Handler]
    public function groupCallStarted(GroupCallStarted $message): void
    {
        if ($message->ended || !in_array($this->getType($message->chatId), [API::PEER_TYPE_GROUP, API::PEER_TYPE_SUPERGROUP], true)) {
            return;
        }
        if ($call = $this->getGroupCall($message->chatId)) {
            $this->autoJoinGroupCall($call);
        }
    }

    /**
     * Join video chats whose updates we receive, if they are in one of our groups.
     */
    #[Handler]
    public function groupCallUpdate(GroupCall $call): void
    {
        if ($call->discarded || $call->isJoined() || $call->peerId === null
            || !in_array($this->getType($call->peerId), [API::PEER_TYPE_GROUP, API::PEER_TYPE_SUPERGROUP], true)
        ) {
            return;
        }
        if ($call = $this->getGroupCall($call->peerId)) {
            $this->autoJoinGroupCall($call);
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
                $this->broadcast($this->joinConferenceCallBySlug($matches[1]));
            } elseif (preg_match(self::GROUP_CALL_LINK, $message->message, $matches)) {
                $message->reply('Joining the video chat...');
                $call = $this->joinGroupCall('@'.$matches[1], inviteHash: $matches[2] ?? null);
                $this->broadcast($call);
            }
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
I also join the video chats of all the groups I'm in: add me to your group and start one!

You can also program a phone call with /program:

/program 29 August 2018 - call me the 29th of august 2018
/program +1 hour 30 minutes - call me in one hour and thirty minutes
/program next Thursday - call me next Thursday at midnight

Send /start to see this message again.

I also provide advanced stats during calls!

I'm a userbot powered by @MadelineProto, created by @danogentili.

Source code: https://github.com/danog/MadelineProto

Propic art by magnaluna on [deviantart](https://magnaluna.deviantart.com).
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
        return ['programmed_call', 'my_users', 'messageIds', 'calls'];
    }
}

$settings = new Settings;
$settings->getSecretChats()->setAccept(false);

MyEventHandler::startAndLoop('magna.madeline', $settings);
