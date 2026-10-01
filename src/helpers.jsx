import {forwardRef} from "react";

// Drupal, once and drupalSettings are read from window when called, not captured at load time.

// Execute callback when element become visible.
// Algorithm is simple: run callback if element is visible
// Get outermost hidden parent and attach mutation observer
// When parent become visible run check again
// Execute passed callback when element become visible.
const isVisible = (element) => element.getClientRects().length > 0;
const waiting = new WeakMap();

window.executeWhenVisible = function(element, callback, id) {
  if (isVisible(element)) {
    callback(element);
    attachBehaviors(element);
    return;
  }
  // If component is initially hidden wait until it will be visible.
  let hiddenParent = null;
  for (let parent = element.parentElement; parent; parent = parent.parentElement) {
    if (!isVisible(parent)) {
      hiddenParent = parent;
    }
  }
  if (!hiddenParent) {
    return;
  }
  const flags = waiting.get(hiddenParent) || new Set();
  if (!flags.has(id)) {
    flags.add(id);
    waiting.set(hiddenParent, flags);
    const observer = new MutationObserver(() => {
      flags.delete(id);
      observer.disconnect();
      executeWhenVisible(element, callback, id);
    });
    observer.observe(hiddenParent, {attributes: true});
  }
}

/**
 * Attach drupal behaviors.
 *
 * @param {HTMLDocument|HTMLElement} element
 */
window.attachBehaviors = function(element) {
  // Remove once() from document.body to force attaching ajax links again.
  // See ajax.es6.js and `Drupal.ajax.bindAjaxLinks(document.body);`
  window.once.remove('ajax', 'body');
  window.Drupal.attachBehaviors(element);
}

/**
 * Detach drupal behaviors.
 *
 * @param {HTMLDocument|HTMLElement} element
 */
window.detachBehaviors = function(element) {
  // Removes [data-once] to prevent ignoring by drupal behaviors when copying
  // element to react component.
  element
    .querySelectorAll('[data-once]')
    .forEach(item => item.removeAttribute('data-once'));
  window.Drupal.detachBehaviors(element, window.drupalSettings, 'unload');
}

/**
 * Detach and then attach drupal behaviors.
 *
 * @param {HTMLDocument|HTMLElement} element
 */
window.reattachBehaviors = function(element) {
  detachBehaviors(element);
  attachBehaviors(element);
}

/**
 * Attach drupal behaviors to react component.
 *
 * @param WrappedComponent
 */
window.withDrupalBehaviors = function(WrappedComponent) {
  return class extends React.Component {
    constructor(props) {
      super(props);
      this.refToElemWithBehaviors = React.createRef();
    }

    componentDidMount() {
      const el = this.refToElemWithBehaviors.current;
      // Using a timeout because of a race with drupal's ajax.js
      setTimeout(function () {
        attachBehaviors(el)
      }, 1);
    }

    render() {
      return <WrappedComponent
        ref={this.refToElemWithBehaviors} {...this.props} />;
    }
  }
}

/**
 * Render raw html as component.
 * @param html
 * @returns {JSX.Element}
 */
window.rawHtml = function(html) {
  if (!isHtml(html)) {
    return html;
  }
  const CustomRef = forwardRef(({html}, ref) => (
    <div ref={ref} dangerouslySetInnerHTML={{__html: html}}/>
  ))
  const Custom = withDrupalBehaviors(CustomRef)
  return <Custom html={html}/>
}

const isHtml = (str) => {
  let a = document.createElement('div');
  a.innerHTML = str;

  for (let c = a.childNodes, i = c.length; i--;) {
    if (c[i].nodeType === 1) {
      return true;
    }
  }

  return false;
}
