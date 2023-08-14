#!/usr/bin/env php
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

use danog\Loop\ResumableSignalLoop;
use danog\MadelineProto\API;
use danog\MadelineProto\EventHandler;
use danog\MadelineProto\EventHandler\Attributes\Handler;
use danog\MadelineProto\EventHandler\SimpleFilter\Incoming;
use danog\MadelineProto\Exception;
use danog\MadelineProto\LocalFile;
use danog\MadelineProto\RPCErrorException;
use danog\MadelineProto\SimpleEventHandler;
use danog\MadelineProto\Tools;
use danog\MadelineProto\VoIP;
use danog\MadelineProto\VoIP\CallState;

//if (file_exists('vendor/autoload.php')) {
require 'vendor/autoload.php';
/*} else {
    if (!file_exists('madeline.php')) {
        copy('https://phar.madelineproto.xyz/madeline.php', 'madeline.php');
    }
    include 'madeline.php';
}*/

echo 'Deserializing MadelineProto from session.madeline...'.PHP_EOL;

/*class MessageLoop extends ResumableSignalLoop
{
    const INTERVAL = 10000;
    private $timeout;
    private $call;
    private EventHandler $API;
    public function __construct($API, $call, $timeout = self::INTERVAL)
    {
        $this->API = $API;
        $this->call = $call;
        $this->timeout = $timeout;
    }
    public function loop(): void
    {
        $MadelineProto = $this->API;
        $logger = $MadelineProto->getLogger();

        while (true) {
            do {
                $result = $this->waitSignal($this->pause($this->timeout));
                if ($result) {
                    $logger->logger("Got signal in $this, exiting");
                    return;
                }
            } while (!isset($this->call->mId));
            $result = $this->waitSignal($this->pause($this->timeout));
            try {
                $message = 'Total running calls: '.count($MadelineProto->getEventHandler()->calls).PHP_EOL.PHP_EOL.$this->call->getDebugString();
                $message .= PHP_EOL.PHP_EOL.PHP_EOL;
                $message .= "Emojis: ".implode('', $this->call->getVisualization());

                $MadelineProto->messages->editMessage(['id' => $this->call->mId, 'peer' => $this->call->otherID, 'message' => $message]);
            } catch (RPCErrorException $e) {
                $MadelineProto->logger($e);
            }
        }
    }
    public function __toString(): string
    {
        return "VoIP message loop ".$this->call->otherID;
    }
}
class StatusLoop extends ResumableSignalLoop
{
    const INTERVAL = 2000;
    private $timeout;
    private $call;
    private EventHandler $API;
    public function __construct($API, $call, $timeout = self::INTERVAL)
    {
        $this->API = $API;
        $this->call = $call;
        $this->timeout = $timeout;
    }
    public function loop(): void
    {
        $MadelineProto = $this->API;
        $logger = $MadelineProto->getLogger();
        $call = $this->call;

        while (true) {
            $result = $this->waitSignal($this->pause($this->timeout));
            if ($result) {
                $logger->logger("Got signal in $this, exiting");
                $MadelineProto->getEventHandler()->cleanUpCall($call->otherID);
                return;
            }

            if ($call->getCallState() === CallState::ENDED) {
                $MadelineProto->getEventHandler()->cleanUpCall($call->otherID);
                if (file_exists('/tmp/logs'.$call->getCallID()['id'].'.log')) {
                    @unlink('/tmp/logs'.$call->getCallID()['id'].'.log');
                    try {
                        $me = $this->API->getEventHandler()->getMe();
                        $MadelineProto->messages->sendMedia([
                            'reply_to_msg_id' => $this->call->mId,
                            'peer'            => $call->otherID, 'message' => "Debug info by $me",
                            'media'           => [
                                '_'          => 'inputMediaUploadedDocument',
                                'file'       => '/tmp/logs'.$call->getCallID()['id'].'.log',
                                'attributes' => [
                                    ['_' => 'documentAttributeFilename', 'file_name' => 'logs'.$call->getCallID()['id'].'.log'],
                                ],
                            ],
                        ]);
                    } catch (Exception $e) {
                        $MadelineProto->logger($e);
                    } catch (RPCErrorException $e) {
                        $MadelineProto->logger($e);
                    } catch (Exception $e) {
                        $MadelineProto->logger($e);
                    }
                }
                if (file_exists('/tmp/stats'.$call->getCallID()['id'].'.txt')) {
                    @unlink('/tmp/stats'.$call->getCallID()['id'].'.txt');
                }
                return;
            }
        }
    }
    public function __toString(): string
    {
        return "VoIP status loop ".$this->call->otherID;
    }
}*/

class MyEventHandler extends SimpleEventHandler
{
    const ADMINS = [101374607]; // @danogentili, creator of MadelineProto
    private $messageLoops = [];
    private $statusLoops = [];
    private $programmed_call;
    private $my_users;
    private $me;
    public $calls = [];
    public function onStart(): void
    {
        $this->me = '@' . ((($this->getSelf())['username']) ?? 'magnaluna');

        $this->programmed_call = [];
        foreach ($this->programmed_call as $key => [$user, $time]) {
            continue;
            $sleepTime = $time <= time() ? 0 : $time - time();
            Tools::callFork(function () use ($sleepTime, $key, $user): void {
                Tools::sleep($sleepTime);
                $this->makeCall($user);
                unset($this->programmed_call[$key]);
            });
        }
    }
    public function getMe(): string
    {
        return $this->me;
    }
    public function getReportPeers(): array
    {
        return self::ADMINS;
    }
    public function configureCall(VoIP $call): void
    {
        $songs = glob('*ogg');
        if (!$songs) {
            die('No songs defined! Convert some songs as described in https://docs.madelineproto.xyz/docs/CALLS.html');
        }
        $songs_length = count($songs);

        for ($x = 0; $x < $songs_length; $x++) {
            shuffle($songs);
        }

        foreach ($songs as &$song) {
            $song = new LocalFile($song);
        }

        $call->playOnHold(...$songs);
        if ($call->getCallState() !== CallState::ENDED) {
            $this->calls[$call->otherID] = $call;
            /*try {
                $message = 'Total running calls: '.count($this->calls).PHP_EOL.PHP_EOL.$call->getDebugString();
                //$message .= PHP_EOL;
                //$message .= "Emojis: ".implode('', $call->getVisualization());

                $call->mId = $this->messages->sendMessage(['peer' => $call->otherID, 'message' => $message])['id'];
            } catch (Throwable $e) {
                $this->logger($e);
            }
            $this->messageLoops[$call->otherID] = new MessageLoop($this, $call);
            $this->statusLoops[$call->otherID] = new StatusLoop($this, $call);
            $this->messageLoops[$call->otherID]->start();
            $this->statusLoops[$call->otherID]->start();*/
        }
        //$this->messages->sendMessage(['message' => var_export($call->configuration, true), 'peer' => $call->otherID]);
    }
    public function cleanUpCall($user): void
    {
        if (isset($this->calls[$user])) {
            unset($this->calls[$user]);
        }
        if (isset($this->messageLoops[$user])) {
            $this->messageLoops[$user]->signal(true);
            unset($this->messageLoops[$user]);
        }
        if (isset($this->statusLoops[$user])) {
            $this->statusLoops[$user]->signal(true);
            unset($this->statusLoops[$user]);
        }
    }
    public function makeCall($user): void
    {
        try {
            if (isset($this->calls[$user])) {
                if ($this->calls[$user]->getCallState() === CallState::ENDED) {
                    $this->cleanUpCall($user);
                } else {
                    $this->messages->sendMessage(['peer' => $user, 'message' => "I'm already in a call with you!"]);
                    return;
                }
            }
            $this->configureCall($this->requestCall($user));
        } catch (RPCErrorException $e) {
            try {
                if ($e->rpc === 'USER_PRIVACY_RESTRICTED') {
                    $e = 'Please disable call privacy settings to make me call you';
                }/* elseif (strpos($e->rpc, 'FLOOD_WAIT_') === 0) {
                    $t = str_replace('FLOOD_WAIT_', '', $e->rpc);
                    $this->programmed_call[] = [$user, time() + 1 + $t];
                    $e = "I'll call you back in $t seconds.\nYou can also call me right now.";
                }*/
                $this->messages->sendMessage(['peer' => $user, 'message' => (string) $e]);
            } catch (RPCErrorException $e) {
            }
        } catch (Throwable $e) {
            $this->messages->sendMessage(['peer' => $user, 'message' => (string) $e]);
        }
    }
    public function handleMessage($chat_id, $from_id, $message): void
    {
        try {
            if (!isset($this->my_users[$from_id]) || $message === '/start') {
                $this->my_users[$from_id] = true;
                $message = '/call';
                $this->messages->sendMessage(['no_webpage' => true, 'peer' => $chat_id, 'message' => "Hi, I'm {$this->me} the webradio.

Call _me_ to listen to some **awesome** music, or send /call to make _me_ call _you_ (don't forget to disable call privacy settings!).

You can also program a phone call with /program:

/program 29 August 2018 - call me the 29th of august 2018
/program +1 hour 30 minutes - call me in one hour and thirty minutes
/program next Thursday - call me next Thursday at midnight

Send /start to see this message again.

I also provide advanced stats during calls!

I'm a userbot powered by @MadelineProto, created by @danogentili.

Source code: https://github.com/danog/MadelineProto

Propic art by magnaluna on [deviantart](https://magnaluna.deviantart.com).", 'parse_mode' => 'Markdown']);
            }
            if (!isset($this->calls[$from_id]) && $message === '/call') {
                $this->makeCall($from_id);
            }
            if (strpos($message, '/program') === 0) {
                $time = strtotime(str_replace('/program ', '', $message));
                if ($time === false) {
                    $this->messages->sendMessage(['peer' => $chat_id, 'message' => 'Invalid time provided']);
                } elseif ($time - time() <= 0) {
                    $this->messages->sendMessage(['peer' => $chat_id, 'message' => 'Invalid time provided']);
                } else {
                    $this->messages->sendMessage(['peer' => $chat_id, 'message' => 'OK']);
                    $this->programmed_call[] = [$from_id, $time];
                    $key = count($this->programmed_call) - 1;
                    Tools::sleep($time - time());
                    $this->makeCall($from_id);
                    unset($this->programmed_call[$key]);
                }
            }
            if ($message === '/broadcast' && in_array(self::ADMINS, $from_id)) {
                $time = time() + 100;
                $message = explode(' ', $message, 2);
                unset($message[0]);
                $message = implode(' ', $message);
                $params = ['multiple' => true];
                foreach ($this->getDialogs() as $peer) {
                    $params []= ['peer' => $peer, 'message' => $message];
                }
                $this->messages->sendMessage($params);
            }
        } catch (RPCErrorException $e) {
            try {
                if ($e->rpc === 'USER_PRIVACY_RESTRICTED') {
                    $e = 'Please disable call privacy settings to make me call you';
                } /*elseif (strpos($e->rpc, 'FLOOD_WAIT_') === 0) {
                    $t = str_replace('FLOOD_WAIT_', '', $e->rpc);
                    $e = "Too many people used the /call function. I'll be able to call you in $t seconds.\nYou can also call me right now";
                }*/
                $this->messages->sendMessage(['peer' => $chat_id, 'message' => (string) $e]);
            } catch (RPCErrorException $e) {
            }
            $this->logger($e);
        } catch (Exception $e) {
            $this->logger($e);
        }
    }

    public function onUpdateNewMessage($update): void
    {
        if ($update['message']['out'] || $update['message']['to_id']['_'] !== 'peerUser' || !isset($update['message']['from_id'])) {
            return;
        }
        $this->logger($update);
        $chat_id = $from_id = $this->getInfo($update)['bot_api_id'];
        $message = $update['message']['message'] ?? '';
        $this->handleMessage($chat_id, $from_id, $message);
    }

    public function onUpdateNewEncryptedMessage($update): void
    {
        return;
        $chat_id = $this->getInfo($update)['InputEncryptedChat'];
        $from_id = $this->getSecretChat($chat_id)['user_id'];
        $message = $update['message']['decrypted_message']['message'] ?? '';
        $this->handleMessage($chat_id, $from_id, $message);
    }

    public function onUpdateEncryption($update): void
    {
        return;

        try {
            if ($update['chat']['_'] !== 'encryptedChat') {
                return;
            }
            $chat_id = $this->getInfo($update)['InputEncryptedChat'];
            $from_id = $this->getSecretChat($chat_id)['user_id'];
            $message = '';
        } catch (Exception $e) {
            return;
        }
        $this->handleMessage($chat_id, $from_id, $message);
    }

    #[Handler]
    public function incomingCall(VoIP&Incoming $voip): void
    {
        $this->configureCall($voip->accept());
    }

    public function __sleep(): array
    {
        return ['programmed_call', 'my_users'];
    }
}

MyEventHandler::startAndLoop('magna.madeline');
