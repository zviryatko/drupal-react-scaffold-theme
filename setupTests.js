import React from "react";
import "@testing-library/jest-dom";

// Make React available in all test files without importing
global.React = React;

// Make Drupal and drupalSettings available in all test files without importin
global.drupalSettings = {
  user: {
    uid: 0,
  },
  editor: {
    formats: {},
  },
};
global.Drupal = {
  editors: {
    ckeditor: {},
  },
  t: (text) => text,
};
global.CKEDITOR = null;

global.jQuery = jest.fn();
global.jQuery.param = jest.fn();

global.matchMedia =
  global.matchMedia ||
  function () {
    return {
      addListener: jest.fn(),
      removeListener: jest.fn(),
    };
  };
