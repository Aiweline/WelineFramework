(function () {
    'use strict';

    var root = document.querySelector('[data-seo-admin-page="health"]');
    if (!root) {
        return;
    }

    function text(key, fallback) {
        var value = root.getAttribute('data-' + key);
        return value === null || value === '' ? fallback : value;
    }

    function setResult(box, state, message) {
        if (!box) {
            return;
        }
        box.hidden = false;
        box.dataset.state = state;
        box.textContent = message;
    }

    var tabsList = root.querySelector('[data-health-tabs]');
    if (tabsList) {
        var scrollStep = function () {
            return Math.max(220, Math.round(tabsList.clientWidth * 0.7));
        };
        var scrollTabs = function (direction) {
            var max = Math.max(0, tabsList.scrollWidth - tabsList.clientWidth);
            var target = Math.min(max, Math.max(0, tabsList.scrollLeft + direction * scrollStep()));
            tabsList.scrollTo({ left: target, behavior: 'auto' });
        };
        var prev = root.querySelector('[data-health-tabs-prev]');
        var next = root.querySelector('[data-health-tabs-next]');
        if (prev) {
            prev.addEventListener('click', function () {
                scrollTabs(-1);
            });
        }
        if (next) {
            next.addEventListener('click', function () {
                scrollTabs(1);
            });
        }

        var tabSearch = root.querySelector('[data-health-tab-search]');
        if (tabSearch) {
            tabSearch.addEventListener('input', function () {
                var query = tabSearch.value.trim().toLowerCase();
                var tabs = tabsList.querySelectorAll('[data-health-tab]');
                var visible = 0;
                Array.prototype.forEach.call(tabs, function (tab) {
                    var label = (tab.getAttribute('data-tab-label') || '').toLowerCase();
                    var match = query === '' || label.indexOf(query) !== -1;
                    tab.hidden = !match;
                    if (match) {
                        visible++;
                    }
                });
                if (query !== '' && visible === 0) {
                    setResult(root.querySelector('[data-health-probe-result]'), 'error', text('tab-search-empty', 'No matching category'));
                } else {
                    var result = root.querySelector('[data-health-probe-result]');
                    if (result && result.dataset.state === 'error' && result.textContent === text('tab-search-empty', 'No matching category')) {
                        result.hidden = true;
                        result.textContent = '';
                        delete result.dataset.state;
                    }
                }
            });
        }

        tabsList.addEventListener('keydown', function (event) {
            if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
                return;
            }
            var tabs = Array.prototype.slice.call(tabsList.querySelectorAll('[data-health-tab]')).filter(function (tab) {
                return !tab.hidden;
            });
            var index = tabs.indexOf(document.activeElement);
            if (index === -1) {
                return;
            }
            event.preventDefault();
            var nextIndex = event.key === 'ArrowRight' ? Math.min(tabs.length - 1, index + 1) : Math.max(0, index - 1);
            tabs[nextIndex].focus();
            tabs[nextIndex].scrollIntoView({ block: 'nearest', inline: 'nearest' });
        });
    }

    var websiteSelect = root.querySelector('[data-health-website]');
    if (websiteSelect && websiteSelect.form) {
        websiteSelect.addEventListener('change', function () {
            websiteSelect.form.submit();
        });
    }

    var probeButton = root.querySelector('[data-health-probe]');
    if (!probeButton) {
        return;
    }

    var resultBox = root.querySelector('[data-health-probe-result]');
    var endpoint = root.getAttribute('data-probe-endpoint') || '';

    probeButton.addEventListener('click', function () {
        if (probeButton.disabled) {
            return;
        }
        var websiteId = websiteSelect ? Number.parseInt(websiteSelect.value, 10) : -1;
        if (!Number.isInteger(websiteId) || websiteId < 0) {
            setResult(resultBox, 'error', text('probe-empty', 'Please select a website first'));
            return;
        }
        if (endpoint === '') {
            setResult(resultBox, 'error', text('probe-failed', 'Probe failed'));
            return;
        }
        if (!window.confirm(text('confirm-probe', 'Probe now?'))) {
            return;
        }

        var originalText = probeButton.textContent;
        probeButton.disabled = true;
        probeButton.setAttribute('aria-busy', 'true');
        probeButton.textContent = text('probe-running', 'Probing…');
        setResult(resultBox, 'loading', text('probe-running', 'Probing…'));

        var body = new URLSearchParams();
        body.set('website_id', String(websiteId));
        body.set('max_urls', '300');

        window.fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body.toString()
        }).then(function (response) {
            return response.text().then(function (raw) {
                var parsed = null;
                try {
                    parsed = JSON.parse(raw);
                } catch (error) {
                    parsed = null;
                }
                if (!parsed) {
                    throw new Error(raw || 'Invalid response');
                }
                if (!response.ok && !parsed.message) {
                    throw new Error('HTTP ' + response.status);
                }
                return parsed;
            });
        }).then(function (payload) {
            var success = !!(payload && payload.success);
            var message = payload && payload.message ? String(payload.message) : text(success ? 'probe-done' : 'probe-failed', success ? 'Done' : 'Failed');
            setResult(resultBox, success ? 'success' : 'error', message);
            if (success) {
                window.setTimeout(function () {
                    window.location.reload();
                }, 900);
            }
        }).catch(function (error) {
            setResult(resultBox, 'error', text('probe-failed', 'Probe failed') + '：' + (error && error.message ? error.message : ''));
        }).finally(function () {
            probeButton.disabled = false;
            probeButton.removeAttribute('aria-busy');
            probeButton.textContent = originalText;
        });
    });

    var policyForm = root.querySelector('[data-health-policy-form]');
    if (policyForm) {
        policyForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var endpoint = policyForm.getAttribute('data-endpoint') || root.getAttribute('data-policy-endpoint') || '';
            var result = policyForm.querySelector('[data-policy-result]');
            var button = policyForm.querySelector('[data-policy-save]');
            if (endpoint === '') {
                setResult(result, 'error', text('policy-failed', 'Save failed'));
                return;
            }
            var policies = {};
            Array.prototype.forEach.call(policyForm.querySelectorAll('[data-policy-row]'), function (row) {
                var code = row.getAttribute('data-issue-code') || '';
                if (code === '') {
                    return;
                }
                var field = function (name) {
                    return row.querySelector('[data-policy-field="' + name + '"]');
                };
                var channels = field('notify_channels');
                policies[code] = {
                    issue_code: code,
                    auto_mode: field('auto_mode') ? field('auto_mode').value : 'notify',
                    action: field('action') ? field('action').value : 'notify',
                    grace_runs: field('grace_runs') ? Number.parseInt(field('grace_runs').value, 10) || 1 : 1,
                    restore_enabled: field('restore_enabled') && field('restore_enabled').checked ? 1 : 0,
                    restore_grace_runs: field('restore_grace_runs') ? Number.parseInt(field('restore_grace_runs').value, 10) || 1 : 1,
                    notify_channels: channels && channels.value
                        ? channels.value.split(',').map(function (value) { return value.trim(); }).filter(Boolean)
                        : ['backend'],
                    is_enabled: 1
                };
            });

            if (button) {
                button.disabled = true;
                button.setAttribute('aria-busy', 'true');
            }
            setResult(result, 'loading', text('policy-saving', 'Saving…'));

            var body = new URLSearchParams();
            body.set('website_id', root.getAttribute('data-website-id') || '0');
            body.set('policies', JSON.stringify(policies));

            window.fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: body.toString()
            }).then(function (response) {
                return response.text().then(function (raw) {
                    var parsed = null;
                    try {
                        parsed = JSON.parse(raw);
                    } catch (error) {
                        parsed = null;
                    }
                    if (!parsed) {
                        throw new Error(raw || 'Invalid response');
                    }
                    return parsed;
                });
            }).then(function (payload) {
                var success = !!(payload && payload.success);
                setResult(result, success ? 'success' : 'error', (payload && payload.message) || text(success ? 'policy-saved' : 'policy-failed', success ? 'Saved' : 'Save failed'));
                if (success) {
                    window.setTimeout(function () {
                        window.location.reload();
                    }, 700);
                }
            }).catch(function (error) {
                setResult(result, 'error', text('policy-failed', 'Save failed') + '：' + (error && error.message ? error.message : ''));
            }).finally(function () {
                if (button) {
                    button.disabled = false;
                    button.removeAttribute('aria-busy');
                }
            });
        });
    }

    root.addEventListener('click', function (event) {
        var button = event.target.closest('[data-health-revert]');
        if (!button) {
            return;
        }
        var actionId = Number.parseInt(button.getAttribute('data-action-id') || '0', 10);
        var endpoint = root.getAttribute('data-revert-endpoint') || '';
        if (!actionId || endpoint === '') {
            return;
        }
        if (!window.confirm(text('revert-confirm', 'Revert this action?'))) {
            return;
        }

        button.disabled = true;
        var body = new URLSearchParams();
        body.set('action_id', String(actionId));

        window.fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body.toString()
        }).then(function (response) {
            return response.text().then(function (raw) {
                var parsed = null;
                try {
                    parsed = JSON.parse(raw);
                } catch (error) {
                    parsed = null;
                }
                if (!parsed) {
                    throw new Error(raw || 'Invalid response');
                }
                return parsed;
            });
        }).then(function (payload) {
            var success = !!(payload && payload.success);
            if (success) {
                button.closest('tr').remove();
                window.setTimeout(function () {
                    window.location.reload();
                }, 500);
            } else {
                window.alert((payload && payload.message) || text('revert-failed', 'Revert failed'));
                button.disabled = false;
            }
        }).catch(function (error) {
            window.alert(text('revert-failed', 'Revert failed') + '：' + (error && error.message ? error.message : ''));
            button.disabled = false;
        });
    });
})();
