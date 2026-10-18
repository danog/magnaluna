# magnaluna webradio

Telegram video webradio based on [MadelineProto](https://github.com/danog/MadelineProto): it plays videos, music, YouTube videos and YouTube live streams in the video chats and RTMP livestreams of groups and channels, in end-to-end encrypted conference calls and in one-to-one video calls, entirely in pure PHP (no ffmpeg needed).

Try it on Telegram [@magicalcrazypony](https://t.me/magicalcrazypony)!

Created by [Daniil Gentili](https://daniil.it).

## Video chats, livestreams and conference calls

magnaluna joins a group call and plays its default video on loop, until you give it something else to play:

- add it to a group and send `/join` (or `/videochat`) there: it joins the video chat, or starts one if none is running and it's allowed to;
- send it the link of a video chat or livestream (`https://t.me/<username>?videochat`, `?livestream` or `?voicechat`, with its invite hash, if any) or of a conference call (`https://t.me/call/...`);
- or send `/join <group or channel>` from anywhere, even its private chat, with the username, link or ID of a group or channel: the members of a private group or channel can make it join its video chat, anyone can for a public one, and only the admins who can manage video chats can make it start one.

It plays in both kinds of group calls of Telegram:

- **video chats** (and conference calls) carry VP8, VP9, H.264 or H.265 video and OPUS audio, published as a participant's camera;
- **RTMP livestreams** carry H.264 video and AAC audio, shown in 720p: magnaluna publishes the livestream itself, which it can only do as an admin who can manage video chats, so only those admins can choose what it plays there.

When a file can only be played (or is played better) in the other kind of call, for example an MP4 file whose AAC audio video chats can't play, an admin who can manage video chats can make magnaluna end the call and start it again as the other kind, by sending the same command (magnaluna needs the same right). Anyone else is told how to do it.

The admins of a group or channel can also keep its video chat to themselves with `/adminonly on` (and open it to everyone again with `/adminonly off`).

## Playlist commands

| Command | |
|---|---|
| `/play` | Reply to a video or audio file, or send `/play <URL>` (even of a YouTube video or live stream), to add it to the playlist |
| `/skip` | Skip the file being played |
| `/loop` | Reply to a file, or send `/loop <URL>`, to loop it instead of the default video when the playlist is empty; just `/loop` loops nothing, `/loop default` loops the default video again |
| `/join`, `/videochat` | Join (or start) the video chat of the group |
| `/leave` | Leave the call |
| `/adminonly on\|off` | As an admin, let only the admins of the group or channel change what is played in its video chat, or everyone again |

The commands apply to the call of the chat they're sent in: the video chat of the group, or the one-to-one call in the private chat.
To control another video chat, livestream or conference call without sending them in its chat, put its group or channel (username, link or ID) or its link right after the command, for example:

```
/join @mygroup
/play @mygroup https://youtu.be/dQw4w9WgXcQ
/loop https://t.me/call/... default
/leave @mygroup
/adminonly @mygroup on
```

`/play` and `/loop` join the call first, if needed.
The commands can also be sent in the chat of a video chat or conference call, where `/play` and `/loop` only take URLs.

## What it plays

Telegram files are streamed, never downloaded, and so are URLs:

- in video chats, conference calls and one-to-one calls: WebM or Matroska files with VP8, VP9, H.264 or H.265 video and OPUS audio, played as they are; with ffmpeg installed, any other audio file, and just the audio of any other video;
- in RTMP livestreams: MP4 or Matroska files with H.264 video and AAC audio.

YouTube videos are played in up to 1080p in calls (VP9 and OPUS) and 720p in livestreams (H.264 and AAC), and YouTube live streams too (in calls, just their video, as YouTube only serves them with AAC audio).
This is all done in pure PHP (see the `Magnaluna` namespace in `magna.php`): like yt-dlp, the formats are got with the visionOS InnerTube client and a visitor ID, which need no JavaScript player nor PO token; video and audio are fetched a range (or a live segment) at a time, and muxed into a single file on the fly.
Playback is resumable: when magnaluna restarts, a video resumes where it was, and a live stream from its live edge.

## One-to-one calls

Call magnaluna, or send it `/call` to make it call you (disable your call privacy settings first), or `/program <time>` to schedule a call (for example `/program +1 hour 30 minutes` or `/program next Thursday`).
During the call, it shows the number of running calls and the emoji key of the call in a status message, and `/play`, `/skip` and `/loop` in the private chat change what it plays.

## Installation

```
wget https://github.com/danog/magnaluna/raw/master/magna.php && php magna.php
```

Don't forget to install the [required dependencies](https://docs.madelineproto.xyz/docs/REQUIREMENTS.html) for MadelineProto.

## Changing the video

magnaluna loops `oncall.webm` in calls and video chats, and `oncall.mp4` in RTMP livestreams (both downloaded on first start).
Replace `oncall.webm` with any WebM/Matroska file with VP8, VP9, H.264 or H.265 video and OPUS audio, and `oncall.mp4` with any MP4 file with H.264 video and AAC audio (in 16:9, as Telegram stretches livestreams to 1280x720).
