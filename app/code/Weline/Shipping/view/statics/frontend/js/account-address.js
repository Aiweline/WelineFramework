(function () {
    'use strict';

    var labels = {
        country: '\u4e2d\u56fd',
        contact: '\u8054\u7cfb\u4eba',
        deliveryContact: '\u6536\u8d27\u4eba',
        postal: '\u90ae\u7f16\uff1a',
        defaultText: '\u9ed8\u8ba4',
        edit: '\u7f16\u8f91',
        add: '\u65b0\u589e',
        deliveryAddress: '\u6536\u8d27\u5730\u5740',
        shippingAddress: '\u53d1\u8d27\u5730\u5740',
        setDefault: '\u8bbe\u4e3a\u9ed8\u8ba4',
        remove: '\u5220\u9664',
        settingFailed: '\u8bbe\u7f6e\u5931\u8d25',
        settingSuccess: '\u8bbe\u7f6e\u6210\u529f',
        deleteFailed: '\u5220\u9664\u5931\u8d25',
        deleteSuccess: '\u5220\u9664\u6210\u529f',
        saveFailed: '\u4fdd\u5b58\u5931\u8d25',
        saveSuccess: '\u4fdd\u5b58\u6210\u529f',
        saving: '\u4fdd\u5b58\u4e2d...',
        requestFailed: '\u8bf7\u6c42\u5931\u8d25\uff0c\u8bf7\u7a0d\u540e\u91cd\u8bd5'
    };

    function text(value) {
        return value == null ? '' : String(value);
    }

    function fullAddress(data) {
        return [data.country, data.province, data.city, data.district, data.street].map(text).filter(Boolean).join('');
    }

    function normalize(data, panel) {
        var idField = panel.getAttribute('data-id-field') || 'id';
        data = data || {};
        data.id = data.id || data[idField] || data.shipping_address_id || data.delivery_address_id || '';
        data[idField] = data[idField] || data.id;
        data.country = data.country || labels.country;
        data.full_address = data.full_address || fullAddress(data);
        data.is_default = data.is_default ? 1 : 0;
        return data;
    }

    function showMessage(panel, message, type) {
        var box = panel.querySelector('[data-address-message]');
        if (!box) {
            return;
        }
        box.textContent = message || '';
        box.classList.remove('account-address-message--success', 'account-address-message--danger');
        box.classList.add(type === 'success' ? 'account-address-message--success' : 'account-address-message--danger');
        box.hidden = !message;
    }

    function setCardData(card, panel, data) {
        data = normalize(data, panel);
        card.dataset.addressId = data.id;
        card.querySelector('[data-address-name]').textContent = text(data.name);
        card.querySelector('[data-address-contact]').textContent = text(data.contact_name);
        card.querySelector('[data-address-phone]').textContent = text(data.contact_phone);
        card.querySelector('[data-address-full]').textContent = text(data.full_address);
        card.querySelector('[data-address-contact-label]').textContent = panel.dataset.addressPanel === 'delivery' ? labels.deliveryContact : labels.contact;

        var postal = card.querySelector('[data-address-postal]');
        postal.textContent = data.postal_code ? labels.postal + data.postal_code : '';
        postal.hidden = !data.postal_code;

        var isDefault = !!data.is_default;
        card.classList.toggle('account-address-card--default', isDefault);
        card.querySelector('[data-address-default-badge]').hidden = !isDefault;
        card.querySelector('[data-address-default]').hidden = isDefault;
        card.querySelector('[data-address-edit]').dataset.addressJson = JSON.stringify(data);
        card.querySelector('[data-address-default]').dataset.id = data.id;
        card.querySelector('[data-address-delete]').dataset.id = data.id;
    }

    function createCard() {
        var card = document.createElement('article');
        card.className = 'account-address-card';
        card.setAttribute('data-address-card', '');
        card.innerHTML = [
            '<header class="account-address-card__header">',
            '<div><strong data-address-name></strong><p><span data-address-contact></span> &middot; <span data-address-phone></span></p></div>',
            '<span class="account-address-card__badge" data-address-default-badge hidden>' + labels.defaultText + '</span>',
            '</header>',
            '<div class="account-address-card__body">',
            '<span class="account-address-card__term" data-address-contact-label></span>',
            '<span class="account-address-card__value" data-address-full></span>',
            '<span class="account-address-card__postal" data-address-postal hidden></span>',
            '</div>',
            '<div class="account-address-card__actions">',
            '<button type="button" class="account-address-card__action" data-address-edit>' + labels.edit + '</button>',
            '<button type="button" class="account-address-card__action" data-address-default>' + labels.setDefault + '</button>',
            '<button type="button" class="account-address-card__action account-address-card__action--danger" data-address-delete>' + labels.remove + '</button>',
            '</div>'
        ].join('');
        return card;
    }

    function upsertCard(panel, data) {
        data = normalize(data, panel);
        var list = panel.querySelector('[data-address-list]');
        var card = list.querySelector('[data-address-id="' + data.id + '"]');
        if (!card) {
            card = createCard();
            list.prepend(card);
        }
        setCardData(card, panel, data);
        panel.querySelector('[data-address-empty]').hidden = !!list.querySelector('[data-address-card]');

        if (data.is_default) {
            list.querySelectorAll('[data-address-card]').forEach(function (item) {
                if (item === card) {
                    return;
                }
                item.classList.remove('account-address-card--default');
                item.querySelector('[data-address-default-badge]').hidden = true;
                item.querySelector('[data-address-default]').hidden = false;
                var edit = item.querySelector('[data-address-edit]');
                try {
                    var itemData = JSON.parse(edit.dataset.addressJson || '{}');
                    itemData.is_default = 0;
                    edit.dataset.addressJson = JSON.stringify(itemData);
                } catch (error) {}
            });
        }
    }

    function openForm(panel, data) {
        var wrap = panel.querySelector('[data-address-form-wrap]');
        var form = panel.querySelector('[data-address-form]');
        data = normalize(data || {}, panel);
        form.reset();
        form.querySelector('[name="' + panel.getAttribute('data-id-field') + '"]').value = data.id || '';
        ['name', 'contact_name', 'contact_phone', 'country', 'province', 'city', 'district', 'street', 'postal_code'].forEach(function (field) {
            var input = form.querySelector('[name="' + field + '"]');
            if (input) {
                input.value = data[field] || (field === 'country' ? labels.country : '');
            }
        });
        var checkbox = form.querySelector('[name="is_default"]');
        if (checkbox) {
            checkbox.checked = !!data.is_default;
        }
        form.querySelector('[data-address-form-title]').textContent = (data.id ? labels.edit : labels.add) + (panel.dataset.addressPanel === 'delivery' ? labels.deliveryAddress : labels.shippingAddress);
        wrap.hidden = false;
        wrap.scrollIntoView({behavior: 'smooth', block: 'nearest'});
    }

    function closeForm(panel) {
        var wrap = panel.querySelector('[data-address-form-wrap]');
        var form = panel.querySelector('[data-address-form]');
        form.reset();
        wrap.hidden = true;
    }

    function requestJson(url, formData) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: formData
        }).then(function (response) {
            return response.text().then(function (body) {
                try {
                    return JSON.parse(body);
                } catch (error) {
                    throw new Error('Response is not valid JSON, status: ' + response.status);
                }
            });
        });
    }

    function bindPanel(panel) {
        if (panel.dataset.addressAjaxBound === 'true') {
            return;
        }
        panel.dataset.addressAjaxBound = 'true';

        panel.querySelector('[data-address-new]').addEventListener('click', function () {
            openForm(panel);
        });

        panel.querySelectorAll('[data-address-close], [data-address-cancel]').forEach(function (button) {
            button.addEventListener('click', function () {
                closeForm(panel);
            });
        });

        panel.addEventListener('click', function (event) {
            var edit = event.target.closest('[data-address-edit]');
            var setDefault = event.target.closest('[data-address-default]');
            var remove = event.target.closest('[data-address-delete]');

            if (edit) {
                event.preventDefault();
                try {
                    openForm(panel, JSON.parse(edit.dataset.addressJson || '{}'));
                } catch (error) {
                    openForm(panel);
                }
                return;
            }

            if (setDefault) {
                event.preventDefault();
                var defaultData = new FormData();
                defaultData.append('id', setDefault.dataset.id || '');
                requestJson(panel.dataset.defaultUrl, defaultData).then(function (data) {
                    if (!data.success) {
                        showMessage(panel, data.message || labels.settingFailed, 'danger');
                        return;
                    }
                    panel.querySelectorAll('[data-address-card]').forEach(function (card) {
                        var current = card.dataset.addressId === setDefault.dataset.id;
                        card.classList.toggle('account-address-card--default', current);
                        card.querySelector('[data-address-default-badge]').hidden = !current;
                        card.querySelector('[data-address-default]').hidden = current;
                    });
                    showMessage(panel, data.message || labels.settingSuccess, 'success');
                }).catch(function (error) {
                    showMessage(panel, error.message || labels.requestFailed, 'danger');
                });
                return;
            }

            if (remove) {
                event.preventDefault();
                var deleteData = new FormData();
                deleteData.append('id', remove.dataset.id || '');
                requestJson(panel.dataset.deleteUrl, deleteData).then(function (data) {
                    if (!data.success) {
                        showMessage(panel, data.message || labels.deleteFailed, 'danger');
                        return;
                    }
                    var card = remove.closest('[data-address-card]');
                    if (card) {
                        card.remove();
                    }
                    panel.querySelector('[data-address-empty]').hidden = !!panel.querySelector('[data-address-card]');
                    showMessage(panel, data.message || labels.deleteSuccess, 'success');
                }).catch(function (error) {
                    showMessage(panel, error.message || labels.requestFailed, 'danger');
                });
            }
        });

        panel.querySelector('[data-address-form]').addEventListener('submit', function (event) {
            event.preventDefault();
            var form = event.currentTarget;
            var submit = form.querySelector('[type="submit"]');
            var originalText = submit.textContent;
            submit.disabled = true;
            submit.textContent = labels.saving;

            requestJson(panel.dataset.saveUrl, new FormData(form)).then(function (data) {
                if (!data.success) {
                    showMessage(panel, data.message || labels.saveFailed, 'danger');
                    return;
                }
                upsertCard(panel, data.data || {});
                closeForm(panel);
                showMessage(panel, data.message || labels.saveSuccess, 'success');
            }).catch(function (error) {
                showMessage(panel, error.message || labels.requestFailed, 'danger');
            }).finally(function () {
                submit.disabled = false;
                submit.textContent = originalText;
            });
        });
    }

    function bind() {
        document.querySelectorAll('[data-address-panel]').forEach(bindPanel);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind);
    } else {
        bind();
    }
})();
