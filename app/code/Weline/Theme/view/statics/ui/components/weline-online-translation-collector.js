/* Weline UI source: js/online-translation-collector.js */
export function register(UI) {
    UI.define('online-translation-collector', ({ element }) => {
        let active = true;
        const collect = async () => {
            if (element.dataset.state === 'complete') return;
            try {
                if (typeof window.Weline?.load === 'function') await window.Weline.load('api');
                const resource = await Promise.resolve(window.Weline?.Api?.resource?.('i18n'));
                if (!resource?.collect) throw new Error('Weline.Api is unavailable.');
                const result = await resource.collect({
                    words: window.site?.i18n || {},
                    module: window.site?.module || 'Weline_I18n',
                }, { silent: true });
                if (!active) return;
                element.dataset.state = 'complete';
                console.info(window.__?.(element.dataset.successMessage || 'Translation words collected.', result || {}));
            } catch (error) {
                if (!active) return;
                element.dataset.state = 'error';
                console.warn(window.__?.(
                    element.dataset.errorMessage || 'Unable to collect translation words.',
                    error instanceof Error ? error.message : String(error),
                ));
            }
        };
        queueMicrotask(collect);
        return { collect, element, destroy: () => { active = false; } };
    });
}
