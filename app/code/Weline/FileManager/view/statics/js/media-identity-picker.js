/**
 * Shared MediaReferenceIdentity helpers for backend pickers (product/catalog/theme).
 * Depends on window.w_scope when building; explicit path wins.
 */
(function (global) {
  'use strict';

  function ambientFrom(el) {
    if (!el) return {};
    var ds = el.dataset || {};
    return {
      root: String(ds.mediaIdentityRoot || ds.identityRoot || '').trim(),
      code: String(ds.mediaIdentityCode || ds.identityCode || '').trim(),
      scope: String(ds.mediaIdentityScope || ds.identityScope || '').trim(),
      kind: String(ds.mediaIdentityKind || ds.identityKind || '').trim(),
      field: String(ds.mediaIdentityField || ds.identityField || '').trim(),
      component: String(ds.mediaIdentityComponent || '').trim(),
      locale: String(ds.mediaIdentityLocale || '').trim(),
      instance: String(ds.mediaIdentityInstance || '').trim(),
      path: String(ds.mediaIdentityPath || ds.identity || '').trim(),
      ownerType: String(ds.mediaOwnerType || '').trim(),
      ownerId: String(ds.mediaOwnerId || '').trim(),
      ownerVersion: String(ds.mediaOwnerVersion || '1').trim(),
      bindUrl: String(ds.mediaBindUrl || '').trim(),
      refMode: String(ds.mediaRefMode || 'single').trim(),
    };
  }

  function buildIdentity(partial) {
    partial = partial || {};
    if (partial.path) {
      return {
        path: partial.path,
        root: partial.root || '',
        code: partial.code || '',
        scope: partial.scope || '',
        slot: {
          kind: partial.kind || '',
          field: partial.field || '',
          component: partial.component || '',
          locale: partial.locale || '',
          instance: partial.instance || '',
        },
      };
    }
    var wScope = typeof global.w_scope === 'function'
      ? global.w_scope
      : (global.Weline && typeof global.Weline.w_scope === 'function' ? global.Weline.w_scope : null);
    if (!wScope || !partial.root || !partial.code) {
      return null;
    }
    var slot = {};
    ['kind', 'field', 'component', 'locale', 'instance', 'role', 'axis', 'ns'].forEach(function (k) {
      if (partial[k]) slot[k] = partial[k];
    });
    try {
      var identity = wScope(partial.scope || null, partial.root, partial.code, slot);
      return {
        path: identity.path,
        root: identity.root,
        code: identity.code,
        scope: identity.scope,
        slot: slot,
      };
    } catch (_e) {
      return null;
    }
  }

  function appendIdentityParams(url, identity) {
    if (!url || !identity) return url;
    var u = url instanceof URL ? url : new URL(String(url), global.location.href);
    if (identity.path) u.searchParams.set('identity', identity.path);
    if (identity.root) u.searchParams.set('identity_root', identity.root);
    if (identity.code) u.searchParams.set('identity_code', identity.code);
    if (identity.scope) u.searchParams.set('identity_scope', identity.scope);
    var slot = identity.slot || {};
    Object.keys(slot).forEach(function (k) {
      if (slot[k]) u.searchParams.set('identity_' + k, String(slot[k]));
    });
    return u;
  }

  function bindSelection(identity, files, options) {
    options = options || {};
    var bindUrl = String(options.bindUrl || '').trim();
    if (!bindUrl || !identity || !files || !files.length) {
      return Promise.resolve(null);
    }
    var assetIds = files.map(function (f) { return String(f.asset_id || ''); }).filter(Boolean);
    if (!assetIds.length) return Promise.resolve(null);
    var body = {
      ref_mode: options.refMode || 'single',
      type: identity.root,
      code: identity.code,
      scope: identity.scope || null,
      slot: identity.slot || {},
      owner_type: options.ownerType || identity.root || '',
      owner_id: options.ownerId || identity.code || '',
      owner_version: Number(options.ownerVersion || 1) || 1,
      field_path: identity.path,
      asset_id: assetIds[0],
      asset_ids: assetIds,
    };
    return fetch(bindUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(body),
    }).then(function (r) { return r.json().catch(function () { return null; }); }).catch(function () { return null; });
  }

  function setAmbient(partial) {
    if (global.Weline && global.Weline.MediaIdentity && typeof global.Weline.MediaIdentity.setAmbient === 'function') {
      return global.Weline.MediaIdentity.setAmbient(partial);
    }
    global.__welineMediaIdentityAmbient = Object.assign({}, global.__welineMediaIdentityAmbient || {}, partial || {});
    return global.__welineMediaIdentityAmbient;
  }

  global.Weline = global.Weline || {};
  global.Weline.MediaIdentityPicker = {
    ambientFrom: ambientFrom,
    buildIdentity: buildIdentity,
    appendIdentityParams: appendIdentityParams,
    bindSelection: bindSelection,
    setAmbient: setAmbient,
  };
})(typeof window !== 'undefined' ? window : globalThis);
