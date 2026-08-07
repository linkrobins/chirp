import { extend } from 'flarum/common/extend';
import app from 'flarum/forum/app';
import Button from 'flarum/common/components/Button';
import DiscussionControls from 'flarum/forum/utils/DiscussionControls';
import m from 'mithril';

import ChirpState from './ChirpState';
import ChirpBar from './components/ChirpBar';

// One connection for the whole SPA session — you can be in one room at a time,
// and audio keeps playing while you browse elsewhere on the forum.
const state = new ChirpState();

app.initializers.add('linkrobins-chirp', () => {
  // LIVE badge on the discussion list.
  extend('flarum/forum/components/DiscussionListItem', 'infoItems', function (this: any, items: any) {
    const discussion = this.attrs.discussion;
    if (discussion?.attribute?.('chirpIsLive')) {
      items.add('chirp-live', m('span.ChirpBadge', app.translator.trans('linkrobins-chirp.forum.live_badge')), 100);
    }
  });

  // The live bar sits ABOVE THE POST STREAM, not in the hero: the hero renders
  // its items (tags, title, badges) in one <ul>, so a bar added there lines up
  // beside the tag chips and looks wedged in. Here it gets its own full-width
  // row directly over the conversation it belongs to.
  extend('flarum/forum/components/DiscussionPage', 'view', function (this: any, vnode: any) {
    const discussion = this.discussion;
    if (!discussion?.attribute?.('chirpIsLive')) return;
    if (!vnode || !Array.isArray(vnode.children)) return;

    vnode.children.unshift(m(ChirpBar, { discussion, state }));
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

    items.add(
      'chirp-go-live',
      m(
        Button,
        {
          icon: 'fas fa-microphone',
          onclick: () => {
            if (liveElsewhere && liveElsewhere !== Number(discussion.id())) {
              app.alerts.show({ type: 'error' }, app.translator.trans('linkrobins-chirp.forum.channel_busy_elsewhere'));
              return;
            }

            app
              .request<any>({
                method: 'POST',
                url: `${app.forum.attribute('apiUrl')}/chirp/rooms`,
                body: { discussionId: Number(discussion.id()) },
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
                discussion.pushAttributes({ chirpIsLive: true });
                app.forum.pushAttributes({ chirpLiveDiscussionId: Number(discussion.id()) });
                await state.connect(Number(discussion.id()), res.endpoint, res.token, true);
                m.redraw();
              });
          },
        },
        app.translator.trans('linkrobins-chirp.forum.go_live')
      )
    );
  });
});
