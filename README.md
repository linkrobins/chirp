# Chirp — live audio for Flarum

[![Latest Stable Version](https://img.shields.io/packagist/v/linkrobins/flarum-chirp.svg)](https://packagist.org/packages/linkrobins/flarum-chirp)

Live audio built into your forum, in two shapes:

- **Live shows** — go live on any discussion. Speakers take the stage,
  unlimited listeners tune in with one click (guests included), and the
  thread itself is the chat. When the show ends, the discussion remains —
  nothing about the conversation is ephemeral.
- **Voice channels** — standing rooms your admins designate on a discussion:
  always open, joining means speaking, members drop in and out all day. They
  never block going live elsewhere.

Built to replace the Twitter-Spaces / Discord-voice pattern with something
your community actually keeps.

## What's in the box

- **Scheduled shows** — announce "going live Friday 8pm": followers get a
  notification, the discussion carries a live countdown (in each reader's own
  timezone) until you go live.
- **Live threads during sessions** — everyone in a room sees new replies
  appear in the thread instantly while the audio runs. Built in, no realtime
  extension required.
- **Recordings** (channel add-on) — every show recorded, posted into the
  discussion when the room ends, stored on *your* forum, never on ours.
  Deleting them is its own permission, behind a confirmation.
- **Host tools** — speaker policies per show (open stage, raise-hand with an
  approval queue, author-only), a participants modal with remove-from-stage
  and remove-from-room for shows, mute and remove for voice channels, and a
  hand-raise badge on the toolbar so the host never misses a request.
- **Notifications** — followers hear when a room opens or gets scheduled;
  alert by default, email opt-in per user.
- **Resilience** — sessions survive page reloads (automatic rejoin), a
  reconnecting badge covers network blips, and a "You're muted" hint appears
  when someone talks into a dead mic. Soft join/leave sound cues, generated
  in the browser, no assets.
- **Fits your theme** — Chirp's own accents by default, or one admin switch
  to adopt your forum's appearance colors.

## How it works

- A **channel** is a dedicated hosted audio server from
  [linkrobins.com](https://linkrobins.com/chirp) — $10/mo flat, up to 50 on
  stage at once, unlimited listeners, never metered.
- Install this extension, paste your **channel key** in the admin settings —
  that's the entire setup. No media servers, ports, or configuration.
- *Go live* and *Take the mic* are normal Flarum permissions (moderators and
  members by default). Anyone who can see the discussion can listen.
- One live show at a time per channel — add more channels for simultaneous
  shows. Voice channels are standing rooms and don't count against that.

## Installation

```sh
composer require linkrobins/flarum-chirp
```

Requires Flarum `^2.0`. The extension is free and open source; it connects to
your paid Chirp channel and does nothing without one.

## Note on the build

Everything is bundled into `js/dist/forum.js` (no code-splitting): Flarum
publishes only the named entry bundles, so any additional webpack chunk 404s
at runtime. `js/webpack.config.js` disables `splitChunks` to enforce this.

## Links

- [Get a channel](https://linkrobins.com/chirp)
- [Forum & support](https://linkrobins.com/forum)
