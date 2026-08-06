const config = require('flarum-webpack-config');

// Single-chunk build. Flarum publishes only the named entry bundles, so any
// code-split chunk (e.g. livekit-client) 404s in production — force webpack
// to inline everything into forum.js/admin.js.
module.exports = {
  ...config(),
  optimization: {
    ...(config().optimization || {}),
    splitChunks: false,
    runtimeChunk: false,
  },
};
