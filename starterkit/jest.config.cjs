const path = require('node:path');
const baseTheme = require('./base-theme.cjs');

module.exports = require(path.join(baseTheme, 'jest.base.cjs'))(__dirname);
