export function register(UI) {
    UI.define('local-translation', ({ element, listen, UI: componentUI }) => {
        const frame = element.querySelector('[data-w-local-frame]');
        const refreshButton = element.querySelector('[data-w-local-refresh]');
        const submitButton = element.querySelector('[data-w-local-submit]');
        const spinner = element.querySelector('[data-w-local-spinner]');

        const source = () => {
            try {
                const url = new URL(element.dataset.wSource || '', window.location.href);
                return url.origin === window.location.origin ? url.href : '';
            } catch (_error) {
                return '';
            }
        };
        const load = (force = false) => {
            if (!(frame instanceof HTMLIFrameElement)) return false;
            const url = source();
            if (url === '') {
                componentUI.toast.error('Translation form URL is invalid.');
                return false;
            }
            if (force || frame.src !== url) frame.src = url;
            return true;
        };
        const setBusy = (busy) => {
            if (submitButton instanceof HTMLButtonElement) {
                submitButton.disabled = busy;
                submitButton.setAttribute('aria-busy', String(busy));
            }
            if (spinner instanceof HTMLElement) spinner.hidden = !busy;
        };
        const submit = () => {
            if (!(frame instanceof HTMLIFrameElement)) return false;
            let frameDocument = null;
            try {
                frameDocument = frame.contentDocument;
            } catch (_error) {
            }
            const form = frameDocument?.getElementById('localTranslationForm');
            if (!(form instanceof HTMLFormElement)) {
                componentUI.toast.warning('Translation form is unavailable.');
                return false;
            }
            if (!form.reportValidity()) return false;
            setBusy(true);
            form.requestSubmit();
            return true;
        };

        listen(element, 'weline:ui:drawer:before-open', () => load());
        if (refreshButton instanceof HTMLButtonElement) listen(refreshButton, 'click', () => load(true));
        if (submitButton instanceof HTMLButtonElement) listen(submitButton, 'click', submit);
        if (frame instanceof HTMLIFrameElement) listen(frame, 'load', () => setBusy(false));

        return { load, submit, element, destroy: () => setBusy(false) };
    });
}
