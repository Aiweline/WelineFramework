const configElement = document.getElementById('w-datatable-admin-config');
const root = document.querySelector('[data-w-datatable-verification], [data-w-datatable-admin]');
let config = {};

try {
    config = JSON.parse(configElement?.textContent || '{}');
} catch (_error) {
    config = {};
}

async function requestVerification() {
    const url = String(config.verifyUrl || '');
    if (!url || typeof window.Weline?.Api?.get !== 'function') {
        throw new Error(config.requestFailed || 'Verification request is unavailable.');
    }
    return window.Weline.Api.get(url, {silent: true});
}

function verificationSection(payload, section) {
    const report = payload?.data || payload || {};
    if (!section) return report;
    return report?.sections?.[section] || report?.[section] || {};
}

async function runVerification(trigger) {
    const output = root?.querySelector('[data-w-datatable-verify-output]');
    if (!(output instanceof HTMLElement)) return;
    trigger.disabled = true;
    output.textContent = 'Working…';
    try {
        const payload = await requestVerification();
        output.textContent = JSON.stringify(verificationSection(payload, trigger.dataset.section || ''), null, 2);
        output.focus({preventScroll: true});
    } catch (error) {
        output.textContent = error instanceof Error ? error.message : String(error);
    } finally {
        trigger.disabled = false;
    }
}

async function reloadVerification(trigger) {
    trigger.disabled = true;
    try {
        await requestVerification();
        window.location.reload();
    } catch (error) {
        trigger.disabled = false;
        window.Weline?.UI?.toast?.show?.(
            error instanceof Error ? error.message : String(error),
            {tone: 'danger'},
        );
    }
}

root?.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const verify = target?.closest('[data-w-datatable-verify]');
    if (verify instanceof HTMLButtonElement) {
        event.preventDefault();
        runVerification(verify);
        return;
    }
    const reload = target?.closest('[data-w-datatable-verify-reload]');
    if (reload instanceof HTMLButtonElement) {
        event.preventDefault();
        reloadVerification(reload);
    }
});

const sectionHost = root?.querySelector('[data-w-tag-sections]');
if (sectionHost instanceof HTMLElement) {
    const focus = String(sectionHost.dataset.focus || '').trim();
    const target = focus ? document.getElementById(`section-${focus}`) : null;
    if (target && sectionHost.contains(target)) {
        requestAnimationFrame(() => target.scrollIntoView({behavior: 'smooth', block: 'start'}));
    }
}
