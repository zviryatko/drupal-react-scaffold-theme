const baseConfig = require("./webpack.common.js");
const CopyWebpackPlugin = require("copy-webpack-plugin");

const config = {
  ...baseConfig,
  mode: "development",
  watch: true,
  devtool: "eval-source-map",

  plugins: baseConfig.plugins.concat([
    // Copy React libraries:
    new CopyWebpackPlugin({
      patterns: [
        {
          from: "node_modules/react/umd/react.development.js",
          to: "react/react.js",
        },
        {
          from: "node_modules/react-dom/umd/react-dom.development.js",
          to: "react/react-dom.js",
        },
      ]
    }),
  ]),

  watchOptions: {
    poll: 500,
    ignored: ["node_modules/**", "assets/**"],
  },
};

module.exports = config;
