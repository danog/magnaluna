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

use danog\MadelineProto\Broadcast\Progress;
use danog\MadelineProto\Broadcast\Status;
use danog\MadelineProto\EventHandler\Attributes\Cron;
use danog\MadelineProto\EventHandler\Attributes\Handler;
use danog\MadelineProto\EventHandler\Filter\FilterCommand;
use danog\MadelineProto\EventHandler\Message;
use danog\MadelineProto\EventHandler\SimpleFilter\Ended;
use danog\MadelineProto\EventHandler\SimpleFilter\FromAdmin;
use danog\MadelineProto\EventHandler\SimpleFilter\Incoming;
use danog\MadelineProto\Exception;
use danog\MadelineProto\LocalFile;
use danog\MadelineProto\Ogg;
use danog\MadelineProto\ParseMode;
use danog\MadelineProto\RPCErrorException;
use danog\MadelineProto\SimpleEventHandler;
use danog\MadelineProto\Tools;
use danog\MadelineProto\VoIP;
use danog\MadelineProto\VoIP\CallState;

if (file_exists('vendor/autoload.php')) {
    require 'vendor/autoload.php';
} else {
    if (!file_exists('madeline.php')) {
        copy('https://phar.madelineproto.xyz/madeline.php', 'madeline.php');
    }
    include 'madeline.php';
}

$songs = glob('*ogg');
if (!$songs) {
    die('No songs defined! Convert some songs by sending them to https://t.me/libtgvoipbot and putting them in the current directory'.PHP_EOL);
}

class MyEventHandler extends SimpleEventHandler
{
    const ADMINS = [101374607]; // @danogentili, creator of MadelineProto
    private array $programmed_call;
    private array $my_users;
    private string $me;
    /** @var array<int, VoIP> */
    private array $calls = [];
    /** @var array<int, int> */
    private array $messageIds = [];
    /** @var list<LocalFile> */
    private array $songs = [];
    public function onStart(): void
    {
        $this->me = '@' . ((($this->getSelf())['username']) ?? 'magnaluna');

        $songs = glob('*ogg');
        if (!$songs) {
            throw new \AssertionError('No songs defined! Convert some songs by sending them to https://t.me/libtgvoipbot and putting them in the current directory');
        }
        foreach ($songs as &$song) {
            $song = new LocalFile($song);
            try {
                Ogg::validateOgg($song);
            } catch (Throwable $e) {
                throw new AssertionError("An error occurred during validation of $song, please convert the file using convert.php or @libtgvoipbot!", 0, $e);
            }
        }
        $this->songs = $songs;

        $this->programmed_call = [];
        foreach ($this->programmed_call as $key => [$user, $time]) {
            $sleepTime = $time <= time() ? 0 : $time - time();
            Tools::callFork(function () use ($sleepTime, $key, $user): void {
                Tools::sleep($sleepTime);
                $this->makeCall($user);
                unset($this->programmed_call[$key]);
            });
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
                $message .= PHP_EOL.PHP_EOL.PHP_EOL;
                $message .= "Emojis: ".implode('', $call->getVisualization());

                $this->messages->editMessage(['id' => $this->messageIds[$call->otherID], 'peer' => $user, 'message' => $message]);
            } catch (RPCErrorException $e) {
                $this->logger($e);
            }
        }
    }

    private function configureCall(VoIP $call): void
    {
        $songs = $this->songs;
        $songs_length = count($songs);

        for ($x = 0; $x < $songs_length; $x++) {
            shuffle($songs);
        }

        $call->playOnHold(...$songs);
        if ($call->getCallState() !== CallState::ENDED) {
            try {
                $message = 'Total running calls: '.count($this->calls).PHP_EOL.PHP_EOL;
                $message .= PHP_EOL.PHP_EOL.PHP_EOL;
                $message .= "Emojis: ".implode('', $call->getVisualization());

                $this->messages[$call->otherID] = $this->sendMessage(peer: $call->otherID, message: $message)->id;
                $this->calls[$call->otherID] = $call;
            } catch (Throwable $e) {
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
            $this->configureCall($this->requestCall($user));
        } catch (RPCErrorException $e) {
            try {
                if ($e->rpc === "CALL_PROTOCOL_COMPAT_LAYER_INVALID") {
                    $e = "Please call me using Telegram Desktop, Telegram for Mac or Telegram Android!";
                }
                if ($e->rpc === 'USER_PRIVACY_RESTRICTED') {
                    $e = 'Please disable call privacy settings to make me call you (or call me yourself!)';
                }
                $this->messages->sendMessage(['peer' => $user, 'message' => (string) $e]);
            } catch (RPCErrorException $e) {
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
            if (!isset($this->my_users[$message->chatId]) || $message->message === '/start') {
                $runCall = true;
                $this->my_users[$message->chatId] = true;
                $message->reply(
                    message: "Hi, I'm {$this->me} the webradio.

Call _me_ to listen to some **awesome** music, or send /call to make _me_ call _you_ (don't forget to disable call privacy settings!).

You can also program a phone call with /program:

/program 29 August 2018 - call me the 29th of august 2018
/program +1 hour 30 minutes - call me in one hour and thirty minutes
/program next Thursday - call me next Thursday at midnight

Send /start to see this message again.

I also provide advanced stats during calls!

I'm a userbot powered by @MadelineProto, created by @danogentili.

Source code: https://github.com/danog/MadelineProto

Propic art by magnaluna on [deviantart](https://magnaluna.deviantart.com).


Note for iOS users: the official Telegram iOS app has a bug which prevents me from working properly, I'm looking into it, try calling from your Mac/Android/PC, instead!
",
                    parseMode: ParseMode::MARKDOWN,
                    noWebpage: true
                );
            }
            if (!$this->getCallByPeer($message->chatId) && $runCall && $message->chatId > 0) {
                $this->makeCall($message->chatId);
            }
            if (strpos($message->message, '/program') === 0 && $message->chatId > 0) {
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
                } elseif (strpos($e->rpc, 'FLOOD_WAIT_') === 0) {
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
    public function incomingCall(VoIP&Incoming $voip): void
    {
        try {
            $voip = $voip->accept();
        } catch (RPCErrorException $e) {
            if ($e->rpc === "CALL_PROTOCOL_COMPAT_LAYER_INVALID") {
                $this->messages->sendMessage(peer: $voip->otherID, message: "Please call me using Telegram Desktop, Telegram for Mac or Telegram Android!");
                return;
            }
            throw $e;
        }
        $this->configureCall($voip);
    }

    #[Handler]
    public function endedCall(VoIP&Ended $voip): void
    {
        unset($this->calls[$voip->otherID], $this->messageIds[$voip->otherID]);

    }

    public function __sleep(): array
    {
        return ['programmed_call', 'my_users', 'messageIds'];
    }
}

MyEventHandler::startAndLoop('magna.madeline');
