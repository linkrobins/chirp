import { extend } from 'flarum/common/extend';
import app from 'flarum/forum/app';
import Button from 'flarum/common/components/Button';
import DiscussionControls from 'flarum/forum/utils/DiscussionControls';
import m from 'mithril';

import ChirpState from './ChirpState';
import ChirpBar from './components/ChirpBar';
import ChirpDock from './components/ChirpDock';
import ChirpRecordingBar from './components/ChirpRecordingBar';
import ChirpRoomStartedNotification from './components/ChirpRoomStartedNotification';

// One connection for the whole SPA session — you can be in one room at a time,
// and audio keeps playing while you browse elsewhere on the forum.
const state = new ChirpState();

app.initializers.add('linkrobins-chirp', () => {
  // Followers hear about rooms opening.
  app.notificationComponents.chirpRoomStarted = ChirpRoomStartedNotification as any;
  extend('flarum/forum/components/NotificationGrid', 'notificationTypes', function (this: any, items: any) {
    items.add('chirpRoomStarted', {
      name: 'chirpRoomStarted',
      icon: 'fas fa-microphone',
      label: app.translator.trans('linkrobins-chirp.forum.settings.notify_room_started_label'),
    });
  });

  // Discussion rows are frozen by Flarum's SubtreeRetainer unless their
  // tracked data changes, so the chip would never repaint when you join or
  // leave. Register the bits of room state the chip renders from.
  extend('flarum/forum/components/DiscussionListItem', 'oninit', function (this: any) {
    this.subtree?.check?.(
      () => state.discussionId,
      () => state.connecting
    );
  });

  // The full room toolbar rides in the list row itself — below the title,
  // tags and last-reply line — so you can listen straight from the index.
  extend('flarum/forum/components/DiscussionListItem', 'view', function (this: any, vnode: any) {
    const discussion = this.attrs.discussion;
    if (!discussion?.attribute?.('chirpIsLive')) return;

    // Append to the ROW (not its inner content box): a plain block child of
    // the row inherits the row's own padding box on both sides, so the
    // toolbar's edges match the row exactly with no tuned margins. Inside the
    // content box it would inherit that box's extra right padding instead and
    // stop short of the row's edge.
    if (!vnode || !Array.isArray(vnode.children)) return;

    // Flag the row so it can reserve space beneath the toolbar (a margin on
    // the toolbar itself collapses out of the row, leaving its background
    // flush against the bar).
    vnode.attrs = vnode.attrs || {};
    vnode.attrs.className = `${vnode.attrs.className || ''} has-chirp-room`.trim();

    vnode.children.push(m(ChirpBar, { discussion, state, inline: true }));
  });

  // Accent mode rides on <html> so it reaches the dock too (which mounts
  // outside the SPA root). 'forum' adopts the forum's Appearance colors via
  // the html.chirp-blend overrides in forum.less; default is Chirp brand.
  // ⚠️ app.forum is NOT populated yet while initializers run — touching it
  // here throws and takes the whole initializer (bar, chip, dock) down with
  // it. The boot payload IS loaded, so read the serialized attribute raw.
  const forumAttrs = (app.data?.resources as any[] | undefined)?.find((r) => r?.type === 'forums')?.attributes;
  document.documentElement.classList.toggle('chirp-blend', forumAttrs?.chirpAppearance === 'forum');

  // The dock lives OUTSIDE the SPA root so listening survives navigation.
  const dock = document.createElement('div');
  dock.id = 'chirp-dock';
  document.body.appendChild(dock);
  m.mount(dock, { view: () => m(ChirpDock, { state }) });

  // The live bar sits ABOVE THE POST STREAM, not in the hero: the hero renders
  // its items (tags, title, badges) in one <ul>, so a bar added there lines up
  // beside the tag chips and looks wedged in. Here it gets its own full-width
  // row directly over the conversation it belongs to. When the room is over,
  // the SAME spot holds the recording bar — where the room was, the recording
  // remains (the live bar owns the spot while a room is actually on).
  extend('flarum/forum/components/DiscussionPage', 'view', function (this: any, vnode: any) {
    const discussion = this.discussion;
    if (!discussion || !vnode || !Array.isArray(vnode.children)) return;

    if (discussion.attribute?.('chirpIsLive')) {
      vnode.children.unshift(m(ChirpBar, { discussion, state }));
      return;
    }

    const recordings = discussion.attribute?.('chirpRecordings') || [];
    if (recordings.length) {
      vnode.children.unshift(m(ChirpRecordingBar, { recordings, discussion }));
    }
  });

  // "Go live" in the discussion controls for people who hold chirpStart.
  // NB: DiscussionControls is a plain util OBJECT — extend it directly (the
  // flarum/lock idiom); the string-path form assumes a class prototype and
  // crashes the whole initializer ("failed to initialize" toast).
  extend(DiscussionControls, 'moderationControls', function (this: any, items: any, discussion: any) {
    if (!discussion?.attribute?.('canChirpStart') || discussion.attribute('chirpIsLive')) return;

    // Channel already live somewhere else? Say so up front instead of letting
    // them click into a 409 (core renders a generic 'something went wrong'
    // for anything but 422/401/403/404/413/429, so our own copy has to do it).
    const liveElsewhere = Number(app.forum.attribute('chirpLiveDiscussionId') || 0);

    const start = (mode: 'live' | 'persistent') => {
      if (liveElsewhere && liveElsewhere !== Number(discussion.id())) {
        app.alerts.show({ type: 'error' }, app.translator.trans('linkrobins-chirp.forum.channel_busy_elsewhere'));
        return;
      }

      app
        .request<any>({
          method: 'POST',
          url: `${app.forum.attribute('apiUrl')}/chirp/rooms`,
          body: { discussionId: Number(discussion.id()), mode },
          // Own the error surface: map our typed 409s to real sentences.
          errorHandler: (error: any) => {
            const code = error?.response?.errors?.[0]?.code;
            const key = code === 'chirp_channel_busy' ? 'channel_busy_elsewhere' : code === 'chirp_not_configured' ? 'not_configured' : null;
            if (!key) throw error; // anything unexpected keeps core's handling
            app.alerts.show({ type: 'error' }, app.translator.trans(`linkrobins-chirp.forum.${key}`));
          },
        })
        .then(async (res) => {
          if (!res) return;
          state.describe(String(discussion.title()), app.route.discussion(discussion));
          discussion.pushAttributes({ chirpIsLive: true, chirpRoomMode: mode });
          app.forum.pushAttributes({ chirpLiveDiscussionId: Number(discussion.id()) });
          await state.connect(Number(discussion.id()), res.endpoint, res.token, true);
          m.redraw();
        });
    };

    items.add(
      'chirp-go-live',
      m(Button, { icon: 'fas fa-microphone', onclick: () => start('live') }, app.translator.trans('linkrobins-chirp.forum.go_live'))
    );

    // The Discord-shaped sibling: a channel that stays open until the host
    // closes it — a place, not a show. Never recorded.
    items.add(
      'chirp-open-voice',
      m(Button, { icon: 'fas fa-headphones', onclick: () => start('persistent') }, app.translator.trans('linkrobins-chirp.forum.open_voice'))
    );
  });
});
