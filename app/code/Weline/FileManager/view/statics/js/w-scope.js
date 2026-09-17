/**
 * MediaReferenceIdentity.v1 — window.w_scope (PHP-isomorphic helper).
 * Mounted by Theme.js and/or this file for non-Theme admin pages.
 */
(function (global) {
  'use strict';

  var AMBIENT_KEY = '__welineMediaIdentityAmbient';

  function isValidScope(scope) {
    if (typeof scope !== 'string') return false;
    scope = scope.trim().toLowerCase();
    var parts = scope.split('.');
    return parts.length === 3 && parts[0] && parts[1] && parts[2] && scope.indexOf(':') < 0;
  }

  function codeKeyForType(type, other) {
    if (other && typeof other.code_key === 'string' && other.code_key) {
      return String(other.code_key).toLowerCase();
    }
    var map = {
      product: 'sku',
      product_brand: 'brand',
      product_supplier: 'supplier',
      theme: 'theme',
      widget: 'theme',
      blog: 'post',
      catalog: 'category',
      config: 'key',
      smtp: 'mail',
      website: 'website',
      cms: 'page',
      eav: 'attribute'
    };
    return map[type] || 'code';
  }

  function resolveScope(explicit) {
    if (isValidScope(explicit)) return String(explicit).trim().toLowerCase();
    var ambient = global[AMBIENT_KEY] || (global.Weline && global.Weline.MediaIdentity && global.Weline.MediaIdentity.getAmbient && global.Weline.MediaIdentity.getAmbient());
    if (ambient && isValidScope(ambient.scope || ambient.storage_scope)) {
      return String(ambient.scope || ambient.storage_scope).trim().toLowerCase();
    }
    return '';
  }

  function w_scope(scope, type, code, other) {
    other = other && typeof other === 'object' ? other : {};
    type = String(type || '').trim().toLowerCase();
    code = String(code || '').trim();
    if (!type || !/^[a-z][a-z0-9_]{0,31}$/.test(type)) {
      throw new Error('w_scope: invalid type');
    }
    if (!code || code.indexOf(':') >= 0) {
      throw new Error('w_scope: invalid code');
    }
    var resolved = resolveScope(scope);
    if (!resolved) {
      throw new Error('w_scope: scope required when context/Ambient missing (CLI must pass storage_scope)');
    }
    var codeKey = codeKeyForType(type, other);
    var segments = [type, codeKey + ':' + code, 'scope:' + resolved];
    var ordered = ['kind', 'role', 'field', 'component', 'layout', 'option', 'locale', 'instance', 'index', 'ns', 'axis'];
    var used = {};
    ordered.forEach(function (k) {
      if (other[k] != null && String(other[k]).trim() !== '' && String(other[k]).indexOf(':') < 0) {
        segments.push(k + ':' + String(other[k]).trim());
        used[k] = true;
      }
    });
    Object.keys(other).sort().forEach(function (k) {
      if (used[k] || k === 'code_key' || k === 'scope' || k === 'root' || k === 'type') return;
      var v = other[k];
      if (v == null || String(v).trim() === '' || String(v).indexOf(':') >= 0) return;
      if (!/^[a-z][a-z0-9_]{0,31}$/.test(k)) return;
      segments.push(k + ':' + String(v).trim());
    });
    var path = segments.join(':');
    var tags = { root: type, scope: resolved };
    tags[codeKey] = code;
    segments.forEach(function (seg) {
      var i = seg.indexOf(':');
      if (i > 0) tags[seg.slice(0, i)] = seg.slice(i + 1);
    });
    return {
      root: type,
      scope: resolved,
      code: code,
      code_key: codeKey,
      path: path,
      tags: tags,
      slot: other
    };
  }

  function setAmbient(partial) {
    var cur = global[AMBIENT_KEY] || {};
    var next = Object.assign({}, cur, partial || {});
    global[AMBIENT_KEY] = next;
    if (!global.Weline) global.Weline = {};
    if (!global.Weline.MediaIdentity) global.Weline.MediaIdentity = {};
    global.Weline.MediaIdentity.ambient = next;
    return next;
  }

  function getAmbient() {
    return global[AMBIENT_KEY] || (global.Weline && global.Weline.MediaIdentity && global.Weline.MediaIdentity.ambient) || null;
  }

  global.w_scope = w_scope;
  if (!global.Weline) global.Weline = {};
  global.Weline.w_scope = w_scope;
  global.Weline.MediaIdentity = global.Weline.MediaIdentity || {};
  global.Weline.MediaIdentity.setAmbient = setAmbient;
  global.Weline.MediaIdentity.getAmbient = getAmbient;
  global.Weline.MediaIdentity.w_scope = w_scope;
})(typeof window !== 'undefined' ? window : globalThis);
