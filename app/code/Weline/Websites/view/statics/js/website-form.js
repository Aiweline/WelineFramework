const UI = window.Weline?.UI;

if (!UI) {
    throw new Error('Weline.UI is required by the Website form.');
}

UI.define('website-form', ({ element, listen }) => {
    const timezoneSearch = element.querySelector('#timezone-search');
    const timezoneSelect = element.querySelector('#default_timezone');
    const defaultLanguage = element.querySelector('#website_default_language_selector_wrapper');
    const relatedLanguages = element.querySelector('#website_related_languages_selector_wrapper');
    let filterTimer = 0;
    let syncingLanguages = false;

    if (timezoneSearch instanceof HTMLInputElement && timezoneSelect instanceof HTMLSelectElement) {
        const options = [...timezoneSelect.options].map((option) => ({
            value: option.value,
            label: option.textContent || '',
            disabled: option.disabled,
        }));

        const renderTimezones = (query = '') => {
            const term = String(query).trim().toLocaleLowerCase();
            const currentValue = timezoneSelect.value;
            const fragment = document.createDocumentFragment();

            for (const record of options) {
                if (record.value !== ''
                    && record.value !== currentValue
                    && term !== ''
                    && !record.label.toLocaleLowerCase().includes(term)) {
                    continue;
                }
                const option = document.createElement('option');
                option.value = record.value;
                option.textContent = record.label;
                option.disabled = record.disabled;
                option.selected = record.value === currentValue;
                fragment.append(option);
            }

            timezoneSelect.replaceChildren(fragment);
        };

        listen(timezoneSearch, 'input', () => {
            window.clearTimeout(filterTimer);
            filterTimer = window.setTimeout(() => renderTimezones(timezoneSearch.value), 160);
        });
        listen(timezoneSearch, 'keydown', (event) => {
            if (event.key !== 'Escape' || timezoneSearch.value === '') return;
            event.preventDefault();
            timezoneSearch.value = '';
            renderTimezones();
        });
        listen(timezoneSelect, 'change', () => {
            const selected = timezoneSelect.selectedOptions[0];
            timezoneSearch.value = selected?.value ? selected.textContent || '' : '';
            renderTimezones();
        });
    }

    const syncLanguages = () => {
        if (syncingLanguages
            || !(defaultLanguage instanceof HTMLElement)
            || !(relatedLanguages instanceof HTMLElement)) {
            return;
        }
        const single = UI.get(defaultLanguage, 'language-select');
        const multiple = UI.get(relatedLanguages, 'language-select');
        if (!single || !multiple) return;

        syncingLanguages = true;
        try {
            const current = String(single.getValue() || '').trim();
            const values = [...new Set(multiple.getValues().map((value) => String(value).trim()).filter(Boolean))];
            if (current && !values.includes(current)) values.unshift(current);
            multiple.setReadonlyValues(current ? [current] : []);
            multiple.setValues(values);
        } finally {
            syncingLanguages = false;
        }
    };

    listen(element, 'weline:ui:language-select:ready', syncLanguages);
    listen(element, 'weline:ui:language-select:change', (event) => {
        if (event.target === defaultLanguage) syncLanguages();
    });
    queueMicrotask(syncLanguages);

    return {
        syncLanguages,
        destroy() {
            window.clearTimeout(filterTimer);
        },
    };
});
