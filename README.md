# magnaluna webradio

[![Build Status](https://travis-ci.org/danog/magnaluna.svg?branch=master)](https://travis-ci.org/danog/magnaluna)

Telegram webradio with advanced features like call programming and real-time call stats based on [MadelineProto](https://github.com/danog/MadelineProto) and [libtgvoip](https://github.com/danog/php-libtgvoip).  

![Screenshot](https://github.com/danog/magnaluna/raw/master/screenshot.png)

Created by [Daniil Gentili](https://daniil.it).

## Installation

```
wget https://github.com/danog/magnaluna/raw/master/magna.php && php magna.php
```

Don't forget to install the [required dependencies](https://docs.madelineproto.xyz/docs/REQUIREMENTS.html) for MadelineProto.

## Converting songs

In order to play songs, they must be first converted to raw PCM format (support for OGG OPUS files will be added soon):

```
ffmpeg -i anyaudioorvideo.mp3 -f s16le -ac 1 -ar 48000 -acodec pcm_s16le mysong.raw
```
