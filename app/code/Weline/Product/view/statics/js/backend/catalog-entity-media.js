/**
 * Shared MediaManager iframe picker for brand/supplier admin forms.
 * Expects:
 * - root [data-product-entity-media-root] (+ optional data-media-identity-* Ambient)
 * - blocks [data-product-entity-media="kind"] with hidden url/asset inputs + preview
 * - dialog [data-product-entity-media-dialog] + iframe[data-product-entity-media-frame]
 */
(function () {
    'use strict';

    function resolvePickerUrl(file) {
        var candidates = [
            file && file.url,
            file && file.path,
            file && file.display_url,
            file && file.editor_preview_url,
            file && file.preview_url,
        ];
        for (var i = 0; i < candidates.length; i += 1) {
            var value = String(candidates[i] || '').trim();
            if (value !== '') {
                return value;
            }
        }
        return '';
    }

    function resolveAssetId(file) {
        return String(
            (file && (file.asset_id || file.id || file.uuid)) || ''
        ).trim();
    }

    function sanitizeIdentityCode(value, fallback) {
        var text = String(value || '').trim().replace(/:/g, '_');
        return text !== '' ? text : fallback;
    }

    function resolveLiveCode(root, ambient) {
        var selector = String(root.getAttribute('data-media-code-input') || '').trim();
        if (selector) {
            var input = root.querySelector(selector);
            if (input instanceof HTMLInputElement || input instanceof HTMLTextAreaElement) {
                var typed = sanitizeIdentityCode(input.value, '');
                if (typed !== '') {
                    return typed;
                }
            }
        }
        return sanitizeIdentityCode(ambient && ambient.code, 'draft');
    }

    function setMedia(block, url, assetId) {
        if (!(block instanceof HTMLElement)) {
            return;
        }
        var urlInput = block.querySelector('[data-product-entity-media-url]');
        var assetInput = block.querySelector('[data-product-entity-media-asset]');
        var preview = block.querySelector('[data-product-entity-media-preview]');
        var img = block.querySelector('[data-product-entity-media-img]');
        var clear = block.querySelector('[data-product-entity-media-clear]');
        url = String(url || '').trim();
        assetId = String(assetId || '').trim();
        if (urlInput instanceof HTMLInputElement) {
            urlInput.value = url;
        }
        if (assetInput instanceof HTMLInputElement) {
            assetInput.value = assetId;
        }
        if (preview instanceof HTMLElement) {
            preview.hidden = url === '';
        }
        if (img instanceof HTMLImageElement) {
            if (url === '') {
                img.removeAttribute('src');
                img.hidden = true;
            } else {
                img.src = url;
                img.hidden = false;
            }
        }
        if (clear instanceof HTMLElement) {
            clear.hidden = url === '';
        }
    }

    function bindRoot(root) {
        if (!(root instanceof HTMLElement) || root.getAttribute('data-entity-media-bound') === '1') {
            return;
        }
        var dialog = root.querySelector('[data-product-entity-media-dialog]');
        var frame = root.querySelector('[data-product-entity-media-frame]');
        var closeBtn = root.querySelector('[data-product-entity-media-close]');
        if (!(dialog instanceof HTMLDialogElement) || !(frame instanceof HTMLIFrameElement)) {
            return;
        }
        root.setAttribute('data-entity-media-bound', '1');
        var activeKind = '';

        function openPicker(kind) {
            activeKind = kind;
            var src = String(frame.dataset.src || '').trim();
            if (src === '') {
                return;
            }
            var pickerUrl;
            try {
                pickerUrl = new URL(src, window.location.href);
            } catch (_err) {
                return;
            }
            if (pickerUrl.searchParams.has('target')) {
                pickerUrl.searchParams.set('target', kind);
            } else {
                pickerUrl.searchParams.set('target', kind);
            }

            var helper = window.Weline && window.Weline.MediaIdentityPicker;
            if (helper) {
                var ambient = helper.ambientFrom(root);
                var identityRoot = String(ambient.root || '').trim();
                if (identityRoot !== '') {
                    var identity = helper.buildIdentity({
                        root: identityRoot,
                        code: resolveLiveCode(root, ambient),
                        scope: ambient.scope || null,
                        kind: ambient.kind || kind,
                        field: ambient.field || kind,
                    });
                    if (identity) {
                        pickerUrl = helper.appendIdentityParams(pickerUrl, identity);
                        dialog.__mediaIdentity = identity;
                        dialog.__mediaAmbient = ambient;
                    }
                }
            }

            frame.src = pickerUrl.href;
            if (typeof dialog.showModal === 'function') {
                dialog.showModal();
            } else {
                dialog.setAttribute('open', '');
            }
        }

        function closePicker() {
            activeKind = '';
            frame.removeAttribute('src');
            if (typeof dialog.close === 'function') {
                dialog.close();
            } else {
                dialog.removeAttribute('open');
            }
        }

        root.querySelectorAll('[data-product-entity-media]').forEach(function (block) {
            if (!(block instanceof HTMLElement)) {
                return;
            }
            var kind = String(block.getAttribute('data-product-entity-media') || '');
            var pick = block.querySelector('[data-product-entity-media-pick]');
            var clear = block.querySelector('[data-product-entity-media-clear]');
            if (pick) {
                pick.addEventListener('click', function () {
                    openPicker(kind);
                });
            }
            if (clear) {
                clear.addEventListener('click', function () {
                    setMedia(block, '', '');
                });
            }
        });

        if (closeBtn) {
            closeBtn.addEventListener('click', closePicker);
        }
        dialog.addEventListener('cancel', function (event) {
            event.preventDefault();
            closePicker();
        });

        window.addEventListener('message', function (event) {
            if (!event || !event.data || typeof event.data !== 'object') {
                return;
            }
            if (event.source && frame.contentWindow && event.source !== frame.contentWindow) {
                return;
            }
            if (event.origin && event.origin !== window.location.origin) {
                return;
            }
            var target = String(event.data.target || '');
            if (target === '' || (activeKind !== '' && activeKind !== target)) {
                return;
            }
            if (event.data.type === 'weline-media-manager-cancel') {
                closePicker();
                return;
            }
            var block = root.querySelector('[data-product-entity-media="' + target + '"]');
            if (!(block instanceof HTMLElement)) {
                return;
            }
            var files = Array.isArray(event.data.files)
                ? event.data.files
                : (event.data.file ? [event.data.file] : []);
            var file = files[0];
            if (!file || typeof file !== 'object') {
                return;
            }
            var url = resolvePickerUrl(file);
            if (url === '') {
                return;
            }
            setMedia(block, url, resolveAssetId(file));
            var helper = window.Weline && window.Weline.MediaIdentityPicker;
            if (helper && dialog.__mediaIdentity && typeof helper.bindSelection === 'function') {
                helper.bindSelection(dialog.__mediaIdentity, files, {
                    bindUrl: (dialog.__mediaAmbient && dialog.__mediaAmbient.bindUrl) || '',
                    ownerType: (dialog.__mediaAmbient && dialog.__mediaAmbient.ownerType) || '',
                    ownerId: (dialog.__mediaAmbient && dialog.__mediaAmbient.ownerId) || '',
                    ownerVersion: (dialog.__mediaAmbient && dialog.__mediaAmbient.ownerVersion) || '1',
                    refMode: (dialog.__mediaAmbient && dialog.__mediaAmbient.refMode) || 'single',
                });
            }
            closePicker();
        });
    }

    function boot() {
        document.querySelectorAll('[data-product-entity-media-root]').forEach(bindRoot);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
