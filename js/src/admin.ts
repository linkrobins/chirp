import app from 'flarum/admin/app';
import m from 'mithril';

// Chirp admin: one field. The owner pastes their channel key from the
// linkrobins.com dashboard and the extension exchanges it for the channel's
// connection config server-side. A plain-language status banner tells a
// no-experience user exactly where things stand.
app.initializers.add('linkrobins-chirp', () => {
  const banner = (): m.Children => {
    const t = (k: string) => app.translator.trans('linkrobins-chirp.admin.' + k);

    // Settings arrive as STRINGS ('1'/'0'); compare strictly against '1'.
    const connected = app.data.settings['linkrobins-chirp.connected'] === '1';

    return m(
      'div',
      { className: connected ? 'Alert Alert--success' : 'Alert', style: 'margin-bottom:16px;' },
      connected ? t('connected') : t('disconnected')
    );
  };

  app.registry
    .for('linkrobins-chirp')
    .registerSetting(banner, 100)
    .registerSetting({
      setting: 'linkrobins-chirp.channel-key',
      label: app.translator.trans('linkrobins-chirp.admin.key_label'),
      help: app.translator.trans('linkrobins-chirp.admin.key_help'),
      type: 'text',
    })
    .registerPermission(
      {
        icon: 'fas fa-microphone',
        label: app.translator.trans('linkrobins-chirp.forum.go_live'),
        permission: 'discussion.chirpStart',
      },
      'moderate'
    )
    .registerPermission(
      {
        icon: 'fas fa-microphone-lines',
        label: app.translator.trans('linkrobins-chirp.forum.take_mic'),
        permission: 'discussion.chirpSpeak',
      },
      'reply'
    );
});
