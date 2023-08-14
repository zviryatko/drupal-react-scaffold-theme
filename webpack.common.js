const path = require("path");
const magicImporter = require("node-sass-magic-importer");
const MiniCssExtractPlugin = require("mini-css-extract-plugin");
const {CleanWebpackPlugin} = require("clean-webpack-plugin");
const WebpackRequireFrom = require("webpack-require-from");
const fs = require("fs");

const rootDir = path.resolve(__dirname);
const buildDir = "assets";

const config = {
  entry: {
    // All assets needed on all pages are compiled in a common:
    common: "./components/common.js",
    // All assets loaded only in some pages will have their own bundle:
    helpers: "./components/helpers.js",
    apiClient: "./components/apiClient.js",
    // Iterate over each directory in "components" and create a bundle for each:
    ...Object.fromEntries(
      fs
        .readdirSync(path.resolve(__dirname, "components"))
        .filter((dir) => fs.lstatSync(path.resolve(__dirname, "components", dir)).isDirectory())
        .map((dir) => [dir, `./components/${dir}/index.js`])
    ),
  },

  output: {
    path: rootDir + "/" + buildDir,
    filename: "[name].js",
    assetModuleFilename: "[name][ext]",
  },

  module: {
    rules: [
      {
        test: /\.(sa|sc|c)ss$/,
        exclude: /node_modules/,
        use: [
          MiniCssExtractPlugin.loader,
          "css-loader",
          {
            loader: "postcss-loader",
            options: {
              postcssOptions: {
                plugins: ["autoprefixer"],
              },
            },
          },
          {
            loader: "sass-loader",
            options: {
              sassOptions: {
                // Custom node-sass importer for selector specific imports,
                // node importing, module importing, globbing support and
                // importing files only once. Check the numerous and
                // interesting options here:
                // https://www.npmjs.com/package/node-sass-magic-importer
                importer: magicImporter(),
              },
            },
          },
        ],
      },
      {
        test: /\.(js|jsx)$/,
        type: "javascript/auto",
        exclude: /node_modules/,
        use: {loader: "babel-loader"},
        resolve: {
          fullySpecified: false,
        },
      },
      {
        test: /\.(png|jp(e*)g|svg)$/,
        exclude: /node_modules/,
        type: "asset",
        parser: {
          dataUrlCondition: {
            maxSize: 8 * 1024, // 8kb
          },
        },
      },
    ],
  },

  resolve: {
    extensions: [".js", ".jsx"],
    alias: {
      Components: path.resolve(__dirname, "components/"),
    },
  },

  externals: {
    "react": "React",
    "react-dom": "ReactDOM",
  },

  plugins: [
    // Clean assets folder before every operation:
    new CleanWebpackPlugin({
      cleanStaleWebpackAssets: false,
    }),

    new WebpackRequireFrom({
      path: "./react_scaffold/assets/",
    }),
    // Compile styles no inlined into the JS bundle, but in a separate CSS file:
    new MiniCssExtractPlugin(),
  ],
};

module.exports = config;
