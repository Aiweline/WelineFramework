/**
 * WeShop Default Theme - Main JavaScript
 * 主题JS - 核心交互功能
 */

(function() {
    'use strict';

    // ============================================
    // Global Namespace
    // ============================================
    window.WeShop = window.WeShop || {};
    WeShop.apiResources = WeShop.apiResources || {};

    WeShop.getApiResource = function(provider) {
        if (!window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
            return Promise.reject(new Error('Weline.Api.resource is not available'));
        }
        if (!WeShop.apiResources[provider]) {
            WeShop.apiResources[provider] = Promise.resolve(window.Weline.Api.resource(provider));
        }
        return WeShop.apiResources[provider];
    };

    WeShop.normalizeApiPayload = function(payload) {
        if (!payload || typeof payload !== 'object') {
            return payload || {};
        }
        if (Object.prototype.hasOwnProperty.call(payload, 'code') && payload.data && typeof payload.data === 'object') {
            var code = Number(payload.code || 0);
            return Object.assign({
                code: code,
                message: payload.msg || payload.data.message || '',
                success: code >= 200 && code < 300 && payload.data.success !== false,
            }, payload.data);
        }
        return payload;
    };

    // ============================================
    // DOM Ready
    // ============================================
    document.addEventListener('DOMContentLoaded', function() {
        WeShop.init();
    });

    // ============================================
    // Main Initialization
    // ============================================
    WeShop.init = function() {
        this.initHeader();
        this.initMiniCart();
        this.initQuantitySteppers();
        this.initProductGallery();
        this.initProductTabs();
        this.initAddToCart();
        this.initWishlist();
        this.initCompare();
        this.initSearch();
        this.initDarkMode();
        this.initScrollToTop();
        this.initLazyLoading();
    };

    // ============================================
    // Header
    // ============================================
    WeShop.initHeader = function() {
        var header = document.querySelector('header.sticky');
        if (!header) return;

        var lastScrollY = 0;
        
        window.addEventListener('scroll', function() {
            var currentScrollY = window.scrollY;
            
            // Add shadow when scrolled
            if (currentScrollY > 10) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
            
            lastScrollY = currentScrollY;
        }, { passive: true });
    };

    // ============================================
    // Mini Cart
    // ============================================
    WeShop.initMiniCart = function() {
        // Mini cart is handled by CSS hover, but we can add touch support
        var miniCartTrigger = document.querySelector('.mini-cart-trigger');
        if (!miniCartTrigger) return;

        // Touch devices
        if ('ontouchstart' in window) {
            miniCartTrigger.addEventListener('click', function(e) {
                var dropdown = this.querySelector('.mini-cart-dropdown');
                if (dropdown) {
                    dropdown.classList.toggle('show');
                }
            });
        }
    };

    // ============================================
    // Quantity Steppers
    // ============================================
    WeShop.initQuantitySteppers = function() {
        document.querySelectorAll('.qty-decrease, .qty-increase').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var container = this.closest('.qty-stepper') || this.parentElement;
                var input = container.querySelector('input[type="number"], .qty-input');
                if (!input) return;

                var currentVal = parseInt(input.value) || 1;
                var min = parseInt(input.getAttribute('min')) || 1;
                var max = parseInt(input.getAttribute('max')) || 999;

                if (this.classList.contains('qty-decrease')) {
                    if (currentVal > min) {
                        input.value = currentVal - 1;
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                } else {
                    if (currentVal < max) {
                        input.value = currentVal + 1;
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                }
            });
        });
    };

    // ============================================
    // Product Gallery
    // ============================================
    WeShop.initProductGallery = function() {
        var mainImage = document.getElementById('main-product-image');
        var thumbnails = document.querySelectorAll('#product-thumbnails > div');
        
        if (!mainImage || !thumbnails.length) return;

        thumbnails.forEach(function(thumb) {
            thumb.addEventListener('click', function() {
                var imageUrl = this.dataset.image;
                if (!imageUrl) return;

                // Update main image
                mainImage.style.backgroundImage = 'url("' + imageUrl + '")';

                // Update thumbnail styles
                thumbnails.forEach(function(t) {
                    t.classList.remove('border-2', 'border-primary');
                    t.classList.add('opacity-70');
                });
                this.classList.add('border-2', 'border-primary');
                this.classList.remove('opacity-70');
            });
        });
    };

    // ============================================
    // Product Tabs
    // ============================================
    WeShop.initProductTabs = function() {
        document.querySelectorAll('.product-tab').forEach(function(tab) {
            tab.addEventListener('click', function(e) {
                e.preventDefault();
                var tabName = this.dataset.tab;
                if (!tabName) return;

                // Update tab styles
                document.querySelectorAll('.product-tab').forEach(function(t) {
                    t.classList.remove('text-primary', 'border-primary');
                    t.classList.add('text-text-secondary-light', 'dark:text-text-secondary-dark', 'border-transparent');
                });
                this.classList.add('text-primary', 'border-primary');
                this.classList.remove('text-text-secondary-light', 'dark:text-text-secondary-dark', 'border-transparent');

                // Show/hide content
                document.querySelectorAll('.tab-content').forEach(function(content) {
                    content.classList.add('hidden');
                });
                var targetContent = document.getElementById(tabName + '-content');
                if (targetContent) {
                    targetContent.classList.remove('hidden');
                }
            });
        });
    };

    // ============================================
    // Add to Cart
    // ============================================
    WeShop.initAddToCart = function() {
        document.querySelectorAll('.add-to-cart, .btn-add-to-cart').forEach(function(btn) {
            if (btn.closest('form.product-add-to-cart-form')) {
                return;
            }
            if (btn.dataset.weshopAddToCartBound === '1') {
                return;
            }
            btn.dataset.weshopAddToCartBound = '1';

            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();

                var productId = this.dataset.productId;
                if (!productId) return;
                var form = this.closest('form');
                var qtyInput = form ? form.querySelector('[name="qty"]') : null;
                var qty = parseInt(this.dataset.qty || (qtyInput ? qtyInput.value : '1'), 10) || 1;
                var selectedOptions = [];
                if (this.dataset.selectedOptions) {
                    try {
                        selectedOptions = JSON.parse(this.dataset.selectedOptions) || [];
                    } catch (error) {
                        selectedOptions = [];
                    }
                }
                if (selectedOptions.length === 0 && form) {
                    selectedOptions = Array.prototype.slice.call(form.querySelectorAll('[name^="selected_options"]'))
                        .map(function(input) { return Number(input.value); })
                        .filter(Boolean);
                }

                // Show loading state
                var originalText = this.innerHTML;
                this.innerHTML = '<span class="material-symbols-outlined animate-spin">sync</span>';
                this.disabled = true;

                WeShop.addToCart(productId, qty, selectedOptions)
                    .then(function(data) {
                        if (WeShop.handleRedirectPayload(data)) {
                            return;
                        }

                        if (data.success) {
                            // Show success
                            btn.innerHTML = '<span class="material-symbols-outlined">check</span>';
                            WeShop.updateCartCount(data.cart_count);
                            WeShop.showNotification(data.message || 'Added to cart', 'success');
                            
                            setTimeout(function() {
                                btn.innerHTML = originalText;
                                btn.disabled = false;
                            }, 2000);
                        } else {
                            btn.innerHTML = originalText;
                            btn.disabled = false;
                            WeShop.showNotification(data.message || 'Error adding to cart', 'error');
                        }
                    })
                    .catch(function(error) {
                        btn.innerHTML = originalText;
                        btn.disabled = false;
                        console.error('Add to cart error:', error);
                        WeShop.showNotification('Unable to add this product to cart right now.', 'error');
                    });
            });
        });
    };

    WeShop.addToCart = function(productId, qty, selectedOptions) {
        return WeShop.getApiResource('cart').then(function(CartApi) {
            return CartApi.add({
                product_id: Number(productId),
                qty: Number(qty) || 1,
                selected_options: Array.isArray(selectedOptions) ? selectedOptions : []
            });
        }).then(function(payload) {
            return WeShop.normalizeApiPayload(payload);
        });
    };

    WeShop.updateCartCount = function(count) {
        document.querySelectorAll('.cart-count').forEach(function(el) {
            el.textContent = count;
            if (count > 0) {
                el.classList.remove('hidden');
            } else {
                el.classList.add('hidden');
            }
        });
    };

    WeShop.handleRedirectPayload = function(payload) {
        var redirectUrl = payload && payload.data && payload.data.redirect_url ? payload.data.redirect_url : '';
        if (!redirectUrl) {
            return false;
        }

        window.location.href = redirectUrl;
        return true;
    };

    // ============================================
    // Wishlist
    // ============================================
    WeShop.initWishlist = function() {
        document.querySelectorAll('.add-to-wishlist').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();

                var productId = this.dataset.productId;
                if (!productId) return;

                var icon = this.querySelector('.material-symbols-outlined');
                
                WeShop.addToWishlist(productId)
                    .then(function(data) {
                        if (WeShop.handleRedirectPayload(data)) {
                            return;
                        }

                        if (data.success) {
                            if (icon) {
                                icon.style.fontVariationSettings = "'FILL' 1";
                                icon.classList.add('text-red-500');
                            }
                            WeShop.showNotification(data.message || 'Added to wishlist', 'success');
                            return;
                        }

                        WeShop.showNotification(data.message || 'Unable to add to wishlist', 'warning');
                    })
                    .catch(function(error) {
                        console.error('Wishlist error:', error);
                    });
            });
        });
    };

    WeShop.addToWishlist = function(productId) {
        return WeShop.getApiResource('wishlist').then(function(WishlistApi) {
            return WishlistApi.add({ product_id: Number(productId) });
        }).then(function(payload) {
            return WeShop.normalizeApiPayload(payload);
        });
    };

    // ============================================
    // Compare
    // ============================================
    WeShop.initCompare = function() {
        document.querySelectorAll('.add-to-compare').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();

                var productId = this.dataset.productId;
                if (!productId) return;

                var button = this;
                var icon = button.querySelector('.material-symbols-outlined');

                WeShop.addToCompare(productId)
                    .then(function(data) {
                        if (WeShop.handleRedirectPayload(data)) {
                            return;
                        }

                        if (data.success) {
                            if (icon) {
                                icon.style.fontVariationSettings = "'FILL' 1";
                            }
                            button.classList.add('ring-2', 'ring-primary/30');
                            WeShop.showNotification(data.message || 'Added to compare', 'success');
                            return;
                        }

                        WeShop.showNotification(data.message || 'Unable to add to compare', 'warning');
                    })
                    .catch(function(error) {
                        console.error('Compare error:', error);
                    });
            });
        });
    };

    WeShop.addToCompare = function(productId) {
        return WeShop.getApiResource('compare').then(function(CompareApi) {
            return CompareApi.add({ product_id: Number(productId) });
        }).then(function(payload) {
            return WeShop.normalizeApiPayload(payload);
        });
    };

    // ============================================
    // Search
    // ============================================
    WeShop.initSearch = function() {
        var searchInput = document.querySelector('input[name="q"]');
        if (!searchInput) return;

        var debounceTimer;
        
        searchInput.addEventListener('input', function() {
            var query = this.value.trim();
            
            clearTimeout(debounceTimer);
            
            if (query.length >= 3) {
                debounceTimer = setTimeout(function() {
                    WeShop.searchProducts(query);
                }, 300);
            }
        });
    };

    WeShop.searchProducts = function(query) {
        WeShop.getApiResource('search')
            .then(function(SearchApi) {
                return SearchApi.suggest({ keyword: query, limit: 10 });
            })
            .then(function(data) {
                var suggestions = Array.isArray(data && data.suggestions)
                    ? data.suggestions
                    : (Array.isArray(data) ? data : []);
                WeShop.displaySearchSuggestions(suggestions);
            })
            .catch(function(error) {
                console.error('Search error:', error);
            });
    };

    WeShop.displaySearchSuggestions = function(suggestions) {
        // This would show a dropdown with suggestions
        // Implementation depends on your UI requirements
    };

    // ============================================
    // Dark Mode
    // ============================================
    WeShop.initDarkMode = function() {
        var toggleBtn = document.querySelector('.dark-mode-toggle');
        if (!toggleBtn) return;

        toggleBtn.addEventListener('click', function() {
            document.documentElement.classList.toggle('dark');
            
            // Save preference
            var isDark = document.documentElement.classList.contains('dark');
            localStorage.setItem('darkMode', isDark ? 'true' : 'false');
        });

        // Check saved preference
        var savedMode = localStorage.getItem('darkMode');
        if (savedMode === 'true') {
            document.documentElement.classList.add('dark');
        } else if (savedMode === null) {
            // Check system preference
            if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
                document.documentElement.classList.add('dark');
            }
        }
    };

    // ============================================
    // Scroll to Top
    // ============================================
    WeShop.initScrollToTop = function() {
        var scrollTopBtn = document.querySelector('a[href="#top"]');
        if (!scrollTopBtn) return;

        scrollTopBtn.addEventListener('click', function(e) {
            e.preventDefault();
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        });
    };

    // ============================================
    // Lazy Loading
    // ============================================
    WeShop.initLazyLoading = function() {
        if ('IntersectionObserver' in window) {
            var lazyImages = document.querySelectorAll('img[data-src], div[data-bg]');
            
            var imageObserver = new IntersectionObserver(function(entries, observer) {
                entries.forEach(function(entry) {
                    if (entry.isIntersecting) {
                        var el = entry.target;
                        
                        if (el.dataset.src) {
                            el.src = el.dataset.src;
                            el.removeAttribute('data-src');
                        }
                        
                        if (el.dataset.bg) {
                            el.style.backgroundImage = 'url("' + el.dataset.bg + '")';
                            el.removeAttribute('data-bg');
                        }
                        
                        observer.unobserve(el);
                    }
                });
            });

            lazyImages.forEach(function(img) {
                imageObserver.observe(img);
            });
        }
    };

    // ============================================
    // Notifications
    // ============================================
    WeShop.showNotification = function(message, type) {
        type = type || 'info';
        
        var notification = document.createElement('div');
        notification.className = 'fixed bottom-4 right-4 z-50 px-6 py-4 rounded-lg shadow-lg animate-slide-up';
        
        switch (type) {
            case 'success':
                notification.className += ' bg-green-500 text-white';
                break;
            case 'error':
                notification.className += ' bg-red-500 text-white';
                break;
            case 'warning':
                notification.className += ' bg-yellow-500 text-white';
                break;
            default:
                notification.className += ' bg-primary text-white';
        }
        
        notification.textContent = message;
        document.body.appendChild(notification);
        
        setTimeout(function() {
            notification.style.opacity = '0';
            notification.style.transform = 'translateY(20px)';
            notification.style.transition = 'all 0.3s ease';
            
            setTimeout(function() {
                notification.remove();
            }, 300);
        }, 3000);
    };

    // ============================================
    // Utility Functions
    // ============================================
    WeShop.formatPrice = function(price, currency) {
        currency = currency || 'USD';
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: currency
        }).format(price);
    };

    WeShop.debounce = function(func, wait) {
        var timeout;
        return function() {
            var context = this;
            var args = arguments;
            clearTimeout(timeout);
            timeout = setTimeout(function() {
                func.apply(context, args);
            }, wait);
        };
    };

    WeShop.throttle = function(func, limit) {
        var inThrottle;
        return function() {
            var context = this;
            var args = arguments;
            if (!inThrottle) {
                func.apply(context, args);
                inThrottle = true;
                setTimeout(function() {
                    inThrottle = false;
                }, limit);
            }
        };
    };

})();
