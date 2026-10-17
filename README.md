# magnaluna webradio

[![Build Status](https://travis-ci.org/danog/magnaluna.svg?branch=master)](https://travis-ci.org/danog/magnaluna)

Telegram video webradio based on [MadelineProto](https://github.com/danog/MadelineProto), with advanced features like call programming and real-time call stats.

It plays a video in:
- one-to-one video calls (call it, or send it /call to make it call you);
- video chats and end-to-end encrypted conference calls, whose links you send it;
- the video chats of the groups it's added to, when you send it `/join` or `/videochat` (it also starts one, if it may).

During a call, `/play` (replying to a video or audio file, or with a URL, even of a YouTube video) adds a file to the playlist, `/skip` skips the file being played, and `/loop` (replying to a file, or with a URL) changes the video looped when the playlist is empty: send just `/loop` to loop nothing, `/loop default` to loop the default video again.
Send them in the private chat (for one-to-one calls and the calls joined with a link you sent), in the group (for its video chat) or in the chat of the call (URLs only); files are streamed, never downloaded.
Send `/leave` in the group (for its video chat) or in the chat of the call to make it leave a video chat or conference call.

![Screenshot](https://github.com/danog/magnaluna/raw/master/screenshot.png)

Try it on Telegram [@magicalcrazypony](https://t.me/magicalcrazypony)!

Created by [Daniil Gentili](https://daniil.it).

## Installation

```
wget https://github.com/danog/magnaluna/raw/master/magna.php && php magna.php
```

Don't forget to install the [required dependencies](https://docs.madelineproto.xyz/docs/REQUIREMENTS.html) for MadelineProto.

## YouTube videos

YouTube videos are played in up to 1080p, entirely in pure PHP (see the `Magnaluna` namespace in `magna.php`): like yt-dlp, their formats are got with the visionOS InnerTube client and a visitor ID, which need no JavaScript player nor PO token; their VP9 video and OPUS audio are fetched a range at a time, and muxed into a WebM file on the fly, without ffmpeg.
The stream is resumable: when magnaluna restarts, the video resumes where it was.

## Changing the video

magnaluna plays `oncall.webm` (downloaded on first start), entirely in pure PHP: replace it with any WebM/Matroska file with VP8, VP9, H.264 or H.265 video and OPUS audio, no ffmpeg needed.
