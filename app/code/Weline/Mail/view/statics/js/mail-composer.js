(function (global) {
  'use strict';

  var ROOT_SELECTOR = '[data-w-component="mail-composer"]';

  function qs(root, sel) {
    return root.querySelector(sel);
  }

  function ensureRoot() {
    return document.querySelector(ROOT_SELECTOR);
  }

  function setError(root, message) {
    var el = qs(root, '[data-mail-composer-error]');
    if (!el) return;
    if (!message) {
      el.hidden = true;
      el.textContent = '';
      return;
    }
    el.hidden = false;
    el.textContent = String(message);
  }

  function resolveEndpoints(root) {
    var area = (root.getAttribute('data-mail-composer-area') || 'auto').toLowerCase();
    var path = global.location.pathname || '';
    var isBackend = area === 'backend' || (area === 'auto' && /\/weline_/.test(path) === false && path.indexOf('/admin') === -1 && /\/[A-Za-z0-9]{16,}\//.test(path));
    // Prefer explicit data attrs when page provides them.
    var threadUrl = root.getAttribute('data-thread-url') || '';
    var sendUrl = root.getAttribute('data-send-url') || '';
    if (threadUrl && sendUrl) {
      return { threadUrl: threadUrl, sendUrl: sendUrl };
    }
    // Backend prefix is first path segment after host when using random backend prefix.
    var parts = path.split('/').filter(Boolean);
    var backendPrefix = parts.length ? parts[0] : '';
    var backendLikely = area === 'backend' || (parts.length > 1 && parts[1] === 'weline_product') || (parts.length > 1 && parts[1] === 'weline_mail') || document.body.classList.contains('w-backend');
    if (backendLikely && backendPrefix) {
      return {
        threadUrl: '/' + backendPrefix + '/weline_mail/backend/composer/thread',
        sendUrl: '/' + backendPrefix + '/weline_mail/backend/composer/send'
      };
    }
    return {
      threadUrl: '/weline_mail/frontend/composer/thread',
      sendUrl: '/weline_mail/frontend/composer/send'
    };
  }

  function renderThread(root, items) {
    var box = qs(root, '[data-mail-composer-thread]');
    if (!box) return;
    var empty = box.getAttribute('data-empty') || '—';
    box.innerHTML = '';
    if (!items || !items.length) {
      var p = document.createElement('p');
      p.className = 'w-text';
      p.setAttribute('data-tone', 'muted');
      p.setAttribute('data-size', 'sm');
      p.textContent = empty;
      box.appendChild(p);
      return;
    }
    items.forEach(function (item) {
      var card = document.createElement('article');
      card.className = 'w-mail-composer__msg';
      var meta = document.createElement('div');
      meta.className = 'w-mail-composer__msg-meta';
      meta.textContent = [item.from || '', '→', item.to || '', '·', item.created_at || '', '·', item.subject || ''].join(' ');
      var body = document.createElement('pre');
      body.className = 'w-mail-composer__msg-body';
      body.textContent = item.body || '';
      card.appendChild(meta);
      card.appendChild(body);
      box.appendChild(card);
    });
  }

  function fillAccounts(root, mailboxes, selectedId) {
    var select = qs(root, '[data-mail-composer-account]');
    if (!select) return;
    // Keep existing options when caller did not pass mailboxes (e.g. reopen / partial open).
    if (mailboxes && mailboxes.length) {
      select.innerHTML = '';
      mailboxes.forEach(function (mb) {
        var opt = document.createElement('option');
        opt.value = String(mb.account_id || mb.id || '');
        opt.textContent = String(mb.email || opt.value) + (mb.is_fake ? ' · fake' : '');
        select.appendChild(opt);
      });
    }
    if (selectedId) {
      select.value = String(selectedId);
    }
    select.disabled = select.options.length <= 1;
  }

  function csrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"], meta[name="form_key"]');
    if (meta && meta.content) return meta.content;
    var input = document.querySelector('input[name="form_key"]');
    return input ? input.value : '';
  }

  async function fetchJson(url, options) {
    var res = await fetch(url, options);
    var data = null;
    try {
      data = await res.json();
    } catch (e) {
      data = null;
    }
    return { ok: res.ok, status: res.status, data: data };
  }

  function close(root) {
    root = root || ensureRoot();
    if (!root) return;
    root.hidden = true;
    root.setAttribute('aria-hidden', 'true');
    document.documentElement.classList.remove('w-mail-composer-open');
    setError(root, '');
  }

  async function open(opts) {
    opts = opts || {};
    var root = ensureRoot();
    if (!root) {
      console.warn('[WelineMailComposer] shell missing; add <w:mail-composer/>');
      return;
    }
    var endpoints = resolveEndpoints(root);
    fillAccounts(root, opts.mailboxes || [], opts.account_id || opts.default_account_id || 0);
    var toEl = qs(root, '[data-mail-composer-to]');
    var subjectEl = qs(root, '[data-mail-composer-subject]');
    var bodyEl = qs(root, '[data-mail-composer-body]');
    var composeWrap = qs(root, '[data-mail-composer-compose]');
    if (toEl) toEl.value = opts.to || '';
    if (subjectEl) subjectEl.value = opts.subject || '';
    if (bodyEl) bodyEl.value = opts.body || '';
    if (composeWrap) {
      composeWrap.hidden = !!opts.read_only;
    }
    root.dataset.source = opts.source || '';
    root.dataset.sourceId = String(opts.source_id || 0);
    root.hidden = false;
    root.setAttribute('aria-hidden', 'false');
    document.documentElement.classList.add('w-mail-composer-open');
    setError(root, '');

    var threadBox = qs(root, '[data-mail-composer-thread]');
    if (threadBox) {
      threadBox.textContent = threadBox.getAttribute('data-loading') || '...';
    }
    var qsParams = new URLSearchParams({
      source: opts.source || '',
      source_id: String(opts.source_id || 0)
    });
    var thread = await fetchJson(endpoints.threadUrl + '?' + qsParams.toString(), {
      method: 'GET',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    });
    if (thread.data && thread.data.items) {
      renderThread(root, thread.data.items);
    } else {
      renderThread(root, []);
    }
  }

  async function send(root) {
    root = root || ensureRoot();
    if (!root) return;
    var endpoints = resolveEndpoints(root);
    var accountEl = qs(root, '[data-mail-composer-account]');
    var toEl = qs(root, '[data-mail-composer-to]');
    var subjectEl = qs(root, '[data-mail-composer-subject]');
    var bodyEl = qs(root, '[data-mail-composer-body]');
    var payload = {
      account_id: accountEl ? parseInt(accountEl.value || '0', 10) : 0,
      to: toEl ? toEl.value : '',
      subject: subjectEl ? subjectEl.value : '',
      body: bodyEl ? bodyEl.value : '',
      source: root.dataset.source || '',
      source_id: parseInt(root.dataset.sourceId || '0', 10) || 0,
      form_key: csrfToken()
    };
    setError(root, '');
    var result = await fetchJson(endpoints.sendUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify(payload)
    });
    if (!result.ok || !result.data || !result.data.success) {
      setError(root, (result.data && result.data.message) || 'Send failed');
      return;
    }
    await open({
      to: payload.to,
      subject: payload.subject,
      body: '',
      source: payload.source,
      source_id: payload.source_id,
      account_id: payload.account_id,
      mailboxes: Array.from(accountEl ? accountEl.options : []).map(function (o) {
        return { account_id: o.value, email: o.textContent };
      })
    });
  }

  function bind(root) {
    if (!root || root.dataset.mailComposerBound === '1') return;
    root.dataset.mailComposerBound = '1';
    var closeBtn = qs(root, '[data-mail-composer-close]');
    if (closeBtn) closeBtn.addEventListener('click', function () { close(root); });
    var sendBtn = qs(root, '[data-mail-composer-send]');
    if (sendBtn) sendBtn.addEventListener('click', function () { send(root); });
    // Only close button closes — ignore backdrop / Escape.
  }

  function boot() {
    var root = ensureRoot();
    if (root) bind(root);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

  global.WelineMailComposer = {
    open: open,
    close: close
  };
})(typeof window !== 'undefined' ? window : this);
