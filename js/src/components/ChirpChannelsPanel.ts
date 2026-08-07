import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import m from 'mithril';

interface Channel {
  discussionId: number;
  title: string;
  slug: string;
}

interface Suggestion {
  id: number;
  title: string;
}

/**
 * Admin management for designated voice channels: the standing Discord-style
 * rooms. Pick the discussion from a search-as-you-type dropdown (core's
 * discussion search filter); remove any time — the discussion itself is
 * untouched either way. Rendered inside the extension's settings page via
 * registerSetting.
 */
export default class ChirpChannelsPanel extends Component {
  private channels: Channel[] = [];
  private loading = true;
  private busy = false;

  private query = '';
  private suggestions: Suggestion[] = [];
  private selected: Suggestion | null = null;
  private searching = false;
  private searchTimer: ReturnType<typeof setTimeout> | null = null;

  // app.forum is not reliably populated in admin — read the raw boot payload.
  private apiUrl(): string {
    const forum = (app.data.resources || []).find((r: any) => r.type === 'forums') as any;
    return forum?.attributes?.apiUrl || '/api';
  }

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    void this.reload();
  }

  private async reload(): Promise<void> {
    try {
      const res = await app.request<any>({ url: `${this.apiUrl()}/chirp/channels` });
      this.channels = res?.channels || [];
    } catch {
      this.channels = [];
    }
    this.loading = false;
    m.redraw();
  }

  private search(q: string): void {
    this.query = q;
    this.selected = null;
    if (this.searchTimer) clearTimeout(this.searchTimer);
    if (!q.trim()) {
      this.suggestions = [];
      return;
    }

    this.searchTimer = setTimeout(async () => {
      this.searching = true;
      m.redraw();
      try {
        const res = await app.request<any>({
          url: `${this.apiUrl()}/discussions`,
          params: { 'filter[q]': q.trim(), 'page[limit]': 6 },
        });
        const taken = new Set(this.channels.map((c) => c.discussionId));
        this.suggestions = (res?.data || [])
          .map((d: any) => ({ id: Number(d.id), title: String(d.attributes?.title || '#' + d.id) }))
          .filter((s: Suggestion) => !taken.has(s.id));
      } catch {
        this.suggestions = [];
      }
      this.searching = false;
      m.redraw();
    }, 300);
  }

  private pick(s: Suggestion): void {
    this.selected = s;
    this.query = s.title;
    this.suggestions = [];
  }

  private async designate(): Promise<void> {
    if (!this.selected || this.busy) return;

    this.busy = true;
    try {
      await app.request({
        method: 'POST',
        url: `${this.apiUrl()}/chirp/rooms`,
        body: { discussionId: this.selected.id, mode: 'persistent' },
      });
      this.query = '';
      this.selected = null;
      await this.reload();
    } finally {
      this.busy = false;
      m.redraw();
    }
  }

  private async remove(discussionId: number): Promise<void> {
    if (!confirm(String(app.translator.trans('linkrobins-chirp.admin.channels_confirm_remove')))) return;
    this.busy = true;
    try {
      await app.request({ method: 'DELETE', url: `${this.apiUrl()}/chirp/rooms/${discussionId}` });
      await this.reload();
    } finally {
      this.busy = false;
      m.redraw();
    }
  }

  view(): Mithril.Children {
    const t = (k: string) => app.translator.trans('linkrobins-chirp.admin.' + k);

    return m('.Form-group.ChirpChannels', [
      m('label', t('channels_label')),
      m('.helpText', t('channels_help')),

      this.loading
        ? m('p.ChirpChannels-empty', '…')
        : this.channels.length
          ? m(
              'ul.ChirpChannels-list',
              this.channels.map((c) =>
                m('li.ChirpChannels-row', { key: String(c.discussionId) }, [
                  m('a.ChirpChannels-title', { href: `${this.apiUrl().replace(/\/api$/, '')}/d/${c.discussionId}-${c.slug}`, target: '_blank' }, c.title),
                  m(
                    Button,
                    { className: 'Button Button--size-sm', loading: this.busy, onclick: () => this.remove(c.discussionId) },
                    t('channels_remove')
                  ),
                ])
              )
            )
          : m('p.ChirpChannels-empty', t('channels_none')),

      m('.ChirpChannels-add', [
        m('.ChirpChannels-pickerwrap', [
          m('input.FormControl', {
            placeholder: String(t('channels_placeholder')),
            value: this.query,
            oninput: (e: Event) => this.search((e.target as HTMLInputElement).value),
            onkeydown: (e: KeyboardEvent) => {
              if (e.key === 'Escape') {
                this.suggestions = [];
              } else if (e.key === 'Enter') {
                e.preventDefault();
                if (this.selected) void this.designate();
                else if (this.suggestions.length) this.pick(this.suggestions[0]);
              }
            },
          }),
          this.suggestions.length || this.searching
            ? m(
                'ul.ChirpChannels-suggest.Dropdown-menu',
                this.searching && !this.suggestions.length
                  ? m('li.ChirpChannels-suggestNote', { key: 'searching' }, '…')
                  : this.suggestions.map((s) =>
                      m(
                        'li',
                        { key: String(s.id) },
                        // mousedown beats the input's blur, so the pick lands.
                        m('button.ChirpChannels-suggestItem', { type: 'button', onmousedown: (e: Event) => { e.preventDefault(); this.pick(s); } }, s.title)
                      )
                    )
              )
            : null,
        ]),
        m(
          Button,
          { className: 'Button Button--primary', loading: this.busy, disabled: !this.selected, onclick: () => this.designate() },
          t('channels_add')
        ),
      ]),
    ]);
  }
}
