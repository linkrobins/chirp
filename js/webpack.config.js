const config = require('flarum-webpack-config');

const base = config();

// Two deliberate deviations from the stock Flarum webpack config:
//
// 1. `mithril` as an external mapped to the global `m`. flarum-webpack-config
//    does NOT externalize it, so `import m from 'mithril'` bundles a SECOND
//    Mithril instance whose m.redraw() doesn't touch Flarum's mounted root —
//    state updates silently never repaint (the join button sat on its spinner
//    forever even though the room had connected).
//
// 2. No code-splitting: Flarum publishes only the named entry bundles, so any
//    extra chunk (e.g. livekit-client) 404s at runtime.
module.exports = {
  ...base,
  externals: [...(Array.isArray(base.externals) ? base.externals : [base.externals]), { mithril: 'm' }],
  optimization: {
    ...(base.optimization || {}),
    splitChunks: false,
    runtimeChunk: false,
  },
};
