(function () {
    'use strict';

    function initRoot(root) {
        if (!root || root.getAttribute('data-w-header-search-init') === '1') {
            return;
        }
        root.setAttribute('data-w-header-search-init', '1');

        var searchInput = root.querySelector('.search-input');
        var suggestions = root.querySelector('.search-suggestions');
        var autoComplete = root.getAttribute('data-autocomplete') === 'true';

        if (!autoComplete || !searchInput || !suggestions) {
            return;
        }

        var debounceTimer;

        searchInput.addEventListener('input', function () {
            clearTimeout(debounceTimer);
            var query = this.value.trim();

            if (query.length < 2) {
                suggestions.hidden = true;
                return;
            }

            debounceTimer = setTimeout(function () {
                var mockSuggestions = [query + ' 热销', query + ' 新款', query + ' 特价'];
                var list = suggestions.querySelector('.suggestion-list');
                if (!list) {
                    return;
                }
                list.innerHTML = mockSuggestions.map(function (s) {
                    return '<div class="suggestion-item">' + s + '</div>';
                }).join('');
                suggestions.hidden = false;
            }, 300);
        });

        suggestions.addEventListener('click', function (e) {
            if (!e.target.classList.contains('suggestion-item')) {
                return;
            }
            searchInput.value = e.target.textContent;
            suggestions.hidden = true;
            var form = searchInput.closest('form');
            if (form) {
                form.submit();
            }
        });

        document.addEventListener('click', function (e) {
            if (!root.contains(e.target)) {
                suggestions.hidden = true;
            }
        });
    }

    function boot() {
        document.querySelectorAll('[data-js-ns="header-search"]').forEach(initRoot);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
