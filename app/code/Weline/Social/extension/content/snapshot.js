(function (root, factory) {
  if (typeof module === 'object' && module.exports) {
    module.exports = factory(require('../shared/schema.js'));
  } else {
    root.WelineSocialSnapshot = factory(root.WelineSocialSchema);
  }
})(typeof globalThis !== 'undefined' ? globalThis : this, function (schema) {
  'use strict';

  const INTERACTIVE_SELECTOR = [
    'a[href]',
    'button',
    'input:not([type="hidden"])',
    'textarea',
    'select',
    '[contenteditable="true"]',
    '[role="button"]',
    '[role="link"]',
    '[role="textbox"]',
    '[role="checkbox"]',
    '[role="menuitem"]',
    '[role="tab"]',
    '[tabindex]:not([tabindex="-1"])',
  ].join(',');

  const MAX_ELEMENTS = (schema && schema.MAX_SNAPSHOT_ELEMENTS) || 200;
  const MAX_TEXT = 4000;
  const MAX_LABEL = 120;

  let handleMap = new Map();
  let lastSnapshot = null;
  const uploadedFiles = [];

  function normalizeText(value, limit) {
    const text = String(value == null ? '' : value)
      .replace(/\r\n?/g, '\n')
      .replace(/[ \t]+/g, ' ')
      .replace(/\n{3,}/g, '\n\n')
      .trim();
    return text.length > limit ? text.slice(0, limit) : text;
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

  function tagName(element) {
    return String((element && element.tagName) || '').toLowerCase();
  }

  function isVisible(element) {
    if (!element || element.hidden === true) {
      return false;
    }
    if (attribute(element, 'aria-hidden') === 'true') {
      return false;
    }
    if (element.disabled === true) {
      return false;
    }
    const type = String(attribute(element, 'type') || element.type || '').toLowerCase();
    if (type === 'hidden' || type === 'password') {
      return false;
    }

    const ownerDocument = element.ownerDocument;
    const view = ownerDocument && ownerDocument.defaultView;
    if (view && typeof view.getComputedStyle === 'function') {
      const style = view.getComputedStyle(element);
      if (style && (style.display === 'none' || style.visibility === 'hidden' || style.opacity === '0')) {
        return false;
      }
    }
    if (
      element.offsetParent === null
      && typeof element.getClientRects === 'function'
      && element.getClientRects().length === 0
    ) {
      return false;
    }
    return true;
  }

  function inferRole(element) {
    const explicit = String(attribute(element, 'role') || '').trim().toLowerCase();
    if (explicit !== '') {
      return explicit;
    }

    const tag = tagName(element);
    const type = String(attribute(element, 'type') || element.type || '').toLowerCase();
    if (tag === 'a') {
      return 'link';
    }
    if (tag === 'button') {
      return 'button';
    }
    if (tag === 'textarea') {
      return 'textbox';
    }
    if (tag === 'select') {
      return 'combobox';
    }
    if (tag === 'input') {
      if (type === 'file') {
        return 'file';
      }
      if (['submit', 'button', 'reset', 'image'].includes(type)) {
        return 'button';
      }
      if (type === 'checkbox' || type === 'radio') {
        return type;
      }
      return 'textbox';
    }
    if (
      element.isContentEditable === true
      || String(attribute(element, 'contenteditable') || '').toLowerCase() === 'true'
    ) {
      return 'textbox';
    }
    return tag || 'element';
  }

  function inferLabel(element) {
    const ariaLabel = normalizeText(attribute(element, 'aria-label'), MAX_LABEL);
    if (ariaLabel !== '') {
      return ariaLabel;
    }

    const labels = element && element.labels;
    if (labels && labels.length > 0) {
      const labelText = normalizeText(labels[0].innerText || labels[0].textContent || '', MAX_LABEL);
      if (labelText !== '') {
        return labelText;
      }
    }

    for (const attr of ['placeholder', 'alt', 'title', 'name']) {
      const value = normalizeText(attribute(element, attr), MAX_LABEL);
      if (value !== '') {
        return value;
      }
    }

    const tag = tagName(element);
    const type = String(attribute(element, 'type') || element.type || '').toLowerCase();
    if (tag === 'input' && ['submit', 'button', 'reset'].includes(type)) {
      const value = normalizeText(element.value, MAX_LABEL);
      if (value !== '') {
        return value;
      }
    }

    const visible = normalizeText(element && (element.innerText || element.textContent), MAX_LABEL);
    if (visible !== '') {
      return visible;
    }

    return inferRole(element);
  }

  function elementValue(element) {
    const tag = tagName(element);
    const type = String(attribute(element, 'type') || element.type || '').toLowerCase();
    if (type === 'password' || type === 'file' || type === 'checkbox' || type === 'radio') {
      return '';
    }
    if (tag === 'input' || tag === 'textarea' || tag === 'select') {
      return normalizeText(element.value, 500);
    }
    const contentEditable = String(attribute(element, 'contenteditable') || '').toLowerCase() === 'true'
      || element.isContentEditable === true;
    if (contentEditable) {
      return normalizeText(element.innerText || element.textContent, 500);
    }
    return '';
  }

  function collectInteractiveElements(doc) {
    const elements = [];
    const map = new Map();
    if (!doc || typeof doc.querySelectorAll !== 'function') {
      handleMap = map;
      return elements;
    }

    let nodes = [];
    try {
      nodes = Array.from(doc.querySelectorAll(INTERACTIVE_SELECTOR));
    } catch {
      nodes = [];
    }

    for (const element of nodes) {
      if (!isVisible(element)) {
        continue;
      }
      const handle = elements.length + 1;
      const item = {
        handle,
        role: inferRole(element),
        label: inferLabel(element),
        tag: tagName(element),
      };
      const testid = String(attribute(element, 'data-testid') || '').trim();
      if (testid !== '') {
        item.testid = testid.slice(0, 100);
      }
      const value = elementValue(element);
      if (value !== '') {
        item.value = value;
      }
      map.set(handle, element);
      elements.push(item);
      if (elements.length >= MAX_ELEMENTS) {
        break;
      }
    }

    handleMap = map;
    return elements;
  }

  function markUploaded(name) {
    const value = normalizeText(name, 200);
    if (value === '' || uploadedFiles.includes(value)) {
      return;
    }
    uploadedFiles.push(value);
    if (uploadedFiles.length > 20) {
      uploadedFiles.shift();
    }
  }

  function collectSnapshot(doc, win) {
    const targetDoc = doc || (typeof document !== 'undefined' ? document : null);
    if (!targetDoc) {
      throw new Error('document_unavailable');
    }
    const targetWin = win || targetDoc.defaultView || (typeof window !== 'undefined' ? window : null);
    const body = targetDoc.body || {};
    const location = targetDoc.location || (targetWin && targetWin.location) || {};
    const elements = collectInteractiveElements(targetDoc);
    const snapshot = {
      url: String(location.href || ''),
      title: normalizeText(targetDoc.title || '', 200),
      visible_text: normalizeText(body.innerText || body.textContent || '', MAX_TEXT),
      elements,
      uploaded: uploadedFiles.slice(),
      captured_at: new Date().toISOString(),
    };
    lastSnapshot = snapshot;
    return snapshot;
  }

  function resolveHandle(handle) {
    const key = Number(handle);
    if (!Number.isInteger(key) || key < 1) {
      return null;
    }
    return handleMap.get(key) || null;
  }

  function findBySemantic(target) {
    if (!target || typeof target !== 'object') {
      return null;
    }
    const role = typeof target.role === 'string' ? target.role.trim().toLowerCase() : '';
    const label = typeof target.label === 'string' ? target.label.trim().toLowerCase() : '';
    if (label === '') {
      return null;
    }

    let partial = null;
    for (const element of handleMap.values()) {
      const elementRole = inferRole(element).toLowerCase();
      const elementLabel = inferLabel(element).toLowerCase();
      if (role !== '' && elementRole !== role) {
        continue;
      }
      if (elementLabel === label) {
        return element;
      }
      if (partial === null && elementLabel.includes(label)) {
        partial = element;
      }
    }
    return partial;
  }

  function getLastSnapshot() {
    return lastSnapshot;
  }

  return {
    collectInteractiveElements,
    collectSnapshot,
    markUploaded,
    resolveHandle,
    findBySemantic,
    getLastSnapshot,
    inferRole,
    inferLabel,
    isVisible,
  };
});
