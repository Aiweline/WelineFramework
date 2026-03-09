(function () {
    'use strict';

    if (window.WelineDomainPurchaseDialog) {
        return;
    }

    var styleInjected = false;

    function injectStyle() {
        if (styleInjected) {
            return;
        }

        var style = document.createElement('style');
        style.textContent = [
            '.weline-domain-purchase-dialog-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200000;display:flex;align-items:center;justify-content:center;padding:16px;}',
            '.weline-domain-purchase-dialog{width:min(640px,100%);background:var(--backend-color-card-bg,#fff);color:var(--backend-color-text-primary,#212529);border:1px solid var(--backend-color-border-default,#dee2e6);border-radius:12px;box-shadow:var(--backend-shadow-lg,0 10px 30px rgba(0,0,0,.18));overflow:hidden;}',
            '.weline-domain-purchase-dialog__header{padding:16px 20px;border-bottom:1px solid var(--backend-color-border-default,#dee2e6);display:flex;justify-content:space-between;align-items:center;gap:12px;}',
            '.weline-domain-purchase-dialog__title{font-size:18px;font-weight:600;}',
            '.weline-domain-purchase-dialog__close{background:transparent;border:none;color:var(--backend-color-text-secondary,#6c757d);font-size:22px;cursor:pointer;}',
            '.weline-domain-purchase-dialog__body{padding:20px;display:flex;flex-direction:column;gap:14px;}',
            '.weline-domain-purchase-dialog__desc{color:var(--backend-color-text-secondary,#6c757d);font-size:13px;line-height:1.5;margin-top:-4px;}',
            '.weline-domain-purchase-dialog__grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}',
            '.weline-domain-purchase-dialog__field{display:flex;flex-direction:column;gap:6px;}',
            '.weline-domain-purchase-dialog__field--full{grid-column:1 / -1;}',
            '.weline-domain-purchase-dialog__label{font-size:13px;font-weight:600;}',
            '.weline-domain-purchase-dialog__input,.weline-domain-purchase-dialog__select{width:100%;padding:10px 12px;border:1px solid var(--backend-color-border-default,#dee2e6);border-radius:8px;background:var(--backend-color-card-bg,#fff);color:var(--backend-color-text-primary,#212529);}',
            '.weline-domain-purchase-dialog__checkbox{display:flex;align-items:center;gap:8px;font-size:13px;}',
            '.weline-domain-purchase-dialog__hint{font-size:12px;color:var(--backend-color-text-secondary,#6c757d);line-height:1.4;}',
            '.weline-domain-purchase-dialog__footer{padding:16px 20px;border-top:1px solid var(--backend-color-border-default,#dee2e6);display:flex;justify-content:flex-end;gap:10px;}',
            '.weline-domain-purchase-dialog__btn{border:none;border-radius:8px;padding:10px 16px;cursor:pointer;font-weight:600;}',
            '.weline-domain-purchase-dialog__btn--cancel{background:var(--backend-color-bg-secondary,#f8f9fa);color:var(--backend-color-text-primary,#212529);}',
            '.weline-domain-purchase-dialog__btn--confirm{background:var(--backend-color-primary,#556ee6);color:var(--backend-color-text-inverse,#fff);}',
            '.weline-domain-purchase-dialog__field[hidden]{display:none !important;}',
            '@media (max-width: 768px){.weline-domain-purchase-dialog__grid{grid-template-columns:1fr;}}'
        ].join('');
        document.head.appendChild(style);
        styleInjected = true;
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text == null ? '' : String(text)));
        return div.innerHTML;
    }

    function getLabelMap(customLabels) {
        return Object.assign({
            title: 'Purchase Domain',
            description: 'Choose how DNS, CDN, and lifecycle monitoring should be handled after the domain is purchased.',
            dnsChoice: 'DNS Strategy',
            dnsFollowRegistrar: 'Follow registrar',
            dnsSelectProviderAccount: 'Specify provider account',
            dnsCustomNameservers: 'Custom nameservers',
            dnsProvider: 'DNS Provider',
            dnsAccount: 'DNS Account',
            dnsProviderPlaceholder: 'Select provider',
            dnsAccountPlaceholder: 'Select account',
            dnsNameservers: 'Nameservers',
            dnsNameserversPlaceholder: 'ns1.example.com, ns2.example.com',
            cdnChoice: 'CDN Strategy',
            cdnFollowRegistrar: 'Follow registrar',
            cdnSelectProviderAccount: 'Specify provider account',
            cdnNone: 'Do not configure CDN',
            cdnProvider: 'CDN Provider',
            cdnAccount: 'CDN Account',
            cdnProviderPlaceholder: 'Select provider',
            cdnAccountPlaceholder: 'Select account',
            resolveToLocal: 'Resolve @ and www to this server',
            resolveHint: 'The purchase flow will create records for the selected subdomains and monitor their status.',
            subdomains: 'Subdomains',
            subdomainsPlaceholder: '@, www',
            startLifecycle: 'Start lifecycle tracking after purchase',
            startLifecycleHint: 'The system will monitor purchase, DNS, verification, and HTTPS status automatically.',
            selectAccountRequired: 'Please select the corresponding provider account.',
            confirm: 'Confirm Purchase',
            cancel: 'Cancel'
        }, customLabels || {});
    }

    function buildProviderMap(accounts) {
        var providers = {};
        (accounts || []).forEach(function (account) {
            if (!account) {
                return;
            }
            var providerCode = String(account.registrar_code || '').trim();
            if (!providerCode) {
                return;
            }
            if (!providers[providerCode]) {
                providers[providerCode] = {
                    code: providerCode,
                    name: String(account.registrar_name || providerCode),
                    accounts: []
                };
            }
            providers[providerCode].accounts.push({
                account_id: String(account.account_id || ''),
                account_name: String(account.account_name || account.name || ''),
                registrar_name: String(account.registrar_name || providerCode)
            });
        });
        return providers;
    }

    function buildOptionsHtml(items, placeholder) {
        var html = '<option value="">' + escapeHtml(placeholder || '') + '</option>';
        (items || []).forEach(function (item) {
            html += '<option value="' + escapeHtml(item.value) + '">' + escapeHtml(item.label) + '</option>';
        });
        return html;
    }

    function showError(message) {
        if (window.BackendToast && typeof window.BackendToast.error === 'function') {
            window.BackendToast.error(message);
            return;
        }
        if (window.AdminToast && typeof window.AdminToast.error === 'function') {
            window.AdminToast.error(message);
        }
    }

    function buildPurchaseErrorMessage(res, fallbackMessage) {
        var base = (res && (res.message || res.msg)) || fallbackMessage || 'Purchase failed';
        if (res && Array.isArray(res.results)) {
            var failed = res.results.filter(function (item) {
                return item && !item.success && item.message;
            });
            if (failed.length > 0) {
                return base + ' ' + failed.map(function (item) {
                    return (item.domain ? item.domain + '：' : '') + item.message;
                }).join('；');
            }
        }
        return base;
    }

    function open(options) {
        injectStyle();

        options = options || {};
        var labels = getLabelMap(options.labels);
        var defaults = Object.assign({
            dnsChoice: 'follow_registrar',
            dnsProvider: '',
            dnsAccountId: '',
            dnsNameservers: '',
            cdnChoice: 'follow_registrar',
            cdnProvider: '',
            cdnAccountId: '',
            resolveToLocal: true,
            subdomains: '@,www',
            startLifecycle: true
        }, options.defaults || {});
        var providerMap = buildProviderMap(options.accounts || []);
        var providerOptions = Object.keys(providerMap).map(function (providerCode) {
            return {
                value: providerCode,
                label: providerMap[providerCode].name
            };
        });

        return new Promise(function (resolve) {
            var overlay = document.createElement('div');
            overlay.className = 'weline-domain-purchase-dialog-overlay';

            overlay.innerHTML = ''
                + '<div class="weline-domain-purchase-dialog" role="dialog" aria-modal="true">'
                + '  <div class="weline-domain-purchase-dialog__header">'
                + '    <div>'
                + '      <div class="weline-domain-purchase-dialog__title">' + escapeHtml(labels.title) + '</div>'
                + '      <div class="weline-domain-purchase-dialog__desc">' + escapeHtml(labels.description) + '</div>'
                + '    </div>'
                + '    <button type="button" class="weline-domain-purchase-dialog__close" data-action="close">&times;</button>'
                + '  </div>'
                + '  <div class="weline-domain-purchase-dialog__body">'
                + '    <div class="weline-domain-purchase-dialog__grid">'
                + '      <div class="weline-domain-purchase-dialog__field">'
                + '        <label class="weline-domain-purchase-dialog__label">' + escapeHtml(labels.dnsChoice) + '</label>'
                + '        <select class="weline-domain-purchase-dialog__select" data-role="dns-choice">'
                + '          <option value="follow_registrar">' + escapeHtml(labels.dnsFollowRegistrar) + '</option>'
                + '          <option value="provider_account">' + escapeHtml(labels.dnsSelectProviderAccount) + '</option>'
                + '          <option value="custom_nameservers">' + escapeHtml(labels.dnsCustomNameservers) + '</option>'
                + '        </select>'
                + '      </div>'
                + '      <div class="weline-domain-purchase-dialog__field">'
                + '        <label class="weline-domain-purchase-dialog__label">' + escapeHtml(labels.cdnChoice) + '</label>'
                + '        <select class="weline-domain-purchase-dialog__select" data-role="cdn-choice">'
                + '          <option value="follow_registrar">' + escapeHtml(labels.cdnFollowRegistrar) + '</option>'
                + '          <option value="provider_account">' + escapeHtml(labels.cdnSelectProviderAccount) + '</option>'
                + '          <option value="none">' + escapeHtml(labels.cdnNone) + '</option>'
                + '        </select>'
                + '      </div>'
                + '      <div class="weline-domain-purchase-dialog__field" data-role="dns-provider-field" hidden>'
                + '        <label class="weline-domain-purchase-dialog__label">' + escapeHtml(labels.dnsProvider) + '</label>'
                + '        <select class="weline-domain-purchase-dialog__select" data-role="dns-provider"></select>'
                + '      </div>'
                + '      <div class="weline-domain-purchase-dialog__field" data-role="dns-account-field" hidden>'
                + '        <label class="weline-domain-purchase-dialog__label">' + escapeHtml(labels.dnsAccount) + '</label>'
                + '        <select class="weline-domain-purchase-dialog__select" data-role="dns-account"></select>'
                + '      </div>'
                + '      <div class="weline-domain-purchase-dialog__field" data-role="cdn-provider-field" hidden>'
                + '        <label class="weline-domain-purchase-dialog__label">' + escapeHtml(labels.cdnProvider) + '</label>'
                + '        <select class="weline-domain-purchase-dialog__select" data-role="cdn-provider"></select>'
                + '      </div>'
                + '      <div class="weline-domain-purchase-dialog__field" data-role="cdn-account-field" hidden>'
                + '        <label class="weline-domain-purchase-dialog__label">' + escapeHtml(labels.cdnAccount) + '</label>'
                + '        <select class="weline-domain-purchase-dialog__select" data-role="cdn-account"></select>'
                + '      </div>'
                + '      <div class="weline-domain-purchase-dialog__field weline-domain-purchase-dialog__field--full" data-role="dns-nameservers-field" hidden>'
                + '        <label class="weline-domain-purchase-dialog__label">' + escapeHtml(labels.dnsNameservers) + '</label>'
                + '        <input type="text" class="weline-domain-purchase-dialog__input" data-role="dns-nameservers" placeholder="' + escapeHtml(labels.dnsNameserversPlaceholder) + '">'
                + '      </div>'
                + '      <div class="weline-domain-purchase-dialog__field weline-domain-purchase-dialog__field--full">'
                + '        <label class="weline-domain-purchase-dialog__checkbox"><input type="checkbox" data-role="resolve-to-local"> <span>' + escapeHtml(labels.resolveToLocal) + '</span></label>'
                + '        <div class="weline-domain-purchase-dialog__hint">' + escapeHtml(labels.resolveHint) + '</div>'
                + '      </div>'
                + '      <div class="weline-domain-purchase-dialog__field weline-domain-purchase-dialog__field--full">'
                + '        <label class="weline-domain-purchase-dialog__label">' + escapeHtml(labels.subdomains) + '</label>'
                + '        <input type="text" class="weline-domain-purchase-dialog__input" data-role="subdomains" placeholder="' + escapeHtml(labels.subdomainsPlaceholder) + '">'
                + '      </div>'
                + '      <div class="weline-domain-purchase-dialog__field weline-domain-purchase-dialog__field--full">'
                + '        <label class="weline-domain-purchase-dialog__checkbox"><input type="checkbox" data-role="start-lifecycle"> <span>' + escapeHtml(labels.startLifecycle) + '</span></label>'
                + '        <div class="weline-domain-purchase-dialog__hint">' + escapeHtml(labels.startLifecycleHint) + '</div>'
                + '      </div>'
                + '    </div>'
                + '  </div>'
                + '  <div class="weline-domain-purchase-dialog__footer">'
                + '    <button type="button" class="weline-domain-purchase-dialog__btn weline-domain-purchase-dialog__btn--cancel" data-action="cancel">' + escapeHtml(labels.cancel) + '</button>'
                + '    <button type="button" class="weline-domain-purchase-dialog__btn weline-domain-purchase-dialog__btn--confirm" data-action="confirm">' + escapeHtml(labels.confirm) + '</button>'
                + '  </div>'
                + '</div>';

            document.body.appendChild(overlay);

            var dialog = overlay.querySelector('.weline-domain-purchase-dialog');
            var dnsChoice = dialog.querySelector('[data-role="dns-choice"]');
            var cdnChoice = dialog.querySelector('[data-role="cdn-choice"]');
            var dnsProviderField = dialog.querySelector('[data-role="dns-provider-field"]');
            var dnsProvider = dialog.querySelector('[data-role="dns-provider"]');
            var dnsAccountField = dialog.querySelector('[data-role="dns-account-field"]');
            var dnsAccount = dialog.querySelector('[data-role="dns-account"]');
            var cdnProviderField = dialog.querySelector('[data-role="cdn-provider-field"]');
            var cdnProvider = dialog.querySelector('[data-role="cdn-provider"]');
            var cdnAccountField = dialog.querySelector('[data-role="cdn-account-field"]');
            var cdnAccount = dialog.querySelector('[data-role="cdn-account"]');
            var dnsNameserversField = dialog.querySelector('[data-role="dns-nameservers-field"]');
            var dnsNameservers = dialog.querySelector('[data-role="dns-nameservers"]');
            var resolveToLocal = dialog.querySelector('[data-role="resolve-to-local"]');
            var subdomains = dialog.querySelector('[data-role="subdomains"]');
            var startLifecycle = dialog.querySelector('[data-role="start-lifecycle"]');

            dnsProvider.innerHTML = buildOptionsHtml(providerOptions, labels.dnsProviderPlaceholder);
            cdnProvider.innerHTML = buildOptionsHtml(providerOptions, labels.cdnProviderPlaceholder);
            dnsChoice.value = defaults.dnsChoice;
            cdnChoice.value = defaults.cdnChoice;
            dnsProvider.value = defaults.dnsProvider || '';
            cdnProvider.value = defaults.cdnProvider || '';
            dnsNameservers.value = defaults.dnsNameservers || '';
            resolveToLocal.checked = !!defaults.resolveToLocal;
            subdomains.value = defaults.subdomains || '@,www';
            startLifecycle.checked = !!defaults.startLifecycle;

            function syncAccountOptions(providerSelect, accountSelect, placeholder, defaultAccountId) {
                var providerCode = providerSelect.value || '';
                var accounts = providerCode && providerMap[providerCode] ? providerMap[providerCode].accounts : [];
                accountSelect.innerHTML = buildOptionsHtml(accounts.map(function (account) {
                    return {
                        value: account.account_id,
                        label: account.account_name + ' (' + account.registrar_name + ')'
                    };
                }), placeholder);
                if (defaultAccountId) {
                    accountSelect.value = String(defaultAccountId);
                } else if (accounts.length === 1) {
                    accountSelect.value = accounts[0].account_id;
                }
            }

            function syncDnsField() {
                var useProviderAccount = dnsChoice.value === 'provider_account';
                dnsProviderField.hidden = !useProviderAccount;
                dnsAccountField.hidden = !useProviderAccount;
                dnsNameserversField.hidden = dnsChoice.value !== 'custom_nameservers';
                if (useProviderAccount) {
                    syncAccountOptions(dnsProvider, dnsAccount, labels.dnsAccountPlaceholder, defaults.dnsAccountId || '');
                }
            }

            function syncCdnField() {
                var useProviderAccount = cdnChoice.value === 'provider_account';
                cdnProviderField.hidden = !useProviderAccount;
                cdnAccountField.hidden = !useProviderAccount;
                if (useProviderAccount) {
                    syncAccountOptions(cdnProvider, cdnAccount, labels.cdnAccountPlaceholder, defaults.cdnAccountId || '');
                }
            }

            function cleanup(value) {
                if (overlay.parentNode) {
                    overlay.parentNode.removeChild(overlay);
                }
                resolve(value);
            }

            syncDnsField();
            syncCdnField();
            dnsChoice.addEventListener('change', syncDnsField);
            dnsProvider.addEventListener('change', function () {
                syncAccountOptions(dnsProvider, dnsAccount, labels.dnsAccountPlaceholder, '');
            });
            cdnChoice.addEventListener('change', syncCdnField);
            cdnProvider.addEventListener('change', function () {
                syncAccountOptions(cdnProvider, cdnAccount, labels.cdnAccountPlaceholder, '');
            });

            overlay.addEventListener('click', function (event) {
                if (event.target === overlay) {
                    cleanup(null);
                }
            });

            overlay.querySelectorAll('[data-action="close"],[data-action="cancel"]').forEach(function (button) {
                button.addEventListener('click', function () {
                    cleanup(null);
                });
            });

            overlay.querySelector('[data-action="confirm"]').addEventListener('click', function () {
                if (dnsChoice.value === 'provider_account' && !dnsAccount.value) {
                    showError(labels.selectAccountRequired);
                    return;
                }
                if (cdnChoice.value === 'provider_account' && !cdnAccount.value) {
                    showError(labels.selectAccountRequired);
                    return;
                }
                cleanup({
                    dns_choice: dnsChoice.value,
                    dns_provider: dnsProvider.value,
                    dns_account_id: dnsAccount.value,
                    dns_nameservers: dnsNameservers.value.trim(),
                    cdn_choice: cdnChoice.value,
                    cdn_provider: cdnProvider.value,
                    cdn_account_id: cdnAccount.value,
                    resolve_to_local: resolveToLocal.checked ? 'yes' : 'no',
                    subdomains: subdomains.value.trim() || '@,www',
                    start_lifecycle: startLifecycle.checked ? '1' : '0'
                });
            });
        });
    }

    window.WelineDomainPurchaseDialog = {
        open: open,
        buildErrorMessage: buildPurchaseErrorMessage
    };
})();
