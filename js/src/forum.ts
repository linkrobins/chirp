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

  // The live bar in the discussion hero.
  extend('flarum/forum/components/DiscussionHero', 'items', function (this: any, items: any) {
    const discussion = this.attrs.discussion;
    if (discussion?.attribute?.('chirpIsLive')) {
      items.add('chirp-bar', m(ChirpBar, { discussion, state }), 5);
    }
  });

  // "Go live" in the discussion controls for people who hold chirpStart.
  // NB: DiscussionControls is a plain util OBJECT — extend it directly (the
  // flarum/lock idiom); the string-path form assumes a class prototype and
  // crashes the whole initializer ("failed to initialize" toast).
  extend(DiscussionControls, 'moderationControls', function (this: any, items: any, discussion: any) {
    if (!discussion?.attribute?.('canChirpStart') || discussion.attribute('chirpIsLive')) return;

    items.add(
      'chirp-go-live',
      m(
        Button,
        {
          icon: 'fas fa-microphone',
          onclick: () =>
            app
              .request<any>({
                method: 'POST',
                url: `${app.forum.attribute('apiUrl')}/chirp/rooms`,
                body: { discussionId: Number(discussion.id()) },
              })
              .then(async (res) => {
                discussion.pushAttributes({ chirpIsLive: true });
                await state.connect(Number(discussion.id()), res.endpoint, res.token, true);
                m.redraw();
              }),
        },
        app.translator.trans('linkrobins-chirp.forum.go_live')
      )
    );
  });
});
