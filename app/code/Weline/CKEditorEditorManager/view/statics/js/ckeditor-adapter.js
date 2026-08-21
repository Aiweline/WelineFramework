const engineLoads = new Map();
let registered = false;

function loadScript(source) {
    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = source;
        script.async = true;
        script.addEventListener('load', () => {
            script.remove();
            resolve();
        }, {once: true});
        script.addEventListener('error', () => {
            script.remove();
            reject(new Error(`Unable to load editor resource: ${source}`));
        }, {once: true});
        document.head.append(script);
    });
}

function loadEngine(engineSource, translationSource) {
    const cacheKey = `${engineSource}|${translationSource}`;
    if (!engineLoads.has(cacheKey)) {
        engineLoads.set(cacheKey, (async () => {
            await loadScript(translationSource);
            await loadScript(engineSource);

            const engine = window.CKSource;
            delete window.CKSource;
            delete window.CKEDITOR_TRANSLATIONS;

            if (!engine?.Editor || !engine?.EditorWatchdog) {
                throw new Error('CKEditor engine did not expose its expected entry points.');
            }
            return engine;
        })());
    }
    return engineLoads.get(cacheKey);
}

function findTarget(name) {
    if (!name) {
        return null;
    }
    return document.getElementById(name)
        || document.querySelector(`.${CSS.escape(name)}`);
}

function syncSource(editor, source) {
    source.value = editor.getData();
    source.textContent = source.value;
    source.dispatchEvent(new Event('input', {bubbles: true}));
}

function register() {
    if (registered || !window.Weline?.UI) {
        return false;
    }
    registered = true;

    window.Weline.UI.define('ckeditor', (marker) => {
        let watchdog = null;
        let editor = null;
        let destroyed = false;

        const ready = (async () => {
            const targetName = marker.dataset.wEditorTarget || '';
            const target = findTarget(targetName);
            if (!target) {
                throw new Error(`CKEditor target not found: ${targetName}`);
            }

            const engine = await loadEngine(
                marker.dataset.wEditorEngine || '',
                marker.dataset.wEditorTranslation || ''
            );
            if (destroyed) {
                return;
            }

            watchdog = new engine.EditorWatchdog();
            watchdog.setCreator((element, config) => engine.Editor.create(element, config));
            watchdog.setDestructor((instance) => instance.destroy());
            watchdog.on('error', (_event, details) => {
                console.error('CKEditor watchdog error', details);
            });

            await watchdog.create(target, {
                language: marker.dataset.wEditorLanguage || 'en',
            });
            editor = watchdog.editor;
            if (!editor) {
                throw new Error('CKEditor watchdog did not create an editor instance.');
            }
            if (destroyed) {
                await watchdog.destroy();
                return;
            }

            editor.model.document.on('change:data', () => syncSource(editor, target));
            syncSource(editor, target);
        })().catch((error) => {
            window.Weline?.UI?.toast.error(error.message);
            console.error(error);
        });

        return {
            ready,
            async destroy() {
                destroyed = true;
                await ready;
                if (watchdog) {
                    await watchdog.destroy();
                }
                watchdog = null;
                editor = null;
            },
        };
    });
    window.Weline.UI.mount(document);
    return true;
}

if (!register()) {
    document.addEventListener('weline:ui:ready', register, {once: true});
}
