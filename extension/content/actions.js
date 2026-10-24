(function (root, factory) {
  if (typeof module === 'object' && module.exports) {
    module.exports = factory(require('../shared/schema.js'), require('./snapshot.js'));
  } else {
    root.WelineSocialActions = factory(root.WelineSocialSchema, root.WelineSocialSnapshot);
  }
})(typeof globalThis !== 'undefined' ? globalThis : this, function (schema, snapshot) {
  'use strict';

  const globalRef = typeof globalThis !== 'undefined' ? globalThis : null;

  function actionError(code) {
    const error = new Error(code);
    error.code = code;
    return error;
  }

  function currentDocument() {
    return typeof document !== 'undefined' ? document : null;
  }

  function currentWindow() {
    return typeof window !== 'undefined' ? window : null;
  }

  function tagOf(element) {
    return String((element && element.tagName) || '').toLowerCase();
  }

  function attribute(element, name) {
    if (!element || typeof element.getAttribute !== 'function') {
      return null;
    }
    try {
      return element.getAttribute(name);
    } catch {
      return null;
    }
  }

  function isPasswordField(element) {
    return String(attribute(element, 'type') || element.type || '').toLowerCase() === 'password';
  }

  function resolveTarget(action) {
    const target = schema.normalizeTarget(action);
    if (!target) {
      throw actionError('target_required');
    }

    let element = null;
    if (target.handle) {
      element = snapshot.resolveHandle(target.handle);
    } else {
      element = snapshot.findBySemantic(target);
    }

    if (!element || element.isConnected === false) {
      throw actionError('target_not_found');
    }
    if (isPasswordField(element)) {
      throw actionError('password_target_forbidden');
    }
    return element;
  }

  function dispatchInput(element) {
    element.dispatchEvent(new Event('input', { bubbles: true }));
    element.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function typeInto(element, text) {
    if (isPasswordField(element)) {
      throw actionError('password_target_forbidden');
    }
    if (typeof element.focus === 'function') {
      element.focus();
    }

    const contentEditable = String(attribute(element, 'contenteditable') || '').toLowerCase() === 'true'
      || element.isContentEditable === true;
    if (contentEditable) {
      element.textContent = text;
    } else {
      const descriptor = Object.getOwnPropertyDescriptor(Object.getPrototypeOf(element), 'value');
      if (descriptor && typeof descriptor.set === 'function') {
        descriptor.set.call(element, text);
      } else {
        element.value = text;
      }
    }
    dispatchInput(element);
  }

  function dataUrlToFile(dataUrl, mime, name) {
    const prefix = 'data:' + mime + ';base64,';
    if (typeof dataUrl !== 'string' || dataUrl.trim() === '') {
      throw actionError('upload_payload_invalid');
    }
    let base64 = dataUrl.trim();
    if (base64.startsWith('data:')) {
      if (!base64.startsWith(prefix)) {
        throw actionError('upload_payload_invalid');
      }
      base64 = base64.slice(prefix.length);
    }
    const binary = atob(base64);
    const bytes = new Uint8Array(binary.length);
    for (let index = 0; index < binary.length; index += 1) {
      bytes[index] = binary.charCodeAt(index);
    }
    const extension = mime === 'image/png' ? 'png' : (mime === 'video/mp4' ? 'mp4' : 'jpg');
    let filename = typeof name === 'string' ? name.trim().split(/[\\/]/).pop() : '';
    if (filename === '' || !/\.[a-z0-9]+$/i.test(filename)) {
      filename = 'weline-upload.' + extension;
    }
    return new File([bytes], filename, { type: mime });
  }

  function uploadFile(element, payload) {
    if (tagOf(element) !== 'input' || String(attribute(element, 'type') || element.type || '').toLowerCase() !== 'file') {
      throw actionError('upload_target_invalid');
    }
    if (!schema.isUploadMimeAllowed(payload.mime)) {
      throw actionError('upload_mime_rejected');
    }

    const file = dataUrlToFile(payload.data_url, payload.mime, payload.name);
    const transfer = new DataTransfer();
    transfer.items.add(file);
    element.files = transfer.files;
    element.dispatchEvent(new Event('change', { bubbles: true }));
    if (snapshot && typeof snapshot.markUploaded === 'function') {
      snapshot.markUploaded(file.name);
    }
    return { mime: payload.mime, size: file.size };
  }

  function scrollPage(action) {
    const win = currentWindow();
    if (!win) {
      throw actionError('window_unavailable');
    }
    const target = schema.normalizeTarget(action);
    if (target) {
      const element = resolveTarget(action);
      if (typeof element.scrollIntoView === 'function') {
        element.scrollIntoView({ block: 'center', behavior: 'smooth' });
      }
      return { target: true };
    }

    const delta = Number.isInteger(action.delta) ? action.delta : 600;
    if (action.direction === 'top') {
      win.scrollTo({ top: 0, behavior: 'smooth' });
    } else if (action.direction === 'bottom') {
      const doc = currentDocument();
      win.scrollTo({ top: (doc && doc.body && doc.body.scrollHeight) || 0, behavior: 'smooth' });
    } else if (action.direction === 'left' || action.direction === 'right') {
      win.scrollBy({
        left: action.direction === 'left' ? -Math.abs(delta) : Math.abs(delta),
        behavior: 'smooth',
      });
    } else {
      win.scrollBy({
        top: action.direction === 'up' ? -Math.abs(delta) : Math.abs(delta),
        behavior: 'smooth',
      });
    }
    return { direction: action.direction || 'down', delta };
  }

  function assertTarget(element, expect) {
    const expected = typeof expect === 'string' ? expect : '';
    if (expected === '') {
      return true;
    }
    const text = String(element.innerText || element.textContent || element.value || '');
    return text.includes(expected);
  }

  function pageText() {
    const doc = currentDocument();
    if (!doc) {
      return '';
    }
    const body = doc.body || {};
    return String(body.innerText || body.textContent || '');
  }

  async function executeAction(action) {
    const check = schema.validateAction(action);
    if (!check.ok) {
      return { ok: false, action: action && action.type, error: check.error };
    }

    try {
      switch (action.type) {
        case 'navigate': {
          const win = currentWindow();
          if (!win || !win.location) {
            throw actionError('window_unavailable');
          }
          setTimeout(function () {
            win.location.assign(action.url);
          }, 0);
          return { ok: true, action: 'navigate', navigating: true };
        }
        case 'click': {
          const element = resolveTarget(action);
          if (typeof element.click === 'function') {
            element.click();
          } else {
            element.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
          }
          return { ok: true, action: 'click' };
        }
        case 'type': {
          typeInto(resolveTarget(action), action.text);
          return { ok: true, action: 'type' };
        }
        case 'upload': {
          const element = resolveTarget(action);
          const uploaded = uploadFile(element, schema.uploadPayload(action));
          return { ok: true, action: 'upload', mime: uploaded.mime, size: uploaded.size };
        }
        case 'scroll': {
          const scrolled = scrollPage(action);
          return { ok: true, action: 'scroll', ...scrolled };
        }
        case 'wait':
          await new Promise(function (resolve) {
            setTimeout(resolve, action.ms);
          });
          return { ok: true, action: 'wait', ms: action.ms };
        case 'assert': {
          const expected = typeof action.expect === 'string' ? action.expect : '';
          const target = schema.normalizeTarget(action);
          const matched = target
            ? assertTarget(resolveTarget(action), expected)
            : (expected === '' || pageText().includes(expected));
          return {
            ok: matched,
            action: 'assert',
            matched,
            error: matched ? undefined : 'assert_failed',
          };
        }
        case 'done':
          return { ok: true, action: 'done', done: true, remote_url: action.remote_url };
        case 'give_up':
          return {
            ok: false,
            action: 'give_up',
            give_up: true,
            error: 'give_up',
            reason: action.reason,
          };
        default:
          return { ok: false, action: String(action.type || ''), error: 'action_not_allowed' };
      }
    } catch (error) {
      return {
        ok: false,
        action: String(action.type || ''),
        error: error && error.code ? error.code : 'action_failed',
      };
    }
  }

  function installMessageListener() {
    if (typeof chrome === 'undefined' || !chrome.runtime || !chrome.runtime.onMessage) {
      return false;
    }
    if (globalRef && globalRef.__welineSocialActionsListener) {
      return false;
    }
    if (globalRef) {
      globalRef.__welineSocialActionsListener = true;
    }

    chrome.runtime.onMessage.addListener(function (message, _sender, sendResponse) {
      if (!message || typeof message.type !== 'string') {
        return false;
      }
      if (message.type === 'weline-social/ping') {
        sendResponse({ ok: true });
        return false;
      }
      if (message.type === 'weline-social/snapshot') {
        try {
          const captured = snapshot.collectSnapshot();
          const result = schema.validateSnapshot(captured);
          sendResponse({ ok: result.ok, snapshot: captured, error: result.error });
        } catch (error) {
          sendResponse({ ok: false, error: error.message || 'snapshot_failed' });
        }
        return false;
      }
      if (message.type === 'weline-social/action') {
        executeAction(message.action)
          .then(function (result) {
            sendResponse(result);
          })
          .catch(function () {
            sendResponse({ ok: false, error: 'action_failed' });
          });
        return true;
      }
      return false;
    });
    return true;
  }

  installMessageListener();

  return {
    executeAction,
    resolveTarget,
    installMessageListener,
  };
});
