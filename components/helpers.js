import {forwardRef} from "react";

const drupalSettings = window.drupalSettings || {};
const Drupal = window.Drupal || {};

// Execute callback when element become visible.
// Algorithm is simple: run callback if element is visible
// Get first hidden parent and attach mutation observer
// When parent become visible run check again
// Execute passed callback when element become visible.
window.executeWhenVisible = function(element, callback, id) {
  const $element = window.jQuery(element);
  if ($element.is(":visible")) {
    callback(element);
    attachBehaviors(element);
  }
  else {
    // If component is initially hidden wait until it will be visible.
    const $firstHiddenParent = $element.parents(":hidden").last();
    if (!$firstHiddenParent.data(`wait-for-${id}`)) {
      $firstHiddenParent.data(`wait-for-${id}`, true);
      const observer = new MutationObserver(() => {
        $firstHiddenParent.removeData(`wait-for-${id}`);
        observer.disconnect();
        executeWhenVisible(element, callback, id);
      });
      observer.observe($firstHiddenParent.get(0), {attributes: true});
    }
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
  once.remove('ajax', 'body');
  Drupal.attachBehaviors(element);
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
  Drupal.detachBehaviors(element, drupalSettings, 'unload');
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
        attachBehaviors(el, drupalSettings)
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
