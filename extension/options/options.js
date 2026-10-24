'use strict';

(function () {
  const elements = {
    form: document.getElementById('auth-form'),
    apiBase: document.getElementById('api-base'),
    authCode: document.getElementById('auth-code'),
    deviceName: document.getElementById('device-name'),
    siteId: document.getElementById('site-id'),
    connect: document.getElementById('connect'),
    authHint: document.getElementById('auth-hint'),
    authMessage: document.getElementById('auth-message'),
    statusPill: document.getElementById('status-pill'),
    statusApi: document.getElementById('status-api'),
    statusDevice: document.getElementById('status-device'),
    statusSite: document.getElementById('status-site'),
    statusVersion: document.getElementById('status-version'),
    statusPoll: document.getElementById('status-poll'),
    statusPhase: document.getElementById('status-phase'),
    statusError: document.getElementById('status-error'),
    statusMessage: document.getElementById('status-message'),
    refresh: document.getElementById('refresh'),
    poll: document.getElementById('poll'),
    disconnect: document.getElementById('disconnect'),
  };

  function send(message) {
    return chrome.runtime.sendMessage(message).then(function (response) {
      if (!response) {
        throw new Error('empty_response');
      }
      return response;
    });
  }

  function setMessage(element, text, kind) {
    element.textContent = text;
    element.dataset.kind = kind || '';
  }

  const AUTH_ERROR_MESSAGES = {
    grant_not_configured: '后台未配置扩展授权（grant_not_configured），请先在「浏览器扩展」页生成一次性授权码。',
    grant_invalid: '授权码无效（grant_invalid），请重新生成。',
    grant_expired: '授权码已过期（grant_expired），请重新生成。',
    grant_already_used: '授权码已使用（grant_already_used），请重新生成。',
    grant_cache_unavailable: '授权码状态写入失败（grant_cache_unavailable），请稍后重试。',
    expired: '授权码已过期（expired），请重新生成。',
    invalid_code: '授权码无效或已使用（invalid_code），请重新生成。',
  };

  function authErrorText(message) {
    const code = String(message || 'authorization_failed');
    return AUTH_ERROR_MESSAGES[code] || ('连接失败：' + code);
  }

  function normalizeSiteId(value) {
    const parsed = Number.parseInt(String(value == null ? '0' : value), 10);
    return Number.isInteger(parsed) && parsed >= 0 ? parsed : 0;
  }

  async function persistSiteId(websiteId) {
    const stored = await chrome.storage.local.get('weline_social_settings');
    const settings = stored && stored.weline_social_settings && typeof stored.weline_social_settings === 'object'
      ? stored.weline_social_settings
      : {};
    await chrome.storage.local.set({
      weline_social_site_id: websiteId,
      weline_social_settings: { ...settings, websiteId },
    });
  }

  async function readStoredSiteId() {
    const stored = await chrome.storage.local.get(['weline_social_settings', 'weline_social_site_id']);
    const settings = stored && stored.weline_social_settings && typeof stored.weline_social_settings === 'object'
      ? stored.weline_social_settings
      : {};
    const value = settings.websiteId ?? settings.siteId ?? stored.weline_social_site_id;
    if (value !== undefined && !siteIdInitialized) {
      elements.siteId.value = String(normalizeSiteId(value));
      siteIdInitialized = true;
    }
  }

  let siteIdInitialized = false;

  function apiPermissionOrigin(apiBase) {
    try {
      return new URL(apiBase).origin + '/*';
    } catch {
      return '';
    }
  }

  function isAllowedApiBase(apiBase) {
    try {
      const url = new URL(apiBase);
      if (url.protocol === 'https:') {
        return true;
      }
      if (url.protocol !== 'http:') {
        return false;
      }
      const host = url.hostname.toLowerCase();
      return host === 'localhost'
        || host === '127.0.0.1'
        || host === '::1'
        || host === '[::1]'
        || host === 'test.weline.com'
        || host.endsWith('.test.weline.com');
    } catch {
      return false;
    }
  }

  function formatTime(value) {
    if (!value) {
      return '—';
    }
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
      return String(value);
    }
    return date.toLocaleString();
  }

  function renderStatus(status) {
    const data = status || {};
    const connected = data.connected === true;
    const phase = String(data.phase || 'idle');
    const hasError = phase === 'error' || Boolean(data.last_error);

    elements.statusPill.textContent = connected ? (hasError ? '已连接 · 异常' : '已连接') : '未连接';
    elements.statusPill.dataset.state = connected ? (hasError ? 'warn' : 'ok') : 'off';
    elements.statusApi.textContent = data.apiBase || '—';
    elements.statusDevice.textContent = data.deviceName
      ? data.deviceName + (data.token_hint ? ' · token …' + data.token_hint : '')
      : (data.token_hint ? 'token …' + data.token_hint : '—');
    elements.statusSite.textContent = data.websiteId !== undefined
      ? String(normalizeSiteId(data.websiteId))
      : '—';
    elements.statusVersion.textContent = data.version || '—';
    elements.statusPoll.textContent = formatTime(data.last_poll_at);
    elements.statusPhase.textContent = phase;
    elements.statusError.textContent = data.last_error || '—';
    elements.authHint.hidden = data.has_token === true;

    if (!elements.apiBase.value && data.apiBase) {
      elements.apiBase.value = data.apiBase;
    }
    if (!elements.deviceName.value && data.deviceName) {
      elements.deviceName.value = data.deviceName;
    }
    if (!siteIdInitialized && data.websiteId !== undefined) {
      elements.siteId.value = String(normalizeSiteId(data.websiteId));
      siteIdInitialized = true;
    }
  }

  async function refreshStatus() {
    try {
      const status = await send({ type: 'weline-social/status' });
      renderStatus(status);
      setMessage(elements.statusMessage, '', '');
    } catch (error) {
      renderStatus({ connected: false, phase: 'error', last_error: error.message || 'status_failed' });
      setMessage(elements.statusMessage, '读取状态失败：' + (error.message || 'status_failed'), 'error');
    }
  }

  async function saveAuthorization(event) {
    event.preventDefault();
    const apiBase = elements.apiBase.value.trim();
    const code = elements.authCode.value.trim();
    const deviceName = elements.deviceName.value.trim();
    const websiteId = normalizeSiteId(elements.siteId.value);

    if (apiBase === '' || code === '') {
      setMessage(elements.authMessage, '请填写服务地址和一次性授权码。', 'error');
      return;
    }
    if (!isAllowedApiBase(apiBase)) {
      setMessage(
        elements.authMessage,
        '服务地址必须是 https://，或本机 http://localhost / http://127.0.0.1 / http://*.test.weline.com。',
        'error'
      );
      return;
    }

    const permissionOrigin = apiPermissionOrigin(apiBase);
    if (permissionOrigin === '') {
      setMessage(elements.authMessage, '服务地址无效，请填写 https:// 地址或本机 http://localhost 地址。', 'error');
      return;
    }

    elements.connect.disabled = true;
    try {
      if (!chrome.permissions || typeof chrome.permissions.request !== 'function') {
        throw new Error('permissions_api_unavailable');
      }
      const granted = await chrome.permissions.request({ origins: [permissionOrigin] });
      if (!granted) {
        setMessage(elements.authMessage, '未授予 ' + permissionOrigin + ' 的访问权限，无法连接。', 'error');
        return;
      }

      try {
        await persistSiteId(websiteId);
      } catch {
        // Authorization can still proceed when local settings persistence fails.
      }
      setMessage(elements.authMessage, '正在交换设备 token…', 'pending');
      const result = await send({
        type: 'weline-social/authorize',
        payload: { apiBase, code, deviceName, website_id: websiteId },
      });
      if (!result.ok) {
        throw new Error(result.error || 'authorization_failed');
      }
      const savedWebsiteId = normalizeSiteId(result.website_id == null ? websiteId : result.website_id);
      elements.siteId.value = String(savedWebsiteId);
      elements.statusSite.textContent = String(savedWebsiteId);
      siteIdInitialized = true;
      elements.authCode.value = '';
      setMessage(elements.authMessage, '连接成功，设备 token 已保存到本机扩展存储。', 'ok');
      await refreshStatus();
    } catch (error) {
      setMessage(elements.authMessage, authErrorText(error.message), 'error');
    } finally {
      elements.connect.disabled = false;
    }
  }

  async function pollNow() {
    elements.poll.disabled = true;
    setMessage(elements.statusMessage, '正在轮询…', 'pending');
    try {
      const result = await send({ type: 'weline-social/poll-now' });
      if (!result.ok) {
        throw new Error(result.error || 'poll_failed');
      }
      const claimed = result.claimed === true;
      setMessage(
        elements.statusMessage,
        claimed ? '已领取 1 条任务并执行完成。' : '轮询完成，当前没有待执行任务。',
        'ok'
      );
      await refreshStatus();
    } catch (error) {
      setMessage(elements.statusMessage, '轮询失败：' + (error.message || 'poll_failed'), 'error');
    } finally {
      elements.poll.disabled = false;
    }
  }

  async function disconnect() {
    elements.disconnect.disabled = true;
    try {
      const result = await send({ type: 'weline-social/disconnect' });
      if (!result.ok) {
        throw new Error(result.error || 'disconnect_failed');
      }
      setMessage(elements.statusMessage, '已移除本机 token。', 'ok');
      await refreshStatus();
    } catch (error) {
      setMessage(elements.statusMessage, '断开失败：' + (error.message || 'disconnect_failed'), 'error');
    } finally {
      elements.disconnect.disabled = false;
    }
  }

  elements.form.addEventListener('submit', saveAuthorization);
  elements.refresh.addEventListener('click', refreshStatus);
  elements.poll.addEventListener('click', pollNow);
  elements.disconnect.addEventListener('click', disconnect);

  (async function init() {
    try {
      await readStoredSiteId();
    } catch {
      // Status refresh below can still provide websiteId.
    }
    await refreshStatus();
  })();
  setInterval(refreshStatus, 15000);
})();
