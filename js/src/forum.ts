import { extend } from 'flarum/common/extend';
import app from 'flarum/forum/app';
import Button from 'flarum/common/components/Button';
import DiscussionControls from 'flarum/forum/utils/DiscussionControls';
import m from 'mithril';

import ChirpState from './ChirpState';
import ChirpBar from './components/ChirpBar';
import ChirpDock from './components/ChirpDock';

// One connection for the whole SPA session — you can be in one room at a time,
// and audio keeps playing while you browse elsewhere on the forum.
const state = new ChirpState();

/** Depth-limited search for the row's content box vnode. */
function findContent(vnode: any, depth = 0): any {
  if (!vnode || depth > 4) return null;
  const cls = vnode.attrs?.className ?? vnode.attrs?.class ?? '';
  if (typeof cls === 'string' && cls.includes('DiscussionListItem-content')) return vnode;

  const kids = Array.isArray(vnode.children) ? vnode.children : [];
  for (const kid of kids) {
    const hit = findContent(kid, depth + 1);
    if (hit) return hit;
  }
  return null;
}

app.initializers.add('linkrobins-chirp', () => {
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

    // Insert INTO the row's content box (which owns the row's padding), not
    // as a sibling of it — that way the toolbar lines up with the row's own
    // edges instead of needing hand-tuned margins that drift per theme.
    const content = findContent(vnode);
    if (!content || !Array.isArray(content.children)) return;

    content.children.push(m(ChirpBar, { discussion, state, inline: true }));
  });

  // The dock lives OUTSIDE the SPA root so listening survives navigation.
  const dock = document.createElement('div');
  dock.id = 'chirp-dock';
  document.body.appendChild(dock);
  m.mount(dock, { view: () => m(ChirpDock, { state }) });

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
                state.describe(String(discussion.title()), app.route.discussion(discussion));
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
