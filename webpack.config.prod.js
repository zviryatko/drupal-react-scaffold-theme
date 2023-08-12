const baseConfig = require("./webpack.common.js");
const CopyWebpackPlugin = require("copy-webpack-plugin");
const CssMinimizerPlugin = require("css-minimizer-webpack-plugin");
const TerserPlugin = require("terser-webpack-plugin");

const config = {
  ...baseConfig,
  mode: "production",

  plugins: baseConfig.plugins.concat([
    // Copy React libraries:
    new CopyWebpackPlugin({
      patterns: [
        {
          from: "node_modules/react/umd/react.production.min.js",
          to: "react/react.js",
        },
        {
          from: "node_modules/react-dom/umd/react-dom.production.min.js",
          to: "react/react-dom.js",
        },
      ],
    }),
  ]),

  // This optimization block is called only in PRODUCTION mode:
  optimization: {
    minimize: true,
    minimizer: [
      new TerserPlugin({
        parallel: true,
      }),
      new CssMinimizerPlugin(),
    ],
  },
};

module.exports = config;
