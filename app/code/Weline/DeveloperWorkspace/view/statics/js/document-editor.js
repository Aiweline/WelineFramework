let enginePromise = null;
let registered = false;

function loadEngine(source) {
    if (!enginePromise) {
        enginePromise = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = source;
            script.async = true;
            script.addEventListener('load', () => {
                const engine = window.tinymce;
                script.remove();
                if (!engine?.init) {
                    reject(new Error('TinyMCE engine did not expose its expected entry point.'));
                    return;
                }
                resolve(engine);
            }, {once: true});
            script.addEventListener('error', () => {
                script.remove();
                reject(new Error(`Unable to load editor resource: ${source}`));
            }, {once: true});
            document.head.append(script);
        });
    }
    return enginePromise;
}

function register() {
    if (registered || !window.Weline?.UI) return false;
    registered = true;

    window.Weline.UI.define('history-back', ({element, listen}) => {
        listen(element, 'click', () => {
            if (window.history.length > 1) {
                window.history.back();
                return;
            }
            const fallback = element.dataset.wFallback;
            if (fallback) window.location.assign(fallback);
        });
        return {element};
    });

    window.Weline.UI.define('tinymce', ({element}) => {
        let editor = null;
        let engine = null;
        let destroyed = false;
        const form = element.closest('form');

        const ready = loadEngine(element.dataset.wEditorEngine || '')
            .then(async (loadedEngine) => {
                engine = loadedEngine;
                const dark = document.documentElement.dataset.theme === 'dark';
                const editors = await engine.init({
                    target: element,
                    language: element.dataset.wEditorLanguage || 'en',
                    language_url: element.dataset.wEditorLanguageSource || undefined,
                    height: 420,
                    auto_focus: false,
                    branding: false,
                    menubar: false,
                    skin: dark ? 'oxide-dark' : 'oxide',
                    content_css: dark ? 'dark' : 'default',
                    automatic_uploads: true,
                    images_reuse_filename: true,
                    images_upload_url: element.dataset.wEditorUploadUrl || undefined,
                    images_upload_base_path: '/',
                    plugins: 'advlist autolink link image lists charmap preview anchor searchreplace wordcount visualblocks code fullscreen insertdatetime media table',
                    toolbar: 'undo redo | formatselect | bold italic | alignleft aligncenter alignright | bullist numlist outdent indent | link image media table | code preview fullscreen',
                    setup(instance) {
                        instance.on('change input undo redo', () => {
                            instance.save();
                            element.dispatchEvent(new Event('input', {bubbles: true}));
                        });
                    },
                });
                editor = editors[0] || null;
                delete window.tinymce;
                delete window.tinyMCE;
                if (destroyed && editor) engine.remove(editor);
                return editor;
            })
            .catch((error) => {
                window.Weline?.UI?.toast.error(error.message);
                console.error(error);
                return null;
            });

        const save = () => editor?.save();
        form?.addEventListener('submit', save);

        return {
            ready,
            async destroy() {
                destroyed = true;
                form?.removeEventListener('submit', save);
                await ready;
                if (engine && editor) engine.remove(editor);
                editor = null;
                engine = null;
            },
        };
    });

    window.Weline.UI.mount(document);
    return true;
}

if (!register()) {
    document.addEventListener('weline:ui:ready', register, {once: true});
}
