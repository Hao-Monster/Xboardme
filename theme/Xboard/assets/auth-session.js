(function () {
  'use strict';

  const TOKEN_KEY = 'VUE_NAIVE_ACCESS_TOKEN';
  const CONFIRMED_EXPIRED_MESSAGE = '未登录或登陆已过期';
  const storage = window.localStorage;
  const storagePrototype = Object.getPrototypeOf(storage);
  const nativeGetItem = storagePrototype.getItem;
  const nativeSetItem = storagePrototype.setItem;
  const nativeRemoveItem = storagePrototype.removeItem;

  let sessionEpoch = 1;
  let trackedToken = null;
  let confirmedIdentity = null;
  let suppressRevoke = false;
  let recoveryState = null;
  const sessionListeners = new Set();

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
      return typeof parsed?.value === 'string' && parsed.value ? parsed.value : null;
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

  function notifySessionListeners(detail) {
    sessionListeners.forEach(function (listener) {
      try {
        listener(detail);
      } catch (_) {
        // Ignore individual listener errors so session state stays consistent.
      }
    });
    if (typeof window.dispatchEvent === 'function' && typeof window.CustomEvent === 'function') {
      try {
        window.dispatchEvent(new window.CustomEvent('xboard:auth-token-change', { detail: detail }));
      } catch (_) {
        // Ignore environments without CustomEvent constructor support.
      }
    }
  }

  function hideRecoveryPrompt() {
    recoveryState = null;
    const doc = window.document;
    if (!doc || typeof doc.getElementById !== 'function') return;
    const existing = doc.getElementById('xboard-auth-recovery');
    if (existing && typeof existing.remove === 'function') {
      existing.remove();
    } else if (existing && existing.parentNode) {
      existing.parentNode.removeChild(existing);
    }
  }

  function syncSession() {
    const nextToken = currentAuthorization();
    if (nextToken !== trackedToken) {
      const previousToken = trackedToken;
      trackedToken = nextToken;
      sessionEpoch += 1;
      confirmedIdentity = null;
      hideRecoveryPrompt();
      notifySessionListeners({
        epoch: sessionEpoch,
        token: trackedToken,
        previousToken: previousToken,
      });
    }
    return {
      epoch: sessionEpoch,
      token: trackedToken,
    };
  }

  function captureSession() {
    return syncSession();
  }

  function isCurrentSession(snapshotOrEpoch, maybeToken) {
    const current = syncSession();
    if (snapshotOrEpoch && typeof snapshotOrEpoch === 'object') {
      return (
        snapshotOrEpoch.epoch === current.epoch &&
        snapshotOrEpoch.token === current.token
      );
    }
    return (
      snapshotOrEpoch === current.epoch &&
      (maybeToken ?? null) === current.token
    );
  }

  function confirmUserIdentity(snapshot, user) {
    if (!snapshot?.token || !isCurrentSession(snapshot)) return false;
    if (!user || typeof user !== 'object' || Array.isArray(user)) return false;
    const hasIdentity = (typeof user.email === 'string' && user.email.trim() !== '')
      || (user.id !== undefined && user.id !== null)
      || (typeof user.uuid === 'string' && user.uuid.trim() !== '');
    if (!hasIdentity || ![true, false, 0, 1].includes(user.is_distributor)) return false;
    const nextUser = { ...user, is_distributor: Boolean(user.is_distributor) };
    const previous = confirmedIdentity;
    const identityChanged = !previous
      || previous.user.is_distributor !== nextUser.is_distributor
      || (previous.user.id ?? previous.user.email ?? previous.user.uuid)
        !== (nextUser.id ?? nextUser.email ?? nextUser.uuid);
    confirmedIdentity = { epoch: snapshot.epoch, token: snapshot.token, user: nextUser };
    if (identityChanged && typeof window.dispatchEvent === 'function' && typeof window.CustomEvent === 'function') {
      window.dispatchEvent(new window.CustomEvent('xboard:auth-user-confirmed', {
        detail: { epoch: snapshot.epoch, token: snapshot.token, user: { ...nextUser } },
      }));
    }
    return true;
  }

  function getConfirmedUser(snapshot = captureSession()) {
    if (!confirmedIdentity || !isCurrentSession(snapshot)
      || confirmedIdentity.epoch !== snapshot.epoch || confirmedIdentity.token !== snapshot.token) return null;
    return { ...confirmedIdentity.user };
  }

  function isConfirmedAuthFailure(status, message) {
    const code = Number(status || 0);
    const normalizedMessage = typeof message === 'string' ? message.trim() : '';
    if (code === 401) return true;
    if (code === 403 && normalizedMessage === CONFIRMED_EXPIRED_MESSAGE) return true;
    return false;
  }

  function invalidateSession(snapshot) {
    if (!snapshot || !snapshot.token || !isCurrentSession(snapshot)) {
      return false;
    }
    suppressRevoke = true;
    try {
      nativeRemoveItem.call(storage, TOKEN_KEY);
    } finally {
      suppressRevoke = false;
    }
    syncSession();
    return true;
  }

  function logoutSession(targetToken) {
    const current = currentAuthorization();
    const tokenToRevoke = typeof targetToken === 'string' && targetToken ? targetToken : current;
    if (!tokenToRevoke) return false;
    if (current && current === tokenToRevoke) {
      suppressRevoke = true;
      try {
        nativeRemoveItem.call(storage, TOKEN_KEY);
      } finally {
        suppressRevoke = false;
      }
      syncSession();
    }
    revokeCurrentToken(tokenToRevoke);
    return true;
  }

  function onSessionChange(listener) {
    if (typeof listener !== 'function') return function () {};
    sessionListeners.add(listener);
    return function () {
      sessionListeners.delete(listener);
    };
  }

  function retryRecovery() {
    if (!recoveryState || typeof recoveryState.onRetry !== 'function') return undefined;
    if (recoveryState.retrying) return undefined;
    const pendingRecovery = recoveryState;
    const sessionSnapshot = captureSession();
    if (recoveryState !== pendingRecovery) return undefined;
    const targetPath = recoveryState.targetPath || '/dashboard';
    const handler = recoveryState.onRetry;
    recoveryState.retrying = true;
    const doc = window.document;
    const button = doc && typeof doc.getElementById === 'function'
      ? doc.getElementById('xboard-auth-recovery-btn')
      : null;
    if (button) {
      button.disabled = true;
      button.textContent = '正在重试...';
    }
    return Promise.resolve()
      .then(function () {
        if (recoveryState !== pendingRecovery || !isCurrentSession(sessionSnapshot)) return;
        return handler(targetPath);
      })
      .finally(function () {
        if (recoveryState === pendingRecovery) {
          recoveryState.retrying = false;
        }
        if (button) {
          button.disabled = false;
          button.textContent = '重试加载';
        }
      });
  }

  function showRecoveryPrompt(options) {
    const opts = options || {};
    const preserveRouterRecovery = recoveryState?.owner === 'main-router'
      && opts.owner === 'distributor-detection';
    const targetPath = typeof opts.targetPath === 'string' && opts.targetPath
      ? opts.targetPath
      : (recoveryState?.targetPath || '/dashboard');
    const message = typeof opts.message === 'string' && opts.message
      ? opts.message
      : '网络或服务暂时不可用，无法完成页面初始化';
    if (preserveRouterRecovery) {
      recoveryState.message = message;
    } else {
      recoveryState = {
        owner: opts.owner || recoveryState?.owner || null,
        sourceHash: typeof opts.sourceHash === 'string' ? opts.sourceHash : (recoveryState?.sourceHash || null),
        targetPath: targetPath,
        message: message,
        onRetry: typeof opts.onRetry === 'function' ? opts.onRetry : (recoveryState?.onRetry || null),
        retrying: false,
      };
    }

    const doc = window.document;
    if (!doc || !doc.body || typeof doc.createElement !== 'function') {
      return recoveryState;
    }

    let container = typeof doc.getElementById === 'function'
      ? doc.getElementById('xboard-auth-recovery')
      : null;
    if (!container) {
      container = doc.createElement('div');
      container.id = 'xboard-auth-recovery';
      container.setAttribute('role', 'alert');
      container.style.cssText = [
        'position:fixed',
        'top:16px',
        'left:50%',
        'transform:translateX(-50%)',
        'z-index:99999',
        'display:flex',
        'align-items:center',
        'gap:12px',
        'padding:12px 16px',
        'border-radius:10px',
        'background:#fff',
        'color:#1f2329',
        'box-shadow:0 8px 24px rgba(15,23,42,0.14)',
        'border:1px solid rgba(229,72,77,0.28)',
        'font-size:14px',
        'line-height:1.5',
        'max-width:calc(100vw - 32px)',
      ].join(';');

      const textSpan = doc.createElement('span');
      textSpan.id = 'xboard-auth-recovery-msg';
      container.appendChild(textSpan);

      const retryBtn = doc.createElement('button');
      retryBtn.id = 'xboard-auth-recovery-btn';
      retryBtn.type = 'button';
      retryBtn.textContent = '重试加载';
      retryBtn.style.cssText = [
        'padding:6px 12px',
        'border-radius:6px',
        'border:none',
        'background:#18a058',
        'color:#fff',
        'font-size:13px',
        'font-weight:500',
        'cursor:pointer',
        'white-space:nowrap',
      ].join(';');
      if (typeof retryBtn.addEventListener === 'function') {
        retryBtn.addEventListener('click', function () {
          retryRecovery();
        });
      }
      container.appendChild(retryBtn);
      doc.body.appendChild(container);
    }

    const msgNode = typeof doc.getElementById === 'function'
      ? doc.getElementById('xboard-auth-recovery-msg')
      : container.firstChild;
    if (msgNode) {
      msgNode.textContent = message;
    }
    return recoveryState;
  }

  try {
    const existing = nativeGetItem.call(storage, TOKEN_KEY);
    if (existing) nativeSetItem.call(storage, TOKEN_KEY, permanentValue(existing));
  } catch (_) {
    // Browsers may disable local storage; the normal login flow handles that case.
  }

  trackedToken = currentAuthorization();

  storagePrototype.setItem = function (key, value) {
    const isToken = this === storage && isAccessTokenKey(key);
    const nextValue = isToken ? permanentValue(value) : value;
    const result = nativeSetItem.call(this, key, nextValue);
    if (isToken) {
      syncSession();
    }
    return result;
  };

  storagePrototype.removeItem = function (key) {
    const isToken = this === storage && isAccessTokenKey(key);
    const tokenToRevoke = isToken ? currentAuthorization() : null;
    const result = nativeRemoveItem.call(this, key);
    if (isToken) {
      syncSession();
      if (!suppressRevoke && tokenToRevoke) {
        revokeCurrentToken(tokenToRevoke);
      }
    }
    return result;
  };

  if (typeof window.addEventListener === 'function') {
    window.addEventListener('storage', function (event) {
      if (!event || !event.key || isAccessTokenKey(event.key)) {
        syncSession();
      }
    });
  }

  window.__xboardAuthSession = {
    captureSession: captureSession,
    isCurrentSession: isCurrentSession,
    confirmUserIdentity: confirmUserIdentity,
    getConfirmedUser: getConfirmedUser,
    isConfirmedAuthFailure: isConfirmedAuthFailure,
    invalidateSession: invalidateSession,
    logoutSession: logoutSession,
    onSessionChange: onSessionChange,
    showRecoveryPrompt: showRecoveryPrompt,
    hideRecoveryPrompt: hideRecoveryPrompt,
    retryRecovery: retryRecovery,
    getRecoveryState: function () {
      return recoveryState;
    },
  };
})();
