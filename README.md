# magnaluna webradio

[![Build Status](https://travis-ci.org/danog/magnaluna.svg?branch=master)](https://travis-ci.org/danog/magnaluna)

Telegram video webradio based on [MadelineProto](https://github.com/danog/MadelineProto), with advanced features like call programming and real-time call stats.

It plays a video in:
- one-to-one video calls (call it, or send it /call to make it call you);
- video chats and end-to-end encrypted conference calls, whose links you send it;
- the video chats of every group it is a member of, joining them as soon as they start.  

![Screenshot](https://github.com/danog/magnaluna/raw/master/screenshot.png)

Try it on Telegram [@magicalcrazypony](https://t.me/magicalcrazypony)!

Created by [Daniil Gentili](https://daniil.it).

## Installation

```
wget https://github.com/danog/magnaluna/raw/master/magna.php && php magna.php
```

Don't forget to install the [required dependencies](https://docs.madelineproto.xyz/docs/REQUIREMENTS.html) for MadelineProto.

## Changing the video

magnaluna plays `oncall.webm` (downloaded on first start), entirely in pure PHP: replace it with any WebM/Matroska file with VP8, VP9, H.264 or H.265 video and OPUS audio, no ffmpeg needed.
