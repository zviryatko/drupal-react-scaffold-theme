import { waitFor } from '@testing-library/react';
import '../helpers';

// jsdom has no layout, so visibility is simulated through getClientRects().
const show = (el) => { el.getClientRects = () => [{}]; };
const hide = (el) => { el.getClientRects = () => []; };

describe('executeWhenVisible()', () => {
  beforeEach(() => {
    document.body.innerHTML = '<div id="parent"><div id="el"></div></div>';
    window.Drupal = { attachBehaviors: jest.fn(), detachBehaviors: jest.fn() };
    window.once = { remove: jest.fn() };
    // Real browsers lay out <html> and <body>.
    show(document.documentElement); show(document.body);
  });

  it('runs the callback right away for a visible element and attaches behaviors', () => {
    const el = document.getElementById('el');
    show(el);
    const callback = jest.fn();
    executeWhenVisible(el, callback, 'test');
    expect(callback).toHaveBeenCalledWith(el);
    expect(window.Drupal.attachBehaviors).toHaveBeenCalledWith(el);
    // Core binds ajax links once on body, the helper clears the guard so new links bind.
    expect(window.once.remove).toHaveBeenCalledWith('ajax', 'body');
  });

  it('waits until the hidden ancestor becomes visible', async () => {
    const parent = document.getElementById('parent');
    const el = document.getElementById('el');
    hide(parent); hide(el);
    const callback = jest.fn();
    executeWhenVisible(el, callback, 'test');
    expect(callback).not.toHaveBeenCalled();

    show(parent); show(el);
    parent.setAttribute('class', 'open');
    await waitFor(() => expect(callback).toHaveBeenCalledWith(el));
  });

  it('observes a hidden ancestor once per id', () => {
    const parent = document.getElementById('parent');
    const el = document.getElementById('el');
    hide(parent); hide(el);
    const observe = jest.spyOn(MutationObserver.prototype, 'observe');
    executeWhenVisible(el, jest.fn(), 'same');
    executeWhenVisible(el, jest.fn(), 'same');
    expect(observe).toHaveBeenCalledTimes(1);
    observe.mockRestore();
  });
});

describe('detachBehaviors()', () => {
  it('removes data-once before detaching so copied markup can be attached again', () => {
    window.Drupal = { attachBehaviors: jest.fn(), detachBehaviors: jest.fn() };
    document.body.innerHTML = '<div id="root"><a data-once="ajax"></a></div>';
    const root = document.getElementById('root');
    detachBehaviors(root);
    expect(root.querySelector('[data-once]')).toBeNull();
    expect(window.Drupal.detachBehaviors).toHaveBeenCalled();
  });
});
