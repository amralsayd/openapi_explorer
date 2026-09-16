/**
 * @file
 * Behaviours for the OpenAPI Explorer documentation page.
 *
 * Drives the category sidebar and the per-operation request tester. Credentials
 * are read from the fields on the page and never stored.
 */

(function (Drupal, drupalSettings, once) {
  'use strict';

  /**
   * Returns this page's settings, with safe defaults.
   *
   * @return {object}
   *   The settings object.
   */
  function config() {
    var settings = drupalSettings.openapiExplorer || {};
    settings.auth = settings.auth || {};
    return settings;
  }

  /**
   * Reads a trimmed value from a field inside the explorer.
   *
   * @param {Element} root
   *   The explorer root element.
   * @param {string} id
   *   The field id.
   *
   * @return {string}
   *   The trimmed value, or an empty string.
   */
  function fieldValue(root, id) {
    var el = root.querySelector('#' + id);
    return el ? el.value.trim() : '';
  }

  /**
   * Sets an element's text content.
   *
   * @param {Element} el
   *   The element, which may be null.
   * @param {string} text
   *   The text to set.
   */
  function setText(el, text) {
    if (el) {
      el.textContent = text;
    }
  }

  /**
   * Builds the query string from the non-empty query inputs.
   *
   * @param {Element} body
   *   The tester body element.
   *
   * @return {string}
   *   The query string, including the leading "?", or an empty string.
   */
  function buildQuery(body) {
    var parts = [];
    body.querySelectorAll('.oae-try-in[data-in="query"]').forEach(function (input) {
      if (input.value !== '') {
        parts.push(encodeURIComponent(input.getAttribute('data-name')) + '=' + encodeURIComponent(input.value));
      }
    });
    return parts.length ? '?' + parts.join('&') : '';
  }

  /**
   * Substitutes {token} path parameters with the entered values.
   *
   * @param {Element} body
   *   The tester body element.
   *
   * @return {string}
   *   The resolved path.
   */
  function buildPath(body) {
    var path = body.getAttribute('data-path') || '';
    body.querySelectorAll('.oae-try-in[data-in="path"]').forEach(function (input) {
      var name = input.getAttribute('data-name');
      var value = input.value !== '' ? encodeURIComponent(input.value) : '{' + name + '}';
      path = path.replace('{' + name + '}', value);
    });
    return path;
  }

  /**
   * Collects the request headers for a call.
   *
   * The schemes resolved asynchronously (JWT, OAuth bearer, CSRF) are merged
   * in by the caller.
   *
   * @param {Element} root
   *   The explorer root element.
   * @param {Element} body
   *   The tester body element.
   * @param {string} auth
   *   The selected authentication scheme.
   * @param {boolean} sendBody
   *   Whether a request body will be sent.
   *
   * @return {object}
   *   The headers.
   */
  function buildHeaders(root, body, auth, sendBody) {
    var headers = {};
    body.querySelectorAll('.oae-try-in[data-in="header"]').forEach(function (input) {
      if (input.value !== '') {
        headers[input.getAttribute('data-name')] = input.value;
      }
    });
    headers.Accept = 'application/json';
    if (sendBody) {
      headers['Content-Type'] = 'application/json';
    }
    if (auth === 'key_auth') {
      headers[fieldValue(root, 'oae-key-header') || 'api-key'] = fieldValue(root, 'oae-key-value');
    }
    else if (auth === 'basic_auth') {
      headers.Authorization = 'Basic ' + btoa(fieldValue(root, 'oae-basic-user') + ':' + fieldValue(root, 'oae-basic-pass'));
    }
    return headers;
  }

  /**
   * Builds a one-entry header object, whose name is only known at runtime.
   *
   * @param {string} name
   *   The header name.
   * @param {string} value
   *   The header value.
   *
   * @return {object}
   *   The header object.
   */
  function headerObject(name, value) {
    var headers = {};
    headers[name] = value;
    return headers;
  }

  /**
   * Prefixes a token with "Bearer " unless it already carries a scheme.
   *
   * @param {string} token
   *   The raw token, as pasted or fetched.
   *
   * @return {string}
   *   The header value.
   */
  function asBearer(token) {
    var value = String(token).trim();
    return /^bearer\s/i.test(value) ? value : 'Bearer ' + value;
  }

  /**
   * The header a JSON Web Token is sent in.
   *
   * @param {Element} root
   *   The explorer root element.
   *
   * @return {string}
   *   The header name.
   */
  function jwtHeaderName(root) {
    return fieldValue(root, 'oae-jwt-header') || config().auth.jwtHeader || 'Authorization';
  }

  /**
   * Base64url-encodes bytes or a string, as JWT requires.
   *
   * @param {Uint8Array|string} input
   *   The bytes, or text to encode as UTF-8 first.
   *
   * @return {string}
   *   The base64url text, without padding.
   */
  function base64Url(input) {
    var binary = '';
    var bytes = typeof input === 'string' ? new TextEncoder().encode(input) : input;
    for (var i = 0; i < bytes.length; i++) {
      binary += String.fromCharCode(bytes[i]);
    }
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }

  /**
   * Decodes a PEM block into the DER bytes WebCrypto imports.
   *
   * @param {string} pem
   *   The PEM text.
   *
   * @return {Uint8Array}
   *   The DER bytes.
   */
  function pemToBytes(pem) {
    var body = pem.replace(/-----[^-]+-----/g, '').replace(/\s+/g, '');
    var binary = atob(body);
    var bytes = new Uint8Array(binary.length);
    for (var i = 0; i < binary.length; i++) {
      bytes[i] = binary.charCodeAt(i);
    }
    return bytes;
  }

  /**
   * Imports the signing key for the chosen algorithm.
   *
   * @param {string} algorithm
   *   A JWS algorithm name such as RS256 or HS256.
   * @param {string} keyText
   *   A PEM private key, or a shared secret for the HMAC algorithms.
   *
   * @return {Promise}
   *   Resolves with a CryptoKey.
   */
  function importSigningKey(algorithm, keyText) {
    var hash = 'SHA-' + algorithm.substring(2);
    if (algorithm.charAt(0) === 'H') {
      return crypto.subtle.importKey(
        'raw',
        new TextEncoder().encode(keyText),
        { name: 'HMAC', hash: hash },
        false,
        ['sign']
      );
    }
    if (/BEGIN RSA PRIVATE KEY/.test(keyText)) {
      return Promise.reject(new Error(Drupal.t('That is a PKCS#1 key, which browsers cannot import. Convert it first: openssl pkcs8 -topk8 -nocrypt -in key.pem -out key-pkcs8.pem')));
    }
    if (/BEGIN ENCRYPTED PRIVATE KEY/.test(keyText)) {
      return Promise.reject(new Error(Drupal.t('That key is passphrase-protected. Decrypt it first: openssl pkcs8 -topk8 -nocrypt -in key.pem -out key-pkcs8.pem')));
    }
    return crypto.subtle.importKey(
      'pkcs8',
      pemToBytes(keyText),
      { name: 'RSASSA-PKCS1-v1_5', hash: hash },
      false,
      ['sign']
    );
  }

  /**
   * Signs a JWT in the browser from a claim set and a key.
   *
   * The key is only ever used by the browser's own crypto implementation; it is
   * never sent anywhere. Signing happens per request so "exp" stays valid.
   *
   * @param {Element} root
   *   The explorer root element.
   *
   * @return {Promise}
   *   Resolves with the encoded token.
   */
  function signJwtToken(root) {
    if (!window.crypto || !window.crypto.subtle) {
      return Promise.reject(new Error(Drupal.t('Signing needs the browser crypto API, which is only available over HTTPS or on localhost.')));
    }
    var algorithm = fieldValue(root, 'oae-jwt-alg') || 'RS256';
    var keyText = fieldValue(root, 'oae-jwt-key');
    if (!keyText) {
      return Promise.reject(new Error(Drupal.t('Enter the private key or shared secret to sign with.')));
    }

    var claims;
    try {
      claims = JSON.parse(fieldValue(root, 'oae-jwt-claims') || '{}');
    }
    catch (e) {
      return Promise.reject(new Error(Drupal.t('The claims are not valid JSON: @message', { '@message': e.message })));
    }
    if (claims === null || typeof claims !== 'object' || Array.isArray(claims)) {
      return Promise.reject(new Error(Drupal.t('The claims must be a JSON object.')));
    }

    // Fill in the time claims the caller left out, so a pasted claim set works
    // without having to keep timestamps up to date by hand.
    var now = Math.floor(Date.now() / 1000);
    if (claims.iat === undefined) {
      claims.iat = now;
    }
    if (claims.exp === undefined) {
      claims.exp = now + 3600;
    }

    var signingInput = base64Url(JSON.stringify({ alg: algorithm, typ: 'JWT' })) +
      '.' + base64Url(JSON.stringify(claims));

    return importSigningKey(algorithm, keyText)
      .then(function (key) {
        var name = algorithm.charAt(0) === 'H' ? 'HMAC' : 'RSASSA-PKCS1-v1_5';
        return crypto.subtle.sign(name, key, new TextEncoder().encode(signingInput));
      })
      .then(function (signature) {
        var token = signingInput + '.' + base64Url(new Uint8Array(signature));
        var field = root.querySelector('#oae-jwt-token');
        if (field) {
          field.value = token;
        }
        return token;
      })
      .catch(function (error) {
        // WebCrypto reports most import failures as a bare DataError.
        var message = String(error && error.message ? error.message : error);
        throw new Error(message || Drupal.t('The key could not be used to sign a token.'));
      });
  }

  /**
   * Resolves the JWT to send, according to the selected source.
   *
   * @param {Element} root
   *   The explorer root element.
   *
   * @return {Promise}
   *   Resolves with the token.
   */
  function resolveJwt(root) {
    var mode = fieldValue(root, 'oae-jwt-mode') || 'token';
    var pasted = fieldValue(root, 'oae-jwt-token');
    if (mode === 'sign') {
      return signJwtToken(root);
    }
    if (mode === 'url') {
      return pasted ? Promise.resolve(pasted) : fetchJwtToken(root);
    }
    return pasted
      ? Promise.resolve(pasted)
      : Promise.reject(new Error(Drupal.t('Paste a token, or choose another source for it.')));
  }

  /**
   * Requests a JSON Web Token from the site and fills the token field.
   *
   * The token endpoint authenticates with the current session, so the request
   * deliberately sends cookies where the API calls themselves do not.
   *
   * @param {Element} root
   *   The explorer root element.
   *
   * @return {Promise}
   *   Resolves with the token.
   */
  function fetchJwtToken(root) {
    var settings = config();
    var configured = settings.auth.jwtTokenUrl ? (settings.basePath || '') + settings.auth.jwtTokenUrl : '';
    var url = fieldValue(root, 'oae-jwt-url') || configured;
    if (!url) {
      return Promise.reject(new Error(Drupal.t('No token URL is available. Paste a token instead, or enter the URL that issues one.')));
    }
    return fetch(url, { headers: { Accept: 'application/json' }, credentials: 'include' })
      .then(function (response) {
        return response.text().then(function (text) {
          return { status: response.status, text: text };
        });
      })
      .then(function (result) {
        var data = {};
        try {
          data = JSON.parse(result.text);
        }
        catch (e) {
          data = {};
        }
        // Drupal's JWT issuer returns {"token": "..."}; accept the other
        // spellings a site may use rather than failing on a valid response.
        var token = data.token || data.jwt || data.access_token || '';
        if (result.status >= 200 && result.status < 300 && token) {
          var field = root.querySelector('#oae-jwt-token');
          if (field) {
            field.value = token;
          }
          return token;
        }
        throw new Error(Drupal.t('Token request failed (HTTP @status): @body', {
          '@status': result.status,
          '@body': result.text
        }));
      });
  }

  /**
   * Requests an OAuth 2 token and fills the bearer field with it.
   *
   * @param {Element} root
   *   The explorer root element.
   *
   * @return {Promise}
   *   Resolves with the access token.
   */
  function fetchOAuthToken(root) {
    var settings = config();
    var url = fieldValue(root, 'oae-oauth-url') || (settings.basePath || '') + (settings.auth.oauthTokenUrl || '/oauth/token');
    var params = new URLSearchParams();
    params.set('grant_type', 'client_credentials');
    params.set('client_id', fieldValue(root, 'oae-oauth-id'));
    params.set('client_secret', fieldValue(root, 'oae-oauth-secret'));
    var scope = fieldValue(root, 'oae-oauth-scope');
    if (scope) {
      params.set('scope', scope);
    }
    return fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        Accept: 'application/json'
      },
      body: params.toString(),
      credentials: 'omit'
    })
      .then(function (response) {
        return response.text().then(function (text) {
          return { status: response.status, text: text };
        });
      })
      .then(function (result) {
        var data = {};
        try {
          data = JSON.parse(result.text);
        }
        catch (e) {
          data = {};
        }
        if (result.status >= 200 && result.status < 300 && data.access_token) {
          var field = root.querySelector('#oae-bearer');
          if (field) {
            field.value = data.access_token;
          }
          return data.access_token;
        }
        throw new Error(Drupal.t('Token request failed (HTTP @status): @body', {
          '@status': result.status,
          '@body': result.text
        }));
      });
  }

  /**
   * Fetches a CSRF token, required for cookie-authenticated write requests.
   *
   * @param {Element} root
   *   The explorer root element.
   *
   * @return {Promise}
   *   Resolves with the token text.
   */
  function fetchCsrfToken(root) {
    var settings = config();
    var url = (settings.basePath || '') + (settings.auth.csrfTokenUrl || '/session/token');
    return fetch(url, { credentials: 'include' }).then(function (response) {
      return response.text();
    });
  }

  /**
   * Writes a result into an operation's response panel.
   *
   * @param {Element} body
   *   The tester body element.
   * @param {string} statusText
   *   The status line.
   * @param {string} output
   *   The response body to show.
   * @param {boolean} isError
   *   Whether this is an error result.
   */
  function showResponse(body, statusText, output, isError) {
    var statusEl = body.querySelector('.oae-try-status');
    var outEl = body.querySelector('.oae-try-out');
    if (statusEl) {
      statusEl.className = 'oae-try-status ' + (isError ? 'oae-err' : 'oae-ok');
      setText(statusEl, statusText || (isError ? Drupal.t('Error') : Drupal.t('OK')));
    }
    if (outEl) {
      outEl.value = output;
    }
  }

  /**
   * Sends one operation's request and renders the response.
   *
   * @param {Element} root
   *   The explorer root element.
   * @param {Element} body
   *   The tester body element.
   */
  function send(root, body) {
    var settings = config();
    var authEl = body.querySelector('.oae-try-auth');
    var auth = authEl ? authEl.value : 'none';
    var method = (body.getAttribute('data-method') || 'GET').toUpperCase();
    var isWrite = method === 'POST' || method === 'PUT' || method === 'PATCH';
    var url = (settings.basePath || '') + buildPath(body) + buildQuery(body);
    setText(body.querySelector('.oae-try-url'), method + ' ' + url);

    var bodyText = null;
    if (isWrite) {
      var editor = body.querySelector('.oae-try-json');
      bodyText = editor ? editor.value : '';
      // Validate the JSON early so the message is about the body, not the call.
      if (bodyText !== '') {
        try {
          JSON.parse(bodyText);
        }
        catch (e) {
          showResponse(body, null, Drupal.t('Invalid JSON in the request body: @message', { '@message': e.message }), true);
          return;
        }
      }
    }

    var statusEl = body.querySelector('.oae-try-status');
    var outEl = body.querySelector('.oae-try-out');
    if (statusEl) {
      statusEl.className = 'oae-try-status';
      setText(statusEl, Drupal.t('Sending…'));
    }
    if (outEl) {
      outEl.value = '';
    }

    // Resolve the asynchronous authentication pieces first.
    var prepare = Promise.resolve({});
    if (auth === 'oauth2') {
      var token = fieldValue(root, 'oae-bearer');
      prepare = token
        ? Promise.resolve({ Authorization: 'Bearer ' + token })
        : fetchOAuthToken(root).then(function (fetched) {
          return { Authorization: 'Bearer ' + fetched };
        });
    }
    else if (auth === 'jwt_auth') {
      var jwtHeader = jwtHeaderName(root);
      prepare = resolveJwt(root).then(function (token) {
        return headerObject(jwtHeader, asBearer(token));
      });
    }
    else if (auth === 'cookie' && isWrite) {
      prepare = fetchCsrfToken(root).then(function (csrf) {
        return { 'X-CSRF-Token': csrf };
      });
    }

    prepare
      .then(function (extra) {
        var sendBody = isWrite && bodyText !== null && bodyText !== '';
        var headers = buildHeaders(root, body, auth, sendBody);
        Object.keys(extra).forEach(function (name) {
          headers[name] = extra[name];
        });
        var options = {
          method: method,
          headers: headers,
          credentials: auth === 'cookie' ? 'include' : 'omit'
        };
        if (sendBody) {
          options.body = bodyText;
        }
        var started = Date.now();
        return fetch(url, options).then(function (response) {
          return response.text().then(function (text) {
            return { response: response, text: text, ms: Date.now() - started };
          });
        });
      })
      .then(function (result) {
        var ok = result.response.status >= 200 && result.response.status < 400;
        if (statusEl) {
          setText(statusEl, Drupal.t('HTTP @status @text · @ms ms', {
            '@status': result.response.status,
            '@text': result.response.statusText,
            '@ms': result.ms
          }));
          statusEl.className = 'oae-try-status ' + (ok ? 'oae-ok' : 'oae-err');
        }
        var pretty = result.text;
        try {
          pretty = JSON.stringify(JSON.parse(result.text), null, 2);
        }
        catch (e) {
          pretty = result.text;
        }
        if (outEl) {
          outEl.value = pretty;
        }
      })
      .catch(function (error) {
        var message = String(error && error.message ? error.message : error);
        showResponse(body, null, message + '\n\n' + Drupal.t('The request failed before a response arrived. Check the network, the certificate, or whether the endpoint is on another origin.'), true);
      });
  }

  /**
   * Turns a tablist into working tabs.
   *
   * The markup ships with every panel visible, so the credentials still work
   * without JavaScript; the inactive panels are only hidden once the tabs are
   * wired up here. Follows the ARIA tabs pattern: one tab in the tab order at a
   * time, with the arrow keys moving between them.
   *
   * @param {Element} root
   *   The explorer root element.
   */
  function initTabs(root) {
    root.querySelectorAll('[data-oae-tabs]').forEach(function (group) {
      var tabs = Array.prototype.slice.call(group.querySelectorAll('[role="tab"]'));
      if (!tabs.length) {
        return;
      }
      var panels = tabs.map(function (tab) {
        return group.querySelector('#' + tab.getAttribute('aria-controls'));
      });

      function select(index, moveFocus) {
        tabs.forEach(function (tab, position) {
          var active = position === index;
          tab.setAttribute('aria-selected', active ? 'true' : 'false');
          tab.tabIndex = active ? 0 : -1;
          if (panels[position]) {
            panels[position].hidden = !active;
          }
        });
        if (moveFocus) {
          tabs[index].focus();
        }
      }

      tabs.forEach(function (tab, index) {
        tab.addEventListener('click', function () {
          select(index, false);
        });
        tab.addEventListener('keydown', function (event) {
          var target = null;
          if (event.key === 'ArrowRight') {
            target = (index + 1) % tabs.length;
          }
          else if (event.key === 'ArrowLeft') {
            target = (index - 1 + tabs.length) % tabs.length;
          }
          else if (event.key === 'Home') {
            target = 0;
          }
          else if (event.key === 'End') {
            target = tabs.length - 1;
          }
          if (target !== null) {
            event.preventDefault();
            select(target, true);
          }
        });
      });

      select(0, false);
    });
  }

  /**
   * Wires the category sidebar: activation, deep links and filtering.
   *
   * @param {Element} root
   *   The explorer root element.
   */
  function initSidebar(root) {
    var categories = root.querySelectorAll('.oae-cat');
    var sections = root.querySelectorAll('.oae-cat-sec');

    function activate(slug) {
      categories.forEach(function (category) {
        category.classList.toggle('active', category.getAttribute('data-cat') === slug);
      });
      sections.forEach(function (section) {
        section.classList.toggle('active', section.getAttribute('data-cat') === slug);
      });
    }

    root.querySelectorAll('.oae-cat-h').forEach(function (button) {
      button.addEventListener('click', function () {
        activate(button.getAttribute('data-cat'));
      });
    });

    root.querySelectorAll('.oae-nav-link').forEach(function (link) {
      link.addEventListener('click', function (event) {
        event.preventDefault();
        activate(link.getAttribute('data-cat'));
        root.querySelectorAll('.oae-nav-link').forEach(function (other) {
          other.classList.remove('active');
        });
        link.classList.add('active');
        var target = document.getElementById(link.getAttribute('data-target'));
        if (target) {
          target.scrollIntoView({ behavior: 'smooth', block: 'start' });
          target.classList.remove('oae-flash');
          // Force a reflow so the animation restarts on a repeated click.
          void target.offsetWidth;
          target.classList.add('oae-flash');
        }
      });
    });

    var filter = root.querySelector('#oae-nav-filter');
    if (filter) {
      filter.addEventListener('input', function () {
        var query = filter.value.trim().toLowerCase();
        categories.forEach(function (category) {
          var anyVisible = false;
          category.querySelectorAll('.oae-sub').forEach(function (subgroup) {
            var subVisible = false;
            subgroup.querySelectorAll('.oae-nav-link').forEach(function (link) {
              var match = query === '' || link.textContent.toLowerCase().indexOf(query) !== -1;
              link.parentNode.hidden = !match;
              if (match) {
                subVisible = true;
              }
            });
            subgroup.hidden = !subVisible;
            if (subVisible) {
              anyVisible = true;
            }
          });
          category.hidden = !(query === '' || anyVisible);
          if (query !== '') {
            category.classList.toggle('active', anyVisible);
          }
        });
      });
    }

    // Open the category referenced by the URL fragment, else the first one.
    var initial = categories.length ? categories[0].getAttribute('data-cat') : null;
    if (window.location.hash) {
      var hashLink = root.querySelector('.oae-nav-link[data-target="' + window.location.hash.slice(1) + '"]');
      if (hashLink) {
        initial = hashLink.getAttribute('data-cat');
      }
    }
    if (initial) {
      activate(initial);
    }
  }

  /**
   * Wires the request tester controls.
   *
   * @param {Element} root
   *   The explorer root element.
   */
  function initTester(root) {
    var oauthButton = root.querySelector('#oae-oauth-fetch');
    if (oauthButton) {
      oauthButton.addEventListener('click', function () {
        var status = root.querySelector('#oae-oauth-status');
        setText(status, Drupal.t('Requesting…'));
        fetchOAuthToken(root)
          .then(function () {
            setText(status, Drupal.t('Token acquired.'));
          })
          .catch(function (error) {
            setText(status, String(error.message || error));
          });
      });
    }

    // Show only the fields belonging to the selected token source.
    var jwtMode = root.querySelector('#oae-jwt-mode');
    if (jwtMode) {
      var applyJwtMode = function () {
        root.querySelectorAll('[data-jwt-mode]').forEach(function (section) {
          var modes = section.getAttribute('data-jwt-mode').split(' ');
          section.hidden = modes.indexOf(jwtMode.value) === -1;
        });
      };
      jwtMode.addEventListener('change', applyJwtMode);
      applyJwtMode();
    }

    var jwtStatus = root.querySelector('#oae-jwt-status');

    var jwtFetch = root.querySelector('#oae-jwt-fetch');
    if (jwtFetch) {
      jwtFetch.addEventListener('click', function () {
        setText(jwtStatus, Drupal.t('Requesting…'));
        fetchJwtToken(root)
          .then(function () {
            setText(jwtStatus, Drupal.t('Token acquired.'));
          })
          .catch(function (error) {
            setText(jwtStatus, String(error.message || error));
          });
      });
    }

    var jwtSign = root.querySelector('#oae-jwt-sign');
    if (jwtSign) {
      jwtSign.addEventListener('click', function () {
        setText(jwtStatus, Drupal.t('Signing…'));
        signJwtToken(root)
          .then(function () {
            setText(jwtStatus, Drupal.t('Token signed. It is in the Token field, and each request is signed afresh.'));
          })
          .catch(function (error) {
            setText(jwtStatus, String(error.message || error));
          });
      });
    }

    root.querySelectorAll('.oae-try-send').forEach(function (button) {
      button.addEventListener('click', function () {
        var body = button.closest('.oae-try-body');
        if (body) {
          send(root, body);
        }
      });
    });

    root.querySelectorAll('.oae-try-format').forEach(function (button) {
      button.addEventListener('click', function () {
        var body = button.closest('.oae-try-body');
        var editor = body ? body.querySelector('.oae-try-json') : null;
        if (!editor) {
          return;
        }
        try {
          editor.value = JSON.stringify(JSON.parse(editor.value), null, 2);
        }
        catch (e) {
          // Leave invalid JSON untouched; sending it reports the error.
        }
      });
    });
  }

  /**
   * Initialises the API documentation page.
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.openapiExplorer = {
    attach: function attach(context) {
      once('openapi-explorer', '.oae', context).forEach(function (root) {
        initTabs(root);
        initSidebar(root);
        initTester(root);
      });
    }
  };
})(Drupal, drupalSettings, once);
