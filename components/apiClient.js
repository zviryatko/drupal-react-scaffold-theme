/**
 * Create API client with axios and provide required headers.
 */
window.apiClient = async function (url, query, params) {
  params = params || {};
  params.method = params.method || "GET";
  params.headers = params.headers || {};
  params.headers["Content-Type"] = "application/vnd.api+json";
  params.headers["Accept"] = "application/vnd.api+json";
  params.headers["X-CSRF-Token"] = window.drupalSettings.csrfToken;
  if (url.indexOf('?') === -1) {
    url += '?';
  } else {
    url += '&';
  }
  if (query) {
    Object.keys(query).forEach((k) => query[k] == null && delete query[k])
    url += new URLSearchParams(query).toString();
  }
  const response = await fetch(url, params);
  return response.json();
}
/**
 * Reads URL params (query) and returns transformed object.
 */
window.getUrlParams = function(defaults) {
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
      }
      else {
        // otherwise add the value to the end of the array
        acc[key].push(value);
      }
    }
    else {
      // we're dealing with a string
      if (!acc[key]) {
        // if it doesn't exist, create property
        acc[key] = value;
      }
      else if (acc[key] && typeof acc[key] === "string") {
        // if property does exist and it's a string, convert it to an array
        acc[key] = [acc[key]];
        acc[key].push(value);
      }
      else {
        // otherwise add the property
        acc[key].push(value);
      }
    }

    return acc;
  }, {});
}

/**
 * Get the cookie by name.
 * @param name
 * @returns {string}
 */
window.getCookie = function(name) {
  const value = `; ${document.cookie}`;
  const parts = value.split(`; ${name}=`);
  if (parts.length === 2) {
    return parts.pop().split(";").shift();
  }
}
