(function () {
    'use strict';
    var labels = window.__welineHeaderLabels || {};
    const headerMoreCategoriesLabel = labels.moreCategories || '';
    const headerMoreLabel = labels.more || '';
    const headerViewAllLabel = labels.viewAll || '';
    const headerBrowseCategoryPattern = labels.browse || '';


    function formatBrowseCategoryDesc(label) {
        var pattern = String(headerBrowseCategoryPattern || '');
        var safeLabel = String(label || '').trim();
        if (pattern.indexOf('%{1}') !== -1) {
            return pattern.split('%{1}').join(safeLabel);
        }
        if (pattern.indexOf('%s') !== -1) {
            return pattern.replace('%s', safeLabel);
        }
        return safeLabel !== '' ? pattern : pattern;
    }

    function escapeHeaderHtml(value) {
        return String(value).replace(/[&<>"']/g, function(match) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[match];
        });
    }

    var headerInteractionsInited = false;

    function initHeaderInteractions() {
        if (headerInteractionsInited) {
            return;
        }
        headerInteractionsInited = true;
        ensureHeaderHamburgerTriggers();
        bindHeaderMegaMenu(document);
        bindDrawerFlyoutAlign(document);
        bindSidebarAccordions(document);

        const header = document.querySelector('.weline-header');
        const searchToggle = header ? header.querySelector('.header-search-toggle') : null;
        const searchPanel = header ? header.querySelector('#header-search-panel') : null;
        const searchInput = searchPanel ? searchPanel.querySelector('.search-input') : null;

        if (header && searchToggle && searchPanel) {
            const searchToggleVisible = function() {
                return window.getComputedStyle(searchToggle).display !== 'none';
            };
            const setSearchOpen = function(open, focusInput) {
                if (!searchToggleVisible()) {
                    header.classList.remove('is-search-open');
                    return;
                }
                header.classList.toggle('is-search-open', open);
                searchToggle.setAttribute('aria-expanded', open ? 'true' : 'false');

                if (open && focusInput && searchInput) {
                    requestAnimationFrame(function() {
                        searchInput.focus();
                    });
                }
            };

            searchToggle.addEventListener('click', function(event) {
                event.preventDefault();
                event.stopPropagation();
                setSearchOpen(!header.classList.contains('is-search-open'), true);
            });

            document.addEventListener('click', function(event) {
                if (window.innerWidth <= 1280
                    && header.classList.contains('is-search-open')
                    && !searchPanel.contains(event.target)
                    && !searchToggle.contains(event.target)
                ) {
                    setSearchOpen(false, false);
                }
            });

            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape' && header.classList.contains('is-search-open')) {
                    setSearchOpen(false, false);
                    searchToggle.focus();
                }
            });

            window.addEventListener('resize', function() {
                if (window.innerWidth > 1280) {
                    setSearchOpen(false, false);
                }
            });
        }


        // 移动端多级菜单点击展开/收起
        const isMobile = window.innerWidth <= 768;
        if (isMobile) {
            const categoryItems = document.querySelectorAll('.category-item.has-children');
            // 删除点击展开逻辑，改为纯hover展开
        }

        // 搜索相关功能已移至 search.js 模块
        // 搜索分类下拉菜单同步和搜索建议功能由 Weline.Search 模块处理

        // 主栏溢出 More：左右簇各自一套，禁止「只做分类菜单 More」
        // - 左：分类 + 政策/关于 → 左簇末 header-left-cluster-more（collectLeftOverflowCandidates）
        // - 右：扩展链 + 快捷导航 → nav-more-wrapper；先左后右互让，不得合并成一个全局 More
        const categoriesList = document.getElementById('categories-list');
        let categoriesOverflowWrapper = document.getElementById('categories-overflow-wrapper');
        let categoriesOverflowBtn = document.getElementById('categories-overflow-btn');
        let categoriesOverflowDropdown = document.getElementById('categories-overflow-dropdown');
        const navLinksList = document.getElementById('nav-links-list');
        const navMoreWrapper = document.getElementById('nav-more-wrapper');
        const navMoreBtn = document.getElementById('nav-more-btn');
        const navMoreDropdown = document.getElementById('nav-more-dropdown');
        const headerNavFill = document.getElementById('header-nav-fill');
        const headerMainNav = document.querySelector('.header-main-nav');

        if ((categoriesList || document.querySelector('.header-nav-left-cluster')) && !categoriesOverflowWrapper) {
            categoriesOverflowWrapper = document.createElement('div');
            categoriesOverflowWrapper.className = 'categories-overflow-wrapper header-left-cluster-more';
            categoriesOverflowWrapper.id = 'categories-overflow-wrapper';
            categoriesOverflowWrapper.style.display = 'none';
            categoriesOverflowWrapper.setAttribute('data-w-component', 'popover');
            categoriesOverflowWrapper.setAttribute('data-w-open-on', 'hover');
            categoriesOverflowWrapper.setAttribute('data-w-placement', 'bottom-start');
            categoriesOverflowWrapper.innerHTML = '<button class="categories-overflow-btn w-button" id="categories-overflow-btn" type="button" data-w-popover-trigger data-tone="quiet" aria-haspopup="dialog" aria-expanded="false" aria-label="' + escapeHeaderHtml(headerMoreCategoriesLabel) + '"><span>' + escapeHeaderHtml(headerMoreLabel) + '</span><i class="fas fa-chevron-down" aria-hidden="true"></i></button><div class="w-popover w-mega-menu w-mega-menu--split header-category-panel categories-overflow-panel w-surface-body is-megamenu is-mega-panel-split" data-w-component="mega-menu" data-surface="body" data-w-popover-panel data-w-mega-menu data-mega-menu data-w-gap="0" role="dialog" data-state="closed" aria-hidden="true" hidden><div class="mega-menu-layout" id="categories-overflow-dropdown" role="presentation"></div></div>';
            const leftHost = document.querySelector('.header-nav-left-cluster');
            if (leftHost) {
                leftHost.appendChild(categoriesOverflowWrapper);
            } else if (categoriesList) {
                categoriesList.appendChild(categoriesOverflowWrapper);
            }
            categoriesOverflowBtn = document.getElementById('categories-overflow-btn');
            categoriesOverflowDropdown = document.getElementById('categories-overflow-dropdown');
            if (window.Weline && window.Weline.UI && typeof window.Weline.UI.mount === 'function') {
                window.Weline.UI.mount(categoriesOverflowWrapper);
            }
        }
        // 左簇整体 More（禁止只挂在 categories-list 末、夹在政策前）：宿主=左簇末，候选=分类+政策
        if (categoriesOverflowWrapper) {
            const leftHost = document.querySelector('.header-nav-left-cluster');
            if (leftHost && categoriesOverflowWrapper.parentElement !== leftHost) {
                leftHost.appendChild(categoriesOverflowWrapper);
            } else if (leftHost && leftHost.lastElementChild !== categoriesOverflowWrapper) {
                leftHost.appendChild(categoriesOverflowWrapper);
            }
            categoriesOverflowWrapper.classList.add('header-left-cluster-more');
        }

        // SSR 生成的 More <li> 无直链样式（裸 li{display:list-item} 会渲染成黑圆点），
        // JS 创建路径才有该内联样式；两路径统一置 none，由 applyCatVisibility 按需显隐
        if (categoriesOverflowWrapper && !categoriesOverflowWrapper.style.display) {
            categoriesOverflowWrapper.style.display = 'none';
        }

        // More Mega：与一级分类相同 bottom-start，打开后再贴一次触发器（内容撑开宽度后）
        if (categoriesOverflowWrapper && categoriesOverflowWrapper.dataset.overflowAlignBound !== '1') {
            categoriesOverflowWrapper.dataset.overflowAlignBound = '1';
            categoriesOverflowWrapper.addEventListener('weline:ui:popover:open', function() {
                var trigger = categoriesOverflowWrapper.querySelector('[data-w-popover-trigger]');
                var panel = document.querySelector('body > .categories-overflow-panel[data-state="open"]')
                    || categoriesOverflowWrapper.querySelector('[data-w-popover-panel]');
                if (!trigger || !panel) {
                    return;
                }
                var place = function() {
                    if (window.Weline && window.Weline.UI && typeof window.Weline.UI.position === 'function') {
                        window.Weline.UI.position(trigger, panel, 'bottom-start');
                    }
                };
                requestAnimationFrame(function() {
                    place();
                    requestAnimationFrame(place);
                });
            });
        }
        
        if ((navLinksList || document.querySelector('.header-nav-right-cluster')) && navMoreWrapper && navMoreBtn && navMoreDropdown) {
            const rightCluster = document.querySelector('.header-nav-right-cluster');
            const leftCluster = document.querySelector('.header-nav-left-cluster');
            const mainNavInner = document.querySelector('.header-main-nav-inner');
            // 拖窗：只按「藏从哪项起」改可见性；菜单 DOM/mount 延到 resize 停稳。禁止每 px 重建。
            let overflowAdjustRaf = 0;
            let overflowIdleTimer = 0;
            let lastNavHideFrom = null;
            let lastCatHideFrom = null;
            let lastNavCandidateCount = -1;
            let lastCatCandidateCount = -1;
            let navMenuBuiltFor = null;
            let catMenuBuiltFor = null;
            let navNaturalCache = { key: '', widths: [], prefix: [], moreW: 0, gap: 20 };
            let catNaturalCache = { key: '', widths: [], prefix: [], moreW: 0, gap: 8 };
            let cachedInnerPadGap = null;
            let cachedRightPad = null;
            let cachedLeftGap = null;
            let cachedAllW = null;
            // 临界宽滞回：变窄立即收，变宽多要 HYSTERESIS px 才吐回一项，避免来回闪
            const OVERFLOW_HYSTERESIS_PX = 12;
            // 两行栈：≤768 进入；>792 才退出（业界断点滞回，避免拖窗在临界来回单行/两行）
            const NAV_STACK_ENTER_MAX = 768;
            const NAV_STACK_EXIT_MIN = 792;
            let navStackedSticky = false;
            
            // 右簇溢出：「更多」默认不占位；仅当前行装不下时再 display:flex
            // 已换两行时按第二行全宽算——放得下则不显示更多；仍放不下才显示
            function clustersOnSeparateRows() {
                if (mainNavInner && mainNavInner.classList.contains('is-nav-stacked')) {
                    return true;
                }
                if (!leftCluster || !rightCluster) {
                    return false;
                }
                // 同行也可能因 align-items / 字高差数 px（实测 ~11）；
                // 只有明显跨到下一 flex 行才算分行，否则会跳过互让、左撑满右被挤扁。
                const rowThreshold = Math.max(
                    24,
                    Math.floor((leftCluster.offsetHeight || 40) * 0.75)
                );
                const deltaTop = Math.abs(leftCluster.offsetTop - rightCluster.offsetTop);
                if (deltaTop < rowThreshold) {
                    return false;
                }
                // offsetTop 差达阈值也可能是「同高基线错位」误判（实测两行时 Δtop≈9px），
                // 必须 rect 佐证垂直区间真的不相交，才按两行全宽算。
                const leftRect = leftCluster.getBoundingClientRect();
                const rightRect = rightCluster.getBoundingClientRect();
                const overlap = Math.min(leftRect.bottom, rightRect.bottom)
                    - Math.max(leftRect.top, rightRect.top);
                return overlap <= 2;
            }

            function syncNavStackMode() {
                if (!mainNavInner) {
                    return;
                }
                const w = window.innerWidth || document.documentElement.clientWidth || 0;
                if (!navStackedSticky && w <= NAV_STACK_ENTER_MAX) {
                    navStackedSticky = true;
                } else if (navStackedSticky && w > NAV_STACK_EXIT_MIN) {
                    navStackedSticky = false;
                }
                const shouldStack = navStackedSticky || w <= NAV_STACK_ENTER_MAX;
                const wasStacked = mainNavInner.classList.contains('is-nav-stacked');
                mainNavInner.classList.toggle('is-nav-stacked', shouldStack);
                if (wasStacked !== shouldStack) {
                    // 单行/两行切换时丢掉 hideFrom 滞回，避免宽屏仍锁在「全进更多」
                    lastNavHideFrom = null;
                    lastCatHideFrom = null;
                    navMenuBuiltFor = null;
                    catMenuBuiltFor = null;
                    // 栈切换会改 gap/padding 语义，禁止沿用单行缓存去算两行全宽
                    cachedInnerPadGap = null;
                    cachedRightPad = null;
                    cachedLeftGap = null;
                    cachedAllW = null;
                }
            }

            function scheduleOverflowAdjust() {
                syncNavStackMode();
                if (!overflowAdjustRaf) {
                    overflowAdjustRaf = window.requestAnimationFrame(function() {
                        overflowAdjustRaf = 0;
                        // 先左后右：左收进更多后右簇变宽，再决定右「更多」
                        warmNavNaturalMetrics();
                        adjustCategoriesOverflow(true);
                        if (rightCluster) {
                            void rightCluster.offsetWidth;
                        }
                        adjustNavLinks(true);
                    });
                }
                if (overflowIdleTimer) {
                    window.clearTimeout(overflowIdleTimer);
                }
                overflowIdleTimer = window.setTimeout(function() {
                    overflowIdleTimer = 0;
                    warmNavNaturalMetrics();
                    adjustCategoriesOverflow(false);
                    if (rightCluster) {
                        void rightCluster.offsetWidth;
                    }
                    adjustNavLinks(false);
                }, 180);
            }

            function warmNavNaturalMetrics() {
                if (!rightCluster) {
                    return;
                }
                const extensionLinks = Array.from(rightCluster.querySelectorAll(
                    ':scope > .header-nav-extension-link, :scope > .header-nav-extensions .header-nav-extension-link'
                ));
                const listItems = navLinksList
                    ? Array.from(navLinksList.querySelectorAll(':scope > li'))
                    : [];
                const candidates = extensionLinks.concat(listItems);
                if (candidates.length) {
                    ensureNavNaturalMetrics(candidates);
                }
            }

            function buildWidthPrefix(widths, gap) {
                const prefix = new Array(widths.length);
                let sum = 0;
                for (let i = 0; i < widths.length; i++) {
                    sum += widths[i] + (i > 0 ? gap : 0);
                    prefix[i] = sum;
                }
                return prefix;
            }

            function measureMainInnerContentWidth() {
                if (!mainNavInner) {
                    return headerMainNav ? headerMainNav.clientWidth : 0;
                }
                if (!cachedInnerPadGap) {
                    const innerStyle = window.getComputedStyle(mainNavInner);
                    cachedInnerPadGap = {
                        l: parseFloat(innerStyle.paddingInlineStart || innerStyle.paddingLeft) || 0,
                        r: parseFloat(innerStyle.paddingInlineEnd || innerStyle.paddingRight) || 0,
                        gap: parseFloat(innerStyle.columnGap || innerStyle.gap) || 0
                    };
                }
                return Math.max(
                    0,
                    mainNavInner.clientWidth - cachedInnerPadGap.l - cachedInnerPadGap.r
                );
            }

            function measureAllButtonWidth() {
                const allRoot = document.getElementById('header-nav-all-root');
                if (!allRoot) {
                    return 0;
                }
                // hidden fallback / 空槽不占宽
                const visible = allRoot.querySelector(
                    '#hamburger-menu:not([hidden]), [data-all-menu-widget="1"], .hamburger-menu-btn:not([hidden])'
                );
                if (!visible) {
                    return 0;
                }
                return allRoot.offsetWidth;
            }

            /**
             * 左簇实际占用：全部 + 仍显示的分类 + 仍显示的政策单元 + 左簇末 More（不含 flex 空白）。
             * 左空时 ≈0，右可用宽 = 主栏 − 左占用 − gap。
             */
            function measurePolicyLinksWidth() {
                const slot = document.querySelector('.header-policy-links-slot');
                if (!slot) {
                    return 0;
                }
                if (slot.hidden || slot.offsetParent === null) {
                    return 0;
                }
                const units = slot.querySelectorAll(
                    '.header-policy-links__inline, .header-policy-links__menu-wrap'
                );
                if (!units.length) {
                    const w = slot.offsetWidth;
                    return w > 0 ? w : 0;
                }
                let sum = 0;
                let visible = 0;
                const gap = cachedLeftGap != null ? cachedLeftGap : 8;
                units.forEach(function(el) {
                    if (
                        el.classList.contains('hidden')
                        || el.classList.contains('is-nav-overflow-hidden')
                        || el.getAttribute('hidden') !== null
                    ) {
                        return;
                    }
                    const w = el.offsetWidth;
                    if (w <= 0) {
                        return;
                    }
                    sum += w + (visible > 0 ? gap : 0);
                    visible += 1;
                });
                return sum;
            }

            function measureLeftOccupiedWidth() {
                const allW = measureAllButtonWidth();
                if (!leftCluster) {
                    return allW;
                }
                if (cachedLeftGap == null) {
                    const leftStyle = window.getComputedStyle(leftCluster);
                    cachedLeftGap = parseFloat(leftStyle.columnGap || leftStyle.gap) || 0;
                }
                let catsW = 0;
                let visibleCount = 0;
                if (categoriesList) {
                    const catGap = parseFloat(window.getComputedStyle(categoriesList).columnGap
                        || window.getComputedStyle(categoriesList).gap) || 8;
                    Array.from(categoriesList.children).forEach(function(el) {
                        if (el === categoriesOverflowWrapper
                            || el.classList.contains('categories-overflow-wrapper')) {
                            // More 已迁到左簇末，list 内残留不计
                            return;
                        }
                        if (
                            el.classList.contains('hidden')
                            || el.classList.contains('is-nav-overflow-hidden')
                            || el.getAttribute('hidden') !== null
                        ) {
                            return;
                        }
                        const w = el.offsetWidth;
                        if (w <= 0) {
                            return;
                        }
                        catsW += w + (visibleCount > 0 ? catGap : 0);
                        visibleCount += 1;
                    });
                }
                const policyW = measurePolicyLinksWidth();
                let occupied = allW;
                if (catsW > 0) {
                    occupied = allW > 0 ? allW + cachedLeftGap + catsW : catsW;
                }
                if (policyW > 0) {
                    occupied = occupied > 0 ? occupied + cachedLeftGap + policyW : policyW;
                }
                // 左簇末 More（分类+政策之后）
                if (categoriesOverflowWrapper
                    && categoriesOverflowWrapper.parentElement === leftCluster) {
                    const moreShown = categoriesOverflowWrapper.style.display !== 'none'
                        && !categoriesOverflowWrapper.hidden
                        && window.getComputedStyle(categoriesOverflowWrapper).display !== 'none';
                    if (moreShown) {
                        const moreW = categoriesOverflowWrapper.offsetWidth;
                        if (moreW > 0) {
                            occupied = occupied > 0
                                ? occupied + cachedLeftGap + moreW
                                : moreW;
                        }
                    }
                }
                return occupied;
            }

            function measureNavAvailableWidth() {
                const mainW = measureMainInnerContentWidth();
                if (!cachedInnerPadGap && mainNavInner) {
                    const innerStyle = window.getComputedStyle(mainNavInner);
                    cachedInnerPadGap = {
                        l: parseFloat(innerStyle.paddingInlineStart || innerStyle.paddingLeft) || 0,
                        r: parseFloat(innerStyle.paddingInlineEnd || innerStyle.paddingRight) || 0,
                        gap: parseFloat(innerStyle.columnGap || innerStyle.gap) || 0
                    };
                }
                if (!cachedRightPad && rightCluster) {
                    const style = window.getComputedStyle(rightCluster);
                    cachedRightPad = {
                        l: parseFloat(style.paddingInlineStart || style.paddingLeft) || 0,
                        r: parseFloat(style.paddingInlineEnd || style.paddingRight) || 0
                    };
                }
                const rightPad = cachedRightPad
                    ? (cachedRightPad.l + cachedRightPad.r)
                    : 0;
                // 已换两行：右簇独占第二行，按主栏 100% 内容宽算（不再扣左占用）
                if (clustersOnSeparateRows()) {
                    const fullRow = Math.max(0, mainW - rightPad);
                    if (!rightCluster) {
                        return fullRow;
                    }
                    const layout = Math.max(
                        0,
                        rightCluster.clientWidth - rightPad
                    );
                    // reflow 未完成时 layout 可能偏小，取与全宽较大者
                    return Math.max(layout, fullRow);
                }
                const gap = cachedInnerPadGap ? cachedInnerPadGap.gap : 0;
                // 互让：右可用 = 主栏内容宽 − 左实际占用 − 簇间距（左空则右几乎拿满）
                const reciprocal = Math.max(0, mainW - measureLeftOccupiedWidth() - gap);
                if (!rightCluster) {
                    if (headerNavFill) {
                        return Math.max(reciprocal, headerNavFill.clientWidth);
                    }
                    return reciprocal;
                }
                const layout = Math.max(
                    0,
                    rightCluster.clientWidth - rightPad
                );
                // 取较大值：左若被错误撑宽导致 layout 偏小，仍以互让宽为准
                return Math.max(layout, reciprocal);
            }

            function measureRightNaturalReserve() {
                if (navNaturalCache.prefix && navNaturalCache.prefix.length) {
                    return navNaturalCache.prefix[navNaturalCache.prefix.length - 1];
                }
                if (navNaturalCache.widths && navNaturalCache.widths.length) {
                    let sum = 0;
                    const gap = navNaturalCache.gap || 20;
                    for (let i = 0; i < navNaturalCache.widths.length; i++) {
                        sum += navNaturalCache.widths[i] + (i > 0 ? gap : 0);
                    }
                    return sum;
                }
                return 0;
            }

            function measureCatAvailableWidth() {
                // 左簇整体（分类+政策）在「主栏−全部−右自然宽」内互让；政策已列入溢出候选，不再单独预留
                const mainW = measureMainInnerContentWidth();
                const allW = measureAllButtonWidth();
                if (!cachedInnerPadGap && mainNavInner) {
                    const innerStyle = window.getComputedStyle(mainNavInner);
                    cachedInnerPadGap = {
                        l: parseFloat(innerStyle.paddingInlineStart || innerStyle.paddingLeft) || 0,
                        r: parseFloat(innerStyle.paddingInlineEnd || innerStyle.paddingRight) || 0,
                        gap: parseFloat(innerStyle.columnGap || innerStyle.gap) || 0
                    };
                }
                if (cachedLeftGap == null && leftCluster) {
                    const leftStyle = window.getComputedStyle(leftCluster);
                    cachedLeftGap = parseFloat(leftStyle.columnGap || leftStyle.gap) || 0;
                }
                const gap = cachedInnerPadGap ? cachedInnerPadGap.gap : 0;
                const leftGap = cachedLeftGap != null ? cachedLeftGap : 0;
                // 已换两行：左簇独占第一行，按主栏 100% 内容宽算（不预留右簇自然宽）
                if (clustersOnSeparateRows()) {
                    // allW（全部分类按钮）在左簇内部，不吃 inner 簇间距 gap
                    let fullRow = Math.max(0, mainW - allW - 8);
                    if (leftCluster) {
                        const layout = Math.max(0, leftCluster.clientWidth - allW - leftGap - 8);
                        fullRow = Math.max(fullRow, layout);
                    }
                    return fullRow;
                }
                // 单行左预算 = 主栏 − 右自然内容宽 − 簇间距 − All − 左簇 gap。
                // 右簇已改为内容宽（禁止 flex:1 / 30% 地板吃空档）；左按剩余实宽算 More。
                if (mainW > 0) {
                    const rightReserve = measureRightNaturalReserve();
                    const leftCeiling = Math.max(0, mainW - rightReserve - gap);
                    return Math.max(0, leftCeiling - allW - leftGap - 8);
                }
                return Math.max(0, mainW - allW - gap - 8);
            }

            function collectLeftOverflowCandidates() {
                // 硬约束：左簇 More 不能只收分类 menu；政策直链/下拉与分类同列候选，从簇末往前藏
                const cats = categoriesList
                    ? Array.from(categoriesList.children).filter(function(item) {
                        return item !== categoriesOverflowWrapper
                            && !item.classList.contains('categories-overflow-wrapper');
                    })
                    : [];
                const policyUnits = [];
                document.querySelectorAll(
                    '.header-policy-links-slot a.header-policy-links__inline, '
                    + '.header-policy-links-slot .header-policy-links__menu-wrap'
                ).forEach(function(child) {
                    policyUnits.push(child);
                });
                return cats.concat(policyUnits);
            }

            function measureSlotChainExtras() {
                // 兜底：分类槽/政策槽内「非候选」的链接链（widget 壳被发布槽替换时
                // collectLeftOverflowCandidates 会抓空）按可见直链逐条计宽并入预算，
                // 保证 More 出现所需空间不被漏算 → 全簇不再被 flex 压扁裁字
                const extras = [];
                if (!leftCluster) {
                    return extras;
                }
                ['.header-nav-left-slot', '.header-policy-links-slot'].forEach(function(sel) {
                    const slot = leftCluster.querySelector(sel);
                    if (!slot) {
                        return;
                    }
                    slot.querySelectorAll('a').forEach(function(a) {
                        const label = (a.textContent || '').trim();
                        if (!label) {
                            return;
                        }
                        if (a.closest('[data-w-popover-panel], [data-w-menu-panel], .w-popover, .w-mega-menu, .w-menu')) {
                            return;
                        }
                        // 已被 collectLeftOverflowCandidates 收作候选的链不得再计一次：
                        // 候选链的宽度已由 ensureCatNaturalMetrics 进入 prefix，重复相减会把
                        // 左预算按「当前可见项宽」逐轮吃掉（可见越多 → 扣得越多 → 永远长不回），
                        // 表现为中间大块空白却仍显示「更多」。只有非候选链才需要并入预算。
                        if (a.closest('.category-item')) {
                            return;
                        }
                        if (a.closest('.header-policy-links__menu-wrap')) {
                            return;
                        }
                        if (a.matches('a.header-policy-links__inline')) {
                            return;
                        }
                        const r = a.getBoundingClientRect();
                        if (r.width < 1 && r.height < 1) {
                            return;
                        }
                        const probe = a.cloneNode(true);
                        probe.style.cssText = 'display:inline-block;width:auto;max-width:none;flex:0 0 auto;';
                        const host = document.createElement('div');
                        host.setAttribute('aria-hidden', 'true');
                        host.style.cssText = 'position:absolute;left:-99999px;top:0;visibility:hidden;white-space:nowrap;display:inline-flex;align-items:center;';
                        host.appendChild(probe);
                        document.body.appendChild(host);
                        const natural = Math.ceil(probe.getBoundingClientRect().width);
                        document.body.removeChild(host);
                        extras.push({ el: a, w: natural });
                    });
                });
                return extras;
            }

            function measureSlotChainExtrasTotal() {
                const extras = measureSlotChainExtras();
                if (!extras.length) {
                    return 0;
                }
                let sum = 0;
                for (let i = 0; i < extras.length; i++) {
                    sum += extras[i].w + (i > 0 ? 8 : 0);
                }
                return sum;
            }

            // 真实溢出兜底：预算判定为不溢出、但分类槽/政策槽仍裁字或压扁时，
            // 逐步把尾部候选收进 More。仅查 leftCluster.scrollWidth 会漏检——
            // 子槽 overflow:hidden 时外层 scrollWidth≈clientWidth，汉服被裁、购物政策被压扁。
            function leftClusterContentIsSquished() {
                if (!leftCluster) {
                    return false;
                }
                if (leftCluster.scrollWidth > leftCluster.clientWidth + 1) {
                    return true;
                }
                const catsSlot = leftCluster.querySelector('.header-nav-left-slot, .header-categories');
                const catsList = categoriesList || (catsSlot && catsSlot.querySelector('.categories-list'));
                if (catsSlot && catsList && catsList.scrollWidth > catsSlot.clientWidth + 1) {
                    return true;
                }
                const policySlot = leftCluster.querySelector('.header-policy-links-slot');
                if (policySlot) {
                    const policyRoot = policySlot.querySelector('.header-policy-links, [data-testid="header-policy-links"]') || policySlot;
                    if (policyRoot.scrollWidth > policySlot.clientWidth + 1) {
                        return true;
                    }
                    const units = policySlot.querySelectorAll(
                        'a.header-policy-links__inline:not(.is-nav-overflow-hidden):not(.hidden),'
                        + ' .header-policy-links__menu-wrap:not(.is-nav-overflow-hidden):not(.hidden)'
                    );
                    const slotRight = policySlot.getBoundingClientRect().right;
                    for (let i = 0; i < units.length; i++) {
                        const el = units[i];
                        // 发布槽内容包在 .weline-template-widget（display:block）里，溢出被壳吞掉、
                        // scrollWidth 查不到 → 按 rect 越出槽右边界判定
                        if (el.getBoundingClientRect().right > slotRight + 0.5) {
                            return true;
                        }
                        const trigger = el.matches('a') ? el : el.querySelector('.header-policy-links__trigger, [data-w-menu-trigger], a');
                        const probe = trigger || el;
                        if (probe.scrollWidth > probe.clientWidth + 1) {
                            return true;
                        }
                    }
                }
                if (catsSlot && catsList) {
                    const slotRight = catsSlot.getBoundingClientRect().right;
                    const items = catsList.querySelectorAll(':scope > .category-item:not(.is-nav-overflow-hidden):not(.hidden)');
                    for (let i = 0; i < items.length; i++) {
                        if (items[i].getBoundingClientRect().right > slotRight + 0.5) {
                            return true;
                        }
                    }
                }
                return false;
            }

            function measureLeftClusterContentWidth() {
                if (!leftCluster) {
                    return 0;
                }
                const gap = cachedLeftGap != null
                    ? cachedLeftGap
                    : (parseFloat(window.getComputedStyle(leftCluster).columnGap
                        || window.getComputedStyle(leftCluster).gap) || 0);
                const children = Array.from(leftCluster.children).filter(function(child) {
                    if (child.hidden) {
                        return false;
                    }
                    const style = window.getComputedStyle(child);
                    if (style.display === 'none' || style.visibility === 'hidden') {
                        return false;
                    }
                    // 零占位 More 不计入内容宽
                    if ((style.width === '0px' || style.flexGrow === '0' && style.flexBasis === '0px')
                        && child.getBoundingClientRect().width < 1) {
                        return false;
                    }
                    return child.getBoundingClientRect().width >= 1;
                });
                let sum = 0;
                for (let i = 0; i < children.length; i++) {
                    sum += children[i].getBoundingClientRect().width + (i > 0 ? gap : 0);
                }
                return sum;
            }

            function leftClusterHasSpareRoom(minSpare) {
                if (!leftCluster) {
                    return false;
                }
                const spare = leftCluster.clientWidth - measureLeftClusterContentWidth();
                return spare >= (minSpare != null ? minSpare : 16);
            }

            function enforceLeftClusterNoSquish(candidates) {
                if (!leftCluster || !candidates.length) {
                    return -1;
                }
                if (clustersOnSeparateRows()) {
                    return lastCatHideFrom;
                }
                // 左簇 flex:1 吃剩余时，中间常有大片空档。此时禁止因克隆宽±数 px
                // 误判「政策压扁」把末项收回更多（宽屏空档仍出「更多」的第二根因）。
                if (leftClusterHasSpareRoom(16)) {
                    return lastCatHideFrom;
                }
                if (!leftClusterContentIsSquished() && !policyUnitsAreSqueezed()) {
                    return lastCatHideFrom;
                }
                let hideFrom = lastCatHideFrom >= 0 ? lastCatHideFrom : candidates.length;
                let guard = candidates.length + 2;
                while (hideFrom > 0 && (leftClusterContentIsSquished() || policyUnitsAreSqueezed()) && guard-- > 0) {
                    hideFrom -= 1;
                    applyCatVisibility(candidates, hideFrom);
                }
                return hideFrom;
            }

            function isLeftCategoryCandidate(el) {
                return !!(el && el.classList && el.classList.contains('category-item'));
            }

            function measureFlexChildrenOffDom(elements, morePrototype, gap) {
                const host = document.createElement('div');
                host.setAttribute('aria-hidden', 'true');
                host.className = 'js-nav-offdom-host';
                host.style.cssText = 'position:absolute;left:-99999px;top:0;visibility:hidden;pointer-events:none;display:flex;align-items:center;white-space:nowrap;width:max-content;max-width:none;gap:'
                    + gap + 'px;';
                const clones = [];
                for (let i = 0; i < elements.length; i++) {
                    const clone = elements[i].cloneNode(true);
                    clone.classList.remove('hidden', 'is-nav-overflow-hidden');
                    clone.removeAttribute('hidden');
                    // 分类 mega/popover 面板不计入行宽，否则左预算被撑爆、右被挤成「更多」
                    clone.querySelectorAll(
                        '.w-popover, .w-mega-menu, .header-category-panel, .categories-overflow-panel, [data-w-popover-panel], [data-w-menu-panel]'
                    ).forEach(function(panel) {
                        panel.remove();
                    });
                    clone.style.cssText = 'display:flex;position:static;visibility:visible;width:auto!important;max-width:none!important;flex:0 0 auto!important;min-width:0!important;';
                    // 脱 DOM 后祖先百分比规则（.categories-list{width:100%} 等）仍命中克隆子级，
                    // 量出的「自然宽」实为渲染宽 → 预算低估、全屏压扁。逐层钉死 auto/max-content。
                    clone.querySelectorAll('*').forEach(function(node) {
                        node.style.width = 'auto';
                        node.style.maxWidth = 'none';
                        node.style.flex = '0 0 auto';
                        node.style.minWidth = '0';
                    });
                    host.appendChild(clone);
                    clones.push(clone);
                }
                let moreClone = null;
                if (morePrototype) {
                    moreClone = morePrototype.cloneNode(true);
                    moreClone.hidden = false;
                    moreClone.classList.remove('hidden', 'is-nav-overflow-hidden');
                    moreClone.querySelectorAll(
                        '.w-popover, .w-mega-menu, .header-category-panel, .categories-overflow-panel, [data-w-popover-panel], [data-w-menu-panel]'
                    ).forEach(function(panel) {
                        panel.remove();
                    });
                    moreClone.style.cssText = 'display:flex;position:static;visibility:visible;width:auto!important;max-width:none!important;flex:0 0 auto!important;min-width:0!important;';
                    moreClone.querySelectorAll('*').forEach(function(node) {
                        node.style.width = 'auto';
                        node.style.maxWidth = 'none';
                        node.style.flex = '0 0 auto';
                        node.style.minWidth = '0';
                    });
                    host.appendChild(moreClone);
                }
                document.body.appendChild(host);
                const widths = clones.map(function(clone) {
                    return clone.getBoundingClientRect().width;
                });
                const moreW = moreClone ? (moreClone.getBoundingClientRect().width + gap) : 0;
                document.body.removeChild(host);
                return { widths: widths, moreW: moreW, gap: gap, hostW: Math.ceil(host.getBoundingClientRect().width) };
            }

            // 左簇整簇自然宽：把 All + 分类槽 + 政策槽 + More 按真实 DOM 层级脱 DOM 克隆，
            // 宿主链注入同构 class（.header-main-nav-inner>.header-nav-left-cluster），让既有
            // 后代选择器继续生效；再逐层钉死宽度，避免祖先百分比污染。
            // 返回 null 表示结构缺失（调用方回退旧逻辑）。
            function measureLeftClusterNaturalWidth(catsSlot, policySlot) {
                if (!leftCluster || !catsSlot || !policySlot) {
                    return null;
                }
                const innerProto = mainNavInner || leftCluster.parentElement;
                if (!innerProto) {
                    return null;
                }
                const gap = parseFloat(window.getComputedStyle(leftCluster).columnGap || window.getComputedStyle(leftCluster).gap) || 0;
                const host = document.createElement('div');
                host.setAttribute('aria-hidden', 'true');
                host.className = 'js-nav-offdom-host header-main-nav-inner '
                    + (innerProto.classList.contains('is-nav-stacked') ? 'is-nav-stacked' : '');
                // 内联 !important 压过 .header-main-nav-inner{max-width}；脱 DOM 宿主必须拿满内容宽
                host.style.cssText = 'position:absolute;left:-99999px;top:0;visibility:hidden;pointer-events:none;display:flex;flex-wrap:nowrap;align-items:center;width:max-content!important;max-width:none!important;'
                    + 'padding-left:0!important;padding-right:0!important;'
                    + 'gap:' + (parseFloat(window.getComputedStyle(innerProto).columnGap || window.getComputedStyle(innerProto).gap) || 0) + 'px;';
                const cluster = document.createElement('div');
                cluster.className = 'header-nav-left-cluster';
                cluster.style.cssText = 'display:flex;align-items:center;flex:0 0 auto!important;width:max-content!important;max-width:none!important;min-width:0;overflow:visible;gap:' + gap + 'px;';

                function cloneVisible(el) {
                    const c = el.cloneNode(true);
                    c.classList.remove('hidden', 'is-nav-overflow-hidden');
                    c.removeAttribute('hidden');
                    c.querySelectorAll('.hidden, .is-nav-overflow-hidden').forEach(function(node) {
                        node.classList.remove('hidden', 'is-nav-overflow-hidden');
                        node.removeAttribute('hidden');
                    });
                    c.querySelectorAll(
                        '.w-popover, .w-mega-menu, .header-category-panel, .categories-overflow-panel, [data-w-popover-panel], [data-w-menu-panel]'
                    ).forEach(function(panel) {
                        panel.remove();
                    });
                    c.querySelectorAll('*').forEach(function(node) {
                        node.style.width = 'auto';
                        node.style.maxWidth = 'none';
                        node.style.flex = '0 0 auto';
                        node.style.minWidth = '0';
                    });
                    return c;
                }

                const allRoot = document.getElementById('header-nav-all-root');
                if (allRoot) {
                    cluster.appendChild(cloneVisible(allRoot));
                }
                // 克隆槽保持其在真实簇中的直接子节点身份（widget 壳→内容根），不得拍平包装层，
                // 否则量出的是内容自然宽而非「含发布包装开销」的簇自然宽。
                const catsClone = cloneVisible(catsSlot);
                // 内层链（.categories-nav/.categories-list）必须 max-content：
                // 既有 CSS 是 width:100%/max-width:100%，克隆父级钉成 max-content 时
                // 百分比子级会解析回内容宽 → 量不到包装开销（此前 nat 恒等 prefix+all+more 的根因）
                catsClone.querySelectorAll('.categories-nav, .categories-list').forEach(function(node) {
                    node.style.setProperty('width', 'max-content', 'important');
                    node.style.setProperty('maxWidth', 'none', 'important');
                });
                catsClone.style.cssText = catsClone.style.cssText + 'display:flex;align-items:center;flex:0 0 auto!important;width:max-content!important;max-width:none!important;min-width:0;overflow:visible;';
                cluster.appendChild(catsClone);
                const policyClone = cloneVisible(policySlot);
                policyClone.querySelectorAll('.header-policy-links').forEach(function(node) {
                    node.style.setProperty('width', 'max-content', 'important');
                    node.style.setProperty('maxWidth', 'none', 'important');
                });
                policyClone.style.cssText = policyClone.style.cssText + 'display:flex;align-items:center;flex:0 0 auto!important;width:max-content!important;max-width:none!important;min-width:0;overflow:visible;';
                cluster.appendChild(policyClone);
                if (categoriesOverflowWrapper) {
                    const moreClone = cloneVisible(categoriesOverflowWrapper);
                    moreClone.style.display = 'flex';
                    cluster.appendChild(moreClone);
                }
                host.appendChild(cluster);
                document.body.appendChild(host);
                const natural = Math.ceil(cluster.getBoundingClientRect().width);
                document.body.removeChild(host);
                return natural > 0 ? natural : null;
            }

            // 单元素自然宽（钉宽克隆）：识别「渲染宽 < 自然宽」的压扁。
            function naturalWidthOf(el) {
                if (!el) {
                    return 0;
                }
                const clone = el.cloneNode(true);
                clone.classList.remove('hidden', 'is-nav-overflow-hidden');
                clone.removeAttribute('hidden');
                clone.querySelectorAll('.hidden, .is-nav-overflow-hidden').forEach(function(node) {
                    node.classList.remove('hidden', 'is-nav-overflow-hidden');
                    node.removeAttribute('hidden');
                });
                clone.querySelectorAll(
                    '.w-popover, .w-mega-menu, .header-category-panel, .categories-overflow-panel, [data-w-popover-panel], [data-w-menu-panel]'
                ).forEach(function(panel) {
                    panel.remove();
                });
                clone.style.cssText = 'display:inline-flex;position:static;visibility:visible;width:max-content!important;max-width:none!important;flex:0 0 auto!important;min-width:0!important;white-space:nowrap;';
                clone.querySelectorAll('*').forEach(function(node) {
                    node.style.width = 'auto';
                    node.style.maxWidth = 'none';
                    node.style.flex = '0 0 auto';
                    node.style.minWidth = '0';
                });
                const host = document.createElement('div');
                host.setAttribute('aria-hidden', 'true');
                host.style.cssText = 'position:absolute;left:-99999px;top:0;visibility:hidden;pointer-events:none;display:inline-flex;align-items:center;white-space:nowrap;width:max-content;max-width:none;';
                host.appendChild(clone);
                document.body.appendChild(host);
                const w = Math.ceil(clone.getBoundingClientRect().width);
                document.body.removeChild(host);
                return w;
            }

            function visiblePolicyUnits() {
                if (!leftCluster) {
                    return [];
                }
                const policySlot = leftCluster.querySelector('.header-policy-links-slot');
                if (!policySlot) {
                    return [];
                }
                return Array.from(policySlot.querySelectorAll(
                    'a.header-policy-links__inline:not(.is-nav-overflow-hidden):not(.hidden),'
                    + ' .header-policy-links__menu-wrap:not(.is-nav-overflow-hidden):not(.hidden)'
                ));
            }

            // 政策单元被压扁判定：widget 壳（display:block）会吞掉溢出，scrollWidth 查不到，
            // 必须按「渲染宽 vs 钉宽克隆自然宽」或 rect 越出槽右边界来判。
            function policyUnitsAreSqueezed() {
                const units = visiblePolicyUnits();
                if (!units.length) {
                    return false;
                }
                const policySlot = leftCluster && leftCluster.querySelector('.header-policy-links-slot');
                const slotRight = policySlot ? policySlot.getBoundingClientRect().right : Infinity;
                for (let i = 0; i < units.length; i++) {
                    const el = units[i];
                    if (el.getBoundingClientRect().right > slotRight + 0.5) {
                        return true;
                    }
                    const nat = naturalWidthOf(el);
                    // 克隆与实渲染常差数 px（字偶/亚像素）；2px 过严会在仍有空档时误收「更多」
                    if (nat > 0 && el.getBoundingClientRect().width < nat - 8) {
                        return true;
                    }
                }
                return false;
            }

            function ensureNavNaturalMetrics(candidates) {
                const key = candidates.map(function(item) {
                    const link = item.tagName === 'A' ? item : item.querySelector('a');
                    return (link && link.getAttribute('href') || '') + '\t' + ((link && link.textContent) || item.textContent || '').trim();
                }).join('\n');
                const clusterGap = rightCluster
                    ? (parseFloat(window.getComputedStyle(rightCluster).gap) || 20)
                    : 20;
                if (navNaturalCache.key === key && navNaturalCache.widths.length === candidates.length) {
                    navNaturalCache.gap = clusterGap;
                    if (!navNaturalCache.prefix || navNaturalCache.prefix.length !== candidates.length) {
                        navNaturalCache.prefix = buildWidthPrefix(navNaturalCache.widths, clusterGap);
                    }
                    return navNaturalCache;
                }
                const measured = measureFlexChildrenOffDom(candidates, navMoreWrapper, clusterGap);
                const widths = measured.widths;
                navNaturalCache = {
                    key: key,
                    widths: widths,
                    prefix: buildWidthPrefix(widths, clusterGap),
                    moreW: measured.moreW || 72,
                    gap: clusterGap
                };
                return navNaturalCache;
            }

            function ensureCatNaturalMetrics(candidates) {
                const catKey = candidates.map(function(item) {
                    if (isLeftCategoryCandidate(item)) {
                        const link = item.querySelector('a');
                        return 'c\t' + (link && link.getAttribute('href') || '') + '\t' + ((link && link.textContent) || item.textContent || '').trim();
                    }
                    if (item.matches && item.matches('a')) {
                        return 'p\t' + (item.getAttribute('href') || '') + '\t' + (item.textContent || '').trim();
                    }
                    const trig = item.querySelector('[data-w-menu-trigger], .header-policy-links__trigger, a');
                    return 'p\t' + ((trig && trig.textContent) || item.textContent || '').trim();
                }).join('\n');
                const gap = leftCluster
                    ? (parseFloat(window.getComputedStyle(leftCluster).gap) || 8)
                    : (categoriesList ? (parseFloat(window.getComputedStyle(categoriesList).gap) || 8) : 8);
                if (catNaturalCache.key === catKey && catNaturalCache.widths.length === candidates.length) {
                    catNaturalCache.gap = gap;
                    if (!catNaturalCache.prefix || catNaturalCache.prefix.length !== candidates.length) {
                        catNaturalCache.prefix = buildWidthPrefix(catNaturalCache.widths, gap);
                    }
                    return catNaturalCache;
                }
                const measured = measureFlexChildrenOffDom(candidates, categoriesOverflowWrapper, gap);
                const widths = measured.widths;
                catNaturalCache = {
                    key: catKey,
                    widths: widths,
                    prefix: buildWidthPrefix(widths, gap),
                    moreW: measured.moreW || 56,
                    gap: gap
                };
                return catNaturalCache;
            }

            /**
             * 前缀和 + 二分：最大可见条数 k → hideFrom=k（全可见则 -1）
             * moreW 已含与末项间距。
             */
            function computeHideFromRaw(prefix, moreW, availableWidth, count) {
                if (!count) {
                    return -1;
                }
                if (prefix[count - 1] <= availableWidth + 0.5) {
                    return -1;
                }
                let lo = 0;
                let hi = count - 1;
                while (lo < hi) {
                    const mid = (lo + hi + 1) >> 1;
                    const need = (mid === 0 ? 0 : prefix[mid - 1]) + moreW;
                    if (need <= availableWidth + 0.5) {
                        lo = mid;
                    } else {
                        hi = mid - 1;
                    }
                }
                const need0 = moreW;
                if (lo === 0 && need0 > availableWidth + 0.5) {
                    return 0;
                }
                return lo;
            }

            /**
             * 滞回：变窄（可见变少）立即采用 raw；变宽（吐回项）需 A−δ 仍装得下才放行。
             * 变宽时允许部分吐回（落到 strict），禁止整段锁死在旧 hideFrom（只缩不放）。
             */
            function resolveHideFromWithHysteresis(prefix, moreW, availableWidth, count, prevHideFrom) {
                const raw = computeHideFromRaw(prefix, moreW, availableWidth, count);
                if (prevHideFrom == null) {
                    return raw;
                }
                const visibleOf = function(hideFrom) {
                    return hideFrom < 0 ? count : hideFrom;
                };
                const rawVis = visibleOf(raw);
                const prevVis = visibleOf(prevHideFrom);
                if (rawVis < prevVis) {
                    return raw;
                }
                if (rawVis > prevVis) {
                    const strict = computeHideFromRaw(
                        prefix,
                        moreW,
                        Math.max(0, availableWidth - OVERFLOW_HYSTERESIS_PX),
                        count
                    );
                    const strictVis = visibleOf(strict);
                    if (strictVis >= rawVis) {
                        return raw;
                    }
                    if (strictVis > prevVis) {
                        return strict;
                    }
                    return prevHideFrom;
                }
                return prevHideFrom;
            }

            function applyNavVisibility(candidates, hideFrom) {
                for (let i = 0; i < candidates.length; i++) {
                    const hide = hideFrom >= 0 && i >= hideFrom;
                    const item = candidates[i];
                    if (hide) {
                        item.classList.add('is-nav-overflow-hidden');
                        if (item.tagName === 'LI') {
                            item.classList.add('hidden');
                        }
                    } else {
                        item.classList.remove('is-nav-overflow-hidden');
                        if (item.tagName === 'LI') {
                            item.classList.remove('hidden');
                        }
                    }
                }
                if (hideFrom < 0) {
                    navMoreWrapper.style.display = 'none';
                    navMoreWrapper.hidden = true;
                } else {
                    navMoreWrapper.hidden = false;
                    navMoreWrapper.style.display = 'flex';
                }
            }

            function rebuildNavMoreMenu(candidates, hideFrom) {
                if (window.Weline && window.Weline.UI && typeof window.Weline.UI.unmount === 'function') {
                    window.Weline.UI.unmount(navMoreWrapper);
                }
                navMoreDropdown.innerHTML = '';
                if (hideFrom < 0) {
                    navMenuBuiltFor = -1;
                    return;
                }
                candidates.slice(hideFrom).forEach(function(item) {
                    const link = item.tagName === 'A' ? item : item.querySelector('a');
                    if (!link) {
                        return;
                    }
                    const menuItem = document.createElement('a');
                    menuItem.className = 'w-menu__item';
                    menuItem.setAttribute('role', 'menuitem');
                    menuItem.tabIndex = -1;
                    menuItem.href = link.getAttribute('href') || '#';
                    if (link.getAttribute('role') === 'button' || (link.getAttribute('href') || '').charAt(0) === '#') {
                        menuItem.addEventListener('click', function(ev) {
                            ev.preventDefault();
                            link.click();
                        });
                    }
                    menuItem.textContent = (link.textContent || '').trim();
                    navMoreDropdown.appendChild(menuItem);
                });
                if (window.Weline && window.Weline.UI && typeof window.Weline.UI.mount === 'function') {
                    window.Weline.UI.mount(navMoreWrapper);
                }
                navMenuBuiltFor = hideFrom;
            }

            function applyCatVisibility(candidates, hideFrom) {
                for (let i = 0; i < candidates.length; i++) {
                    const hide = hideFrom >= 0 && i >= hideFrom;
                    candidates[i].classList.toggle('hidden', hide);
                    candidates[i].classList.toggle('is-nav-overflow-hidden', hide);
                }
                if (hideFrom < 0) {
                    categoriesOverflowWrapper.style.display = 'none';
                    categoriesOverflowWrapper.hidden = true;
                    categoriesOverflowWrapper.classList.remove('active');
                } else {
                    categoriesOverflowWrapper.hidden = false;
                    categoriesOverflowWrapper.style.display = 'flex';
                }
            }

            function collectPolicyOverflowEntries(unit) {
                const entries = [];
                if (!unit) {
                    return entries;
                }
                if (unit.matches && unit.matches('a')) {
                    entries.push({
                        label: (unit.textContent || '').trim(),
                        href: unit.getAttribute('href') || '#'
                    });
                    return entries;
                }
                Array.from(unit.querySelectorAll('a.header-policy-links__item, a.w-menu__item, a')).forEach(function(a) {
                    const label = (a.textContent || '').trim();
                    if (!label) {
                        return;
                    }
                    entries.push({ label: label, href: a.getAttribute('href') || '#' });
                });
                if (!entries.length) {
                    const trig = unit.querySelector('[data-w-menu-trigger], .header-policy-links__trigger');
                    const label = ((trig && trig.textContent) || unit.textContent || '').trim();
                    if (label) {
                        entries.push({ label: label, href: '#' });
                    }
                }
                return entries;
            }

            function rebuildCatMoreMenu(candidates, hideFrom) {
                if (window.Weline && window.Weline.UI && typeof window.Weline.UI.unmount === 'function') {
                    window.Weline.UI.unmount(categoriesOverflowWrapper);
                }
                categoriesOverflowDropdown.innerHTML = '';
                categoriesOverflowWrapper.classList.remove('active');
                if (hideFrom < 0) {
                    catMenuBuiltFor = -1;
                    return;
                }
                const hidden = candidates.slice(hideFrom);
                const catHidden = hidden.filter(isLeftCategoryCandidate);
                const policyHidden = hidden.filter(function(el) { return !isLeftCategoryCandidate(el); });
                if (catHidden.length) {
                    buildCategoriesOverflowMega(catHidden, categoriesOverflowDropdown);
                } else {
                    // 仅政策进 More：仍用 mega 壳，侧栏塞直链
                    buildCategoriesOverflowMega([], categoriesOverflowDropdown);
                }
                if (policyHidden.length) {
                    const sidebar = categoriesOverflowDropdown.querySelector('.mega-menu-sidebar');
                    const panels = categoriesOverflowDropdown.querySelector('.mega-menu-panels');
                    if (sidebar && typeof appendLeafTab === 'function') {
                        // appendLeafTab 在 buildCategoriesOverflowMega 闭包内，下面用直写
                    }
                    policyHidden.forEach(function(unit) {
                        collectPolicyOverflowEntries(unit).forEach(function(entry) {
                            if (!entry.label) {
                                return;
                            }
                            // 无空 mega 时补最简侧栏+面板
                            if (!sidebar || !panels) {
                                return;
                            }
                            const tabIndex = sidebar.querySelectorAll('.mega-menu-sidebar-item').length;
                            const panelId = 'categories-overflow-mega-panel-policy-' + tabIndex;
                            const tabId = 'categories-overflow-mega-tab-policy-' + tabIndex;
                            const isActive = tabIndex === 0 && catHidden.length === 0;
                            const wrap = document.createElement('li');
                            wrap.className = 'mega-menu-sidebar-item-wrap';
                            wrap.setAttribute('role', 'presentation');
                            wrap.innerHTML = ''
                                + '<a href="' + escapeHeaderHtml(entry.href) + '" class="mega-menu-sidebar-item' + (isActive ? ' is-active' : '') + '"'
                                + ' role="tab" id="' + escapeHeaderHtml(tabId) + '"'
                                + ' data-mega-tab="' + escapeHeaderHtml(panelId) + '"'
                                + ' aria-selected="' + (isActive ? 'true' : 'false') + '"'
                                + ' aria-controls="' + escapeHeaderHtml(panelId) + '">'
                                + '<span class="mega-menu-sidebar-item__label">' + escapeHeaderHtml(entry.label) + '</span>'
                                + '</a>';
                            sidebar.appendChild(wrap);
                            const panel = document.createElement('div');
                            panel.className = 'mega-menu-panel' + (isActive ? ' is-active' : '');
                            panel.id = panelId;
                            panel.setAttribute('role', 'tabpanel');
                            panel.setAttribute('aria-labelledby', tabId);
                            if (!isActive) {
                                panel.hidden = true;
                            }
                            panel.innerHTML = '<div class="mega-menu-panel__body"><a class="mega-menu-card__title" href="'
                                + escapeHeaderHtml(entry.href) + '">' + escapeHeaderHtml(entry.label) + '</a></div>';
                            panels.appendChild(panel);
                        });
                    });
                }
                if (window.Weline && window.Weline.UI && typeof window.Weline.UI.mount === 'function') {
                    window.Weline.UI.mount(categoriesOverflowWrapper);
                }
                var overflowMega = categoriesOverflowWrapper.querySelector('[data-mega-menu]')
                    || document.querySelector('body > .categories-overflow-panel[data-mega-menu]');
                if (overflowMega) {
                    bindHeaderMegaMenu(overflowMega);
                    overflowMega.querySelectorAll('[data-mega-card-expand="1"]').forEach(function(card) {
                        card.classList.add('is-expanded');
                        var children = card.querySelector('.mega-menu-card__children');
                        if (children) {
                            children.hidden = false;
                            children.removeAttribute('hidden');
                        }
                    });
                }
                catMenuBuiltFor = hideFrom;
            }

            function adjustNavLinks(lightOnly) {
                const extensionLinks = rightCluster
                    ? Array.from(rightCluster.querySelectorAll(':scope > .header-nav-extension-link, :scope > .header-nav-extensions .header-nav-extension-link'))
                    : [];
                const listItems = navLinksList
                    ? Array.from(navLinksList.querySelectorAll(':scope > li'))
                    : [];
                const candidates = extensionLinks.concat(listItems);
                if (candidates.length === 0) {
                    if (lastNavHideFrom !== -1 || lastNavCandidateCount !== 0) {
                        applyNavVisibility([], -1);
                        if (!lightOnly) {
                            rebuildNavMoreMenu([], -1);
                        }
                        lastNavHideFrom = -1;
                        lastNavCandidateCount = 0;
                    }
                    return;
                }

                const availableWidth = measureNavAvailableWidth();
                const metrics = ensureNavNaturalMetrics(candidates);
                const hideFrom = resolveHideFromWithHysteresis(
                    metrics.prefix,
                    metrics.moreW,
                    availableWidth,
                    candidates.length,
                    lastNavHideFrom
                );

                if (hideFrom !== lastNavHideFrom || candidates.length !== lastNavCandidateCount) {
                    applyNavVisibility(candidates, hideFrom);
                    lastNavHideFrom = hideFrom;
                    lastNavCandidateCount = candidates.length;
                }

                if (!lightOnly && navMenuBuiltFor !== hideFrom) {
                    rebuildNavMoreMenu(candidates, hideFrom);
                }
            }

            function adjustCategoriesOverflow(lightOnly) {
                if (!categoriesOverflowWrapper || !categoriesOverflowBtn || !categoriesOverflowDropdown || !headerMainNav) {
                    return;
                }

                const candidates = collectLeftOverflowCandidates();

                if (candidates.length === 0) {
                    if (lastCatHideFrom !== -1 || lastCatCandidateCount !== 0) {
                        applyCatVisibility([], -1);
                        if (!lightOnly) {
                            rebuildCatMoreMenu([], -1);
                        }
                        lastCatHideFrom = -1;
                        lastCatCandidateCount = 0;
                    }
                    return;
                }

                const availableWidth = measureCatAvailableWidth() - measureSlotChainExtrasTotal();
                const metrics = ensureCatNaturalMetrics(candidates);
                let hideFrom = resolveHideFromWithHysteresis(
                    metrics.prefix,
                    metrics.moreW,
                    availableWidth,
                    candidates.length,
                    lastCatHideFrom
                );

                // 整簇自然宽封顶：候选 prefix 只覆盖「分类+政策」内容，All 按钮、槽内 widget 壳、
                // 发布层额外包装都可能让真实簇宽超过 prefix 和。prefix 判定「装得下」但实际仍被
                // flex-shrink 压扁裁字时，以脱 DOM 克隆自然宽为准强制收项。
                //
                // 禁止用 leftCluster.getBoundingClientRect().width 当地板：左簇 flex:1 吃剩余，
                // 盒宽≈主栏−右自然宽，几乎恒 ≥ available → 误进封顶；再加
                // capped=min(k, length-1) 会在「全装得下」(k===length) 时强制藏末项，
                // 表现为中间大片空档仍显示「更多」（宽屏审图复现）。
                const catsSlotEl = leftCluster && leftCluster.querySelector('.header-nav-left-slot, .header-categories');
                const policySlotEl = leftCluster && leftCluster.querySelector('.header-policy-links-slot');
                let clusterNatW = measureLeftClusterNaturalWidth(catsSlotEl, policySlotEl);
                if (clusterNatW != null) {
                    const prefixBased = Math.ceil((metrics.prefix[candidates.length - 1] || 0) + metrics.moreW)
                        + Math.ceil(measureAllButtonWidth() + (cachedLeftGap || 0));
                    // 克隆若被百分比祖先污染偏小，用 prefix+All+More 抬地板；勿用 flex 盒宽。
                    clusterNatW = Math.max(clusterNatW, prefixBased);
                }
                if (clusterNatW != null && clusterNatW > availableWidth) {
                    const allReserve = measureAllButtonWidth() + (cachedLeftGap || 0);
                    let k = candidates.length;
                    while (k > 0) {
                        const visibleW = (k === 0 ? 0 : metrics.prefix[k - 1]) + metrics.moreW + allReserve;
                        if (visibleW <= availableWidth) {
                            break;
                        }
                        k -= 1;
                    }
                    // k===length → 带 More 预留仍全装得下 → 不收（-1）；否则 hideFrom=k
                    const capped = k >= candidates.length ? -1 : k;
                    if (capped >= 0 && (hideFrom < 0 || capped < hideFrom)) {
                        hideFrom = capped;
                        // 封顶必须落进滞回缓存：只改局部值不写 lastCatHideFrom 时，
                        // apply-if-changed 永不触发、enforce 见无压扁即放行 → 缓存毒化在「全显示」，
                        // resize 后靠 flex-shrink 压扁裁字（全屏截图根因）。
                        if (hideFrom !== lastCatHideFrom || candidates.length !== lastCatCandidateCount) {
                            applyCatVisibility(candidates, hideFrom);
                            lastCatHideFrom = hideFrom;
                            lastCatCandidateCount = candidates.length;
                        }
                    }
                }

                if (hideFrom !== lastCatHideFrom || candidates.length !== lastCatCandidateCount) {
                    applyCatVisibility(candidates, hideFrom);
                    lastCatHideFrom = hideFrom;
                    lastCatCandidateCount = candidates.length;
                }

                const enforced = enforceLeftClusterNoSquish(candidates);
                if (enforced !== lastCatHideFrom) {
                    lastCatHideFrom = enforced;
                    lastCatCandidateCount = candidates.length;
                }

                if (!lightOnly && catMenuBuiltFor !== lastCatHideFrom) {
                    rebuildCatMoreMenu(candidates, lastCatHideFrom);
                }
            }

            function buildCategoriesOverflowMega(itemsToHide, container) {
                const panelId = 'categories-overflow-mega';
                const sidebar = document.createElement('ul');
                sidebar.className = 'mega-menu-sidebar';
                sidebar.setAttribute('role', 'tablist');
                sidebar.setAttribute('aria-label', headerMoreCategoriesLabel);

                const panels = document.createElement('div');
                panels.className = 'mega-menu-panels';

                let tabIndex = 0;

                function headerDemoImage(seed) {
                    if (window.Weline && window.Weline.Theme && typeof window.Weline.Theme.storefrontImagePlaceholder === 'function') {
                        return window.Weline.Theme.storefrontImagePlaceholder(seed);
                    }
                    return '/Weline/Theme/view/statics/images/storefront-placeholder/default.svg';
                }

                function appendLeafTab(label, href, description, categoryImage) {
                    const newPanelId = panelId + '-panel-' + tabIndex;
                    const newTabId = panelId + '-tab-' + tabIndex;
                    const isActive = tabIndex === 0;
                    const sidebarImage = String(categoryImage || '').trim()
                        || headerDemoImage((tabIndex + 1) * 11);
                    const cardImage = String(categoryImage || '').trim()
                        || headerDemoImage((tabIndex + 1) * 17);
                    const descText = String(description || '').trim() || formatBrowseCategoryDesc(label);

                    const wrap = document.createElement('li');
                    wrap.className = 'mega-menu-sidebar-item-wrap';
                    wrap.setAttribute('role', 'presentation');
                    wrap.innerHTML = ''
                        + '<a href="' + escapeHeaderHtml(href) + '" class="mega-menu-sidebar-item' + (isActive ? ' is-active' : '') + '"'
                        + ' role="tab" id="' + escapeHeaderHtml(newTabId) + '"'
                        + ' data-mega-tab="' + escapeHeaderHtml(newPanelId) + '"'
                        + ' aria-selected="' + (isActive ? 'true' : 'false') + '"'
                        + ' aria-controls="' + escapeHeaderHtml(newPanelId) + '">'
                        + '<span class="mega-menu-sidebar-item__media">'
                        + '<img src="' + escapeHeaderHtml(sidebarImage) + '" alt="" loading="lazy" width="36" height="36">'
                        + '</span>'
                        + '<span class="mega-menu-sidebar-item__label">' + escapeHeaderHtml(label) + '</span>'
                        + '</a>';
                    sidebar.appendChild(wrap);

                    const panel = document.createElement('div');
                    panel.className = 'mega-menu-panel' + (isActive ? ' is-active' : '');
                    panel.id = newPanelId;
                    panel.setAttribute('role', 'tabpanel');
                    panel.setAttribute('data-mega-panel', newPanelId);
                    panel.setAttribute('aria-labelledby', newTabId);
                    if (!isActive) {
                        panel.hidden = true;
                    }
                    panel.innerHTML = ''
                        + '<div class="mega-menu-panel__head">'
                        + '<h3 class="mega-menu-panel__title">' + escapeHeaderHtml(label) + '</h3>'
                        + '<a class="mega-menu-panel__all" href="' + escapeHeaderHtml(href) + '">' + escapeHeaderHtml(headerViewAllLabel) + '</a>'
                        + '</div>'
                        + '<ul class="mega-menu-subgrid mega-menu-subgrid--cards" role="list">'
                        + '<li class="mega-menu-card">'
                        + '<a class="mega-menu-card__link" href="' + escapeHeaderHtml(href) + '">'
                        + '<span class="mega-menu-card__media">'
                        + '<img src="' + escapeHeaderHtml(cardImage) + '" alt="" loading="lazy" width="72" height="72">'
                        + '</span>'
                        + '<span class="mega-menu-card__body">'
                        + '<span class="mega-menu-card__title">' + escapeHeaderHtml(label) + '</span>'
                        + '<span class="mega-menu-card__desc">' + escapeHeaderHtml(descText) + '</span>'
                        + '</span></a></li></ul>';
                    panels.appendChild(panel);
                    tabIndex += 1;
                }


                function appendTopLevelTab(label, href, sourceMega, categoryImage) {
                    const newPanelId = panelId + '-panel-' + tabIndex;
                    const newTabId = panelId + '-tab-' + tabIndex;
                    const isActive = tabIndex === 0;
                    // SSR data-category-image 是权威源；面板首图仅作回退（懒面板可能无 img）。
                    const firstSidebarImg = sourceMega.querySelector('.mega-menu-sidebar-item__media img');
                    const sidebarImage = String(categoryImage || '').trim()
                        || (firstSidebarImg && firstSidebarImg.getAttribute('src'))
                        || headerDemoImage((tabIndex + 1) * 11);

                    const wrap = document.createElement('li');
                    wrap.className = 'mega-menu-sidebar-item-wrap';
                    wrap.setAttribute('role', 'presentation');
                    wrap.innerHTML = ''
                        + '<a href="' + escapeHeaderHtml(href) + '" class="mega-menu-sidebar-item' + (isActive ? ' is-active' : '') + '"'
                        + ' role="tab" id="' + escapeHeaderHtml(newTabId) + '"'
                        + ' data-mega-tab="' + escapeHeaderHtml(newPanelId) + '"'
                        + ' aria-selected="' + (isActive ? 'true' : 'false') + '"'
                        + ' aria-controls="' + escapeHeaderHtml(newPanelId) + '">'
                        + '<span class="mega-menu-sidebar-item__media">'
                        + '<img src="' + escapeHeaderHtml(sidebarImage) + '" alt="" loading="lazy" width="36" height="36">'
                        + '</span>'
                        + '<span class="mega-menu-sidebar-item__label">' + escapeHeaderHtml(label) + '</span>'
                        + '</a>';
                    sidebar.appendChild(wrap);

                    const panel = document.createElement('div');
                    panel.className = 'mega-menu-panel mega-menu-panel--nested-mega' + (isActive ? ' is-active' : '');
                    panel.id = newPanelId;
                    panel.setAttribute('role', 'tabpanel');
                    panel.setAttribute('data-mega-panel', newPanelId);
                    panel.setAttribute('aria-labelledby', newTabId);
                    if (!isActive) {
                        panel.hidden = true;
                    }

                    // 右侧嵌入该 L1 在顶栏中的完整 Mega（L2 侧栏 + L3 卡片），与悬停 nav 一致
                    const sourceLayout = sourceMega.querySelector('.mega-menu-layout') || sourceMega;
                    const nestedHost = document.createElement('div');
                    nestedHost.className = 'w-mega-menu w-mega-menu--split mega-menu-nested-host is-megamenu is-mega-panel-split';
                    nestedHost.setAttribute('data-w-component', 'mega-menu');
                    nestedHost.setAttribute('data-w-mega-menu', '');
                    nestedHost.setAttribute('data-mega-menu', '');
                    nestedHost.setAttribute('data-surface', 'body');
                    nestedHost.setAttribute('role', 'presentation');

                    const layoutClone = sourceLayout.cloneNode(true);
                    layoutClone.classList.add('mega-menu-layout', 'is-nested-in-overflow');
                    layoutClone.removeAttribute('id');

                    const idPrefix = newPanelId + '-nest';
                    function remapId(value) {
                        if (!value) {
                            return value;
                        }
                        return idPrefix + '--' + value;
                    }
                    layoutClone.querySelectorAll('[id]').forEach(function(el) {
                        el.id = remapId(el.id);
                    });
                    layoutClone.querySelectorAll('[data-mega-tab]').forEach(function(el) {
                        var oldTab = el.getAttribute('data-mega-tab');
                        el.setAttribute('data-mega-tab', remapId(oldTab));
                        if (el.hasAttribute('aria-controls')) {
                            el.setAttribute('aria-controls', remapId(el.getAttribute('aria-controls')));
                        }
                    });
                    layoutClone.querySelectorAll('[data-mega-panel]').forEach(function(el) {
                        var oldPanel = el.getAttribute('data-mega-panel');
                        el.setAttribute('data-mega-panel', remapId(oldPanel));
                        if (el.id && el.id.indexOf(idPrefix + '--') !== 0) {
                            el.id = remapId(el.id);
                        } else if (!el.id) {
                            el.id = remapId(oldPanel);
                        }
                        if (el.hasAttribute('aria-labelledby')) {
                            el.setAttribute('aria-labelledby', remapId(el.getAttribute('aria-labelledby')));
                        }
                    });

                    nestedHost.appendChild(layoutClone);
                    panel.appendChild(nestedHost);
                    panels.appendChild(panel);
                    tabIndex += 1;
                }

                itemsToHide.forEach(function(item) {
                    const link = item.querySelector(':scope > .category-link, :scope > .category-link-wrapper > .category-link');
                    const label = ((link && link.textContent) || '').trim() || headerMoreLabel;
                    const href = (link && link.getAttribute('href')) || '#';
                    const description = (link && (link.getAttribute('data-category-description') || link.getAttribute('data-description'))) || '';
                    const categoryImage = (item.getAttribute && item.getAttribute('data-category-image')) || '';
                    const sourceMega = item.querySelector(':scope > [data-mega-menu], :scope > .header-category-panel[data-mega-menu]');

                    // More = 展开被隐藏的顶层分类（L1），不把二级摊进侧栏
                    if (!sourceMega) {
                        appendLeafTab(label, href, description, categoryImage);
                        return;
                    }
                    const sourceTabs = Array.from(sourceMega.querySelectorAll('.mega-menu-sidebar [data-mega-tab]'));
                    if (!sourceTabs.length) {
                        appendLeafTab(label, href, description, categoryImage);
                        return;
                    }
                    appendTopLevelTab(label, href, sourceMega, categoryImage);
                });

                container.innerHTML = '';
                container.appendChild(sidebar);
                container.appendChild(panels);
            }


            // 首屏同步：先锁单行/两行策略；先左后右
            syncNavStackMode();
            warmNavNaturalMetrics();
            adjustCategoriesOverflow(true);
            if (rightCluster) {
                void rightCluster.offsetWidth;
            }
            adjustNavLinks(true);
            adjustCategoriesOverflow(false);
            if (rightCluster) {
                void rightCluster.offsetWidth;
            }
            adjustNavLinks(false);
            if (mainNavInner) {
                mainNavInner.setAttribute('data-nav-overflow-ready', '1');
            }

            // 解析期首算可能量到回退字体宽：load / 字体就绪后作废自然宽缓存重算一次，
            // 避免按回退宽度定死藏/显
            var remeasureAfterResources = function () {
                navNaturalCache = { key: '', widths: [], prefix: [], moreW: 0, gap: navNaturalCache.gap || 20 };
                catNaturalCache = { key: '', widths: [], prefix: [], moreW: 0, gap: catNaturalCache.gap || 8 };
                scheduleOverflowAdjust();
            };
            window.addEventListener('load', remeasureAfterResources, { once: true });
            if (document.fonts && document.fonts.ready && typeof document.fonts.ready.then === 'function') {
                document.fonts.ready.then(remeasureAfterResources);
            }

            // 只听 window.resize：拖窗足够。RO 会在改 class 后回环，造成一闪一闪。
            window.addEventListener('resize', scheduleOverflowAdjust, { passive: true });

            // 展开/关闭由 Weline.UI menu / popover 接管，不再手写 hover/click 层
        }
        
        // 移动端"我的"菜单：点击展开/关闭
        const myMenu = document.querySelector('.header-my-menu');
        const myMenuLink = document.querySelector('.my-menu-link');
        const myMenuDropdown = document.querySelector('.my-menu-dropdown');
        
        if (myMenu && myMenuLink && myMenuDropdown) {
            // 点击"我的"按钮切换显示/隐藏
            myMenuLink.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                myMenu.classList.toggle('active');
                myMenu.setAttribute('aria-expanded', myMenu.classList.contains('active') ? 'true' : 'false');
            });
            
            // 点击页面其他地方时关闭
            document.addEventListener('click', function(e) {
                if (myMenu && !myMenu.contains(e.target)) {
                    myMenu.classList.remove('active');
                    myMenu.setAttribute('aria-expanded', 'false');
                }
            });
            
            // 子菜单展开/收起
            const submenuTitles = myMenuDropdown.querySelectorAll('.my-menu-submenu-title');
            submenuTitles.forEach(function(title) {
                title.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    const submenu = title.parentElement;
                    const isActive = submenu.classList.contains('active');
                    
                    // 关闭其他已展开的子菜单（可选：如果需要手风琴效果）
                    // submenuTitles.forEach(function(otherTitle) {
                    //     if (otherTitle !== title) {
                    //         otherTitle.parentElement.classList.remove('active');
                    //     }
                    // });
                    
                    // 切换当前子菜单
                    submenu.classList.toggle('active');
                    
                    // 更新 aria-expanded 属性（如果需要）
                    const arrow = title.querySelector('.my-menu-submenu-arrow');
                    if (arrow) {
                        arrow.setAttribute('aria-expanded', !isActive ? 'true' : 'false');
                    }
                });
            });
        }

        // 迷你购物车：点击展开/关闭
        const miniCartOverlay = document.getElementById('mini-cart-overlay');
        const miniCart = document.getElementById('mini-cart');
        const headerCart = document.querySelector('.header-cart');
        const cartLink = document.querySelector('.cart-link');

        // 迷你购物车控制函数（需要在 if 之前定义，以便在 if 内部调用）
        function toggleMiniCart() {
            if (headerCart && headerCart.classList.contains('mini-cart-open')) {
                hideMiniCart();
            } else {
                showMiniCart();
            }
        }

        function showMiniCart() {
            if (headerCart) {
                headerCart.classList.add('mini-cart-open');
                headerCart.setAttribute('aria-expanded', 'true');
            }
        }

        function hideMiniCart() {
            if (headerCart) {
                headerCart.classList.remove('mini-cart-open');
                headerCart.setAttribute('aria-expanded', 'false');
            }
        }

        if (miniCartOverlay && miniCart && headerCart && cartLink) {
            // 点击购物车按钮切换显示/隐藏
            cartLink.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                toggleMiniCart();
            });
            
            // 点击 header-cart 容器也切换（如果点击的不是按钮）
            headerCart.addEventListener('click', function(e) {
                // 如果点击的是按钮，不处理（已在上面处理）
                if (cartLink.contains(e.target)) {
                    return;
                }
                // 如果点击的是 mini-cart 或 overlay，不处理
                if (miniCart.contains(e.target) || miniCartOverlay.contains(e.target)) {
                    return;
                }
                // 其他情况切换显示
                toggleMiniCart();
            });

            // 点击 overlay 时关闭（关键：确保点击蒙版能关闭，但点击 mini-cart 本身不关闭）
            miniCartOverlay.addEventListener('click', function(e) {
                // 如果点击的是 mini-cart 本身，不关闭
                if (miniCart.contains(e.target)) {
                    return;
                }
                // 点击的是 overlay（蒙版），立即关闭
                e.preventDefault();
                e.stopPropagation();
                hideMiniCart();
            });
            
            // 确保 overlay 可以接收点击事件
            miniCartOverlay.style.pointerEvents = 'auto';
            
            // 阻止 mini-cart 的点击事件冒泡到 overlay
            miniCart.addEventListener('click', function(e) {
                e.stopPropagation();
            });

            // 点击关闭按钮时关闭
            const miniCartClose = document.getElementById('mini-cart-close');
            if (miniCartClose) {
                miniCartClose.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    hideMiniCart();
                });
            }

            // 点击页面其他地方时关闭（排除购物车按钮和迷你购物车本身）
            // 注意：overlay 的点击事件在上面单独处理，这里排除 overlay 避免重复
            document.addEventListener('click', function(e) {
                // 如果点击的是 overlay 或 mini-cart，不在这里处理（已在上面单独处理）
                if (miniCartOverlay.contains(e.target) || miniCart.contains(e.target)) {
                    return;
                }
                // 如果点击的是购物车按钮或容器，不关闭（会触发切换）
                if (headerCart.contains(e.target)) {
                    return;
                }
                // 点击页面其他地方，立即关闭
                hideMiniCart();
            });
        }

        // 右侧快捷导航 More：仅由上方 adjustNavLinks 写入 w-menu__item 并 Weline.UI.mount(menu)
        // 已删除 checkNavFillOverflow（会覆盖为 li/a 且不 mount，破坏通用 menu 悬浮）

        // 分类窄宽：禁止 hide-narrow 整块 display:none。
        // 旧逻辑用 offsetWidth<200 隐藏；一旦隐藏 offsetWidth 恒为 0 → 加宽永不恢复，且 More 一并消失。
        // ≤768 / 桌面一律交给 adjustCategoriesOverflow 收纳到 More。
        (function() {
            const headerCategories = document.querySelector('.header-categories');
            if (!headerCategories) return;

            function clearHideNarrowAndReflow() {
                if (!headerCategories.classList.contains('hide-narrow')) {
                    return;
                }
                headerCategories.classList.remove('hide-narrow');
                if (typeof scheduleOverflowAdjust === 'function') {
                    scheduleOverflowAdjust();
                } else {
                    window.dispatchEvent(new Event('resize'));
                }
            }

            clearHideNarrowAndReflow();
            window.addEventListener('resize', clearHideNarrowAndReflow, { passive: true });
            if (window.ResizeObserver) {
                const resizeObserver = new ResizeObserver(clearHideNarrowAndReflow);
                resizeObserver.observe(headerCategories);
            }
        })();

    }

    function bindHeaderMegaMenu(root) {
        // Mega tab/panel behavior is owned by Weline.UI `mega-menu` component.
        var scope = root || document;
        if (scope instanceof Element && !scope.getAttribute('data-w-component') && (scope.hasAttribute('data-mega-menu') || scope.hasAttribute('data-w-mega-menu') || scope.classList.contains('w-mega-menu'))) {
            var existing = scope.getAttribute('data-w-component') || '';
            if (!/\bmega-menu\b/.test(existing)) {
                scope.setAttribute('data-w-component', existing ? (existing + ' mega-menu') : 'mega-menu');
            }
            scope.classList.add('w-mega-menu');
            if (!scope.hasAttribute('data-w-mega-menu')) {
                scope.setAttribute('data-w-mega-menu', '');
            }
        }
        if (window.Weline && window.Weline.UI && typeof window.Weline.UI.mount === 'function') {
            window.Weline.UI.mount(scope);
        }
    }

    function normalizeHeaderNavHref(value) {
        value = String(value || '').trim();
        if (!value) {
            return '';
        }
        try {
            var resolved = new URL(value, window.location.href);
            return (resolved.pathname || '/') + (resolved.search || '');
        } catch (_error) {
            return value.replace(/\/+$/, '') || '/';
        }
    }

    function hydrateSidebarMegaPanels(root) {
        var scope = root || document;
        if (!scope || typeof scope.querySelectorAll !== 'function') {
            return;
        }

        var sourceByHref = Object.create(null);
        document.querySelectorAll('[data-mega-menu][data-mega-source-url]').forEach(function(panel) {
            if (panel.closest('#categories-sidebar')) {
                return;
            }
            var href = normalizeHeaderNavHref(panel.getAttribute('data-mega-source-url'));
            if (href && !sourceByHref[href]) {
                sourceByHref[href] = panel;
            }
        });

        scope.querySelectorAll('[data-sidebar-mega-deferred="1"]').forEach(function(item, index) {
            var existingPanel = Array.from(item.children || []).find(function(child) {
                return child instanceof HTMLElement && child.hasAttribute('data-w-popover-panel');
            });
            if (existingPanel) {
                item.setAttribute('data-sidebar-mega-deferred', '0');
                return;
            }

            var sourceHref = normalizeHeaderNavHref(item.getAttribute('data-sidebar-mega-source'));
            var sourcePanel = sourceByHref[sourceHref];
            if (!sourcePanel) {
                return;
            }

            var clone = sourcePanel.cloneNode(true);
            clone.classList.add('is-drawer-flyout');
            clone.setAttribute('data-drawer-flyout', '1');
            clone.setAttribute('data-state', 'closed');
            clone.setAttribute('aria-hidden', 'true');
            clone.hidden = true;

            var idPrefix = 'drawer-mega-' + index + '-';
            var idMap = Object.create(null);
            clone.querySelectorAll('[id]').forEach(function(element) {
                var oldId = element.getAttribute('id');
                if (!oldId) {
                    return;
                }
                var newId = idPrefix + oldId;
                idMap[oldId] = newId;
                element.setAttribute('id', newId);
            });
            var remapId = function(value) {
                value = String(value || '');
                return value ? (idMap[value] || idPrefix + value) : value;
            };
            clone.querySelectorAll('[data-mega-tab]').forEach(function(element) {
                element.setAttribute('data-mega-tab', remapId(element.getAttribute('data-mega-tab')));
                if (element.hasAttribute('aria-controls')) {
                    element.setAttribute('aria-controls', remapId(element.getAttribute('aria-controls')));
                }
            });
            clone.querySelectorAll('[data-mega-panel]').forEach(function(element) {
                element.setAttribute('data-mega-panel', remapId(element.getAttribute('data-mega-panel')));
                if (element.hasAttribute('aria-labelledby')) {
                    element.setAttribute('aria-labelledby', remapId(element.getAttribute('aria-labelledby')));
                }
            });

            item.appendChild(clone);
            item.setAttribute('data-sidebar-mega-deferred', '0');
        });
    }

    function bindDrawerFlyoutAlign(root) {
        var scope = root || document;
        scope.querySelectorAll('.sidebar-category-item--mega[data-w-component="popover"]').forEach(function(item) {
            if (item.dataset.drawerAlignBound === '1') {
                return;
            }
            item.dataset.drawerAlignBound = '1';
            item.addEventListener('weline:ui:popover:open', function() {
                var trigger = item.querySelector('[data-w-popover-trigger]');
                var panel = document.querySelector('body > .header-category-panel.is-drawer-flyout[data-state="open"]');
                if (!trigger || !panel) {
                    panel = item.querySelector('[data-w-popover-panel]');
                }
                if (!trigger || !panel) {
                    return;
                }
                var tr = trigger.getBoundingClientRect();
                var maxH = Math.max(240, Math.floor(window.innerHeight - tr.top - 12));
                panel.style.maxHeight = maxH + 'px';
                var layout = panel.querySelector('.mega-menu-layout');
                if (layout) {
                    layout.style.maxHeight = Math.max(200, maxH - 8) + 'px';
                }
                // 高度收紧后重新贴齐 trigger 顶部
                requestAnimationFrame(function() {
                    if (window.Weline && window.Weline.UI && typeof window.Weline.UI.position === 'function') {
                        window.Weline.UI.position(trigger, panel, item.getAttribute('data-w-placement') || 'right-start');
                        return;
                    }
                    // fallback：直接写 top/left，保证在 hover 项旁
                    var next = trigger.getBoundingClientRect();
                    var sidebar = document.getElementById('categories-sidebar');
                    var left = sidebar ? sidebar.getBoundingClientRect().right : next.right;
                    panel.style.position = 'fixed';
                    panel.style.top = Math.max(8, Math.round(next.top)) + 'px';
                    panel.style.left = Math.round(left) + 'px';
                    panel.style.right = 'auto';
                    panel.style.bottom = 'auto';
                });
            });
        });
    }

    function bindSidebarAccordions(root) {
        var scope = root || document;
        scope.querySelectorAll('[data-sidebar-accordion]').forEach(function(section) {
            if (section.dataset.sidebarAccordionBound === '1') {
                return;
            }
            section.dataset.sidebarAccordionBound = '1';
            var toggle = section.querySelector('.sidebar-section-toggle');
            var panel = section.querySelector('.sidebar-section-panel');
            if (!toggle || !panel) {
                return;
            }
            toggle.addEventListener('click', function() {
                var willOpen = panel.hasAttribute('hidden');
                if (willOpen) {
                    panel.removeAttribute('hidden');
                    section.classList.remove('is-collapsed');
                    toggle.setAttribute('aria-expanded', 'true');
                } else {
                    panel.setAttribute('hidden', '');
                    section.classList.add('is-collapsed');
                    toggle.setAttribute('aria-expanded', 'false');
                }
            });
        });
    }

    function ensureHeaderHamburgerTriggers() {
        var navAllRoot = document.getElementById('header-nav-all-root');
        if (navAllRoot) {
            var hasWidget = navAllRoot.querySelector('#hamburger-menu, [data-all-menu-widget="1"]');
            var fallback = document.getElementById('hamburger-menu-fallback');
            if (fallback) {
                fallback.hidden = !!hasWidget;
            }
        }

        var mobileBtn = document.querySelector('.weline-header .header-mobile-menu-btn.js-header-drawer-trigger');
        var sidebar = document.getElementById('categories-sidebar');
        if (mobileBtn && sidebar && !mobileBtn.getAttribute('aria-controls')) {
            mobileBtn.setAttribute('aria-controls', 'categories-sidebar');
        }
    }

    function bindHeaderCategoryDrawer() {
        if (window.__welineHeaderDrawerBound) {
            return;
        }

        const hamburgerMenu = document.getElementById('hamburger-menu');
        const drawerTriggers = Array.from(document.querySelectorAll('.js-header-drawer-trigger'));
        if (hamburgerMenu && drawerTriggers.indexOf(hamburgerMenu) === -1) {
            drawerTriggers.push(hamburgerMenu);
        }
        const fallbackMenu = document.getElementById('hamburger-menu-fallback');
        if (fallbackMenu && !fallbackMenu.hidden && drawerTriggers.indexOf(fallbackMenu) === -1) {
            drawerTriggers.push(fallbackMenu);
        }

        const categoriesSidebar = document.getElementById('categories-sidebar');
        const categoriesSidebarOverlay = document.getElementById('categories-sidebar-overlay');
        const categoriesSidebarCloseButtons = document.querySelectorAll(
            '#categories-sidebar-close, .js-categories-sidebar-close'
        );

        if (!drawerTriggers.length || !categoriesSidebar || !categoriesSidebarOverlay) {
            return;
        }

        window.__welineHeaderDrawerBound = true;
        drawerTriggers.forEach(function(btn) {
            btn.dataset.hamburgerHandler = 'true';
        });

        let isProcessing = false;
        let lastClickTime = 0;
        let mouseDownPos = null;

        function showSidebar() {
            drawerTriggers.forEach(function(btn) {
                btn.setAttribute('aria-expanded', 'true');
                btn.classList.add('active');
            });
            categoriesSidebar.setAttribute('aria-hidden', 'false');
            categoriesSidebarOverlay.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
            // 抽屉打开后再绑定内部 mega（右侧飞出）
            hydrateSidebarMegaPanels(categoriesSidebar);
            bindHeaderMegaMenu(categoriesSidebar);
            bindDrawerFlyoutAlign(categoriesSidebar);
            bindSidebarAccordions(categoriesSidebar);
        }

        function hideSidebar() {
            drawerTriggers.forEach(function(btn) {
                btn.setAttribute('aria-expanded', 'false');
                btn.classList.remove('active');
            });
            categoriesSidebar.setAttribute('aria-hidden', 'true');
            categoriesSidebarOverlay.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
            // 关闭抽屉时一并收起右侧飞出层
            document.querySelectorAll('.header-category-panel.is-drawer-flyout[data-state="open"]').forEach(function(panel) {
                panel.hidden = true;
                panel.dataset.state = 'closed';
                panel.setAttribute('aria-hidden', 'true');
            });
            categoriesSidebar.querySelectorAll('[data-w-popover-trigger][aria-expanded="true"]').forEach(function(trigger) {
                trigger.setAttribute('aria-expanded', 'false');
            });
        }

        function handleDrawerTriggerClick(e) {
            const now = Date.now();
            if (isProcessing || (now - lastClickTime < 300)) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                return;
            }

            if (mouseDownPos) {
                const moveDistance = Math.sqrt(
                    Math.pow(e.clientX - mouseDownPos.x, 2) +
                    Math.pow(e.clientY - mouseDownPos.y, 2)
                );
                if (moveDistance > 5) {
                    mouseDownPos = null;
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                    return;
                }
            }

            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();

            isProcessing = true;
            lastClickTime = now;
            mouseDownPos = null;

            const isExpanded = drawerTriggers.some(function(btn) {
                return btn.getAttribute('aria-expanded') === 'true';
            });
            if (!isExpanded) {
                showSidebar();
            } else {
                hideSidebar();
            }

            setTimeout(function() {
                isProcessing = false;
            }, 300);
        }

        drawerTriggers.forEach(function(trigger) {
            trigger.addEventListener('mousedown', function(e) {
                mouseDownPos = { x: e.clientX, y: e.clientY };
            }, true);

            trigger.addEventListener('touchstart', function(e) {
                const touch = e.touches[0];
                mouseDownPos = { x: touch.clientX, y: touch.clientY };
            }, true);

            trigger.addEventListener('touchend', function(e) {
                if (!mouseDownPos) {
                    return;
                }

                const touch = e.changedTouches[0];
                const moveDistance = Math.sqrt(
                    Math.pow(touch.clientX - mouseDownPos.x, 2) +
                    Math.pow(touch.clientY - mouseDownPos.y, 2)
                );

                if (moveDistance > 10) {
                    mouseDownPos = null;
                    return;
                }

                trigger.dispatchEvent(new MouseEvent('click', {
                    bubbles: true,
                    cancelable: true,
                    clientX: touch.clientX,
                    clientY: touch.clientY
                }));
            }, true);

            trigger.addEventListener('click', handleDrawerTriggerClick, true);
        });

        categoriesSidebarCloseButtons.forEach(function(closeButton) {
            closeButton.addEventListener('click', function(e) {
                e.preventDefault();
                hideSidebar();
            });
        });

        // 遮罩不再接收指针（避免挡住飞出层）；用 document 点击关闭
        document.addEventListener('click', function(e) {
            if (categoriesSidebar.getAttribute('aria-hidden') !== 'false') {
                return;
            }
            var target = e.target;
            if (!(target instanceof Element)) {
                return;
            }
            if (categoriesSidebar.contains(target)) {
                return;
            }
            if (target.closest('.header-category-panel.is-drawer-flyout')) {
                return;
            }
            if (target.closest('.js-header-drawer-trigger, #hamburger-menu, #hamburger-menu-fallback')) {
                return;
            }
            if (target.closest('#categories-sidebar-close, .js-categories-sidebar-close')) {
                return;
            }
            hideSidebar();
        }, true);

        categoriesSidebarOverlay.addEventListener('click', function(e) {
            if (categoriesSidebar.contains(e.target)) {
                return;
            }
            hideSidebar();
        });
    }

    function scheduleHeaderInteractions() {
        var scheduleIdle = function () {
            var run = function () {
                requestAnimationFrame(initHeaderInteractions);
            };

            if (typeof window.requestIdleCallback === 'function') {
                window.requestIdleCallback(run, { timeout: 800 });
                return;
            }

            setTimeout(run, 160);
        };

        if (document.readyState === 'loading') {
            // 本脚本位于顶栏导航 DOM 之后：解析到此处即可同步首算，
            // 消除「首绘 → load+idle」窗口内 SSR 全显示被 flex 压扁/裁切
            initHeaderInteractions();
            return;
        }

        if (document.readyState === 'complete') {
            scheduleIdle();
            return;
        }

        scheduleIdle();
    }

    bindHeaderCategoryDrawer();
    ensureHeaderHamburgerTriggers();
    bindDrawerFlyoutAlign(document);
    bindSidebarAccordions(document);
    scheduleHeaderInteractions();

    // 解析期 init 只见到脚本以上的 DOM：DCL 后对全文档补绑一次（各 binder 自带 dataset 防重）
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            bindHeaderMegaMenu(document);
            bindDrawerFlyoutAlign(document);
            bindSidebarAccordions(document);
        }, { once: true });
    }
})();
