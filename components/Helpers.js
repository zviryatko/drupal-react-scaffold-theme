import axios from "axios";

const drupalSettings = window.drupalSettings || {};
const Drupal = window.Drupal || {};


// Execute callback when element become visible.
// Algorithm is simple: run callback if element is visible
// Get first hidden parent and attach mutation observer
// When parent become visible run check again
// Execute passed callback when element become visible.
export const executeWhenVisible = (element, callback, id) => {
  const $element = window.jQuery(element);
  if ($element.is(":visible")) {
    callback(element);
    attachBehaviors(element);
  } else {
    // If component is initially hidden wait until it will be visible.
    const $firstHiddenParent = $element.parents(":hidden").last();
    if (!$firstHiddenParent.data(`wait-for-${id}`)) {
      $firstHiddenParent.data(`wait-for-${id}`, true);
      const observer = new MutationObserver(() => {
        $firstHiddenParent.removeData(`wait-for-${id}`);
        observer.disconnect();
        executeWhenVisible(element, callback, id);
      });
      observer.observe($firstHiddenParent.get(0), { attributes: true });
    }
  }
};

/**
 * Attach drupal behaviors.
 *
 * @param {HTMLDocument|HTMLElement} element
 */
export const attachBehaviors = (element) => {
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
export const detachBehaviors = (element) => {
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
export const reattachBehaviors = (element) => {
  detachBehaviors(element);
  attachBehaviors(element);
}

/**
 * Attach drupal behaviors to react component.
 *
 * @param WrappedComponent
 */
export const withDrupalBehaviors = (WrappedComponent) => {
  return class extends React.Component {
    constructor(props) {
      super(props);
      this.refToElemWithBehaviors = React.createRef();
    }
    componentDidMount() {
      const el = this.refToElemWithBehaviors.current;
      // Using a timeout because of a race with drupal's ajax.js
      setTimeout(function() {
        attachBehaviors(el, drupalSettings)
      }, 1);
    }
    render() {
      return <WrappedComponent ref={this.refToElemWithBehaviors} {...this.props} />;
    }
  }
}

/**
 * Reads URL params (query) and returns transformed object.
 */
export const getUrlParams = (defaults) => {
  const params = new URLSearchParams(window.location.search);

  return [...params].concat(defaults).reduce((acc, entry) => {
    // keep case consistent
    let key = entry[0].toLowerCase();
    // set parameter value (use 'true' if empty)
    let value = typeof entry[1] === "undefined" ? true : entry[1];
    if (key.match(/\[(\d+)?\]$/)) {
      key = key.replace(/\[(\d+)?\]/, "");
      if (!acc[key]) {
        acc[key] = [];
      }

      // if it's an indexed array e.g. colors[2]
      if (key.match(/\[\d+\]$/)) {
        // get the index value and add the entry at the appropriate position
        const index = /\[(\d+)\]/.exec(key)[1];
        acc[key][index] = value;
      } else {
        // otherwise add the value to the end of the array
        acc[key].push(value);
      }
    } else {
      // we're dealing with a string
      if (!acc[key]) {
        // if it doesn't exist, create property
        acc[key] = value;
      } else if (acc[key] && typeof acc[key] === "string") {
        // if property does exist and it's a string, convert it to an array
        acc[key] = [acc[key]];
        acc[key].push(value);
      } else {
        // otherwise add the property
        acc[key].push(value);
      }
    }

    return acc;
  }, {});
};

//#region API Client
/**
 * Create API client with axios and provide required headers.
 */
export const apiClient = axios.create({
  headers: {
    "Content-Type": "application/vnd.api+json",
    Accept: "application/vnd.api+json",
  },
});
apiClient.interceptors.request.use(
  (config) => {
    config.headers["X-CSRF-Token"] = window.drupalSettings.csrfToken;
    return config;
  },
  (error) => Promise.reject(error)
);
apiClient.interceptors.response.use(
  (response) => response.data,
  (error) => Promise.reject(error)
);
//#endregion

//#region Documents
/**
 * Get the cookie by name.
 * @param name
 * @returns {string}
 */
export const getCookie = (name) => {
  const value = `; ${document.cookie}`;
  const parts = value.split(`; ${name}=`);
  if (parts.length === 2) return parts.pop().split(";").shift();
}
