import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import m from 'mithril';

/**
 * Admin management for designated voice channels: the standing Discord-style
 * rooms. Designate by discussion ID (the number in the discussion URL);
 * remove any time — the discussion itself is untouched either way. Rendered
 * inside the extension's settings page via registerSetting.
 */
export default class ChirpChannelsPanel extends Component {
  private channels: { discussionId: number; title: string; slug: string }[] = [];
  private loading = true;
  private busy = false;
  private input = '';

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

  private async designate(): Promise<void> {
    // Accept a bare ID or a pasted discussion URL (…/d/123-some-slug).
    const match = this.input.match(/\/d\/(\d+)/) || this.input.match(/^\s*(\d+)\s*$/);
    const id = match ? Number(match[1]) : 0;
    if (!id || this.busy) return;

    this.busy = true;
    try {
      await app.request({
        method: 'POST',
        url: `${this.apiUrl()}/chirp/rooms`,
        body: { discussionId: id, mode: 'persistent' },
      });
      this.input = '';
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
                  m('a.ChirpChannels-title', { href: `${app.forum?.attribute?.('baseUrl') || ''}/d/${c.discussionId}-${c.slug}`, target: '_blank' }, c.title),
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
        m('input.FormControl', {
          placeholder: String(t('channels_placeholder')),
          value: this.input,
          oninput: (e: Event) => (this.input = (e.target as HTMLInputElement).value),
          onkeydown: (e: KeyboardEvent) => { if (e.key === 'Enter') { e.preventDefault(); void this.designate(); } },
        }),
        m(
          Button,
          { className: 'Button Button--primary', loading: this.busy, disabled: !this.input.trim(), onclick: () => this.designate() },
          t('channels_add')
        ),
      ]),
    ]);
  }
}
