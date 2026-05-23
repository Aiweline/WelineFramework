(function() {
    function readAccountConfig() {
        var el = document.getElementById('weline-account-index-config');
        if (!el) {
            return {};
        }

        try {
            return JSON.parse(el.textContent || '{}') || {};
        } catch (error) {
            console.error(error);
            return {};
        }
    }

    function mergeI18n(serverI18n) {
        var fallback = {
            saving: 'Saving...',
            changing: 'Changing...',
            profileUpdateFailed: 'Update failed. Please try again later.',
            securityUpdateFailed: 'Password change failed. Please try again later.',
            fillAllFields: 'Please fill in all fields.',
            passwordTooShort: 'New password must be at least 6 characters.',
            passwordMismatch: 'The two passwords do not match.',
            invalidServerResponse: 'Invalid server response. Please try again later.',
            endpointNotFound: 'The save endpoint was not found.'
        };

        serverI18n = serverI18n && typeof serverI18n === 'object' ? serverI18n : {};
        Object.keys(serverI18n).forEach(function(key) {
            if (typeof serverI18n[key] === 'string' && serverI18n[key] !== '') {
                fallback[key] = serverI18n[key];
            }
        });

        return fallback;
    }

    function initAccountIndex() {
        var accountConfig = readAccountConfig();
        var i18nAccount = mergeI18n(accountConfig.i18n);
        var accountApiPromise = null;

        function welineDecodeHtmlEntities(message) {
            var s = String(message || '');
            if (s.indexOf('&') === -1) {
                return s;
            }

            var div = document.createElement('div');
            var prev;
            for (var i = 0; i < 5; i++) {
                prev = s;
                div.innerHTML = s;
                s = div.textContent || div.innerText || '';
                if (s === prev || s.indexOf('&') === -1) {
                    break;
                }
            }

            return s;
        }

        function parseJsonResponse(response) {
            var contentType = String(response.headers.get('content-type') || '').toLowerCase();
            if (contentType.indexOf('application/json') === -1) {
                return response.text().then(function(text) {
                    if (response.status === 404) {
                        throw new Error(i18nAccount.endpointNotFound);
                    }

                    var normalized = welineDecodeHtmlEntities(text);
                    var trimmed = normalized.trim();

                    if (trimmed.indexOf('{') === 0 || trimmed.indexOf('[') === 0) {
                        var parsed = null;
                        try {
                            parsed = JSON.parse(trimmed);
                        } catch (jsonError) {}

                        if (parsed && typeof parsed === 'object') {
                            if (!response.ok) {
                                throw new Error(welineDecodeHtmlEntities(parsed && parsed.message ? parsed.message : '') || i18nAccount.invalidServerResponse);
                            }

                            return parsed;
                        }
                    }

                    if (normalized.indexOf('<') !== -1 || normalized.indexOf('>') !== -1) {
                        throw new Error(i18nAccount.invalidServerResponse);
                    }

                    throw new Error(normalized || i18nAccount.invalidServerResponse);
                });
            }

            return response.json().then(function(data) {
                if (!response.ok) {
                    var message = welineDecodeHtmlEntities(data && data.message ? data.message : '');
                    throw new Error(message || i18nAccount.invalidServerResponse);
                }

                return data;
            });
        }

        function getAccountApi() {
            if (!accountApiPromise) {
                if (window.Weline && window.Weline.Api && typeof window.Weline.Api.resource === 'function') {
                    accountApiPromise = window.Weline.Api.resource('account');
                } else if (window.Weline && typeof window.Weline.load === 'function') {
                    accountApiPromise = window.Weline.load('api').then(function() {
                        if (!window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
                            throw new Error('Weline.Api is unavailable.');
                        }

                        return window.Weline.Api.resource('account');
                    });
                } else {
                    accountApiPromise = Promise.reject(new Error('Weline.Api is unavailable.'));
                }
            }

            return accountApiPromise;
        }

        function formDataToObject(formData) {
            var payload = {};
            formData.forEach(function(value, key) {
                payload[key] = value;
            });
            delete payload.form_key;
            return payload;
        }

        var navLinks = document.querySelectorAll('[data-account-nav-link][data-section]');
        var sidebarContentMount = document.querySelector('[data-account-sidebar-content-mount]');
        var loadedSidebarSections = Object.create(null);
        var sidebarContentLoading = Object.create(null);

        function executeInsertedScripts(container) {
            Array.prototype.slice.call(container.querySelectorAll('script')).forEach(function(oldScript) {
                var script = document.createElement('script');
                Array.prototype.slice.call(oldScript.attributes).forEach(function(attribute) {
                    script.setAttribute(attribute.name, attribute.value);
                });
                script.text = oldScript.textContent || '';
                oldScript.parentNode.replaceChild(script, oldScript);
            });
        }

        function buildSidebarContentUrl(sectionName) {
            var baseUrl = sidebarContentMount ? (sidebarContentMount.getAttribute('data-account-sidebar-content-url') || '') : '';
            if (!baseUrl || !sectionName) {
                return '';
            }

            var separator = baseUrl.indexOf('?') >= 0 ? '&' : '?';
            return baseUrl + separator + 'section=' + encodeURIComponent(sectionName);
        }

        function loadSidebarContent(sectionName) {
            if (!sidebarContentMount || !sectionName) {
                return Promise.resolve(true);
            }

            if (loadedSidebarSections[sectionName]) {
                return Promise.resolve(true);
            }

            if (sidebarContentLoading[sectionName]) {
                return sidebarContentLoading[sectionName];
            }

            var url = buildSidebarContentUrl(sectionName);
            if (!url) {
                loadedSidebarSections[sectionName] = true;
                return Promise.resolve(false);
            }

            sidebarContentLoading[sectionName] = fetch(url, {
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).then(function(response) {
                if (!response.ok) {
                    throw new Error('Account sections request failed: ' + response.status);
                }

                return response.json();
            }).then(function(payload) {
                if (!payload || payload.success === false) {
                    if (payload && payload.redirect) {
                        window.location.href = payload.redirect;
                    }

                    return false;
                }

                if (payload.html) {
                    sidebarContentMount.insertAdjacentHTML('beforeend', payload.html);
                    executeInsertedScripts(sidebarContentMount);
                }

                loadedSidebarSections[sectionName] = true;
                window.dispatchEvent(new CustomEvent('weline:account-sidebar-content-loaded', {
                    detail: { section: sectionName, length: payload.length || 0 }
                }));
                return true;
            }).catch(function(error) {
                console.error(error);
                return false;
            }).finally(function() {
                delete sidebarContentLoading[sectionName];
            });

            return sidebarContentLoading[sectionName];
        }

        function parseHash(inputHash) {
            var rawHash = String(inputHash || '');
            if (!rawHash) {
                return { section: '', query: {} };
            }

            var trimmed = rawHash.replace(/^.*#/, '');
            if (!trimmed) {
                return { section: '', query: {} };
            }

            var section = trimmed;
            var query = {};
            var splitAt = trimmed.indexOf('?');

            if (splitAt !== -1) {
                section = trimmed.substring(0, splitAt);
                var queryString = trimmed.substring(splitAt + 1);
                if (queryString) {
                    try {
                        var parsed = new URLSearchParams(queryString);
                        parsed.forEach(function(value, key) {
                            query[String(key)] = String(value);
                        });
                    } catch (err) {}
                }
            }

            return {
                section: section,
                query: query
            };
        }

        function parseAccountHash() {
            return parseHash(window.location.hash || '');
        }

        function getNavTarget(link) {
            var targetId = link.getAttribute('data-section') || '';
            var hashInfo = parseHash(link.getAttribute('href') || '');
            if (hashInfo.section) {
                targetId = hashInfo.section;
            }

            return {
                section: targetId,
                query: hashInfo.query
            };
        }

        function hasNavSection(section) {
            return Array.prototype.some.call(document.querySelectorAll('[data-account-nav-link][data-section]'), function(a) {
                return a.getAttribute('data-section') === section;
            });
        }

        function updateHash(section, query) {
            var targetHash = '#' + section;
            if (query && typeof query === 'object' && Object.keys(query).length > 0) {
                var nextQuery = new URLSearchParams();
                Object.keys(query).forEach(function(key) {
                    var value = query[key];
                    if (value === '' || value === null || value === undefined) {
                        return;
                    }
                    nextQuery.set(key, String(value));
                });

                var nextQueryString = nextQuery.toString();
                if (nextQueryString) {
                    targetHash += '?' + nextQueryString;
                }
            }

            if (window.history && typeof window.history.replaceState === 'function') {
                window.history.replaceState(null, '', window.location.pathname + window.location.search + targetHash);
            }
        }

        function setActiveNavLink(targetId) {
            var activeParent = '';
            navLinks.forEach(function(nav) {
                if (nav.getAttribute('data-section') === targetId) {
                    activeParent = nav.getAttribute('data-account-nav-parent') || '';
                }
            });

            navLinks.forEach(function(nav) {
                var isActive = nav.getAttribute('data-section') === targetId;
                var isActiveParent = activeParent && nav.getAttribute('data-section') === activeParent;
                nav.classList.remove('is-active');
                if (nav.classList.contains('account-sidebar__nav-link')) {
                    nav.classList.remove('account-sidebar__nav-link--active');
                }
                if (!isActive && !isActiveParent) {
                    return;
                }
                if (nav.classList.contains('account-sidebar__nav-link')) {
                    nav.classList.add('account-sidebar__nav-link--active');
                } else if (!isActiveParent) {
                    nav.classList.add('is-active');
                }
            });
        }

        function showAccountSection(targetId) {
            if (!targetId) {
                return;
            }

            var sections = document.querySelectorAll('[data-account-section]');
            var targetSection = document.querySelector('[data-account-section="' + targetId + '"]');
            if (!targetSection) {
                targetSection = document.getElementById(targetId + '-section');
            }
            if (!targetSection) {
                return loadSidebarContent(targetId).then(function() {
                    showAccountSection(targetId);
                });
            }
            sections.forEach(function(section) {
                section.classList.add('d-none');
                section.hidden = true;
            });
            if (targetSection) {
                targetSection.classList.remove('d-none');
                targetSection.hidden = false;
            }
            if (targetId === 'orders') {
                window.dispatchEvent(new CustomEvent('weshop:orders-viewed'));
            }
        }

        function syncFromHash() {
            var parsed = parseAccountHash();
            var targetId = parsed.section || 'profile';
            if (!hasNavSection(targetId)) {
                targetId = 'profile';
            }

            setActiveNavLink(targetId);
            showAccountSection(targetId);

            if (typeof parsed.query.return_anchor === 'string' && parsed.query.return_anchor) {
                var anchor = document.getElementById(parsed.query.return_anchor);
                if (anchor && typeof anchor.scrollIntoView === 'function') {
                    anchor.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }
        }

        navLinks.forEach(function(link) {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                var navTarget = getNavTarget(link);
                var targetId = navTarget.section;
                if (!targetId) {
                    return;
                }

                setActiveNavLink(targetId);
                showAccountSection(targetId);
                try {
                    updateHash(targetId, navTarget.query);
                } catch (err) {}
            });
        });

        syncFromHash();
        window.addEventListener('hashchange', syncFromHash);

        var avatarInput = document.getElementById('avatar');
        var avatarTargets = [
            {
                image: document.getElementById('avatarPreview'),
                fallback: document.getElementById('avatarFallback')
            },
            {
                image: document.getElementById('sidebarAvatarPreview'),
                fallback: document.getElementById('sidebarAvatarFallback')
            }
        ];

        function syncAvatarPreview() {
            if (!avatarInput) {
                return;
            }

            var avatarUrl = avatarInput.value.trim();
            if (!avatarUrl) {
                avatarTargets.forEach(function(target) {
                    if (!target.image || !target.fallback) {
                        return;
                    }
                    target.image.hidden = true;
                    target.fallback.hidden = false;
                    target.image.removeAttribute('src');
                });
                return;
            }

            avatarTargets.forEach(function(target) {
                if (!target.image || !target.fallback) {
                    return;
                }
                target.image.hidden = false;
                target.fallback.hidden = true;
                target.image.src = avatarUrl;
            });
        }

        avatarTargets.forEach(function(target) {
            if (!target.image || !target.fallback) {
                return;
            }
            target.image.addEventListener('error', function() {
                target.image.hidden = true;
                target.fallback.hidden = false;
            });
        });

        if (avatarInput) {
            avatarInput.addEventListener('input', syncAvatarPreview);
            avatarInput.addEventListener('change', syncAvatarPreview);
            syncAvatarPreview();
        }

        var profileForm = document.getElementById('profileForm');
        if (profileForm) {
            profileForm.addEventListener('submit', function(e) {
                e.preventDefault();
                var btn = this.querySelector('button[type="submit"]');
                var originalText = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<i class="ri-loader-4-line"></i> ' + i18nAccount.saving;

                var successMsg = document.getElementById('profileSuccessMsg');
                var errorMsg = document.getElementById('profileErrorMsg');
                successMsg.textContent = '';
                errorMsg.textContent = '';

                var formData = new FormData(this);
                getAccountApi()
                    .then(function(AccountApi) {
                        return AccountApi.updateProfile(formDataToObject(formData), {
                            onError: function(_status, error) {
                                errorMsg.textContent = welineDecodeHtmlEntities(error && error.message ? error.message : '') || i18nAccount.profileUpdateFailed;
                            }
                        });
                    })
                    .then(function(data) {
                        if (data.success) {
                            errorMsg.textContent = '';
                            successMsg.textContent = welineDecodeHtmlEntities(data.message);
                        } else {
                            successMsg.textContent = '';
                            errorMsg.textContent = welineDecodeHtmlEntities(data.message) || i18nAccount.profileUpdateFailed;
                        }
                    })
                    .catch(function(error) {
                        successMsg.textContent = '';
                        errorMsg.textContent = welineDecodeHtmlEntities(error && error.message ? error.message : '') || i18nAccount.profileUpdateFailed;
                    })
                    .finally(function() {
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                    });
            });
        }

        var securityForm = document.getElementById('securityForm');
        if (securityForm) {
            securityForm.addEventListener('submit', function(e) {
                e.preventDefault();

                var successMsg = document.getElementById('securitySuccessMsg');
                var errorMsg = document.getElementById('securityErrorMsg');
                successMsg.textContent = '';
                errorMsg.textContent = '';

                var oldPassword = document.getElementById('old_password').value;
                var newPassword = document.getElementById('new_password').value;
                var confirmPassword = document.getElementById('confirm_password').value;

                if (!oldPassword || !newPassword || !confirmPassword) {
                    errorMsg.textContent = i18nAccount.fillAllFields;
                    return;
                }

                if (newPassword.length < 6) {
                    errorMsg.textContent = i18nAccount.passwordTooShort;
                    return;
                }

                if (newPassword !== confirmPassword) {
                    errorMsg.textContent = i18nAccount.passwordMismatch;
                    return;
                }

                var btn = this.querySelector('button[type="submit"]');
                var originalText = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<i class="ri-loader-4-line"></i> ' + i18nAccount.changing;

                var formData = new FormData(this);
                getAccountApi()
                    .then(function(AccountApi) {
                        return AccountApi.updatePassword(formDataToObject(formData), {
                            onError: function(_status, error) {
                                errorMsg.textContent = welineDecodeHtmlEntities(error && error.message ? error.message : '') || i18nAccount.securityUpdateFailed;
                            }
                        });
                    })
                    .then(function(data) {
                        if (data.success) {
                            errorMsg.textContent = '';
                            successMsg.textContent = welineDecodeHtmlEntities(data.message);
                            document.getElementById('old_password').value = '';
                            document.getElementById('new_password').value = '';
                            document.getElementById('confirm_password').value = '';
                        } else {
                            successMsg.textContent = '';
                            errorMsg.textContent = welineDecodeHtmlEntities(data.message) || i18nAccount.securityUpdateFailed;
                        }
                    })
                    .catch(function(error) {
                        successMsg.textContent = '';
                        errorMsg.textContent = welineDecodeHtmlEntities(error && error.message ? error.message : '') || i18nAccount.securityUpdateFailed;
                    })
                    .finally(function() {
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                    });
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAccountIndex);
    } else {
        initAccountIndex();
    }
})();
