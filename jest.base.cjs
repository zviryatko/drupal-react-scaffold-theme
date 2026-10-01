// Shared Jest configuration for the React Scaffold base theme and subthemes.
//   jest.config.cjs:  module.exports = require('react-scaffold/jest')(__dirname);
// Babel presets are resolved from the base theme, so subthemes need no .babelrc.
const path = require('path');

module.exports = (rootDir, overrides = {}) => ({
  rootDir,
  testEnvironment: path.dirname(require.resolve('jest-environment-jsdom/package.json')),
  moduleFileExtensions: ['js', 'jsx', 'json'],
  // Test libraries (@testing-library/*) are installed with the base theme, make them importable from subthemes.
  modulePaths: [path.dirname(path.dirname(require.resolve('react/package.json')))],
  moduleNameMapper: {
    '^Components(.*)$': '<rootDir>/components$1',
    '\\.(css|scss)$': path.join(__dirname, 'test/style-mock.cjs'),
    // One React for tests, the base theme's, even if a subtheme dependency (rsuite...) installed another copy.
    '^react$': require.resolve('react'),
    '^react/(.*)$': path.join(path.dirname(require.resolve('react/package.json')), '$1'),
    '^react-dom$': require.resolve('react-dom'),
    '^react-dom/(.*)$': path.join(path.dirname(require.resolve('react-dom/package.json')), '$1'),
  },
  setupFilesAfterEnv: [path.join(__dirname, 'test/setup.js')],
  transform: {
    '^.+\\.[jt]sx?$': [
      require.resolve('babel-jest'),
      {
        babelrc: false,
        configFile: false,
        presets: [
          [require.resolve('@babel/preset-env'), { targets: { node: 'current' } }],
          [require.resolve('@babel/preset-react'), { runtime: 'classic' }],
        ],
      },
    ],
  },
  testPathIgnorePatterns: ['/node_modules/', '/assets/'],
  ...overrides,
});
