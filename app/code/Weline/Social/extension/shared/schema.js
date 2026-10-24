(function (root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  } else {
    root.WelineSocialSchema = api;
  }
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  'use strict';

  const ACTIONS = Object.freeze([
    'navigate',
    'click',
    'type',
    'upload',
    'scroll',
    'wait',
    'assert',
    'done',
    'give_up',
  ]);

  const UPLOAD_MIME_WHITELIST = Object.freeze([
    'image/jpeg',
    'image/png',
    'video/mp4',
  ]);

  const ALLOWED_HOSTS = Object.freeze([
    'facebook.com',
    'x.com',
    'tiktok.com',
  ]);

  const MAX_STEPS = 25;
  const STEP_TIMEOUT_MS = 30000;
  const JOB_TIMEOUT_MS = 600000;
  const MAX_SNAPSHOT_ELEMENTS = 200;

  function ok() {
    return { ok: true };
  }

  function fail(error) {
    return { ok: false, error };
  }

  function isPlainObject(value) {
    return Boolean(value) && typeof value === 'object' && !Array.isArray(value);
  }

  function isHttpsUrl(value) {
    if (typeof value !== 'string' || value.trim() === '') {
      return false;
    }
    try {
      const parsed = new URL(value);
      return parsed.protocol === 'https:' && parsed.hostname !== '';
    } catch {
      return false;
    }
  }

  function isAllowedHostUrl(value) {
    if (!isHttpsUrl(value)) {
      return false;
    }
    try {
      const host = new URL(value).hostname.toLowerCase();
      return ALLOWED_HOSTS.some(function (domain) {
        return host === domain || host.endsWith('.' + domain);
      });
    } catch {
      return false;
    }
  }

  function isAllowedNavigationUrl(value) {
    if (typeof value !== 'string') {
      return false;
    }
    const url = value.trim();
    if (url === '') {
      return false;
    }
    if (url.startsWith('/') && !url.startsWith('//')) {
      return true;
    }
    return isAllowedHostUrl(url);
  }

  function isUploadMimeAllowed(mime) {
    return typeof mime === 'string' && UPLOAD_MIME_WHITELIST.includes(mime);
  }

  function isUploadPayloadAllowed(dataUrl, mime) {
    if (typeof dataUrl !== 'string' || dataUrl.trim() === '' || !isUploadMimeAllowed(mime)) {
      return false;
    }
    const value = dataUrl.trim();
    if (value.startsWith('data:')) {
      const prefix = 'data:' + mime + ';base64,';
      return value.startsWith(prefix) && value.length > prefix.length;
    }
    return value.length % 4 === 0 && /^[A-Za-z0-9+/=\s]+$/.test(value);
  }

  function uploadPayload(action) {
    const asset = isPlainObject(action.asset) ? action.asset : {};
    let name = '';
    for (const candidate of [action.name, asset.name, asset.ref]) {
      if (typeof candidate === 'string' && candidate.trim() !== '') {
        name = candidate.trim();
        break;
      }
    }
    return {
      mime: typeof action.mime === 'string' ? action.mime : (typeof asset.mime === 'string' ? asset.mime : ''),
      data_url: typeof action.data_url === 'string'
        ? action.data_url
        : (typeof asset.data_url === 'string' ? asset.data_url : ''),
      name,
    };
  }

  function normalizeHandle(value) {
    const handle = Number(value);
    return Number.isInteger(handle) && handle > 0 ? handle : 0;
  }

  function hasTargetHint(action) {
    const target = isPlainObject(action.target) ? action.target : action;
    const hasHandle = target.handle !== undefined && target.handle !== null && target.handle !== '';
    const hasRole = typeof target.role === 'string' && target.role.trim() !== '';
    const hasLabel = typeof target.label === 'string' && target.label.trim() !== '';
    return hasHandle || hasRole || hasLabel;
  }

  function normalizeTarget(action) {
    const target = isPlainObject(action.target) ? action.target : action;
    const handle = normalizeHandle(target.handle);
    if (handle > 0) {
      return { handle };
    }
    const role = typeof target.role === 'string' ? target.role.trim() : '';
    const label = typeof target.label === 'string' ? target.label.trim() : '';
    if (role !== '' && label !== '') {
      return { role, label };
    }
    return null;
  }

  function validateAction(action) {
    if (!isPlainObject(action)) {
      return fail('action_invalid');
    }
    if (typeof action.type !== 'string' || !ACTIONS.includes(action.type)) {
      return fail('action_not_allowed');
    }

    switch (action.type) {
      case 'navigate':
        return isAllowedNavigationUrl(action.url) ? ok() : fail('navigate_url_invalid');
      case 'click':
        if (!normalizeTarget(action)) {
          return fail('target_required');
        }
        return ok();
      case 'assert':
        if (typeof action.expect !== 'string') {
          return fail('assert_expect_invalid');
        }
        if (hasTargetHint(action) && !normalizeTarget(action)) {
          return fail('target_required');
        }
        return ok();
      case 'type':
        if (!normalizeTarget(action)) {
          return fail('target_required');
        }
        return typeof action.text === 'string' ? ok() : fail('text_required');
      case 'upload': {
        if (!normalizeTarget(action)) {
          return fail('target_required');
        }
        const upload = uploadPayload(action);
        if (!isUploadMimeAllowed(upload.mime)) {
          return fail('upload_mime_rejected');
        }
        if (!isUploadPayloadAllowed(upload.data_url, upload.mime)) {
          return fail('upload_payload_invalid');
        }
        return ok();
      }
      case 'scroll':
        if (
          action.delta !== undefined
          && (!Number.isInteger(action.delta) || Math.abs(action.delta) > 5000)
        ) {
          return fail('scroll_delta_invalid');
        }
        if (
          action.direction !== undefined
          && !['up', 'down', 'left', 'right', 'top', 'bottom'].includes(action.direction)
        ) {
          return fail('scroll_direction_invalid');
        }
        return ok();
      case 'wait':
        return Number.isInteger(action.ms) && action.ms >= 0 && action.ms <= STEP_TIMEOUT_MS
          ? ok()
          : fail('wait_ms_invalid');
      case 'done':
        return isHttpsUrl(action.remote_url) ? ok() : fail('remote_url_invalid');
      case 'give_up':
        return typeof action.reason === 'string' && action.reason.trim() !== ''
          ? ok()
          : fail('reason_required');
      default:
        return fail('action_invalid');
    }
  }

  function validateSnapshot(snapshot) {
    if (!isPlainObject(snapshot)) {
      return fail('snapshot_invalid');
    }
    if (!isHttpsUrl(snapshot.url)) {
      return fail('snapshot_url_invalid');
    }
    if (!Array.isArray(snapshot.elements)) {
      return fail('snapshot_elements_invalid');
    }
    if (snapshot.elements.length > MAX_SNAPSHOT_ELEMENTS) {
      return fail('snapshot_too_large');
    }
    if (snapshot.visible_text !== undefined && typeof snapshot.visible_text !== 'string') {
      return fail('snapshot_text_invalid');
    }

    const handles = new Set();
    for (const element of snapshot.elements) {
      if (!isPlainObject(element)) {
        return fail('snapshot_element_invalid');
      }
      if (!Number.isInteger(element.handle) || element.handle < 1) {
        return fail('snapshot_handle_invalid');
      }
      if (handles.has(element.handle)) {
        return fail('snapshot_handle_duplicate');
      }
      handles.add(element.handle);
      for (const key of ['role', 'label', 'tag']) {
        if (typeof element[key] !== 'string' || element[key].trim() === '') {
          return fail('snapshot_element_invalid');
        }
      }
    }

    return ok();
  }

  return {
    ACTIONS,
    UPLOAD_MIME_WHITELIST,
    ALLOWED_HOSTS,
    MAX_STEPS,
    STEP_TIMEOUT_MS,
    JOB_TIMEOUT_MS,
    MAX_SNAPSHOT_ELEMENTS,
    isPlainObject,
    isHttpsUrl,
    isAllowedHostUrl,
    isAllowedNavigationUrl,
    isUploadMimeAllowed,
    isUploadPayloadAllowed,
    uploadPayload,
    normalizeHandle,
    normalizeTarget,
    validateAction,
    validateSnapshot,
  };
});
