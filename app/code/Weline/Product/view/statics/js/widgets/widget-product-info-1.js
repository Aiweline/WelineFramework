window.WelineWidgetAssets.register('product-product-info-1', function (widgetScript) {
(function () {
    const root = document.querySelector(('.product-native-detail--amazon[data-product-id="' + Number(widgetScript.dataset.v0) + '"]'))
        || document.querySelector('.product-native-detail--amazon');
    if (!root) {
        return;
    }
    const modal = root.querySelector('[data-product-quote-modal]');
    const form = root.querySelector('[data-product-quote-form]');
    const openButton = root.querySelector('[data-action="open-product-quote"]');
    if (!modal || !form || !openButton) {
        return;
    }
    const messageNode = modal.querySelector('[data-product-quote-message]');

    function openModal() {
        modal.hidden = false;
        syncModalFromButton();
        if (messageNode) {
            messageNode.textContent = '';
            messageNode.className = 'product-native-detail__quote-form-message';
        }
    }

    function closeModal() {
        modal.hidden = true;
    }

    function syncModalFromButton() {
        const sku = openButton.dataset.sku || '';
        const selection = openButton.dataset.selectionLabel || '';
        const currency = openButton.dataset.currency || 'CNY';
        const minor = Math.max(0, Number(openButton.dataset.referencePriceMinor) || 0);
        const campaign = openButton.dataset.campaignLabel || '';
        const skuNode = modal.querySelector('[data-quote-field="sku"]');
        if (skuNode) {
            skuNode.textContent = sku;
        }
        const selectionWrap = modal.querySelector('[data-quote-field="selection-wrap"]');
        const selectionNode = modal.querySelector('[data-quote-field="selection"]');
        if (selectionWrap && selectionNode) {
            selectionWrap.hidden = selection === '';
            selectionNode.textContent = selection;
        }
        const priceWrap = modal.querySelector('[data-quote-field="price-wrap"]');
        const priceNode = modal.querySelector('[data-quote-field="price"]');
        if (priceWrap && priceNode) {
            priceWrap.hidden = minor <= 0;
            priceNode.textContent = currencySymbol(currency) + (minor / 100).toFixed(2);
        }
        const campaignWrap = modal.querySelector('[data-quote-field="campaign-wrap"]');
        const campaignNode = modal.querySelector('[data-quote-field="campaign"]');
        if (campaignWrap && campaignNode) {
            campaignWrap.hidden = campaign === '';
            campaignNode.textContent = campaign;
        }
    }

    function idempotencyKey() {
        const seed = String(Date.now()) + '-' + Math.random().toString(36).slice(2) + '-' + Math.random().toString(36).slice(2);
        return seed.replace(/[^A-Za-z0-9._-]/g, '').slice(0, 64);
    }

    openButton.addEventListener('click', function () {
        if (openButton.disabled || openButton.hidden) {
            return;
        }
        openModal();
    });
    try {
        const params = new URLSearchParams(window.location.search || '');
        const wantsQuote = params.get('quote') === '1'
            || (window.location.hash || '') === '#quote';
        if (wantsQuote && !openButton.disabled && !openButton.hidden) {
            openModal();
            params.delete('quote');
            const nextQuery = params.toString();
            const nextUrl = window.location.pathname
                + (nextQuery ? ('?' + nextQuery) : '');
            window.history.replaceState({}, '', nextUrl);
        }
    } catch (error) {
        // Ignore deep-link cleanup failures; CTA click still works.
    }
    modal.querySelectorAll('[data-product-quote-close]').forEach(function (node) {
        node.addEventListener('click', closeModal);
    });
    form.addEventListener('submit', function (event) {
        event.preventDefault();
        const email = String((form.elements.email && form.elements.email.value) || '').trim();
        const phone = String((form.elements.phone && form.elements.phone.value) || '').trim();
        if (email === '' && phone === '') {
            if (messageNode) {
                messageNode.textContent = JSON.parse(widgetScript.dataset.v1 || 'null');
                messageNode.className = 'product-native-detail__quote-form-message is-error';
            }
            return;
        }
        let selection = {};
        try {
            selection = JSON.parse(openButton.dataset.selectionJson || '{}') || {};
        } catch (error) {
            selection = {};
        }
        const payload = {
            product_id: Number(openButton.dataset.productId || 0),
            sku: String(openButton.dataset.sku || ''),
            global_offer_uuid: String(openButton.dataset.globalOfferUuid || ''),
            selection: selection,
            reference_price_minor: Number(openButton.dataset.referencePriceMinor || 0),
            currency: String(openButton.dataset.currency || 'CNY'),
            campaign_label: String(openButton.dataset.campaignLabel || ''),
            product_url: String(openButton.dataset.productUrl || (window.location.pathname + window.location.search)),
            contact_name: String((form.elements.contact_name && form.elements.contact_name.value) || '').trim(),
            email: email,
            phone: phone,
            quantity: Number((form.elements.quantity && form.elements.quantity.value) || 1),
            message: String((form.elements.message && form.elements.message.value) || '').trim(),
            address: (function () {
                const pick = function (name) {
                    const el = form.elements[name];
                    return String((el && el.value) || '').trim();
                };
                return {
                    postal_code: pick('postal_code'),
                    country_code: pick('country_code'),
                    country: pick('country'),
                    province: pick('province'),
                    city: pick('city'),
                    district: pick('district'),
                    street: pick('street'),
                    address1: pick('address1'),
                };
            })(),
            company_website: String((form.elements.company_website && form.elements.company_website.value) || ''),
            locale: document.documentElement.lang || '',
            idempotency_key: idempotencyKey(),
            captcha_provider: String((form.elements.captcha_provider && form.elements.captcha_provider.value) || ''),
            captcha_token: String((form.elements.captcha_token && form.elements.captcha_token.value) || ''),
            captcha_response: String((form.elements.captcha_response && form.elements.captcha_response.value) || ''),
        };
        const submitUrl = openButton.dataset.quoteSubmitUrl || '';
        if (!submitUrl) {
            return;
        }
        const submitButton = form.querySelector('.product-native-detail__quote-submit');
        if (submitButton) {
            submitButton.disabled = true;
        }
        fetch(submitUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify(payload),
        }).then(function (response) {
            return response.json().catch(function () {
                return { success: false, message: JSON.parse(widgetScript.dataset.v2 || 'null') };
            }).then(function (data) {
                return { ok: response.ok, data: data || {} };
            });
        }).then(function (result) {
            if (messageNode) {
                messageNode.textContent = String(result.data.message || (result.ok
                    ? JSON.parse(widgetScript.dataset.v3 || 'null')
                    : JSON.parse(widgetScript.dataset.v4 || 'null')));
                messageNode.className = 'product-native-detail__quote-form-message ' + (result.ok && result.data.success !== false ? 'is-success' : 'is-error');
            }
            if (result.ok && result.data.success !== false) {
                form.reset();
                setTimeout(closeModal, 1200);
            }
        }).catch(function () {
            if (messageNode) {
                messageNode.textContent = JSON.parse(widgetScript.dataset.v5 || 'null');
                messageNode.className = 'product-native-detail__quote-form-message is-error';
            }
        }).finally(function () {
            if (submitButton) {
                submitButton.disabled = false;
            }
        });
    });
})();
});
