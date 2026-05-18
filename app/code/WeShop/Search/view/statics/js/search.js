/**
 * WeShop Search module.
 *
 * Provides search suggestions, local search history, keyboard navigation,
 * and optional category facade syncing for storefront search forms.
 */

(function (window, document) {
    'use strict';

    if (window.Weline && window.Weline.Search && window.Weline.Search.__initialized) {
        return;
    }

    function SearchManager() {
        this.searchWrapper = null;
        this.searchInput = null;
        this.searchForm = null;
        this.suggestionsContainer = null;
        this.suggestionsList = null;
        this.selectedSuggestionIndex = -1;
        this.searchTimeout = null;
        this.isInitialized = false;
        this.searchCategorySelect = null;
        this.searchCategoryLabel = null;
        this.searchCategoryFacade = null;
        this.searchApiPromise = null;
    }

    SearchManager.prototype = {
        init: function () {
            if (this.isInitialized) {
                return;
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', () => this.setup(), { once: true });
            } else {
                this.setup();
            }
        },

        setup: function () {
            this.searchWrapper = document.querySelector('[data-weshop-search]') || document.querySelector('.header-search-wrapper');
            if (!this.searchWrapper) {
                return;
            }

            this.searchInput = this.searchWrapper.querySelector('.search-input');
            this.searchForm = this.searchWrapper.querySelector('.header-search-form');
            this.suggestionsContainer = this.searchWrapper.querySelector('.search-suggestions');
            this.suggestionsList = this.searchWrapper.querySelector('.search-suggestions-list');
            this.searchCategorySelect = this.searchWrapper.querySelector('.search-category-select');
            this.searchCategoryLabel = this.searchWrapper.querySelector('.search-category-label');
            this.searchCategoryFacade = this.searchWrapper.querySelector('.search-category-facade');

            if (!this.searchInput || !this.searchForm || !this.suggestionsContainer || !this.suggestionsList) {
                return;
            }

            this.setupEventListeners();
            this.setupCategoryDropdown();
            this.hideSuggestions();
            this.isInitialized = true;
        },

        setupEventListeners: function () {
            this.searchInput.addEventListener('focus', () => {
                this.showSuggestions();
                this.loadSearchHistory();
            });

            this.searchInput.addEventListener('blur', () => {
                setTimeout(() => {
                    if (!this.suggestionsContainer.matches(':hover') && !this.searchWrapper.matches(':hover')) {
                        this.hideSuggestions();
                    }
                }, 200);
            });

            this.searchInput.addEventListener('input', (event) => {
                clearTimeout(this.searchTimeout);
                this.selectedSuggestionIndex = -1;

                const query = event.target.value.trim();
                if (query.length === 0) {
                    this.loadSearchHistory();
                    return;
                }

                this.searchTimeout = setTimeout(() => {
                    this.updateSearchSuggestions(query, this.suggestionsList);
                }, 250);
            });

            this.searchInput.addEventListener('keydown', (event) => {
                const suggestions = this.suggestionsList.querySelectorAll('.search-suggestions-item a');
                if (!suggestions.length) {
                    return;
                }

                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    this.selectedSuggestionIndex = (this.selectedSuggestionIndex + 1) % suggestions.length;
                    this.highlightSuggestion(suggestions, this.selectedSuggestionIndex);
                    return;
                }

                if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    this.selectedSuggestionIndex = this.selectedSuggestionIndex <= 0
                        ? suggestions.length - 1
                        : this.selectedSuggestionIndex - 1;
                    this.highlightSuggestion(suggestions, this.selectedSuggestionIndex);
                    return;
                }

                if (event.key === 'Enter' && this.selectedSuggestionIndex >= 0) {
                    event.preventDefault();
                    this.selectSuggestion(suggestions[this.selectedSuggestionIndex]);
                    return;
                }

                if (event.key === 'Escape') {
                    this.hideSuggestions();
                }
            });

            this.suggestionsList.addEventListener('click', (event) => {
                const suggestionLink = event.target.closest('.search-suggestions-item a');
                if (!suggestionLink) {
                    return;
                }

                event.preventDefault();
                this.selectSuggestion(suggestionLink);
            });

            this.searchForm.addEventListener('submit', () => {
                const query = this.searchInput.value.trim();
                if (query !== '') {
                    this.saveSearchHistory(query);
                }
            });

            document.addEventListener('keydown', (event) => {
                if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                    event.preventDefault();
                    this.searchInput.focus();
                }
            });

            document.addEventListener('click', (event) => {
                if (this.searchWrapper && !this.searchWrapper.contains(event.target)) {
                    this.hideSuggestions();
                }
            });
        },

        showSuggestions: function () {
            this.searchWrapper.classList.add('active');
            this.suggestionsContainer.classList.remove('hidden');
            this.suggestionsContainer.setAttribute('aria-hidden', 'false');
            this.searchInput.setAttribute('aria-expanded', 'true');
        },

        hideSuggestions: function () {
            this.searchWrapper.classList.remove('active');
            this.suggestionsContainer.classList.add('hidden');
            this.suggestionsContainer.setAttribute('aria-hidden', 'true');
            this.searchInput.setAttribute('aria-expanded', 'false');
            this.selectedSuggestionIndex = -1;
        },

        updateSearchSuggestions: function (query, container) {
            if (!container) {
                return;
            }

            container.innerHTML = '';
            this.showSuggestions();

            const customEvent = new CustomEvent('weline:search-suggestions-request', {
                detail: { query: query, container: container },
                bubbles: true,
                cancelable: true
            });
            document.dispatchEvent(customEvent);

            if (customEvent.defaultPrevented) {
                return;
            }

            this.fetchSearchSuggestions(query, container);
        },

        fetchSearchSuggestions: function (query, container) {
            this.getSearchApi()
                .then((SearchApi) => {
                    return SearchApi.suggest({ keyword: query, limit: 8 });
                })
                .then((data) => {
                    const suggestions = Array.isArray(data && data.suggestions)
                        ? data.suggestions
                        : (Array.isArray(data) ? data : []);
                    if (suggestions.length > 0) {
                        this.renderSuggestions(suggestions, container, query);
                        return;
                    }

                    this.renderDefaultSuggestions(query, container);
                })
                .catch(() => {
                    this.renderDefaultSuggestions(query, container);
                });
        },

        getSearchApi: function () {
            if (!this.searchApiPromise) {
                if (!window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
                    return Promise.reject(new Error('Weline.Api.resource is not available'));
                }
                this.searchApiPromise = Promise.resolve(window.Weline.Api.resource('search'));
            }
            return this.searchApiPromise;
        },

        renderSuggestions: function (suggestions, container, query) {
            container.innerHTML = '';

            suggestions.forEach((suggestion) => {
                const li = document.createElement('li');
                li.className = 'search-suggestions-item';

                const text = suggestion.text || suggestion.query || query;
                const icon = suggestion.icon || 'fa-search';
                const href = suggestion.url || this.buildSearchUrl(text);

                li.innerHTML = [
                    '<a class="flex items-center gap-3 rounded-xl px-3 py-3 text-sm text-text-primary-light transition hover:bg-background-light" href="',
                    href,
                    '" role="option" tabindex="0" data-query="',
                    this.escapeHtml(text),
                    '">',
                    '<i class="fas ',
                    icon,
                    ' text-text-secondary-light" aria-hidden="true"></i>',
                    '<span>',
                    this.escapeHtml(text),
                    '</span>',
                    '</a>'
                ].join('');

                container.appendChild(li);
            });
        },

        renderDefaultSuggestions: function (query, container) {
            this.renderSuggestions([
                { text: query + ' product', icon: 'fa-search', url: this.buildSearchUrl(query) },
                { text: query + ' brand', icon: 'fa-tag', url: this.buildSearchUrl(query + ' brand') },
                { text: query + ' category', icon: 'fa-folder', url: this.buildSearchUrl(query + ' category') }
            ], container, query);
        },

        highlightSuggestion: function (suggestions, index) {
            Array.prototype.forEach.call(suggestions, function (item, itemIndex) {
                if (itemIndex === index) {
                    item.setAttribute('aria-selected', 'true');
                    item.focus();
                    item.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                } else {
                    item.setAttribute('aria-selected', 'false');
                }
            });
        },

        selectSuggestion: function (suggestionLink) {
            if (!suggestionLink) {
                return;
            }

            const query = suggestionLink.getAttribute('data-query') || suggestionLink.textContent || '';
            const href = suggestionLink.getAttribute('href');

            if (query) {
                this.searchInput.value = query;
                this.saveSearchHistory(query);
            }

            if (href && href !== '#') {
                window.location.href = href;
                return;
            }

            this.searchForm.submit();
        },

        loadSearchHistory: function () {
            if (!this.suggestionsList) {
                return;
            }

            try {
                const history = JSON.parse(localStorage.getItem('weline_search_history') || '[]');
                this.suggestionsList.innerHTML = '';

                if (!Array.isArray(history) || history.length === 0) {
                    this.suggestionsList.innerHTML = '<li class="px-3 py-3 text-sm text-text-secondary-light">No recent searches yet.</li>';
                    return;
                }

                this.renderSuggestions(history.slice(0, 5).map((item) => ({
                    text: item,
                    icon: 'fa-history',
                    url: this.buildSearchUrl(item)
                })), this.suggestionsList, '');
            } catch (error) {
                this.suggestionsList.innerHTML = '<li class="px-3 py-3 text-sm text-text-secondary-light">No recent searches yet.</li>';
            }
        },

        saveSearchHistory: function (query) {
            if (!query || query.trim() === '') {
                return;
            }

            try {
                const stored = JSON.parse(localStorage.getItem('weline_search_history') || '[]');
                const history = Array.isArray(stored) ? stored : [];
                const normalizedQuery = query.trim();
                const nextHistory = history.filter((item) => item !== normalizedQuery);
                nextHistory.unshift(normalizedQuery);
                localStorage.setItem('weline_search_history', JSON.stringify(nextHistory.slice(0, 10)));
            } catch (error) {
                // Ignore storage failures.
            }
        },

        escapeHtml: function (text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        buildSearchUrl: function (query) {
            const configuredUrl = (window.Weline && window.Weline.config && window.Weline.config.searchPageUrl)
                || (window.welineConfig && window.welineConfig.searchPageUrl)
                || this.searchWrapper.dataset.searchUrl
                || this.searchForm.getAttribute('action')
                || '/search';

            try {
                const url = new URL(configuredUrl, window.location.origin);
                url.searchParams.set('q', query);
                return url.pathname + url.search + url.hash;
            } catch (error) {
                return '/search?q=' + encodeURIComponent(query);
            }
        },

        setupCategoryDropdown: function () {
            if (!this.searchCategorySelect || !this.searchCategoryLabel || !this.searchCategoryFacade) {
                return;
            }

            const updateCategoryLabel = () => {
                const selectedOption = this.searchCategorySelect.options[this.searchCategorySelect.selectedIndex];
                if (!selectedOption) {
                    return;
                }

                const label = selectedOption.getAttribute('data-display-label') || selectedOption.textContent || '';
                this.searchCategoryLabel.textContent = label.trim() || 'All Categories';
            };

            updateCategoryLabel();

            this.searchCategoryFacade.addEventListener('click', () => {
                this.searchCategorySelect.focus();
                this.searchCategorySelect.click();
            });

            this.searchCategorySelect.addEventListener('change', updateCategoryLabel);
        }
    };

    const searchManager = new SearchManager();

    window.Weline = window.Weline || {};
    window.Weline.Search = {
        __initialized: true,
        init: function () {
            searchManager.init();
        },
        manager: searchManager
    };
    window.WelineSearchModule = window.Weline.Search;

    searchManager.init();
})(window, document);
