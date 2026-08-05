# Chirp — live audio rooms for Flarum

[![Latest Stable Version](https://img.shields.io/packagist/v/linkrobins/flarum-chirp.svg)](https://packagist.org/packages/linkrobins/flarum-chirp)

Attach a **live audio stage to any discussion**: unlimited listeners, speakers
on stage up to your channel's slots, and the thread itself is the chat. When
the room ends, the discussion remains — nothing about the conversation is
ephemeral. Built to replace the Twitter-Spaces / Discord-stage pattern with
something your community actually keeps.

## How it works

- A **channel** is a dedicated hosted audio server from
  [linkrobins.com](https://linkrobins.com) ($10/mo, unlimited listeners).
- Install this extension, paste your **channel key** in the admin settings —
  that's the entire setup.
- Anyone with the *Go live* permission (moderators by default) can start a
  room on a discussion. Members with the *Take the mic* permission can join
  the stage, up to your channel's speaker slots. Everyone else — including
  guests, if they can see the discussion — listens in one click.
- One room live at a time per channel; add more channels for simultaneous
  rooms.

## Installation

```sh
composer require linkrobins/flarum-chirp
```

Requires Flarum `^2.0`. The extension is free and open source; it connects to
your paid Chirp channel and does nothing without one.

## Links

- [Get a channel](https://linkrobins.com/chirp)
- [Forum & support](https://linkrobins.com/forum)
