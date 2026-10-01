import React from "react";
import "@testing-library/jest-dom";

// Make React available in all test files without importing (components use the global, like in Drupal).
global.React = React;

// Globals provided by Drupal at runtime. Override per test when needed.
global.drupalSettings = { user: { uid: 0 } };
global.Drupal = { t: (text) => text };

global.matchMedia =
  global.matchMedia ||
  function () {
    return {
      addListener: jest.fn(),
      removeListener: jest.fn(),
    };
  };
