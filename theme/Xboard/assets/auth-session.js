(function () {
  'use strict';

  const TOKEN_KEY = 'VUE_NAIVE_ACCESS_TOKEN';
  const storage = window.localStorage;
  const storagePrototype = Object.getPrototypeOf(storage);
  const nativeGetItem = storagePrototype.getItem;
  const nativeSetItem = storagePrototype.setItem;
  const nativeRemoveItem = storagePrototype.removeItem;

  function isAccessTokenKey(key) {
    return String(key).toUpperCase() === TOKEN_KEY;
  }

  function permanentValue(value) {
    try {
      const parsed = JSON.parse(value);
      if (!parsed || typeof parsed !== 'object' || !parsed.value) return value;
      parsed.expire = null;
      return JSON.stringify(parsed);
    } catch (_) {
      return value;
    }
  }

  function currentAuthorization() {
    try {
      const raw = nativeGetItem.call(storage, TOKEN_KEY);
      const parsed = JSON.parse(raw || 'null');
      return typeof parsed?.value === 'string' ? parsed.value : null;
    } catch (_) {
      return null;
    }
  }

  function logoutUrl() {
    const base = (window.routerBase || '/').replace(/\/$/, '');
    return `${window.location.origin}${base}/api/v1/user/logout`;
  }

  function revokeCurrentToken(authorization) {
    if (!authorization || typeof window.fetch !== 'function') return;
    window.fetch(logoutUrl(), {
      method: 'POST',
      headers: {
        Authorization: authorization,
        'Content-Language': 'zh-CN',
      },
      keepalive: true,
    }).catch(function () {
      // The local logout must still complete if the network is unavailable.
    });
  }

  try {
    const existing = nativeGetItem.call(storage, TOKEN_KEY);
    if (existing) nativeSetItem.call(storage, TOKEN_KEY, permanentValue(existing));
  } catch (_) {
    // Browsers may disable local storage; the normal login flow handles that case.
  }

  storagePrototype.setItem = function (key, value) {
    const nextValue = this === storage && isAccessTokenKey(key)
      ? permanentValue(value)
      : value;
    return nativeSetItem.call(this, key, nextValue);
  };

  storagePrototype.removeItem = function (key) {
    if (this === storage && isAccessTokenKey(key)) {
      revokeCurrentToken(currentAuthorization());
    }
    return nativeRemoveItem.call(this, key);
  };
})();
