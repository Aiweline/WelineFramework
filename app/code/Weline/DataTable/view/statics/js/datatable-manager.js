// 婵″倹鐏夊▽鈩冩箒__閸戣姤鏆熼敍灞藉灟鐎规矮绠熸稉鈧稉?
if (typeof __ === 'undefined') {
    function __(text) {
        return text;
    }
}
/**
 * DataTable Manager - 閺佺増宓佺悰銊︾壐缁狅紕鎮婇崳?
 * 閹绘劒绶甸弫鐗堝祦鐞涖劍鐗搁惃鍕灥婵瀵查妴渚€鍘ょ純顔衡偓浣规殶閹诡喖濮炴潪濮愨偓浣虹摣闁鈧焦甯撴惔蹇曠搼閸旂喕鍏?
 *
 * @version 2.0.0
 * @author Weline Framework
 * @description 婢х偛宸遍悧鍫熸殶閹诡喛銆冮弽鑲╊吀閻炲棗娅掗敍灞炬暜閹镐礁顦垮Ο鈥崇€烽妴涓IN閺屻儴顕楅妴浣哥杽閺冨墎绱潏鎴犵搼閸旂喕鍏?
 */

// 濞ｈ濮為幍瀣棑閻炴潙绱＄粵娑⑩偓澶婁紣閸忛攱鐖惃鍑淪S閺嶅嘲绱?
if (typeof filterToolbarStyles === 'undefined') {
    var filterToolbarStyles = `
<style>
.filter-toolbar-item {
    display: inline-block;
    margin-right: 15px;
    margin-bottom: 10px;
    vertical-align: top;
}

.filter-toolbar-item label {
    display: block;
    font-size: 12px;
    color: #666;
    margin-bottom: 4px;
    font-weight: 500;
}

.filter-toolbar-item .filter-input {
    padding: 4px 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 12px;
    min-width: 120px;
}

.filter-toolbar-item select.filter-input {
    min-width: 140px;
}

.filter-specified-fields {
    margin-bottom: 10px;
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
}

.filter-accordion {
    border: 1px solid #ddd;
    border-radius: 4px;
    overflow: hidden;
}

.filter-accordion-header {
    background: #f8f9fa;
    padding: 8px 12px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 13px;
    color: #495057;
    border-bottom: 1px solid #ddd;
}

.filter-accordion-header:hover {
    background: #e9ecef;
}

.filter-accordion-header i {
    margin-right: 6px;
}

.filter-accordion-icon {
    margin-left: auto;
    transition: transform 0.2s;
}

.filter-accordion-content {
    background: white;
    padding: 12px;
}

.filter-accordion-fields {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.filter-accordion-fields .filter-toolbar-item {
    margin-right: 0;
    margin-bottom: 0;
}
</style>
`;
}

// 鐏忓棙鐗卞蹇斿潑閸旂姴鍩屾い鐢告桨
if (!document.querySelector('#datatable-filter-toolbar-styles')) {
    const styleElement = document.createElement('div');
    styleElement.id = 'datatable-filter-toolbar-styles';
    styleElement.innerHTML = filterToolbarStyles;
    document.head.appendChild(styleElement);
}

// 绾喕绻?DataTableManager 閺嗘挳婀堕崚?window 娑撳绱欓崡鏇氱伐濡€崇础閿?
// 婵″倹鐏夊鑼病鐎涙ê婀稉鏂跨暚閺佽揪绱濋惄瀛樺复娴ｈ法鏁ら敍娑樻儊閸掓瑥鍨卞鐑樻煀鐎圭偘绶?
if (typeof window === 'undefined' || !window.DataTableManager || typeof window.DataTableManager.initTable !== 'function') {
    // 閸掓稑缂撻弬鎵畱 DataTableManager 鐎圭偘绶?
    var DataTableManager = {
    // 鐞涖劍鐗哥€圭偘绶ョ紓鎾崇摠
    instances: {},

    // 闁板秶鐤嗛柅澶愩€?
    config: {
        apiUrl: '',
        defaultPageSize: 20,
        maxPageSize: 100,
        debounceDelay: 300,
        autoSave: true,
        confirmDelete: true
    },

    buildApiUrl: function (instance, endpoint = '') {
        const baseUrl = (instance && instance.apiUrl) ? instance.apiUrl : this.config.apiUrl;
        if (!endpoint) {
            return baseUrl;
        }

        return baseUrl.replace(/\/+$/, '') + '/' + String(endpoint).replace(/^\/+/, '');
    },

    // 濞夈劍鍓伴敍姝焏itingState 瀹歌尙些閸掔増鐦℃稉顏勭杽娓氬鑵戦敍宀€鈥樻穱婵嗙杽娓氬娈х粋?

    /**
     * 閸掓繂顫愰崠鏍︾瑓閹峰褰嶉崡鏇炲閼?
     */
    initDropdowns: function () {
        // 闂冨弶顒涢柌宥咁槻閸掓繂顫愰崠?
        if (window._wDropdownInitialized) {
            return;
        }
        window._wDropdownInitialized = true;

        // 娴ｈ法鏁ゆ禍瀣╂婵梹澧径鍕倞娑撳濯洪懣婊冨礋閸掑洦宕?
        document.addEventListener('click', function (e) {
            const toggle = e.target.closest('[data-w-toggle="dropdown"]');
            
            if (toggle) {
                e.preventDefault();
                e.stopPropagation();
                
                const dropdownContainer = toggle.closest('.w-dropdown');
                const dropdown = dropdownContainer ? dropdownContainer.querySelector('.w-dropdown-menu') : toggle.parentElement.querySelector('.w-dropdown-menu');
                
                if (!dropdown) {
                    console.warn('DataTable: dropdown menu not found for toggle button');
                    return;
                }
                
                // 閸忔娊妫撮崗鏈电铂閹碘偓閺堝绗呴幏澶庡綅閸?
                document.querySelectorAll('.w-dropdown-menu.show').forEach(function (menu) {
                    if (menu !== dropdown) {
                        menu.classList.remove('show');
                    }
                });
                
                // 閸掑洦宕茶ぐ鎾冲娑撳濯洪懣婊冨礋
                const isOpen = dropdown.classList.contains('show');
                dropdown.classList.toggle('show');
                
                // 濞ｈ濮為弮瀣祮閸斻劎鏁鹃崚鏉挎禈閺嶅浄绱欐俊鍌涚亯閺堝娈戠拠婵撶礆
                const icon = toggle.querySelector('i.fas.fa-undo, i.fas.fa-chevron-down');
                if (icon) {
                    icon.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(180deg)';
                }
                
                return;
            }
            
            // 婵″倹鐏夐悙鐟板毊閻ㄥ嫪绗夐弰顖欑瑓閹峰褰嶉崡鏇炲敶闁煉绱濋崗鎶芥４閹碘偓閺堝绗呴幏澶庡綅閸?
            if (!e.target.closest('.w-dropdown-menu') && !e.target.closest('.w-dropdown-item')) {
                document.querySelectorAll('.w-dropdown-menu.show').forEach(function (menu) {
                    menu.classList.remove('show');
                });
                document.querySelectorAll('[data-w-toggle="dropdown"] i.fas.fa-undo, [data-w-toggle="dropdown"] i.fas.fa-chevron-down').forEach(function (icon) {
                    icon.style.transform = 'rotate(0deg)';
                });
            }
        }, false);

        // ESC闁款喖鍙ч梻顓濈瑓閹峰褰嶉崡?
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.w-dropdown-menu.show').forEach(function (menu) {
                    menu.classList.remove('show');
                });
                document.querySelectorAll('[data-w-toggle="dropdown"] i.fas.fa-undo, [data-w-toggle="dropdown"] i.fas.fa-chevron-down').forEach(function (icon) {
                    icon.style.transform = 'rotate(0deg)';
                });
            }
        }, false);
    },

    /**
     * 閼惧嘲褰囪ぐ鎾冲娑撳顣?
     * @returns {string} 'dark' | 'light'
     */
    getCurrentTheme: function () {
        const body = document.body;
        const sidebarTheme = body.getAttribute('data-sidebar');
        const topbarTheme = body.getAttribute('data-topbar');

        // 婵″倹鐏塻idebar閹存潰opbar閺勭棛ark閿涘苯鍨潻鏂挎礀dark
        if (sidebarTheme === 'dark' || topbarTheme === 'dark') {
            return 'dark';
        }

        // 濡偓閺屻儱鐛熸担鎾寸叀鐠?
        if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
            return 'dark';
        }

        return 'light';
    },

    /**
     * 鎼存梻鏁ゆ稉濠氼暯
     * @param {string} theme - 'dark' | 'light'
     */
    applyTheme: function (theme) {
        const body = document.body;
        const tables = document.querySelectorAll('.weline-datatable, .w-datatable');

        tables.forEach(function (table) {
            if (theme === 'dark') {
                table.classList.add('theme-dark');
            } else {
                table.classList.remove('theme-dark');
            }
        });

        // 鎼存梻鏁ょ悰銊ュ礋娑撳顣?
        const forms = document.querySelectorAll('.w-form-container, .w-form-inline-container');
        forms.forEach(function (form) {
            if (theme === 'dark') {
                form.classList.add('theme-dark');
            } else {
                form.classList.remove('theme-dark');
            }
        });
    },

    /**
     * 閸掓繂顫愰崠鏍﹀瘜妫?
     */
    initTheme: function () {
        const currentTheme = this.getCurrentTheme();
        this.applyTheme(currentTheme);

        // 閻╂垵鎯夋稉濠氼暯閸欐ê瀵查敍鍫濐洤閺嬫粎閮寸紒鐔告箒閸忋劌鐪稉濠氼暯閸掑洦宕叉禍瀣╂閿?
        if (window.addEventListener && typeof MutationObserver !== 'undefined') {
            // 閻╂垵鎯塨ody鐏炵偞鈧冨綁閸?
            const observer = new MutationObserver((mutations) => {
                mutations.forEach((mutation) => {
                    if (mutation.type === 'attributes' &&
                        (mutation.attributeName === 'data-sidebar' || mutation.attributeName === 'data-topbar')) {
                        const newTheme = this.getCurrentTheme();
                        this.applyTheme(newTheme);
                    }
                });
            });

            observer.observe(document.body, {
                attributes: true,
                attributeFilter: ['data-sidebar', 'data-topbar']
            });

            // 閻╂垵鎯夋刊鎺嶇秼閺屻儴顕楅崣妯哄
            if (window.matchMedia) {
                const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
                if (mediaQuery.addEventListener) {
                    mediaQuery.addEventListener('change', (e) => {
                        const theme = e.matches ? 'dark' : 'light';
                        this.applyTheme(theme);
                    });
                } else {
                    // 閸忕厧顔愰弮褏澧楀ù蹇氼潔閸?
                    mediaQuery.addListener((e) => {
                        const theme = e.matches ? 'dark' : 'light';
                        this.applyTheme(theme);
                    });
                }
            }
        }
    },

    /**
     * 閸掓繂顫愰崠鏍﹀瘜妫版﹢鍘ょ純顔煎閼?
     */
    initThemeConfig: function () {
        // 閸掓稑缂撴稉濠氼暯闁板秶鐤嗛棃銏℃緲
        if (!document.querySelector('.w-theme-config')) {
            // 绾喕绻氱紙鏄忕槯閸戣姤鏆熺€涙ê婀?
            const translate = window.__ || function (text) { return text; };

            const tableThemeConfig = __('鐞涖劍鐗告稉濠氼暯闁板秶鐤?');
            const displayOptions = __('閺勫墽銇氶柅澶愩€?');
            const showZebra = __('閺勫墽銇氶弬鎴︹攬缁?');
            const showHover = __('閺勫墽銇氶幃顒€浠犻弫鍫熺亯');
            const showSort = __('閺勫墽銇氶幒鎺戠碍閸ョ偓鐖?');
            const colorTheme = __('妫版粏澹婃稉濠氼暯');
            const primaryColor = __('娑撴槒澹婄拫?');
            const headerBackground = __('鐞涖劌銇旈懗灞炬珯');
            const hoverColor = __('鐞涘本鍋撻崑婊嗗');
            const fontSettings = __('鐎涙ぞ缍嬬拋鍓х枂');
            const fontSize = __('鐎涙ぞ缍嬫径褍鐨?');
            const small = __('鐏?');
            const medium = __('娑?');
            const large = __('婢?');

            const themeConfigHtml = `
                <div class="w-theme-config">
                    <div class="w-theme-config-header">
                        <h4 class="w-theme-config-title">
                            <i class="fas fa-palette"></i>
                            ${tableThemeConfig}
                        </h4>
                    </div>
                    <div class="w-theme-config-body">
                        <div class="w-theme-section">
                            <div class="w-theme-section-title">${displayOptions}</div>
                            <div class="w-theme-option">
                                <label>${showZebra}</label>
                                <input type="checkbox" id="theme-zebra" checked>
                            </div>
                            <div class="w-theme-option">
                                <label>${showHover}</label>
                                <input type="checkbox" id="theme-hover" checked>
                            </div>
                            <div class="w-theme-option">
                                <label>${showSort}</label>
                                <input type="checkbox" id="theme-sort" checked>
                            </div>
                        </div>
                        <div class="w-theme-section">
                            <div class="w-theme-section-title">${colorTheme}</div>
                            <div class="w-theme-option">
                                <label>${primaryColor}</label>
                                <input type="color" id="theme-primary" value="#3b82f6">
                            </div>
                            <div class="w-theme-option">
                                <label>${headerBackground}</label>
                                <input type="color" id="theme-header" value="#f8fafc">
                            </div>
                            <div class="w-theme-option">
                                <label>${hoverColor}</label>
                                <input type="color" id="theme-hover-color" value="#f1f5f9">
                            </div>
                        </div>
                        <div class="w-theme-section">
                            <div class="w-theme-section-title">${fontSettings}</div>
                            <div class="w-theme-option">
                                <label>${fontSize}</label>
                                <select id="theme-font-size">
                                    <option value="0.875rem">${small}</option>
                                    <option value="1rem" selected>${medium}</option>
                                    <option value="1.125rem">${large}</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', themeConfigHtml);
            console.log('娑撳顣介柊宥囩枂闂堛垺婢樺鎻掑灡瀵?');
        } else {
            console.log('娑撳顣介柊宥囩枂闂堛垺婢樺鎻掔摠閸?');
        }

        // 缂佹垵鐣炬稉濠氼暯闁板秶鐤嗘禍瀣╂
        this.bindThemeEvents();
    },

    /**
     * 缂佹垵鐣炬稉濠氼暯闁板秶鐤嗘禍瀣╂
     */
    bindThemeEvents: function () {
        // 娑撳顣介柊宥囩枂閸掑洦宕?
        document.removeEventListener('click', window._wThemeToggleHandler, false);
        window._wThemeToggleHandler = function (e) {
            const btn = e.target.closest('[data-w-action="theme-config"]');
            if (btn) {
                e.preventDefault();
                e.stopPropagation();
                console.log('娑撳顣介柊宥囩枂閹稿鎸崇悮顐ゅ仯閸?');
                const themeConfig = document.querySelector('.w-theme-config');
                if (themeConfig) {
                    themeConfig.classList.toggle('show');
                    console.log('娑撳顣介柊宥囩枂闂堛垺婢橀崚鍥ㄥ床:', themeConfig.classList.contains('show'));
                } else {
                    console.error('娑撳顣介柊宥囩枂闂堛垺婢橀張顏呭閸?');
                }
            }
        };
        document.addEventListener('click', window._wThemeToggleHandler, false);

        // 閻愮懓鍤径鏍劥閸忔娊妫存稉濠氼暯闁板秶鐤?
        document.removeEventListener('click', window._wThemeCloseHandler, false);
        window._wThemeCloseHandler = function (e) {
            if (!e.target.closest('.w-theme-config')) {
                document.querySelector('.w-theme-config').classList.remove('show');
            }
        };
        document.addEventListener('click', window._wThemeCloseHandler, false);

        // 娑撳顣介柅澶愩€嶉崣妯哄娴滃娆?- 瀵ゆ儼绻滅紒鎴濈暰閿涘瞼鈥樻穱婵嬫桨閺夊灝鍑￠崚娑樼紦
        setTimeout(() => {
            var themeInputs = document.querySelectorAll('.w-theme-config input, .w-theme-config select');
            themeInputs.forEach(function (input) {
                input.removeEventListener('change', window._wThemeChangeHandler, false);
                window._wThemeChangeHandler = function () {
                    DataTableManager.applyThemeConfig();
                };
                input.addEventListener('change', window._wThemeChangeHandler, false);
            });
        }, 100);
    },

    /**
     * 鎼存梻鏁ゆ稉濠氼暯闁板秶鐤?
     */
    applyThemeConfig: function () {
        const config = {
            zebra: document.getElementById('theme-zebra') && document.getElementById('theme-zebra').checked,
            hover: document.getElementById('theme-hover') && document.getElementById('theme-hover').checked,
            sort: document.getElementById('theme-sort') && document.getElementById('theme-sort').checked,
            primary: document.getElementById('theme-primary') && document.getElementById('theme-primary').value,
            header: document.getElementById('theme-header') && document.getElementById('theme-header').value,
            hoverColor: document.getElementById('theme-hover-color') && document.getElementById('theme-hover-color').value,
            fontSize: document.getElementById('theme-font-size') && document.getElementById('theme-font-size').value
        };

        // 鎼存梻鏁ら柊宥囩枂閸掔増澧嶉張澶庛€冮弽?
        document.querySelectorAll('.w-datatable').forEach(function (table) {
            // 閺傛垿鈹堢痪?
            if (config.zebra) {
                table.querySelectorAll('tbody tr:nth-child(even)').forEach(function (row) {
                    row.style.display = '';
                });
            } else {
                table.querySelectorAll('tbody tr:nth-child(even)').forEach(function (row) {
                    row.style.display = 'none';
                });
            }

            // 閹剙浠犻弫鍫熺亯
            if (config.hover) {
                table.querySelectorAll('tbody tr').forEach(function (row) {
                    row.style.cursor = 'pointer';
                });
            } else {
                table.querySelectorAll('tbody tr').forEach(function (row) {
                    row.style.cursor = 'default';
                });
            }

            // 閹烘帒绨崶鐐垼
            if (config.sort) {
                table.querySelectorAll('th.sortable').forEach(function (th) {
                    th.style.display = '';
                });
            } else {
                table.querySelectorAll('th.sortable').forEach(function (th) {
                    th.style.display = 'none';
                });
            }

            // 鐎涙ぞ缍嬫径褍鐨?
            table.querySelectorAll('td, th').forEach(function (cell) {
                cell.style.fontSize = config.fontSize;
            });
        });

        // 娣囨繂鐡ㄩ柊宥囩枂閸掔増婀伴崷鏉跨摠閸?
        localStorage.setItem('weline-datatable-theme', JSON.stringify(config));
    },

    /**
     * 閸旂姾娴囨稉濠氼暯闁板秶鐤?
     */
    loadThemeConfig: function () {
        const savedConfig = localStorage.getItem('weline-datatable-theme');
        if (savedConfig) {
            const config = JSON.parse(savedConfig);

            // 鐠佸墽鐤嗙悰銊ュ礋閸?
            if (document.getElementById('theme-zebra')) document.getElementById('theme-zebra').checked = config.zebra;
            if (document.getElementById('theme-hover')) document.getElementById('theme-hover').checked = config.hover;
            if (document.getElementById('theme-sort')) document.getElementById('theme-sort').checked = config.sort;
            if (document.getElementById('theme-primary')) document.getElementById('theme-primary').value = config.primary;
            if (document.getElementById('theme-header')) document.getElementById('theme-header').value = config.header;
            if (document.getElementById('theme-hover-color')) document.getElementById('theme-hover-color').value = config.hoverColor;
            if (document.getElementById('theme-font-size')) document.getElementById('theme-font-size').value = config.fontSize;

            // 鎼存梻鏁ら柊宥囩枂
            this.applyThemeConfig();
        }
    },

    /**
     * 闁插秷顩﹂崚妤佺垼濞夈劌濮涢懗?
     */
    initImportantFlags: function () {
        // 缂佹垵鐣鹃柌宥堫洣閸掓鍨忛幑顫皑娴?
        document.removeEventListener('click', window._wImportantToggleHandler, false);
        window._wImportantToggleHandler = function (e) {
            const btn = e.target.closest('[data-w-action="important-view"]');
            if (btn) {
                e.preventDefault();
                const table = btn.closest('.w-datatable');
                if (table) {
                    DataTableManager.toggleImportantView(table);
                }
            }
        };
        document.addEventListener('click', window._wImportantToggleHandler, false);
    },

    /**
     * 閸掑洦宕查柌宥堫洣閸掓妯夌粈?
     */
    toggleImportantView: function (tableIdOrElement) {
        // 閺€顖涘瘮娴肩姴鍙?tableId 鐎涙顑佹稉鍙夊灗 DOM 閸忓啰绀?
        let table = tableIdOrElement;
        if (typeof tableIdOrElement === 'string') {
            const instance = this.getInstance(tableIdOrElement);
            if (instance) {
                table = instance.container[0] || instance.container;
            } else {
                table = document.getElementById(tableIdOrElement);
            }
        }
        
        if (!table) {
            console.error('toggleImportantView: table not found');
            return;
        }

        const isImportantView = table.classList.contains('w-important-view');

        if (isImportantView) {
            // 閺勫墽銇氶幍鈧張澶婂灙
            table.classList.remove('w-important-view');
            table.querySelectorAll('th, td').forEach(function (cell) {
                cell.style.display = '';
            });
            const btn = table.querySelector('[data-w-action="important-view"]');
            if (btn) btn.textContent = __('閸欘亝妯夌粈娲櫢鐟曚焦鏆熼幑?');
        } else {
            // 閸欘亝妯夌粈娲櫢鐟曚礁鍨?
            table.classList.add('w-important-view');
            table.querySelectorAll('th, td').forEach(function (cell) {
                cell.style.display = 'none';
            });
            table.querySelectorAll('.w-important-column').forEach(function (cell) {
                cell.style.display = '';
            });
            const btn = table.querySelector('[data-w-action="important-view"]');
            if (btn) btn.textContent = __('閺勫墽銇氶幍鈧張澶嬫殶閹?');
        }
    },

    /**
     * 娣囨繂鐡ㄩ柌宥堫洣閸掓鍘?
     */
    saveImportantColumns: function (tableId, columnIndex, isImportant) {
        const key = `weline-datatable-important-${tableId}`;
        let importantColumns = JSON.parse(localStorage.getItem(key) || '[]');

        if (isImportant) {
            if (!importantColumns.includes(columnIndex)) {
                importantColumns.push(columnIndex);
            }
        } else {
            importantColumns = importantColumns.filter(col => col !== columnIndex);
        }

        localStorage.setItem(key, JSON.stringify(importantColumns));
    },

    /**
     * 閸旂姾娴囬柌宥堫洣閸掓鍘?
     */
    loadImportantColumns: function (tableId) {
        const key = `weline-datatable-important-${tableId}`;
        const importantColumns = JSON.parse(localStorage.getItem(key) || '[]');

        const $table = $(`#${tableId}`);
        importantColumns.forEach(columnIndex => {
            $table.find(`th:eq(${columnIndex}), td:eq(${columnIndex})`).addClass('w-important-column');
            $table.find(`td:eq(${columnIndex}) .w-important-flag`).addClass('active');
        });
    },

    /**
     * 鐎电厧鍤弫鐗堝祦閸旂喕鍏?
     */
    exportData: function (tableId, format = 'excel') {
        const instance = this.getInstance(tableId);
        if (!instance) {
            console.error('Table instance not found:', tableId);
            return;
        }

        // 閺勫墽銇氱€电厧鍤潻娑樺濡剝鈧焦顢?
        this.showExportModal(tableId, format);

        // 瀵偓婵顕遍崙楦跨箖?
        this.startExport(tableId, format);
    },

    /**
     * 閺勫墽銇氱€电厧鍤潻娑樺濡剝鈧焦顢?
     */
    showExportModal: function (tableId, format) {
        const instance = this.instances[tableId];
        const totalRecords = instance ? instance.totalCount || 0 : 0;
        const pageSize = instance ? instance.pageSize || 20 : 20;
        const totalPages = Math.ceil(totalRecords / pageSize);

        const modalHtml = `
            <div class="w-export-modal show">
                <div class="w-export-content">
                    <div class="w-export-header">
                        <h3 class="w-export-title">
                            <i class="fas fa-download me-2"></i>
                            ${__('濮濓絽婀€电厧鍤弫鐗堝祦')}
                        </h3>
                        <p class="w-export-subtitle">
                            ${__('鐎电厧鍤弽鐓庣础')}閿?span class="format-badge ${format}">${format.toUpperCase()}</span>
                            <br>${__('妫板嫯顓哥€电厧鍤?')} <strong>${totalRecords}</strong> ${__('閺壜ゎ唶瑜?')}閿?{__('閸?')} <strong>${totalPages}</strong> ${__('妞?')}
                        </p>
                    </div>
                    
                    <div class="w-export-progress-section">
                        <div class="w-progress-info">
                            <div class="progress-stats">
                                <div class="stat-item">
                                    <span class="stat-label">${__('瑜版挸澧犳い?')}閿?/span>
                                    <span class="stat-value current-page">0</span>
                                    <span class="stat-separator">/</span>
                                    <span class="stat-value total-pages">${totalPages}</span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-label">${__('瀹告彃顕遍崙?')}閿?/span>
                                    <span class="stat-value exported-records">0</span>
                                    <span class="stat-separator">/</span>
                                    <span class="stat-value total-records">${totalRecords}</span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-label">${__('鏉╂稑瀹?')}閿?/span>
                                    <span class="stat-value progress-percentage">0%</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="w-export-progress">
                            <div class="w-progress-bar">
                                <div class="w-progress-fill" style="width: 0%"></div>
                                <div class="w-progress-text">0%</div>
                            </div>
                        </div>
                        
                        <div class="w-export-status">
                            <i class="fas fa-spinner fa-spin loading"></i>
                            <span class="w-export-status-text">${__('濮濓絽婀崚婵嗩潗閸栨牕顕遍崙?..')}</span>
                        </div>
                        
                        <div class="w-export-time-info">
                            <div class="time-item">
                                <span class="time-label">${__('瀹歌尙鏁ら弮鍫曟？')}閿?/span>
                                <span class="time-value elapsed-time">00:00</span>
                            </div>
                            <div class="time-item">
                                <span class="time-label">${__('妫板嫯顓搁崜鈺€缍?')}閿?/span>
                                <span class="time-value remaining-time">${__('鐠侊紕鐣绘稉?..')}</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="w-export-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span class="w-export-warning-text">${__('鐎电厧鍤潻鍥┾柤娑擃叀顕崟鍨彠闂傤厽顒濈粣妤€褰涢敍灞间簰閸忓秴顕遍懛瀛樻殶閹诡喕娑径?')}</span>
                    </div>
                    
                    <div class="w-export-actions">
                        <button type="button" class="w-export-btn secondary" data-datatable-action="cancel-export" id="cancel-export-btn">
                            <i class="fas fa-times me-1"></i>${__('閸欐牗绉风€电厧鍤?')}
                        </button>
                    </div>
                </div>
                
                <!-- 濞ｈ濮炵€电厧鍤Ο鈩冣偓浣诡攱閺嶅嘲绱￠敍鍫熸暜閹镐椒瀵屾０姗€鈧倿鍘ら敍?-->
                <style>
                    .w-export-modal {
                        position: fixed;
                        top: 0;
                        left: 0;
                        width: 100%;
                        height: 100%;
                        background: rgba(0, 0, 0, 0.6);
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        z-index: 10000;
                    }
                    
                    .w-export-content {
                        background: var(--datatable-bg, var(--bs-body-bg, #fff));
                        color: var(--datatable-text, var(--bs-body-color, #333));
                        border-radius: 8px;
                        padding: 24px;
                        min-width: 480px;
                        max-width: 600px;
                        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
                        position: relative;
                        border: 1px solid var(--datatable-border, var(--bs-border-color, #dee2e6));
                    }
                    
                    .w-export-header {
                        margin-bottom: 20px;
                        text-align: center;
                    }
                    
                    .w-export-title {
                        color: var(--datatable-text, var(--bs-body-color, #333));
                        margin: 0 0 8px 0;
                        font-size: 18px;
                        font-weight: 600;
                    }
                    
                    .w-export-subtitle {
                        color: var(--datatable-muted, var(--bs-secondary-color, #666));
                        margin: 0;
                        font-size: 14px;
                        line-height: 1.5;
                    }
                    
                    .format-badge {
                        display: inline-block;
                        padding: 2px 8px;
                        border-radius: 12px;
                        font-size: 11px;
                        font-weight: bold;
                        color: white;
                    }
                    
                    .format-badge.excel { background: #217346; }
                    .format-badge.csv { background: #d63384; }
                    .format-badge.json { background: #6f42c1; }
                    
                    .w-export-progress-section {
                        margin-bottom: 20px;
                    }
                    
                    .progress-stats {
                        display: flex;
                        justify-content: space-between;
                        margin-bottom: 16px;
                        padding: 12px;
                        background: var(--datatable-hover-bg, var(--bs-tertiary-bg, #f8f9fa));
                        border-radius: 6px;
                        border: 1px solid var(--datatable-border, var(--bs-border-color, #dee2e6));
                    }
                    
                    .stat-item {
                        text-align: center;
                        flex: 1;
                    }
                    
                    .stat-label {
                        font-size: 12px;
                        color: var(--datatable-muted, var(--bs-secondary-color, #666));
                        display: block;
                        margin-bottom: 4px;
                    }
                    
                    .stat-value {
                        font-size: 16px;
                        font-weight: bold;
                        color: var(--datatable-text, var(--bs-body-color, #333));
                    }
                    
                    .stat-separator {
                        color: var(--datatable-muted, var(--bs-secondary-color, #999));
                        margin: 0 2px;
                    }
                    
                    .w-progress-bar {
                        position: relative;
                        height: 20px;
                        background: var(--datatable-hover-bg, var(--bs-tertiary-bg, #e9ecef));
                        border-radius: 10px;
                        overflow: hidden;
                        margin-bottom: 12px;
                        border: 1px solid var(--datatable-border, var(--bs-border-color, #dee2e6));
                    }
                    
                    .w-progress-fill {
                        height: 100%;
                        background: linear-gradient(45deg, #28a745, #20c997);
                        transition: width 0.3s ease;
                        position: relative;
                    }
                    
                    .w-progress-text {
                        position: absolute;
                        top: 50%;
                        left: 50%;
                        transform: translate(-50%, -50%);
                        font-size: 12px;
                        font-weight: bold;
                        color: var(--datatable-text, var(--bs-body-color, #333));
                        z-index: 1;
                    }
                    
                    .w-export-status {
                        display: flex;
                        align-items: center;
                        margin-bottom: 16px;
                        padding: 8px 0;
                    }
                    
                    .w-export-status i {
                        margin-right: 8px;
                        font-size: 16px;
                        color: var(--datatable-primary, var(--bs-primary, #007bff));
                    }
                    
                    .w-export-status-text {
                        font-size: 14px;
                        color: var(--datatable-text, var(--bs-body-color, #333));
                    }
                    
                    .w-export-time-info {
                        display: flex;
                        justify-content: space-between;
                        margin-bottom: 16px;
                        font-size: 13px;
                    }
                    
                    .time-label {
                        color: var(--datatable-muted, var(--bs-secondary-color, #666));
                    }
                    
                    .time-value {
                        font-weight: bold;
                        color: var(--datatable-text, var(--bs-body-color, #333));
                        margin-left: 4px;
                    }
                    
                    .w-export-warning {
                        background: var(--bs-warning-bg-subtle, #fff3cd);
                        border: 1px solid var(--bs-warning-border-subtle, #ffeaa7);
                        color: var(--bs-warning-text-emphasis, #856404);
                        padding: 8px 12px;
                        border-radius: 4px;
                        margin-bottom: 20px;
                        font-size: 13px;
                        display: flex;
                        align-items: center;
                    }
                    
                    .w-export-warning i {
                        margin-right: 8px;
                        color: var(--bs-warning, #f39c12);
                    }
                    
                    .w-export-actions {
                        text-align: center;
                    }
                    
                    .w-export-btn {
                        padding: 8px 20px;
                        border: none;
                        border-radius: 4px;
                        font-size: 14px;
                        cursor: pointer;
                        transition: all 0.2s;
                    }
                    
                    .w-export-btn.secondary {
                        background: var(--bs-secondary, #6c757d);
                        color: white;
                    }
                    
                    .w-export-btn.secondary:hover {
                        background: var(--bs-secondary-bg-subtle, #5a6268);
                    }
                    
                    .w-export-btn.primary {
                        background: var(--datatable-primary, var(--bs-primary, #007bff));
                        color: white;
                    }
                    
                    .w-export-btn.primary:hover {
                        opacity: 0.9;
                    }
                </style>
            </div>
        `;

        // 缁夊娅庨悳鐗堟箒濡剝鈧焦顢?
        $('.w-export-modal').remove();

        // 濞ｈ濮為弬鐗埬侀幀浣诡攱
        $('body').append(modalHtml);
    },

    /**
     * 瀵偓婵顕遍崙楦跨箖缁?
     */
    startExport: function (tableId, format) {
        const instance = this.instances[tableId];
        const totalRecords = instance.totalCount || 0;
        const pageSize = instance.pageSize || 20;
        const totalPages = Math.ceil(totalRecords / pageSize);
        let currentPage = 1;
        let allData = [];
        let isCancelled = false;
        let startTime = Date.now();

        // 鐠佲剝妞傞崳?
        const timer = setInterval(() => {
            if (isCancelled) {
                clearInterval(timer);
                return;
            }

            const elapsed = Date.now() - startTime;
            const elapsedText = this.formatTime(elapsed);
            $('.elapsed-time').text(elapsedText);

            // 鐠侊紕鐣婚崜鈺€缍戦弮鍫曟？
            if (currentPage > 1) {
                const avgTimePerPage = elapsed / (currentPage - 1);
                const remainingPages = totalPages - currentPage + 1;
                const remainingTime = avgTimePerPage * remainingPages;
                const remainingText = this.formatTime(remainingTime);
                $('.remaining-time').text(remainingText);
            }
        }, 1000);

        // 閸欐牗绉风€电厧鍤禍瀣╂
        window.DataTableManager.cancelExport = function () {
            isCancelled = true;
            clearInterval(timer);
            $('.w-export-modal').remove();
        };

        const exportNextPage = () => {
            if (isCancelled) {
                clearInterval(timer);
                return;
            }

            // 閺囧瓨鏌婃潻娑樺娣団剝浼?
            const progress = Math.round(((currentPage - 1) / totalPages) * 100);
            const exportedRecords = (currentPage - 1) * pageSize;

            $('.w-progress-fill').css('width', progress + '%');
            $('.w-progress-text').text(`${progress}%`);
            $('.current-page').text(currentPage);
            $('.exported-records').text(Math.min(exportedRecords, totalRecords));
            $('.progress-percentage').text(`${progress}%`);
            $('.w-export-status-text').text(`濮濓絽婀懢宄板絿缁?${currentPage} 妞ゅ灚鏆熼幑?..`);

            // 娴ｈ法鏁ら弫鐗堝祦閼惧嘲褰嘇PI鏉╂稖顢戦崚鍡涖€夌€电厧鍤?
            this.requestJson(instance, 'data', {
                model: instance.options.model,
                scope: instance.options.scope,
                page: currentPage,
                limit: pageSize,
                filters: instance.filters || {},
                sort: instance.sorts || {},
                search: instance.search || ''
            })
                .then(response => {
                    if (isCancelled) {
                        clearInterval(timer);
                        return;
                    }

                    if ((response.code == 200 || response.code === '200' || response.success) && response.data) {
                        // 濞ｈ濮炶ぐ鎾冲妞ゅ灚鏆熼幑?
                        if (response.data.data) {
                            allData = allData.concat(response.data.data);
                        }

                        currentPage++;

                        if (currentPage <= totalPages) {
                            // 缂佈呯敾娑撳绔存い纰夌礉濞ｈ濮炵亸蹇撴閺冨爼浼╅崗宥嗘箛閸斺€虫珤閸樺濮?
                            setTimeout(exportNextPage, 200);
                        } else {
                            // 鐎瑰本鍨氱€电厧鍤?
                            clearInterval(timer);
                            this.completeExport(allData, format, tableId);
                        }
                    } else {
                        clearInterval(timer);
                        this.showExportError('閼惧嘲褰囬弫鐗堝祦婢惰精瑙﹂敍? ' + (response.msg || '閺堫亞鐓￠柨娆掝嚖'));
                    }
                })
                .catch(error => {
                    clearInterval(timer);
                    this.showExportError('缂冩垹绮堕柨娆掝嚖閿? ' + error.message);
                });
        };

        // 瀵偓婵顕遍崙?
        exportNextPage();
    },

    /**
     * 閺嶇厧绱￠崠鏍ㄦ闂傚瓨妯夌粈?
     */
    formatTime: function (milliseconds) {
        const seconds = Math.floor(milliseconds / 1000);
        const minutes = Math.floor(seconds / 60);
        const remainingSeconds = seconds % 60;

        return `${minutes.toString().padStart(2, '0')}:${remainingSeconds.toString().padStart(2, '0')}`;
    },

    /**
     * 鐎瑰本鍨氱€电厧鍤?
     */
    completeExport: function (data, format, tableId) {
        document.querySelector('.w-progress-fill').style.width = '100%';
        document.querySelector('.w-progress-text').textContent = __('濮濓絽婀悽鐔稿灇閺傚洣娆?..');
        document.querySelector('.w-export-status-text').textContent = __('鐎电厧鍤€瑰本鍨氶敍?');
        let icon = document.querySelector('.w-export-status i');
        icon.classList.remove('fa-spinner', 'fa-spin', 'loading');
        icon.classList.add('fa-check-circle');
        try {
            let content, filename, mimeType;
            if (format === 'excel') {
                content = this.generateExcel(data, tableId);
                filename = `datatable_export_${tableId}_${new Date().getTime()}.xlsx`;
                mimeType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            } else if (format === 'csv') {
                content = this.generateCSV(data, tableId);
                filename = `datatable_export_${tableId}_${new Date().getTime()}.csv`;
                mimeType = 'text/csv';
            } else if (format === 'json') {
                content = JSON.stringify(data, null, 2);
                filename = `datatable_export_${tableId}_${new Date().getTime()}.json`;
                mimeType = 'application/json';
            }
            // 閸掓稑缂撴稉瀣祰闁剧偓甯?
            const blob = new Blob([content], { type: mimeType });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
            // 閺囧瓨鏌婂Ο鈩冣偓浣诡攱
            document.querySelector('.w-export-actions').innerHTML = `
                <button type="button" class="w-export-btn primary" data-datatable-action="close-export-modal">鐎瑰本鍨?/button>
            `;
        } catch (error) {
            this.showExportError('閻㈢喐鍨氶弬鍥︽婢惰精瑙﹂敍? ' + error.message);
        }
    },

    /**
     * 閻㈢喐鍨欵xcel閺傚洣娆?
     */
    generateExcel: function (data, tableId) {
        // 鏉╂瑩鍣锋担璺ㄦ暏缁犫偓閸楁洜娈慍SV閺嶇厧绱￠敍灞界杽闂勫懘銆嶉惄顔昏厬閸欘垯浜掓担璺ㄦ暏SheetJS缁涘绨?
        return this.generateCSV(data, tableId);
    },

    /**
     * 閻㈢喐鍨欳SV閺傚洣娆?
     */
    generateCSV: function (data, tableId) {
        if (!data || data.length === 0) return '';

        const instance = this.instances[tableId];
        const headers = instance.displayFields.map(field => field.label || field.name);
        const csvRows = [headers.join(',')];

        data.forEach(row => {
            const values = instance.displayFields.map(field => {
                let value = row[field.name] || '';
                // 婢跺嫮鎮婇崠鍛儓闁褰块惃?
                if (typeof value === 'string' && value.includes(',')) {
                    value = `"${value}"`;
                }
                return value;
            });
            csvRows.push(values.join(','));
        });

        return csvRows.join('\n');
    },

    /**
     * 閺勫墽銇氱€电厧鍤柨娆掝嚖
     */
    showExportError: function (message) {
        let icon = document.querySelector('.w-export-status i');
        icon.classList.remove('fa-spinner', 'fa-spin', 'loading');
        icon.classList.add('fa-exclamation-circle');
        document.querySelector('.w-export-status-text').textContent = __('鐎电厧鍤径杈Е');
        document.querySelector('.w-export-actions').innerHTML = `
            <button type="button" class="w-export-btn primary" data-datatable-action="close-export-modal">${__('閸忔娊妫?')}</button>
        `;
        console.error('Export error:', message);
    },

    /**
     * 鐎涙顔岀猾璇茬€烽柅澶愩€?
     */
    fieldTypeOptions: [
        { value: 'text', label: '閺傚洦婀?' },
        { value: 'number', label: '閺佹澘鐡?' },
        { value: 'date', label: '閺冦儲婀?' },
        { value: 'select', label: '娑撳濯洪柅澶愩€?' },
        { value: 'email', label: '闁喚顔?' },
        { value: 'tel', label: '閻絻鐦?' },
        { value: 'url', label: '缂冩垵娼?' },
        { value: 'image', label: '閸ュ墽澧?' }
    ],

    /**
     * 閸掓繂顫愰崠鏍€冮弽纭风礄鐎圭偘绶ラ梾鏃傤瀲閿?
     */
    initTable: function (selector, options) {
        const container = document.querySelector(selector);
        if (!container) {
            console.error('DataTable container not found:', selector);
            return null;
        }
        
        // 濡偓閺屻儲妲搁崥锕侇啎缂冾喕绨￠梾鏃傤瀲閺嶅洤绻?
        const isolate = options.isolate === true;
        
        let tableId = container.getAttribute('id');
        let instanceKey = tableId; // 閻劋绨€涙ê鍋嶇€圭偘绶ラ惃鍕暛
        
        // 婵″倹鐏夌拋鍓х枂娴滃棝娈х粋缁樼垼韫囨绱濇担璺ㄦ暏 scope 娴ｆ粈璐熺€圭偘绶ラ弽鍥槕缁?
        if (isolate) {
            if (!options.scope) {
                console.error('DataTable: isolate flag is set but scope is not provided');
                return null;
            }
            // 娴ｈ法鏁?scope 娴ｆ粈璐熺€圭偘绶ラ弽鍥槕缁?
            instanceKey = 'scope-' + options.scope;
            
            // 婵″倹鐏夌€圭懓娅掑▽鈩冩箒 ID閿涘奔濞囬悽?scope 閻㈢喐鍨?ID
            if (!tableId) {
                tableId = 'datatable-scope-' + options.scope;
                container.setAttribute('id', tableId);
            } else {
                // 婵″倹鐏夊鍙夋箒 ID閿涘奔绲剧拋鍓х枂娴滃棝娈х粋缁樼垼韫囨绱濈涵顔荤箽 ID 娑?scope 娑撯偓閼?
                const expectedId = 'datatable-scope-' + options.scope;
                if (tableId !== expectedId) {
                    console.warn('DataTable: isolate flag is set, but container ID does not match scope. Expected:', expectedId, 'Got:', tableId);
                    // 閺囧瓨鏌婄€圭懓娅?ID 娴犮儱灏柊?scope
                    container.setAttribute('id', expectedId);
                    tableId = expectedId;
                }
            }
            
            // 濡偓閺屻儲妲搁崥锕€鍑＄€涙ê婀惄绋挎倱 scope 閻ㄥ嫬鐤勬笟?
            if (this.instances[instanceKey]) {
                console.warn('DataTable instance with scope already exists:', options.scope, 'Reusing existing instance.');
                // 閺囧瓨鏌婄€圭懓娅掗惃鍕穿閻㈩煉绱欓崣顖濆厴閸氬奔绔存稉?scope 閺堝顦挎稉顏勵啇閸ｎ煉绱?
                this.instances[instanceKey].container = container;
                return this.instances[instanceKey];
            }
        } else {
            // 閺堫亣顔曠純顕€娈х粋缁樼垼韫囨绱濇担璺ㄦ暏 tableId 娴ｆ粈璐熺€圭偘绶ラ弽鍥槕缁?
            if (!tableId) {
                // 婵″倹鐏夊▽鈩冩箒 ID閿涘矁鍤滈崝銊ф晸閹存劒绔存稉顏勬暜娑撯偓閻?ID
                tableId = 'datatable-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
                container.setAttribute('id', tableId);
            }
            instanceKey = tableId;
            
            // 婵″倹鐏夌€圭偘绶ュ鎻掔摠閸︻煉绱濇潻鏂挎礀閻滅増婀佺€圭偘绶?
            if (this.instances[instanceKey]) {
                console.warn('DataTable instance already exists for:', tableId);
                return this.instances[instanceKey];
            }
            
            // 绾喕绻?scope 閻ㄥ嫬鏁稉鈧幀褝绱欐俊鍌涚亯閺堫亝褰佹笟娑欏灗瀹告彃鐡ㄩ崷顭掔礉濞ｈ濮?tableId 閸氬海绱戦敍?
            if (options.scope) {
                const existingScope = this.getInstanceByScope(options.scope);
                if (existingScope && existingScope.id !== tableId) {
                    options.scope = options.scope + '-' + tableId;
                    console.warn('Scope conflict detected, using:', options.scope);
                }
            }
        }
        // 閼奉亜濮╅幒銊︽焽API閸╄櫣顢呯捄顖氱窞
        let apiUrl = options.workerApi ? '' : options.apiUrl;
        if (!options.workerApi && !apiUrl) {
            apiUrl = '';
        }
        const instance = {
            id: tableId, // 鐎圭懓娅扞D
            instanceKey: instanceKey, // 鐎圭偘绶ョ€涙ê鍋嶉柨顕嗙礄閸欘垵鍏橀弰?tableId 閹?scope閿?
            scope: options.scope, // scope 閸?
            isolate: isolate, // 闂呮梻顬囬弽鍥х箶
            container: container,
            options: options,
            currentPage: 1,
            pageSize: options.pageSize || 20,
            data: [],
            config: {},
            filters: {},
            search: '',
            sorts: {},
            isEditing: false,
            editingRow: null,
            editingData: {},
            apiUrl: apiUrl,
            allFields: [],
            displayFields: [],
            filterFields: [],
            // 濮ｅ繋閲滅€圭偘绶ラ悪顒傜彌閻ㄥ嫮绱潏鎴犲Ц閹緤绱濈涵顔荤箽鐎圭偘绶ラ梾鏃傤瀲
            editingState: {
                isEditing: false,
                currentCell: null,
                originalValue: null,
                editingRow: null
            },
            // 娴滃娆㈡径鍕倞閸ｃ劌鐡ㄩ崒顭掔礉閻劋绨〒鍛倞
            eventHandlers: {},
            // 鐎圭偘绶ラ悧鐟扮暰閻ㄥ嫬鎳￠崥宥団敄闂?
            namespace: isolate ? 'datatable-scope-' + options.scope : 'datatable-' + tableId
        };
        // 娴ｈ法鏁?instanceKey 鐎涙ê鍋嶇€圭偘绶ラ敍鍫濆讲閼宠姤妲?tableId 閹?scope閿?
        this.instances[instanceKey] = instance;

        // 閸掓繂顫愰崠鏍︾瑓閹峰褰嶉崡鏇礄绾喕绻氬В蹇旑偧閸掓繂顫愰崠鏍€冮弽鍏兼闁姤顥呴弻銉礆
        this.initDropdowns();

        // 閸掓繂顫愰崠鏍﹀瘜妫?
        this.initTheme();

        // 閸掓繂顫愰崠鏍ㄥ闁插繑鎼锋担婊冧紣閸忛攱鐖?
        this.initBatchActionToolbar(instance);

        // 閸掓繂顫愰崠鏍ㄦ閸旂姾娴囩€涙顔岄柊宥囩枂
        this.loadFieldsOnInit(instance);
        
        // 閸︺劌顔愰崳銊ょ瑐濞ｈ濮炵€圭偘绶ラ弽鍥唶閿涘奔绌舵禍搴㈢叀閹?
        container.setAttribute('data-datatable-instance', tableId);
        
        return instance;
    },
    
    /**
     * 闁库偓濮ｄ浇銆冮弽鐓庣杽娓氬绱欏〒鍛倞閹碘偓閺堝绨ㄦ禒璺烘嫲鐠у嫭绨敍宀€鈥樻穱婵嗙杽娓氬娈х粋浼欑礆
     * @param {string} identifier - 鐎圭偘绶ラ弽鍥槕缁楋讣绱檛ableId 閹?scope閿涘苯褰囬崘鍏呯艾閺勵垰鎯佺拋鍓х枂娴滃棝娈х粋缁樼垼韫囨绱?
     */
    destroyInstance: function (identifier) {
        // 鐏忔繆鐦惄瀛樺复閺屻儲澹?
        let instance = this.instances[identifier];
        let instanceKey = identifier;
        
        // 婵″倹鐏夐張顏呭閸掑府绱濈亸婵婄槸闁俺绻?scope 閺屻儲澹?
        if (!instance) {
            const scopeKey = 'scope-' + identifier;
            instance = this.instances[scopeKey];
            if (instance) {
                instanceKey = scopeKey; // 閺囧瓨鏌婃稉鐑橆劀绾喚娈戦柨?
            }
        }
        
        // 婵″倹鐏夋禒宥嗘弓閹垫儳鍩岄敍灞界毦鐠囨洟鈧俺绻?tableId 閺屻儲澹?
        if (!instance) {
            for (const key in this.instances) {
                if (this.instances[key].id === identifier) {
                    instance = this.instances[key];
                    instanceKey = key; // 閺囧瓨鏌婃稉鐑橆劀绾喚娈戦柨?
                    break;
                }
            }
        }
        
        if (!instance) {
            console.warn('DataTable instance not found:', identifier);
            return;
        }
        
        // 濞撳懐鎮婇幍鈧張澶夌皑娴犺泛顦╅悶鍡楁珤
        if (instance.eventHandlers) {
            // 濞撳懐鎮婇幍褰掑櫤閹垮秳缍旀禍瀣╂
            if (instance.eventHandlers.batchActions) {
                instance.eventHandlers.batchActions.forEach(({ element, event, handler }) => {
                    if (element && handler) {
                        element.removeEventListener(event, handler);
                    }
                });
            }
            
            // 濞撳懐鎮婇崗鏈电铂娴滃娆?
            if (instance.eventHandlers.dblclick) {
                const table = document.getElementById(instance.id);
                if (table) {
                    table.removeEventListener('dblclick', instance.eventHandlers.dblclick);
                }
            }
            
            if (instance.eventHandlers.keydown) {
                document.removeEventListener('keydown', instance.eventHandlers.keydown);
            }
        }
        
        // 濞撳懐鎮婄紓鏍帆閻樿埖鈧?
        if (instance.editingState && instance.editingState.isEditing) {
            this.cancelCellEdit(instance.id);
        }
        
        // 娴犲骸顔愰崳銊ょ瑐缁夊娅庣€圭偘绶ラ弽鍥唶
        if (instance.container) {
            instance.container.removeAttribute('data-datatable-instance');
        }
        
        // 娴犲骸鐤勬笟瀣灙鐞涖劋鑵戠粔濠氭珟閿涘牅濞囬悽銊︻劀绾喚娈戦柨顕嗙礆
        delete this.instances[instanceKey];
        
        console.log('DataTable instance destroyed:', instanceKey, instance.isolate ? '(isolated by scope: ' + instance.scope + ')' : '');
    },

    /**
     * 閸掓繂顫愰崠鏍ㄥ闁插繑鎼锋担婊冧紣閸忛攱鐖?
     */
    initBatchActionToolbar: function (instance) {
        if (instance.options.enableBatchActions === false) return;

        const tableId = instance.container.getAttribute('id');
        const container = instance.container[0] || instance.container;

        // 濡偓閺屻儲妲搁崥锕€鍑＄€涙ê婀銉ュ徔閺?
        let toolbar = container.querySelector('.batch-action-toolbar');
        if (!toolbar) {
            // 閸掓稑缂撻幍褰掑櫤閹垮秳缍斿銉ュ徔閺?
            const toolbarHtml = `
                <div class="batch-action-toolbar" style="display: none; margin-bottom: 10px;">
                    <div class="d-flex align-items-center gap-2">
                        <span class="selected-count">瀹告煡鈧鑵?<strong>0</strong> 妞?/span>
                        <div class="btn-group">
                            <button type="button" class="btn btn-sm btn-danger batch-delete-btn">
                                <i class="fas fa-trash me-1"></i>閸掔娀娅庨柅澶夎厬
                            </button>
                            <button type="button" class="btn btn-sm btn-warning batch-soft-delete-btn">
                                <i class="fas fa-archive me-1"></i>缁夋槒鍤﹂崶鐐存暪缁?
                            </button>
                            <button type="button" class="btn btn-sm btn-secondary batch-clear-btn">
                                <i class="fas fa-times me-1"></i>閸欐牗绉烽柅澶嬪
                            </button>
                            <div class="btn-group">
                                <button type="button" class="btn btn-sm btn-info batch-export-btn">
                                    <i class="fas fa-download me-1"></i>鐎电厧鍤柅澶夎厬
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-info dropdown-toggle dropdown-toggle-split"
                                        data-bs-toggle="dropdown">
                                    <span class="visually-hidden">Toggle Dropdown</span>
                                </button>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item export-excel-btn" href="#"><i class="fas fa-file-excel me-2"></i>鐎电厧鍤稉绡峹cel</a></li>
                                    <li><a class="dropdown-item export-csv-btn" href="#"><i class="fas fa-file-csv me-2"></i>鐎电厧鍤稉绡奡V</a></li>
                                    <li><a class="dropdown-item export-json-btn" href="#"><i class="fas fa-file-code me-2"></i>鐎电厧鍤稉绡擲ON</a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            // 閹绘帒鍙嗛崚鎷屻€冮弽鐓庡闂?
            const tableElement = container.querySelector('table');
            if (tableElement) {
                tableElement.insertAdjacentHTML('beforebegin', toolbarHtml);
                toolbar = container.querySelector('.batch-action-toolbar');
            }
        }

        // 缂佹垵鐣惧銉ュ徔閺嶅繋绨ㄦ禒?
        this.bindBatchActionEvents(instance, toolbar);
    },

    /**
     * 缂佹垵鐣鹃幍褰掑櫤閹垮秳缍旀禍瀣╂閿涘牆鐤勬笟瀣缁備紮绱?
     */
    bindBatchActionEvents: function (instance, toolbar) {
        if (!toolbar) {
            console.warn('閹靛綊鍣洪幙宥勭稊瀹搞儱鍙块弽蹇庣瑝鐎涙ê婀敍宀冪儲鏉╁洣绨ㄦ禒鍓佺拨鐎?');
            return;
        }
        const tableId = instance.container.getAttribute('id');
        
        // 閸掓繂顫愰崠鏍︾皑娴犺泛顦╅悶鍡楁珤鐎涙ê鍋?
        if (!instance.eventHandlers) instance.eventHandlers = {};
        if (!instance.eventHandlers.batchActions) instance.eventHandlers.batchActions = [];

        // 閸掔娀娅庨柅澶夎厬妞?
        const deleteHandler = () => {
            const selectedIds = this.getSelectedRowIds(instance);
            this.batchDelete(instance, selectedIds, { softDelete: false });
        };
        toolbar.querySelector('.batch-delete-btn')?.addEventListener('click', deleteHandler);
        instance.eventHandlers.batchActions.push({ element: toolbar.querySelector('.batch-delete-btn'), event: 'click', handler: deleteHandler });

        // 鏉烆垰鍨归梽銈夆偓澶夎厬妞?
        const softDeleteHandler = () => {
            const selectedIds = this.getSelectedRowIds(instance);
            this.batchDelete(instance, selectedIds, { softDelete: true });
        };
        toolbar.querySelector('.batch-soft-delete-btn')?.addEventListener('click', softDeleteHandler);
        instance.eventHandlers.batchActions.push({ element: toolbar.querySelector('.batch-soft-delete-btn'), event: 'click', handler: softDeleteHandler });

        // 閸欐牗绉烽柅澶嬪
        const clearHandler = () => {
            this.clearSelection(instance);
        };
        toolbar.querySelector('.batch-clear-btn')?.addEventListener('click', clearHandler);
        instance.eventHandlers.batchActions.push({ element: toolbar.querySelector('.batch-clear-btn'), event: 'click', handler: clearHandler });

        // 鐎电厧鍤崝鐔诲厴
        const exportHandler = () => {
            const selectedIds = this.getSelectedRowIds(instance);
            this.exportDataBatch(instance, selectedIds, 'excel');
        };
        toolbar.querySelector('.batch-export-btn')?.addEventListener('click', exportHandler);
        instance.eventHandlers.batchActions.push({ element: toolbar.querySelector('.batch-export-btn'), event: 'click', handler: exportHandler });

        const exportExcelHandler = (e) => {
            e.preventDefault();
            const selectedIds = this.getSelectedRowIds(instance);
            this.exportDataBatch(instance, selectedIds, 'excel');
        };
        toolbar.querySelector('.export-excel-btn')?.addEventListener('click', exportExcelHandler);
        instance.eventHandlers.batchActions.push({ element: toolbar.querySelector('.export-excel-btn'), event: 'click', handler: exportExcelHandler });

        const exportCsvHandler = (e) => {
            e.preventDefault();
            const selectedIds = this.getSelectedRowIds(instance);
            this.exportDataBatch(instance, selectedIds, 'csv');
        };
        toolbar.querySelector('.export-csv-btn')?.addEventListener('click', exportCsvHandler);
        instance.eventHandlers.batchActions.push({ element: toolbar.querySelector('.export-csv-btn'), event: 'click', handler: exportCsvHandler });

        const exportJsonHandler = (e) => {
            e.preventDefault();
            const selectedIds = this.getSelectedRowIds(instance);
            this.exportDataBatch(instance, selectedIds, 'json');
        };
        toolbar.querySelector('.export-json-btn')?.addEventListener('click', exportJsonHandler);
        instance.eventHandlers.batchActions.push({ element: toolbar.querySelector('.export-json-btn'), event: 'click', handler: exportJsonHandler });

        // 閸忋劑鈧?閸欐牗绉烽崗銊┾偓?
        const selectAllCheckbox = instance.container.querySelector(`#select-all-${tableId}`);
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', (e) => {
                this.toggleSelectAll(instance, e.target.checked);
            });
        }
    },

    /**
     * 閼惧嘲褰囬柅澶夎厬鐞涘瞼娈慖D
     */
    getSelectedRowIds: function (instance) {
        const checkboxes = instance.container.querySelectorAll('.row-checkbox:checked');
        return Array.from(checkboxes).map(checkbox => checkbox.value);
    },

    /**
     * 閸掑洦宕查崗銊┾偓澶屽Ц閹?
     */
    toggleSelectAll: function (instance, checked) {
        const checkboxes = instance.container.querySelectorAll('.row-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.checked = checked;
            const row = checkbox.closest('tr');
            if (row) {
                row.classList.toggle('selected', checked);
            }
        });

        this.updateBatchActionButtons(instance);
    },

    /**
     * 閺囧瓨鏌婇幍褰掑櫤閹垮秳缍旈幐澶愭尦閻樿埖鈧?
     */
    updateBatchActionButtons: function (instance) {
        const selectedIds = this.getSelectedRowIds(instance);
        const toolbar = instance.container.querySelector('.batch-action-toolbar');
        const countElement = toolbar?.querySelector('.selected-count strong');

        if (toolbar) {
            if (selectedIds.length > 0) {
                toolbar.style.display = 'block';
                if (countElement) {
                    countElement.textContent = selectedIds.length;
                }
            } else {
                toolbar.style.display = 'none';
            }
        }

        // 閺囧瓨鏌婇崗銊┾偓澶婎槻闁顢嬮悩鑸碘偓?
        const tableId = instance.container.getAttribute('id');
        const selectAllCheckbox = instance.container.querySelector(`#select-all-${tableId}`);
        const allCheckboxes = instance.container.querySelectorAll('.row-checkbox');

        if (selectAllCheckbox && allCheckboxes.length > 0) {
            const checkedCount = instance.container.querySelectorAll('.row-checkbox:checked').length;
            selectAllCheckbox.checked = checkedCount === allCheckboxes.length;
            selectAllCheckbox.indeterminate = checkedCount > 0 && checkedCount < allCheckboxes.length;
        }
    },

    /**
     * 閹靛綊鍣虹€电厧鍤弫鐗堝祦閿涘牏鏁ゆ禍搴㈠闁插繑鎼锋担婊冧紣閸忛攱鐖敍?
     */
    exportDataBatch: function (instance, selectedIds = null, format = 'excel') {
        const tableId = instance.container.getAttribute('id');

        // 婵″倹鐏夊▽鈩冩箒闁鑵戞禒璁崇秿鐞涘矉绱濈€电厧鍤幍鈧張澶嬫殶閹?
        if (!selectedIds || selectedIds.length === 0) {
            selectedIds = instance.data.map(row => row.id || row.index);
        }

        if (selectedIds.length === 0) {
            this.showWarning(tableId, __('濞屸剝婀侀崣顖氼嚤閸戣櫣娈戦弫鐗堝祦'));
            return;
        }

        // 閺勫墽銇氶崝鐘烘祰閻樿埖鈧?
        this.showLoading(tableId, __('濮濓絽婀崙鍡楊槵鐎电厧鍤弫鐗堝祦...'));

        // 閸戝棗顦€电厧鍤崣鍌涙殶
        const exportParams = {
            model: instance.options.model,
            ids: selectedIds,
            format: format,
            fields: instance.displayFields.map(field => ({
                name: field.name,
                label: field.label || field.name
            }))
        };

        // 閸欐垿鈧礁顕遍崙楦款嚞濮?
        this.requestJson(instance, 'export-data', exportParams)
            .then(response => {
                this.hideLoading(tableId);
                if (response.code == 200 || response.code === '200' || response.success) {
                    const payload = response.data || {};
                    const content = payload.body || '';
                    const contentType = payload.content_type || (format === 'json' ? 'application/json' : 'text/csv');
                    const filename = payload.filename || `export_${Date.now()}.${format === 'excel' ? 'xlsx' : format}`;
                    this.downloadFile(new Blob([content], { type: contentType }), filename);
                } else {
                    throw new Error('Export failed');
                }
            })
            .then(() => {
                this.showSuccess(tableId, __('閹存劕濮涚€电厧鍤?%{1} 閺壜ゎ唶瑜?', selectedIds.length));
            })
            .catch(error => {
                this.hideLoading(tableId);
                console.error('Export error:', error);
                this.showError(tableId, __('鐎电厧鍤径杈Е閿?{1}', error.message));
            });
    },

    /**
     * 娑撳娴囬弬鍥︽
     */
    downloadFile: function (blob, filename) {
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.style.display = 'none';
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        document.body.removeChild(a);
    },

    /**
     * 娑撳娴嘕SON閺傚洣娆?
     */
    downloadJsonFile: function (data, filename) {
        const jsonStr = JSON.stringify(data, null, 2);
        const blob = new Blob([jsonStr], { type: 'application/json' });
        this.downloadFile(blob, filename);
    },

    /**
     * 鐎广垺鍩涚粩顖氼嚤閸戝搫濮涢懗鏂ょ礄婢跺洨鏁ら弬瑙勵攳閿?
     */
    exportDataClient: function (instance, selectedIds = null, format = 'csv') {
        const tableId = instance.container.getAttribute('id');

        // 閼惧嘲褰囩憰浣割嚤閸戣櫣娈戦弫鐗堝祦
        let exportData = instance.data;
        if (selectedIds && selectedIds.length > 0) {
            exportData = instance.data.filter(row => selectedIds.includes(row.id || row.index));
        }

        if (exportData.length === 0) {
            this.showWarning(tableId, __('濞屸剝婀侀崣顖氼嚤閸戣櫣娈戦弫鐗堝祦'));
            return;
        }

        // 閼惧嘲褰囬崣顖濐潌鐎涙顔?
        const visibleFields = instance.displayFields.filter(field => field.visible !== false);

        if (format === 'csv') {
            this.exportToCsv(exportData, visibleFields);
        } else if (format === 'json') {
            this.exportToJson(exportData, visibleFields);
        } else {
            this.showError(tableId, __('娑撳秵鏁幐浣烘畱鐎电厧鍤弽鐓庣础'));
        }
    },

    /**
     * 鐎电厧鍤稉绡奡V
     */
    exportToCsv: function (data, fields) {
        // 閺嬪嫬缂揅SV婢舵挳鍎?
        const headers = fields.map(field => field.label || field.name);
        let csvContent = headers.join(',') + '\n';

        // 閺嬪嫬缂揅SV閺佺増宓佺悰?
        data.forEach(row => {
            const values = fields.map(field => {
                let value = row[field.name] || '';
                // 婢跺嫮鎮婇崠鍛儓闁褰块幋鏍х穿閸欓娈戦崐?
                if (typeof value === 'string' && (value.includes(',') || value.includes('"') || value.includes('\n'))) {
                    value = '"' + value.replace(/"/g, '""') + '"';
                }
                return value;
            });
            csvContent += values.join(',') + '\n';
        });

        // 娑撳娴囬弬鍥︽
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        this.downloadFile(blob, `export_${Date.now()}.csv`);
    },

    /**
     * 鐎电厧鍤稉绡擲ON
     */
    exportToJson: function (data, fields) {
        // 閸欘亜顕遍崙鍝勫讲鐟欎礁鐡у▓鐢垫畱閺佺増宓?
        const exportData = data.map(row => {
            const exportRow = {};
            fields.forEach(field => {
                exportRow[field.name] = row[field.name];
            });
            return exportRow;
        });

        this.downloadJsonFile(exportData, `export_${Date.now()}.json`);
    },

    /**
     * 閸掓繂顫愰崠鏍ㄦ閸旂姾娴囩€涙顔岄柊宥囩枂
     */
    loadFieldsOnInit: function (instance) {
        console.log('loadFieldsOnInit: 瀵偓婵濮炴潪钘夌摟濞堢敻鍘ょ純?', {
            model: instance.options.model,
            scope: instance.options.scope
        });
        // 閸忓牆鐨剧拠鏇氱矤HTML娑擃厼鍨垫慨瀣閸╄櫣顢呴柊宥囩枂
        this.initFromHTML(instance);
        // 閻掕泛鎮楅崝鐘烘祰鐎涙顔岄柊宥囩枂楠炶埖瑕嗛弻鎾广€冮弽?
        this.loadModelFieldsForInit(instance.container.getAttribute('id'));
    },

    /**
     * 閸掓繂顫愰崠鏍ㄦ閸旂姾娴囧Ο鈥崇€风€涙顔岄敍鍫滅窗鐟欙箑褰傜悰銊︾壐闁插秵鏌婇弸鍕紦閿?
     */
    loadModelFieldsForInit: function (tableId) {
        const instance = this.instances[tableId];
        if (!instance) return;
        if (!instance.apiUrl) {
            console.error('[DataTableManager] apiUrl閺堫亣顔曠純顕嗙礉閺冪姵纭堕崝鐘烘祰鐎涙顔岄柊宥囩枂');
            return;
        }

        // 1. 閹绘劕褰囧Ο鈩冩緲鐎涙顔岄敍鍧抜eld閹稿洤鐣剧€涙顔岄敍?
        const templateFields = this.extractFieldsFromDOM(tableId, 'display');
        const templateFilterFields = this.extractFieldsFromDOM(tableId, 'filter');
        console.log('loadModelFieldsForInit: 濡剝婢樼€涙顔?', templateFields);
        console.log('loadModelFieldsForInit: 濡剝婢樼粵娑⑩偓澶婄摟濞?', templateFilterFields);
        instance.templateFields = templateFields;
        instance.templateFilterFields = templateFilterFields;

        console.log('loadModelFieldsForInit: 瀵偓婵濮炴潪钘夌摟濞堢敻鍘ょ純?', {
            tableId,
            model: instance.options.model,
            scope: instance.options.scope
        });

        this.requestJson(instance, 'fields', {
            table_id: tableId,
            model: instance.options.model,
            scope: instance.options.scope
        })
            .then(response => {
                // 3. 閸氬牆鑻熷Ο鈩冩緲鐎涙顔岄崪灞惧复閸欙絽鐡у▓纰夌礄閻劋绨?閸欘垳鏁ょ€涙顔?閸掓銆冮敍?
                let apiFields = (response.data && response.data.all_fields) ? response.data.all_fields : [];
                let mergedFields = this.mergeTemplateAndApiFields(templateFields, apiFields);
                // 閸氬牆鑻焒ilter鐎涙顔?
                let apiFilterFields = (response.data && response.data.filter_fields) ? response.data.filter_fields : [];
                let mergedFilterFields = this.mergeTemplateAndApiFields(templateFilterFields, apiFilterFields);

                // 4. 绾喖鐣鹃弰鍓с仛鐎涙顔岄敍姘喘閸忓牏楠囨稉?缂傛挸鐡ㄩ柊宥囩枂 > 濡剝婢樼€涙顔?> API姒涙顓荤€涙顔?
                let displayFields;
                const cachedDisplayFields = response.data.cached_display_fields;
                const templateFieldNames = new Set(templateFields.map(f => f.name));

                if (cachedDisplayFields && cachedDisplayFields.length > 0) {
                    // 閺堝绱︾€涙﹢鍘ょ純顕嗙礉娴ｈ法鏁ょ紓鎾崇摠闁板秶鐤嗛敍灞肩稻绾喕绻氬Ο鈩冩緲鐎涙顔岀仦鐐粹偓褌绱崗?
                    displayFields = cachedDisplayFields.map(cachedField => {
                        const templateField = templateFields.find(t => t.name === cachedField.name);
                        return templateField ? { ...cachedField, ...templateField } : cachedField;
                    });
                    console.log('loadModelFieldsForInit: 娴ｈ法鏁ょ紓鎾崇摠闁板秶鐤?', displayFields);
                } else if (templateFields.length > 0) {
                    // 濞屸剝婀佺紓鎾崇摠闁板秶鐤嗛敍灞肩稻閺堝膩閺夊灝鐡у▓纰夌礉閸欘亝妯夌粈鐑樐侀弶鍨摟濞?
                    displayFields = [...templateFields];
                    console.log('loadModelFieldsForInit: 娴ｈ法鏁ゅΟ鈩冩緲鐎涙顔岄敍鍫ョ帛鐠併倕褰ч弰鍓с仛濡剝婢樻稉顓熷瘹鐎规氨娈戠€涙顔岄敍?', displayFields);
                } else {
                    // 濞屸剝婀佺紓鎾崇摠闁板秶鐤嗘稊鐔哥梾閺堝膩閺夊灝鐡у▓纰夌礉娴ｈ法鏁PI姒涙顓荤€涙顔?
                    displayFields = response.data.display_fields || [];
                    console.log('loadModelFieldsForInit: 娴ｈ法鏁PI姒涙顓荤€涙顔?', displayFields);
                }

                // 5. 鐠佹澘缍嶉悽銊﹀煕闁瀚ㄩ惃鍕摟濞堢绱欓棃鐐茨侀弶鍨摟濞堢绱?
                const userSelectedFields = displayFields.filter(field => !templateFieldNames.has(field.name));
                console.log('loadModelFieldsForInit: 閻劍鍩涢柅澶嬪閻ㄥ嫬鐡у▓?', userSelectedFields);

                // 6. 婢跺嫮鎮婇崣妞剧箽閹躲倕鐡у▓鐢垫畱闁板秶鐤?
                displayFields = displayFields.map(field => {
                    const isProtected = this.isFieldProtected(field);
                    const isPrimaryOrIndex = field.is_primary === true || field.primary === true || field.primary_key === true || field.pk === true || ['id', 'ID', 'Id', 'primary', 'pk', 'primary_key', 'is_primary'].includes(field.name);
                    if (isProtected) {
                        // 娑撳鏁?缁便垹绱╃€涙顔屾稉宥堝厴閹烘帒绨崪宀€些閸?
                        if (isPrimaryOrIndex) {
                            return {
                                ...field,
                                sortable: false,
                                editable: field.editable === true || field.editable === 'true',
                                searchable: field.searchable !== false,
                                resizable: field.resizable !== false,
                                visible: field.visible !== false,
                                display_orderable: false
                            };
                        }
                        // 閸忚泛鐣犻崣妞剧箽閹躲倕鐡у▓鐢哥帛鐠併倕褰叉禒銉﹀笓鎼村繐鎷扮粔璇插З
                        return {
                            ...field,
                            sortable: field.sortable !== false && field.sortable !== 'false',
                            editable: field.editable === true || field.editable === 'true',
                            searchable: field.searchable !== false,
                            resizable: field.resizable !== false,
                            visible: field.visible !== false,
                            display_orderable: field.display_orderable !== false && field.display_orderable !== 0 && field.display_orderable !== 'false' && field.display_orderable !== '0'
                        };
                    }
                    return field;
                });

                // 7. 绾喕绻氶幐鍥х暰鐎涙顔岄幒鎺戝煂閸撳秹娼?
                const displayTemplateFields = displayFields.filter(field =>
                    field.template_defined || field.field_defined || field.from_field
                );
                const userFields = displayFields.filter(field =>
                    !field.template_defined && !field.field_defined && !field.from_field
                );

                // 闁插秵鏌婇幒鎺戠碍閿涙碍膩閺夊灝鐡у▓闈涙躬閸撳稄绱濋悽銊﹀煕鐎涙顔岄崷銊ユ倵
                displayFields = [...displayTemplateFields, ...userFields];

                // 8. 閺囧瓨鏌婄€圭偘绶ユ稉顓犳畱鐎涙顔岄弫鐗堝祦
                instance.allFields = mergedFields;
                instance.displayFields = displayFields;
                instance.filterFields = mergedFilterFields;

                // 9. 鐟欙箑褰傜悰銊︾壐闁插秵鏌婇弸鍕紦
                this.rebuildTableFromConfig(tableId, displayFields, mergedFilterFields);
            })
            .catch(error => {
                console.error('loadModelFieldsForInit: 閸旂姾娴囩€涙顔岄柊宥囩枂婢惰精瑙?', error);
                this.showError(tableId, error || __('閼惧嘲褰囩€涙顔屾径杈Е'));
            });
    },

    /**
     * 娴犲订TML娑擃厼鍨垫慨瀣闁板秶鐤嗛敍鍫熸暜閹镐龚ata-w-field鐏炵偞鈧嶇礆
     */
    initFromHTML: function (instance) {
        const container = instance.container[0] || instance.container;
        const thead = container.querySelector('thead');
        const filterContainer = container.querySelector('.datatable-filter');

        // 娴兼ê鍘涙禒宸榟[data-w-field]鐠囪褰囩€涙顔岄柊宥囩枂
        const fields = [];
        if (thead) {
            const thElements = thead.querySelectorAll('th[data-w-field]');
            thElements.forEach(function (th) {
                try {
                    const fieldConfig = JSON.parse(th.getAttribute('data-w-field'));
                    fields.push(fieldConfig);
                } catch (e) {
                    // fallback: 閸忕厧顔愰弮褏绮ㄩ弸?
                    const fieldName = th.getAttribute('data-field');
                    if (fieldName) fields.push({ name: fieldName, label: th.textContent.trim(), type: 'text', visible: true });
                }
            });
        }

        // 鐠佸墽鐤嗛崺铏诡攨闁板秶鐤?
        instance.config = {
            fields: fields,
            pageSize: instance.pageSize,
            showPagination: instance.options.showPagination !== false,
            showToolbar: instance.options.showToolbar !== false,
            showConfig: instance.options.showConfig !== false
        };

        // 閸掓繂顫愰崠鏍ㄥ閺堝绻冨銈呮珤鐎圭懓娅?
        this.initAllFilters(instance);

        console.log('initFromHTML: 閸╄櫣顢呴柊宥囩枂閸掓繂顫愰崠鏍х暚閹?', {
            fieldsCount: fields.length,
            config: instance.config
        });

        // 濞夈劍鍓伴敍姘崇箹闁插奔绗夊〒鍙夌厠鐞涖劍鐗搁敍宀€鐡戠€涙顔岄柊宥囩枂閸旂姾娴囩€瑰本鍨氶崥搴″晙濞撳弶鐓?
    },

    /**
     * 閸掓繂顫愰崠鏍箖濠娿倕娅?
     */
    initFilters: function (instance, filterContainer) {
        if (!filterContainer) return;

        // 缂佹垵鐣炬潻鍥ㄦ姢閸ｃ劋绨ㄦ禒?
        const searchButtons = filterContainer.querySelectorAll('button[onclick*="search"]');
        searchButtons.forEach(button => {
            button.removeEventListener('click', this.applyFilters.bind(this, instance));
            button.addEventListener('click', this.applyFilters.bind(this, instance));
        });

        const resetButtons = filterContainer.querySelectorAll('button[onclick*="reset"]');
        resetButtons.forEach(button => {
            button.removeEventListener('click', this.resetFilters.bind(this, instance));
            button.addEventListener('click', this.resetFilters.bind(this, instance));
        });
    },

    /**
     * 閸掓繂顫愰崠鏍ㄥ閺堝鐡柅澶婃珤鐎圭懓娅?
     */
    initAllFilters: function (instance) {
        const container = instance.container[0] || instance.container;

        // 閸掓繂顫愰崠鏍﹀瘜鐟曚胶娈戠粵娑⑩偓澶婃珤鐎圭懓娅?
        const filterContainer = container.querySelector('.datatable-filter');
        if (filterContainer) {
            this.initFilters(instance, filterContainer);
        }

        // 閸掓繂顫愰崠鏍摣闁娅掑銉ュ徔閺?
        const filterToolbar = container.querySelector('.datatable-filter-toolbar');
        if (filterToolbar) {
            this.initFilters(instance, filterToolbar);
        }

        // 閸掓繂顫愰崠鏍摣闁娅掔悰銊ュ礋
        const filterForm = container.querySelector('.datatable-filter-form');
        if (filterForm) {
            this.initFilters(instance, filterForm);
        }
    },

    /**
     * 鎼存梻鏁ゆ潻鍥ㄦ姢閸?
     */
    applyFilters: function (instance) {
        const container = instance.container[0] || instance.container;
        instance.filters = {};

        // 婢跺嫮鎮婃稉鏄忣洣閻ㄥ嫮鐡柅澶婃珤鐎圭懓娅?
        const filterContainer = container.querySelector('.datatable-filter');
        if (filterContainer) {
            const filterInputs = filterContainer.querySelectorAll('[data-field]');
            filterInputs.forEach(function (input) {
                const fieldName = input.getAttribute('data-field');
                const value = input.value;

                if (value !== '' && value !== null && value !== undefined) {
                    instance.filters[fieldName] = value;
                }
            });
        }

        // 婢跺嫮鎮婄粵娑⑩偓澶婃珤瀹搞儱鍙块弽?
        const filterToolbar = container.querySelector('.datatable-filter-toolbar');
        if (filterToolbar) {
            const filterInputs = filterToolbar.querySelectorAll('[data-field]');
            filterInputs.forEach(function (input) {
                const fieldName = input.getAttribute('data-field');
                const value = input.value;

                if (value !== '' && value !== null && value !== undefined) {
                    instance.filters[fieldName] = value;
                }
            });
        }

        // 婢跺嫮鎮婄粵娑⑩偓澶婃珤鐞涖劌宕?
        const filterForm = container.querySelector('.datatable-filter-form');
        if (filterForm) {
            const filterInputs = filterForm.querySelectorAll('[data-field]');
            filterInputs.forEach(function (input) {
                const fieldName = input.getAttribute('data-field');
                const value = input.value;

                if (value !== '' && value !== null && value !== undefined) {
                    instance.filters[fieldName] = value;
                }
            });
        }

        instance.currentPage = 1;
        this.loadData(instance);
    },

    /**
     * 闁插秶鐤嗘潻鍥ㄦ姢閸?
     */
    resetFilters: function (instance) {
        const container = instance.container[0] || instance.container;

        // 闁插秶鐤嗘稉鏄忣洣閻ㄥ嫮鐡柅澶婃珤鐎圭懓娅?
        const filterContainer = container.querySelector('.datatable-filter');
        if (filterContainer) {
            const filterInputs = filterContainer.querySelectorAll('[data-field]');
            filterInputs.forEach(function (input) {
                if (input.type === 'checkbox') {
                    input.checked = false;
                } else {
                    input.value = '';
                }
            });
        }

        // 闁插秶鐤嗙粵娑⑩偓澶婃珤瀹搞儱鍙块弽?
        const filterToolbar = container.querySelector('.datatable-filter-toolbar');
        if (filterToolbar) {
            const filterInputs = filterToolbar.querySelectorAll('[data-field]');
            filterInputs.forEach(function (input) {
                if (input.type === 'checkbox') {
                    input.checked = false;
                } else {
                    input.value = '';
                }
            });
        }

        // 闁插秶鐤嗙粵娑⑩偓澶婃珤鐞涖劌宕?
        const filterForm = container.querySelector('.datatable-filter-form');
        if (filterForm) {
            const filterInputs = filterForm.querySelectorAll('[data-field]');
            filterInputs.forEach(function (input) {
                if (input.type === 'checkbox') {
                    input.checked = false;
                } else {
                    input.value = '';
                }
            });
        }

        instance.filters = {};
        instance.currentPage = 1;
        this.loadData(instance);
    },

    /**
     * 濞撳弶鐓嬬悰銊︾壐
     */
    renderTable: function (instance) {
        const container = instance.container[0] || instance.container;
        const tbody = container.querySelector('tbody');

        // 閸欘亝瑕嗛弻鎾存殶閹诡喛顢戦敍灞肩瑝闁插秵鏌婂〒鍙夌厠鐞涖劌銇?
        this.renderBody(instance, tbody);

        // 濞撳弶鐓嬮崚鍡涖€?
        this.renderPagination(instance);
    },

    /**
     * 鐟欙絾鐎経RL娑擃厾娈戦幒鎺戠碍閸欏倹鏆?
     */
    parseUrlSortParams: function () {
        const urlParams = new URLSearchParams(window.location.search);
        const current = urlParams.get('current');
        const sortParams = {};

        // 鐟欙絾鐎絪ort閸欏倹鏆熼敍灞筋洤sort.store_id=desc
        for (const [key, value] of urlParams.entries()) {
            if (key.startsWith('sort.')) {
                const fieldName = key.replace('sort.', '');
                sortParams[fieldName] = value;
            }
        }

        return {
            current: current,
            sorts: sortParams
        };
    },

    /**
     * 濞撳弶鐓嬬悰銊︾壐婢舵挳鍎?
     */
    renderHeader: function (tableId, fields) {
        const instance = this.instances[tableId];
        if (!instance) return;

        const container = instance.container[0] || instance.container;
        const thead = container.querySelector('thead');
        if (!thead) return;

        // 绾喕绻氱€涙顔屾い鍝勭碍濮濓絿鈥?
        const templateFields = fields.filter(field =>
            field.template_defined || field.field_defined || field.from_field
        );
        const userFields = fields.filter(field =>
            !field.template_defined && !field.field_defined && !field.from_field
        );
        const orderedFields = [...templateFields, ...userFields];

        let headerHtml = '<tr>';
        let hasSortableFields = false;

        // 濞ｈ濮炴径宥夆偓澶嬵攱閸掓绱欐俊鍌涚亯閸氼垳鏁ゆ禍鍡樺闁插繑鎼锋担婊愮礆
        if (instance.options.enableBatchActions !== false) {
            headerHtml += `
                <th class="checkbox-column" style="width: 40px;">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="select-all-${tableId}">
                        <label class="form-check-label" for="select-all-${tableId}"></label>
                    </div>
                </th>`;
        }

        orderedFields.forEach(field => {
            const isProtected = this.isFieldProtected(field);
            const canSort = isProtected ?
                (field.sortable === true || field.sortable === 'true') :
                (field.sortable !== false);
            const canEdit = isProtected ?
                (field.editable === true || field.editable === 'true') :
                (field.editable !== false);
            // 濡偓閺屻儱鍨弰顖氭儊閸欘垯浜掗幏鏍уЗ閹烘帒绨敍鍫ョ帛鐠併倕鍘戠拋闈╃礉闂勩倝娼弰搴ｂ€樼粋浣诡剾閿?
            const canDragOrder = field.display_orderable !== false && 
                                 field.display_orderable !== 'false' && 
                                 field.display_orderable !== 0 && 
                                 field.display_orderable !== '0';

            if (canSort) {
                hasSortableFields = true;
            }

            const sortIcon = canSort ?
                '<i class="fas fa-sort sort-icon" data-field="' + field.name + '"></i>' : '';
            const editIcon = canEdit ?
                '<i class="fas fa-edit edit-icon" data-field="' + field.name + '"></i>' : '';
            // 閹锋牕濮╅幍瀣労閸ョ偓鐖ｉ敍鍫濆涧閺堝褰查幏鏍уЗ閻ㄥ嫬鍨幍宥嗘▔缁€鐚寸礆
            const dragHandle = canDragOrder ?
                '<i class="fas fa-grip-vertical column-drag-handle" title="' + __('閹锋牕濮╃拫鍐╂殻閸掓銆庢惔?') + '"></i>' : '';

            headerHtml += `
                <th data-field="${field.name}"
                    class="${canSort ? 'sortable' : ''} ${canEdit ? 'editable' : ''} resizable ${canDragOrder ? 'column-draggable' : ''}"
                    style="min-width: ${field.minWidth || '100px'}; max-width: ${field.maxWidth || 'none'}; position: relative;"
                    ${canDragOrder ? 'draggable="true"' : ''}>
                    <div class="header-content">
                        ${dragHandle}
                        <span class="field-label">${field.label || field.name}</span>
                        ${sortIcon}
                        ${editIcon}
                    </div>
                    <div class="resize-handle" style="
                        position: absolute;
                        top: 0;
                        right: 0;
                        width: 5px;
                        height: 100%;
                        cursor: col-resize;
                        background: transparent;
                        z-index: 10;
                    "></div>
                </th>`;
        });
        headerHtml += '</tr>';

        thead.innerHTML = headerHtml;

        // 缂佹垵鐣鹃幒鎺戠碍娴滃娆?
        if (hasSortableFields) {
            const sortIcons = thead.querySelectorAll('.sort-icon');
            sortIcons.forEach(icon => {
                icon.addEventListener('click', function () {
                    const fieldName = this.getAttribute('data-field');
                    DataTableManager.sortTable(tableId, fieldName);
                });
            });
        }

        // 闁插秵鏌婇崚婵嗩潗閸栨牗瀚嬮幏鑺ュ笓鎼村繐濮涢懗鏂ょ礄鐎涙顔岄柊宥囩枂瀵湱鐛ラ敍?
        this.initDragSort(tableId);
        
        // 閸掓繂顫愰崠鏍у灙婢跺瓨瀚嬮崝銊﹀笓鎼村繐濮涢懗?
        this.initColumnDragSort(tableId);
    },
    
    /**
     * 閸掓繂顫愰崠鏍€冮弽鐓庡灙婢跺瓨瀚嬮崝銊﹀笓鎼村繐濮涢懗?
     * @param {string} tableId 鐞涖劍鐗窱D
     */
    initColumnDragSort: function (tableId) {
        const instance = this.instances[tableId];
        if (!instance) return;

        const container = instance.container[0] || instance.container;
        const thead = container.querySelector('thead');
        if (!thead) return;

        const headerCells = thead.querySelectorAll('th.column-draggable');
        const self = this;

        headerCells.forEach(th => {
            const fieldName = th.getAttribute('data-field');
            if (!fieldName) return;

            // 閹锋牕濮╁鈧慨?
            th.addEventListener('dragstart', function (e) {
                // 濡偓閺屻儲妲搁崥锔惧仯閸戣崵娈戦弰顖涘珛閸斻劍澧滈弻鍕灗闂堢€漞size-handle閸栧搫鐓?
                const target = e.target;
                if (target.classList.contains('resize-handle')) {
                    e.preventDefault();
                    return;
                }
                
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', fieldName);
                e.dataTransfer.setData('source', 'column-header');
                th.classList.add('column-dragging');
                
                // 濞ｈ濮為幏鏍уЗ閺冨墎娈戠憴鍡氼潕閺佸牊鐏?
                setTimeout(() => {
                    th.style.opacity = '0.5';
                }, 0);
            });

            // 閹锋牕濮╃紒鎾存将
            th.addEventListener('dragend', function () {
                th.classList.remove('column-dragging');
                th.style.opacity = '';
                
                // 濞撳懘娅庨幍鈧張澶嬪珛閺€鐐瘹缁€鍝勬珤
                thead.querySelectorAll('.column-drop-indicator').forEach(el => el.remove());
                thead.querySelectorAll('.column-drag-over').forEach(el => el.classList.remove('column-drag-over'));
            });

            // 閹锋牕濮╃紒蹇氱箖
            th.addEventListener('dragover', function (e) {
                const source = e.dataTransfer.types.includes('source') ? 'column-header' : '';
                // 閸欘亜顦╅悶鍡樻降閼奉亜鍨径瀵告畱閹锋牕濮?
                if (e.dataTransfer.getData('source') === 'column-header' || e.dataTransfer.types.includes('text/plain')) {
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                    th.classList.add('column-drag-over');
                    
                    // 閺勫墽銇氶弨鍓х枂閹稿洨銇氶崳?
                    self.showColumnDropIndicator(th, e);
                }
            });

            // 閹锋牕濮╃粋璇茬磻
            th.addEventListener('dragleave', function (e) {
                // 绾喕绻氶弰顖滄埂濮濓絿顬囧鈧懓灞肩瑝閺勵垵绻橀崗銉ョ摍閸忓啰绀?
                if (!th.contains(e.relatedTarget)) {
                    th.classList.remove('column-drag-over');
                    self.removeColumnDropIndicator(th);
                }
            });

            // 閺€鍓х枂
            th.addEventListener('drop', function (e) {
                e.preventDefault();
                th.classList.remove('column-drag-over');
                self.removeColumnDropIndicator(th);
                
                const draggedFieldName = e.dataTransfer.getData('text/plain');
                
                if (draggedFieldName && draggedFieldName !== fieldName) {
                    // 閹笛嗩攽閸掓些閸?
                    self.moveColumnByDrag(tableId, draggedFieldName, fieldName);
                }
            });
        });
    },
    
    /**
     * 閺勫墽銇氶崚妤佹杹缂冾喗瀵氱粈鍝勬珤
     */
    showColumnDropIndicator: function (th, e) {
        // 缁夊娅庢稊瀣閻ㄥ嫭瀵氱粈鍝勬珤
        this.removeColumnDropIndicator(th);
        
        const rect = th.getBoundingClientRect();
        const midPoint = rect.left + rect.width / 2;
        const isLeftSide = e.clientX < midPoint;
        
        // 閸掓稑缂撻幐鍥┿仛閸?
        const indicator = document.createElement('div');
        indicator.className = 'column-drop-indicator';
        indicator.style.cssText = `
            position: absolute;
            top: 0;
            bottom: 0;
            width: 3px;
            background: var(--datatable-primary, #2563eb);
            z-index: 1000;
            pointer-events: none;
            ${isLeftSide ? 'left: 0;' : 'right: 0;'}
        `;
        
        th.style.position = 'relative';
        th.appendChild(indicator);
        th.setAttribute('data-drop-position', isLeftSide ? 'before' : 'after');
    },
    
    /**
     * 缁夊娅庨崚妤佹杹缂冾喗瀵氱粈鍝勬珤
     */
    removeColumnDropIndicator: function (th) {
        const indicator = th.querySelector('.column-drop-indicator');
        if (indicator) {
            indicator.remove();
        }
        th.removeAttribute('data-drop-position');
    },
    
    /**
     * 闁俺绻冮幏鏍уЗ缁夎濮╅崚?
     * @param {string} tableId 鐞涖劍鐗窱D
     * @param {string} draggedFieldName 鐞氼偅瀚嬮崝銊ф畱鐎涙顔岄崥?
     * @param {string} targetFieldName 閻╊喗鐖ｇ€涙顔岄崥?
     */
    moveColumnByDrag: function (tableId, draggedFieldName, targetFieldName) {
        const instance = this.instances[tableId];
        if (!instance) return;

        const fieldList = instance.displayFields;
        const draggedIndex = fieldList.findIndex(f => f.name === draggedFieldName);
        const targetIndex = fieldList.findIndex(f => f.name === targetFieldName);

        if (draggedIndex === -1 || targetIndex === -1) {
            console.warn('moveColumnByDrag: 鐎涙顔岄張顏呭閸?', { draggedFieldName, targetFieldName });
            return;
        }

        const draggedField = fieldList[draggedIndex];
        const targetField = fieldList[targetIndex];

        // 濡偓閺屻儱鐡у▓鍨Ц閸氾箑鍘戠拋鍝バ╅崝?
        const draggedCanMove = draggedField.display_orderable !== false && 
                               draggedField.display_orderable !== 'false' && 
                               draggedField.display_orderable !== 0 && 
                               draggedField.display_orderable !== '0';
        const targetCanMove = targetField.display_orderable !== false && 
                              targetField.display_orderable !== 'false' && 
                              targetField.display_orderable !== 0 && 
                              targetField.display_orderable !== '0';

        if (!draggedCanMove) {
            console.warn('moveColumnByDrag: 鐞氼偅瀚嬮崝銊ф畱鐎涙顔屾稉宥呭帒鐠佸摜些閸?', draggedFieldName);
            this.showWarning(tableId, __('鐠囥儱鍨稉宥呭帒鐠佸摜些閸?'));
            return;
        }

        if (!targetCanMove) {
            console.warn('moveColumnByDrag: 閻╊喗鐖ｆ担宥囩枂鐎涙顔屾稉宥呭帒鐠佸摜些閸?', targetFieldName);
            this.showWarning(tableId, __('閺冪姵纭堕弨鍓х枂閸掓媽顕氭担宥囩枂'));
            return;
        }

        // 閹笛嗩攽缁夎濮?
        const movedField = fieldList.splice(draggedIndex, 1)[0];
        // 鐠侊紕鐣婚弬鎵畱閻╊喗鐖ｇ槐銏犵穿閿涘牆娲滄稉鍝勫灩闂勩倓绨℃稉鈧稉顏勫帗缁辩媴绱?
        const newTargetIndex = draggedIndex < targetIndex ? targetIndex - 1 : targetIndex;
        fieldList.splice(newTargetIndex, 0, movedField);

        // 娣囨繂鐡ㄩ悽銊﹀煕闁板秶鐤嗛崚鎵处鐎?
        this.saveFieldConfigToCache(tableId);

        // 闁插秵鏌婂〒鍙夌厠鐞涖劌銇旈崪灞炬殶閹?
        this.renderHeader(tableId, fieldList);
        this.renderTable(instance);

        // 閺勫墽銇氶幋鎰閹绘劗銇?
        this.showSuccess(tableId, __('閸掓銆庢惔蹇撳嚒閺囧瓨鏌?'));

        console.log('moveColumnByDrag: 閸掓瀚嬮崝銊╅崝銊ョ暚閹?', {
            dragged: draggedFieldName,
            target: targetFieldName,
            newOrder: fieldList.map(f => f.name)
        });
    },

    /**
     * 濞撳弶鐓嬮弫鐗堝祦鐞?
     */
    renderBody: function (instance, tbody) {
        if (!instance.data || instance.data.length === 0) {
            // 鐠侊紕鐣婚幀璇插灙閺佸府绱欓崠鍛婢跺秹鈧顢嬮崚妤€鎷伴幙宥勭稊閸掓绱?
            let totalColumns = instance.config.fields.length;
            if (instance.options.enableBatchActions !== false) totalColumns += 1; // 婢跺秹鈧顢嬮崚?
            if (instance.options.editable) totalColumns += 1; // 閹垮秳缍旈崚?
            tbody.innerHTML = `<tr><td colspan="${totalColumns}" class="text-center">閺嗗倹妫ら弫鐗堝祦</td></tr>`;
            return;
        }

        let bodyHtml = '';

        instance.data.forEach((row, index) => {
            bodyHtml += '<tr data-row-index="' + index + '" data-id="' + (row.id || row.index || '') + '">';

            // 濞ｈ濮炴径宥夆偓澶嬵攱閸掓绱欐俊鍌涚亯閸氼垳鏁ゆ禍鍡樺闁插繑鎼锋担婊愮礆
            if (instance.options.enableBatchActions !== false) {
                bodyHtml += `
                    <td class="checkbox-column">
                        <div class="form-check">
                            <input class="form-check-input row-checkbox" type="checkbox"
                                   value="${row.id || index}" data-row-index="${index}">
                        </div>
                    </td>`;
            }

            // 濞撳弶鐓嬮弫鐗堝祦閸?
            instance.config.fields.forEach(field => {
                if (field.visible) {
                    const value = row[field.name] || '';

                    // 鐎甸€涚艾閸欐ぞ绻氶幎銈囨畱鐎涙顔岄敍灞藉涧閺堝婀弰搴ｂ€橀幐鍥х暰editable=true閺冭埖澧犻崥顖滄暏缂傛牞绶?
                    const isTemplateField = field.template_defined || field.field_defined || field.from_field;
                    const canEdit = isTemplateField ?
                        (field.editable === true || field.editable === 'true') :
                        (field.editable !== false);

                    const cellClass = canEdit ? 'editable-cell' : '';

                    bodyHtml += `
                        <td data-field="${field.name}" class="${cellClass}">
                            <div class="cell-content">${this.formatCellValue(value, field)}</div>
                            ${canEdit ? '<div class="edit-overlay"><i class="fas fa-edit"></i></div>' : ''}
                        </td>
                    `;
                }
            });

            // 濞撳弶鐓嬮幙宥勭稊閸?
            if (instance.options.editable) {
                bodyHtml += `
                    <td class="actions-cell">
                        <button class="btn btn-sm btn-primary edit-row-btn" data-row-index="${index}">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-sm btn-danger delete-row-btn" data-row-index="${index}">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                `;
            }

            bodyHtml += '</tr>';
        });

        tbody.innerHTML = bodyHtml;

        // 缂佹垵鐣剧悰灞肩皑娴?
        this.bindRowEvents(instance, tbody);
    },

    /**
     * 閺嶇厧绱￠崠鏍у礋閸忓啯鐗?
     */
    formatCellValue: function (value, field) {
        if (field.formatter && typeof window[field.formatter] === 'function') {
            return window[field.formatter](value, field);
        }

        switch (field.type) {
            case 'date':
                return value ? new Date(value).toLocaleDateString() : '';
            case 'datetime':
                return value ? new Date(value).toLocaleString() : '';
            case 'number':
                return value ? Number(value).toLocaleString() : '';
            case 'boolean':
                return value ? '<span class="badge bg-success"></span>' : '<span class="badge bg-secondary"></span>';
            default:
                return value;
        }
    },

    /**
     * 濞撳弶鐓嬮崚鍡涖€?
     */
    renderPagination: function (instance) {
        const container = instance.container[0] || instance.container;
        const paginationContainer = container.querySelector('.datatable-pagination');

        if (!instance.options.showPagination || !instance.pagination) {
            if (paginationContainer) {
                paginationContainer.style.display = 'none';
            }
            return;
        }

        const pagination = instance.pagination;
        let paginationHtml = `
            <div class="pagination-info">
                閺勫墽銇氱粭?${(pagination.page - 1) * pagination.pageSize + 1} 閸?
                ${Math.min(pagination.page * pagination.pageSize, pagination.total)} 閺夆槄绱?
                閸?${pagination.total} 閺壜ゎ唶瑜?
            </div>
            <ul class="pagination">
        `;

        // 娑撳﹣绔存い?
        paginationHtml += `
            <li class="page-item ${pagination.hasPrevPage ? '' : 'disabled'}">
                <a class="page-link" href="#" data-page="${pagination.page - 1}">娑撳﹣绔存い?/a>
            </li>
        `;

        // 妞ょ數鐖?
        const startPage = Math.max(1, pagination.page - 2);
        const endPage = Math.min(pagination.lastPage, pagination.page + 2);

        for (let i = startPage; i <= endPage; i++) {
            paginationHtml += `
                <li class="page-item ${i === pagination.page ? 'active' : ''}">
                    <a class="page-link" href="#" data-page="${i}">${i}</a>
                </li>
            `;
        }

        // 娑撳绔存い?
        paginationHtml += `
            <li class="page-item ${pagination.hasNextPage ? '' : 'disabled'}">
                <a class="page-link" href="#" data-page="${pagination.page + 1}">娑撳绔存い?/a>
            </li>
        `;

        paginationHtml += '</ul>';

        if (paginationContainer) {
            paginationContainer.innerHTML = paginationHtml;
            paginationContainer.style.display = 'block';
        }

        // 缂佹垵鐣鹃崚鍡涖€夋禍瀣╂
        this.bindPaginationEvents(instance, paginationContainer);
    },

    /**
     * 閸旂姾娴囬弫鐗堝祦
     */
    loadData: function (instance) {
        const $loading = instance.container.find('.datatable-loading');
        const $content = instance.container.find('.datatable-content');

        console.log('瀵偓婵濮炴潪鑺ユ殶?', {
            model: instance.options.model,
            scope: instance.options.scope,
            page: instance.currentPage,
            pageSize: instance.pageSize,
            filters: instance.filters
        });

        $loading.show();
        $content.hide();

        this.requestJson(instance, 'data', {
            model: instance.options.model,
            scope: instance.options.scope,
            page: instance.currentPage,
            pageSize: instance.pageSize,
            search: instance.search,
            filters: instance.filters,
            sorts: instance.sorts
        })
            .then(response => {
                console.log('API閸濆秴绨?', response);
                $loading.hide();
                $content.show();

                // 閸忕厧顔?code 娑撳搫鐡х粭锔胯閹存牗鏆熺€?
                if (response.code == 200 || response.code === '200' || response.success) {
                    instance.data = response.data.data || [];
                    instance.pagination = response.data.pagination;
                    // 鐠佸墽鐤?totalCount 閻劋绨€电厧鍤崝鐔诲厴
                    instance.totalCount = response.data.total || response.data.pagination?.total || instance.data.length || 0;
                    this.renderTable(instance);
                } else {
                    console.error('API闁挎瑨顕?', response.msg);
                    this.showError(response.msg || response.message || __('閸旂姾娴囬弫鐗堝祦婢惰精瑙?'));
                }
            })
            .catch(error => {
                console.error('AJAX闁挎瑨顕?', error);
                $loading.hide();
                $content.show();
                this.showError(__('閸旂姾娴囬弫鐗堝祦婢惰精瑙? %{1}', error));
            });
    },

    /**
     * 閹兼粎鍌?
     */
    search: function (scope) {
        const instance = this.getInstanceByScope(scope);
        if (!instance) return;

        instance.search = instance.container.find('#search-input-' + scope).val();
        instance.currentPage = 1;
        this.loadData(instance);
    },

    /**
     * 濞撳懘娅庨幖婊呭偍
     */
    clearSearch: function (scope) {
        const instance = this.getInstanceByScope(scope);
        if (!instance) return;

        instance.container.find('#search-input-' + scope).val('');
        instance.search = '';
        instance.currentPage = 1;
        this.loadData(instance);
    },

    /**
     * 鎼存梻鏁ゆ潻鍥ㄦ姢?
     */
    applyFilter: function (scope) {
        const instance = this.getInstanceByScope(scope);
        if (!instance) return;

        const $form = instance.container.find('#filter-form-' + scope);
        instance.filters = {};

        $form.find('[data-field]').each(function () {
            const field = $(this).data('field');
            const value = $(this).val();
            if (value !== '' && value !== null) {
                instance.filters[field] = value;
            }
        });

        instance.currentPage = 1;
        this.loadData(instance);
    },

    /**
     * 濞撳懘娅庢潻鍥ㄦ姢?
     */
    clearFilter: function (scope) {
        const instance = this.getInstanceByScope(scope);
        if (!instance) return;

        instance.container.find('#filter-form-' + scope)[0].reset();
        instance.filters = {};
        instance.currentPage = 1;
        this.loadData(instance);
    },

    /**
     * 娣囨繂鐡ㄦ潻鍥ㄦ姢?
     */
    saveFilter: async function (scope) {
        const instance = this.getInstanceByScope(scope);
        if (!instance) return;

        if (typeof BackendConfirm === 'undefined') {
            console.warn('BackendConfirm is missing');
            return;
        }

        const filterName = await BackendConfirm.showInput({
            title: __('鏉堟挸鍙?'),
            message: __('鐠囩柉绶崗銉ㄧ箖濠娿倕娅掗崥宥囆?'),
            type: 'info'
        });
        if (!filterName) return;

        const $form = instance.container.find('#filter-form-' + scope);
        const filterData = {};

        $form.find('[data-field]').each(function () {
            const field = $(this).data('field');
            const value = $(this).val();
            filterData[field] = value;
        });

        // 娣囨繂鐡ㄩ崚鐗堟拱閸︽澘鐡?
        const savedFilters = JSON.parse(localStorage.getItem('datatable_filters_' + scope) || '{}');
        savedFilters[filterName] = filterData;
        localStorage.setItem('datatable_filters_' + scope, JSON.stringify(savedFilters));

        this.showSuccess(scope, __('鏉╁洦鎶ら崳銊ょ箽鐎涙ɑ鍨氶崝?'));
    },

    /**
     * 娣囨繂鐡ㄧ悰銊︾壐闁板秶鐤?
     */
    saveTableConfig: function (scope) {
        const instance = this.getInstanceByScope(scope);
        if (!instance) return;

        // 閺€鍫曟肠闁板秶鐤嗛弫鐗堝祦
        const config = {
            fields: instance.config.fields,
            pageSize: instance.pageSize,
            showPagination: instance.options.showPagination,
            showToolbar: instance.options.showToolbar,
            showConfig: instance.options.showConfig
        };

        this.requestJson(instance, 'save-config', {
            scope: scope,
            config: config
        })
            .then(response => {
                // 閸忕厧顔?code 娑撳搫鐡х粭锔胯閹存牗鏆熺€?
                if (response.code == 200 || response.code === '200' || response.success) {
                    this.showSuccess(scope, __('闁板秶鐤嗘穱婵嗙摠閹存劕濮?'));
                    const modal = document.getElementById('table-config-modal-' + scope);
                    if (modal && typeof bootstrap !== 'undefined') {
                        const bsModal = bootstrap.Modal.getInstance(modal);
                        if (bsModal) {
                            bsModal.hide();
                        }
                    }
                } else {
                    this.showError(scope, response.msg || response.message || __('娣囨繂鐡ㄦ径杈Е'));
                }
            })
            .catch(() => {
                this.showError(scope, __('娣囨繂鐡ㄩ柊宥囩枂婢惰精瑙?'));
            });
    },

    /**
     * 缂傛牞绶?
     */
    editRow: function (instance, rowIndex) {
        if (instance.isEditing) {
            this.showWarning(instance.container.attr('id'), __('鐠囧嘲鍘涙穱婵嗙摠瑜版挸澧犵紓鏍帆閻ㄥ嫯顢?'));
            return;
        }

        const row = instance.data[rowIndex];
        if (!row) return;

        instance.isEditing = true;
        instance.editingRow = rowIndex;
        instance.editingData = { ...row };

        // 閺勫墽銇氱紓鏍帆濡剝鈧焦顢?
        this.showEditModal(instance, row);
    },

    /**
     * 閺勫墽銇氱紓鏍帆濡剝鈧焦顢?
     */
    showEditModal: function (instance, row) {
        const modalId = 'edit-modal-' + instance.container.attr('id');
        const editTitle = __('缂傛牞绶弫鐗堝祦');
        let modalHtml = `
            <div class="modal fade" id="${modalId}" tabindex="-1">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">${editTitle}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <form id="edit-form-${modalId}">
        `;

        instance.config.fields.forEach(field => {
            if (field.editable) {
                const value = row[field.name] || '';
                modalHtml += this.renderEditField(field, value);
            }
        });
        const saveBtnText = __('娣囨繂鐡?');
        const cancelBtnText = __('閸欐牗绉?');
        modalHtml += `
                            </form>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">${cancelBtnText}</button>
                            <button type="button" class="btn btn-primary" data-datatable-action="save-row" data-table="${instance.container.attr('id')}">${saveBtnText}</button>
                        </div>
                    </div>
                </div>
            </div>
        `;

        // 缁夊娅庡鎻掔摠閸︺劎娈戝Ο鈩冣偓浣诡攱
        $('#' + modalId).remove();

        // 濞ｈ濮為弬鐗埬侀幀浣诡攱
        $('body').append(modalHtml);

        // 閺勫墽銇氬Ο鈩冣偓浣诡攱
        $('#' + modalId).modal('show');

        // 缂佹垵鐣惧Ο鈩冣偓浣诡攱娴滃娆?
        this.bindEditModalEvents(instance, modalId);
    },

    /**
     * 濞撳弶鐓嬬紓鏍帆鐎涙顔?
     */
    renderEditField: function (field, value) {
        const fieldId = 'edit-' + field.name;

        const pleaseSelect = __('鐠囩兘鈧瀚?');

        switch (field.type) {
            case 'textarea':
                return `
                    <div class="mb-3">
                        <label for="${fieldId}" class="form-label">${field.label}</label>
                        <textarea class="form-control" id="${fieldId}" name="${field.name}" rows="3">${value}</textarea>
                    </div>
                `;
            case 'select':
                return `
                    <div class="mb-3">
                        <label for="${fieldId}" class="form-label">${field.label}</label>
                        <select class="form-control" id="${fieldId}" name="${field.name}">
                            <option value="">${pleaseSelect}</option>
                            ${field.options ? field.options.map(opt =>
                    `<option value="${opt.value}" ${value == opt.value ? 'selected' : ''}>${opt.label}</option>`
                ).join('') : ''}
                        </select>
                    </div>
                `;
            case 'checkbox':
                return `
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="${fieldId}" name="${field.name}" value="1" ${value ? 'checked' : ''}>
                            <label class="form-check-label" for="${fieldId}">${field.label}</label>
                        </div>
                    </div>
                `;
            default:
                return `
                    <div class="mb-3">
                        <label for="${fieldId}" class="form-label">${field.label}</label>
                        <input type="${field.type}" class="form-control" id="${fieldId}" name="${field.name}" value="${value}">
                    </div>
                `;
        }
    },

    /**
     * 娣囨繂鐡ㄧ悰灞炬殶?
     */
    saveRow: function (tableId) {
        const instance = this.instances[tableId];
        if (!instance || !instance.isEditing) return;

        const modalId = 'edit-modal-' + tableId;
        const $form = $('#' + modalId + ' form');
        const formData = {};

        $form.find('[name]').each(function () {
            const name = $(this).attr('name');
            let value = $(this).val();

            if ($(this).attr('type') === 'checkbox') {
                value = $(this).prop('checked') ? 1 : 0;
            }

            formData[name] = value;
        });

        // 濞ｈ濮濱D
        formData.id = instance.data[instance.editingRow].id;

        this.requestJson(instance, 'save-data', {
            model: instance.options.model,
            data: formData
        })
            .then(response => {
                // 閸忕厧顔?code 娑撳搫鐡х粭锔胯閹存牗鏆熺€?
                if (response.code == 200 || response.code === '200' || response.success) {
                    this.showSuccess(tableId, __('娣囨繂鐡ㄩ幋鎰'));
                    $('#' + modalId).modal('hide');
                    instance.isEditing = false;
                    instance.editingRow = null;
                    instance.editingData = {};
                    this.loadData(instance);
                } else {
                    this.showError(tableId, response.msg || response.message || __('娣囨繂鐡ㄦ径杈Е'));
                }
            })
            .catch(() => {
                this.showError(tableId, __('娣囨繂鐡ㄦ径杈Е'));
            });
    },

    /**
     * 閸掔娀娅庣悰宀嬬礄婢х偛宸遍悧鍫礆
     */
    deleteRow: function (instance, rowIndex, options = {}) {
        const row = instance.data[rowIndex];
        if (!row || !row.id) return;

        // 閸氬牆鑻熸妯款吇闁銆?
        const deleteOptions = {
            confirmMessage: __('绾喖鐣剧憰浣稿灩闂勩倛绻栭弶陇顔囪ぐ鏇炴偋閿?'),
            softDelete: false,
            showDetails: true,
            ...options
        };

        // 閺勫墽銇氶崚鐘绘珟绾喛顓荤€电鐦藉?
        this.showDeleteConfirmDialog(instance, [row], deleteOptions, () => {
            this.performDelete(instance, [row.id], deleteOptions);
        });
    },

    /**
     * 閹靛綊鍣洪崚鐘绘珟
     */
    batchDelete: function (instance, selectedIds, options = {}) {
        if (!selectedIds || selectedIds.length === 0) {
            this.showError(instance.container.attr('id'), __('鐠囩兘鈧瀚ㄧ憰浣稿灩闂勩倗娈戠拋鏉跨秿'));
            return;
        }

        // 閼惧嘲褰囬柅澶夎厬閻ㄥ嫯顢戦弫鐗堝祦
        const selectedRows = instance.data.filter(row => selectedIds.includes(row.id));

        // 閸氬牆鑻熸妯款吇闁銆?
        const deleteOptions = {
            confirmMessage: __('Confirm deleting selected records: %{1}', selectedIds.length),
            softDelete: false,
            showDetails: true,
            ...options
        };

        // 閺勫墽銇氶崚鐘绘珟绾喛顓荤€电鐦藉?
        this.showDeleteConfirmDialog(instance, selectedRows, deleteOptions, () => {
            this.performDelete(instance, selectedIds, deleteOptions);
        });
    },

    /**
     * 閺勫墽銇氶崚鐘绘珟绾喛顓荤€电鐦藉?
     */
    showDeleteConfirmDialog: function (instance, rows, options, onConfirm) {
        const tableId = instance.container.attr('id');

        // 閸掓稑缂撶涵顔款吇鐎电鐦藉鍜筎ML
        const dialogHtml = `
            <div class="modal fade" id="delete-confirm-modal-${tableId}" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="fas fa-exclamation-triangle text-warning me-2"></i>
                                ${__('閸掔娀娅庣涵顔款吇')}
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                ${options.confirmMessage}
                            </div>

                            ${options.showDetails ? this.generateDeleteDetailsHtml(rows) : ''}

                            <div class="form-check mt-3">
                                <input class="form-check-input" type="checkbox" id="soft-delete-${tableId}">
                                <label class="form-check-label" for="soft-delete-${tableId}">
                                    ${__('鏉烆垰鍨归梽銈忕礄閸欘垱浠径宥忕礆')}
                                </label>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                ${__('閸欐牗绉?')}
                            </button>
                            <button type="button" class="btn btn-danger" id="confirm-delete-${tableId}">
                                <i class="fas fa-trash me-2"></i>
                                ${__('绾喛顓婚崚鐘绘珟')}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;

        // 缁夊娅庡鎻掔摠閸︺劎娈戠€电鐦藉?
        $(`#delete-confirm-modal-${tableId}`).remove();

        // 濞ｈ濮為崚浼淬€夐棃?
        $('body').append(dialogHtml);

        // 閺勫墽銇氱€电鐦藉?
        const modal = new bootstrap.Modal(document.getElementById(`delete-confirm-modal-${tableId}`));
        modal.show();

        // 缂佹垵鐣剧涵顔款吇閹稿鎸虫禍瀣╂
        $(`#confirm-delete-${tableId}`).on('click', () => {
            const softDelete = $(`#soft-delete-${tableId}`).is(':checked');
            options.softDelete = softDelete;
            modal.hide();
            onConfirm();
        });

        // 濞撳懐鎮婃禍瀣╂
        $(`#delete-confirm-modal-${tableId}`).on('hidden.bs.modal', function () {
            $(this).remove();
        });
    },

    /**
     * 閻㈢喐鍨氶崚鐘绘珟鐠囷附鍎廐TML
     */
    generateDeleteDetailsHtml: function (rows) {
        if (rows.length === 1) {
            const row = rows[0];
            return `
                <div class="delete-details">
                    <h6>${__('閸楀啿鐨㈤崚鐘绘珟閻ㄥ嫯顔囪ぐ鏇窗')}</h6>
                    <div class="card">
                        <div class="card-body p-2">
                            ${this.generateRowDetailsHtml(row)}
                        </div>
                    </div>
                </div>
            `;
        } else {
            return `
                <div class="delete-details">
                    <h6>${__('閸楀啿鐨㈤崚鐘绘珟閻ㄥ嫯顔囪ぐ鏇窗')}</h6>
                    <div class="alert alert-info">
                        ${__('閸忛亶鈧鑵?')} <strong>${rows.length}</strong> ${__('閺壜ゎ唶瑜?')}
                    </div>
                    <div class="row-list" style="max-height: 200px; overflow-y: auto;">
                        ${rows.map(row => `
                            <div class="card mb-2">
                                <div class="card-body p-2">
                                    ${this.generateRowDetailsHtml(row)}
                                </div>
                            </div>
                        `).join('')}
                    </div>
                </div>
            `;
        }
    },

    /**
     * 閻㈢喐鍨氱悰宀冾嚊閹専TML
     */
    generateRowDetailsHtml: function (row) {
        const details = [];

        // 閺勫墽銇氭稉鏄忣洣鐎涙顔?
        const mainFields = ['id', 'name', 'title', 'username', 'email'];
        mainFields.forEach(field => {
            if (row[field] !== undefined) {
                details.push(`<strong>${field}:</strong> ${row[field]}`);
            }
        });

        // 婵″倹鐏夊▽鈩冩箒娑撴槒顩︾€涙顔岄敍灞炬▔缁€鍝勫閸戠姳閲滅€涙顔?
        if (details.length === 0) {
            const keys = Object.keys(row).slice(0, 3);
            keys.forEach(key => {
                if (row[key] !== undefined) {
                    details.push(`<strong>${key}:</strong> ${row[key]}`);
                }
            });
        }

        return details.join('<br>');
    },

    /**
     * 閹笛嗩攽閸掔娀娅庨幙宥勭稊
     */
    performDelete: function (instance, ids, options) {
        const tableId = instance.container.attr('id');

        // 閺勫墽銇氶崝鐘烘祰閻樿埖鈧?
        this.showLoading(tableId, __('濮濓絽婀崚鐘绘珟...'));

        this.requestJson(instance, 'delete-data', {
            model: instance.options.model,
            ids: Array.isArray(ids) ? ids : [ids],
            soft_delete: options.softDelete || false
        })
            .then(response => {
                this.hideLoading(tableId);

                // 閸忕厧顔?code 娑撳搫鐡х粭锔胯閹存牗鏆熺€?
                if (response.code == 200 || response.code === '200' || response.success) {
                    const message = options.softDelete
                        ? __('鐠佹澘缍嶅鑼╅懛鍐叉礀閺€鍓佺彲')
                        : __('閸掔娀娅庨幋鎰');
                    this.showSuccess(tableId, message);

                    // 闁插秵鏌婇崝鐘烘祰閺佺増宓?
                    this.loadData(instance);

                    // 濞撳懘娅庨柅澶夎厬閻樿埖鈧?
                    this.clearSelection(instance);
                } else {
                    this.showError(tableId, response.msg || response.message || __('閸掔娀娅庢径杈Е'));
                }
            })
            .catch(error => {
                this.hideLoading(tableId);
                console.error('Delete error:', error);
                this.showError(tableId, __('閸掔娀娅庢径杈Е'));
            });
    },

    /**
     * 濞撳懘娅庨柅澶夎厬閻樿埖鈧?
     */
    clearSelection: function (instance) {
        const tableId = instance.container.attr('id');

        // 濞撳懘娅庢径宥夆偓澶嬵攱闁鑵戦悩鑸碘偓?
        $(`#${tableId} input[type="checkbox"]`).prop('checked', false);

        // 濞撳懘娅庨柅澶夎厬鐞涘本鐗卞?
        $(`#${tableId} tbody tr`).removeClass('selected');

        // 閺囧瓨鏌婇幍褰掑櫤閹垮秳缍旈幐澶愭尦閻樿埖鈧?
        this.updateBatchActionButtons(instance);
    },

    /**
     * 缂佹垵鐣炬禍瀣╂
     */
    bindEvents: function (instance) {
        // 閼惧嘲褰囩€圭懓娅掗敍鍫熸暜閹?DOM 閸忓啰绀岄崪?jQuery 鐎电钖勯敍?
        const container = instance.container.jquery ? instance.container[0] : instance.container;
        
        // 閹兼粎鍌ㄦ禍瀣╂
        const searchInput = container.querySelector('#search-input-' + instance.options.scope);
        if (searchInput) {
            searchInput.addEventListener('keypress', (e) => {
                if (e.which === 13 || e.keyCode === 13) {
                    this.search(instance.options.scope);
                }
            });
        }

        // 鏉╁洦鎶ら崳銊ょ皑娴?
        const filterForm = container.querySelector('#filter-form-' + instance.options.scope);
        if (filterForm) {
            filterForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.applyFilter(instance.options.scope);
            });
        }

        // 缁愭褰涢崗鎶芥４閸撳秵褰佺粈?
        window.addEventListener('beforeunload', (e) => {
            if (instance.isEditing) {
                e.preventDefault();
                e.returnValue = __('閹劍婀侀張顏冪箽鐎涙娈戠紓鏍帆閸愬懎顔愰敍宀€鈥樼€规俺顩︾粋璇茬磻閸氭绱?');
            }
        });
    },

    /**
     * 缂佹垵鐣剧悰銊ャ仈娴滃娆?
     */
    bindHeaderEvents: function (instance, $thead) {
        // 閹烘帒绨柧鐐复閻愮懓鍤禍瀣╂
        $thead.on('click', '.sort-link', function (e) {
            e.preventDefault();
            const field = $(this).data('field');
            const currentSort = instance.sorts[field];
            let newSortDirection;

            // 绾喖鐣鹃弬鎵畱閹烘帒绨弬鐟版倻
            if (currentSort === 'asc') {
                newSortDirection = 'desc';
            } else if (currentSort === 'desc') {
                newSortDirection = null; // 閸欐牗绉烽幒鎺戠碍
            } else {
                newSortDirection = 'asc';
            }

            // 閺囧瓨鏌婄€圭偘绶ユ稉顓犳畱閹烘帒绨悩?
            if (newSortDirection) {
                instance.sorts[field] = newSortDirection;
            } else {
                delete instance.sorts[field];
            }

            // 閺囧瓨鏌奤RL閸欏倹鏆?
            DataTableManager.updateUrlSortParams(field, newSortDirection);

            // 闁插秵鏌婇崝鐘烘祰閺佺増宓?
            DataTableManager.loadData(instance);
        });

        // 閸忕厧顔愰弮褏娈戦幒鎺戠碍娴滃娆㈤敍鍫㈠仯閸戠粯鏆ｆ稉鐚糷?
        $thead.on('click', '[data-sortable="true"]', function (e) {
            // 婵″倹鐏夐悙鐟板毊閻ㄥ嫭妲搁幒鎺戠碍闁剧偓甯撮敍灞肩瑝婢跺嫮鎮婇敍鍫ヤ缉閸忓秹鍣告径宥忕礆
            if ($(e.target).closest('.sort-link').length) {
                return;
            }

            const field = $(this).data('field');
            const currentSort = instance.sorts[field];

            if (currentSort === 'asc') {
                instance.sorts[field] = 'desc';
            } else if (currentSort === 'desc') {
                delete instance.sorts[field];
            } else {
                instance.sorts[field] = 'asc';
            }

            DataTableManager.loadData(instance);
        });

        // 閸掓顔旂拫鍐╂殻娴滃娆?
        $thead.on('mousedown', '.resize-handle', function (e) {
            e.preventDefault();
            const $th = $(this).parent();
            const startX = e.clientX;
            const startWidth = $th.width();

            const onMouseMove = function (e) {
                const newWidth = startWidth + (e.clientX - startX);
                $th.css('width', Math.max(50, newWidth) + 'px');
            };

            const onMouseUp = function () {
                $(document).off('mousemove', onMouseMove).off('mouseup', onMouseUp);

                // 娣囨繂鐡ㄩ崚妤€顔旈柊宥囩枂
                const field = $th.data('field');
                const width = $th.width() + 'px';

                instance.config.fields.forEach(f => {
                    if (f.name === field) {
                        f.width = width;
                    }
                });
            };

            $(document).on('mousemove', onMouseMove).on('mouseup', onMouseUp);
        });
    },

    /**
     * 閺囧瓨鏌奤RL娑擃厾娈戦幒鎺戠碍閸欏倹鏆?
     */
    updateUrlSortParams: function (field, sortDirection) {
        const url = new URL(window.location);
        const urlParams = url.searchParams;

        if (sortDirection) {
            // 鐠佸墽鐤嗛幒鎺戠碍閸欏倹鏆?
            urlParams.set('current', field);
            urlParams.set(`sort.${field}`, sortDirection);
        } else {
            // 閸欐牗绉烽幒鎺戠碍
            urlParams.delete('current');
            urlParams.delete(`sort.${field}`);
        }

        // 閺囧瓨鏌奤RL閿涘牅绗夐崚閿嬫煀妞ょ敻娼伴敍?
        window.history.replaceState({}, '', url.toString());
        console.log('updateUrlSortParams: URL瀹稿弶娲块弬?', url.toString());
    },

    /**
     * 缂佹垵鐣剧悰灞肩皑娴?
     */
    bindRowEvents: function (instance, $tbody) {
        // 缂傛牞绶幐澶愭尦娴滃娆?
        $tbody.on('click', '.edit-row-btn', function () {
            const rowIndex = $(this).data('row-index');
            DataTableManager.editRow(instance, rowIndex);
        });

        // 閸掔娀娅庨幐澶愭尦娴滃娆?
        $tbody.on('click', '.delete-row-btn', function () {
            const rowIndex = $(this).data('row-index');
            DataTableManager.deleteRow(instance, rowIndex);
        });

        // 婢跺秹鈧顢嬫禍瀣╂
        $tbody.on('change', '.row-checkbox', function () {
            const $row = $(this).closest('tr');
            $row.toggleClass('selected', this.checked);
            DataTableManager.updateBatchActionButtons(instance);
        });

        // 閸楁洖鍘撻弽鑲╃椽鏉堟垳绨ㄦ禒?
        $tbody.on('click', '.editable-cell', function () {
            const $cell = $(this);
            const field = $cell.data('field');
            const value = $cell.find('.cell-content').text();

            // 閸掓稑缂撻崘鍛颁粓缂傛牞绶崳?
            const $input = $('<input type="text" class="form-control form-control-sm">').val(value);
            $cell.find('.cell-content').hide();
            $cell.append($input);
            $input.focus();

            $input.on('blur keypress', function (e) {
                if (e.type === 'blur' || e.which === 13) {
                    const newValue = $(this).val();
                    $cell.find('.cell-content').text(newValue).show();
                    $(this).remove();

                    // 娣囨繂鐡ㄩ弫鐗堝祦
                    const rowIndex = $cell.closest('tr').data('row-index');
                    const row = instance.data[rowIndex];
                    if (row) {
                        row[field] = newValue;
                        DataTableManager.saveRowData(instance, row);
                    }
                }
            });
        });
    },

    /**
     * 缂佹垵鐣鹃崚鍡涖€夋禍瀣╂
     */
    bindPaginationEvents: function (instance, $pagination) {
        $pagination.on('click', '.page-link', function (e) {
            e.preventDefault();
            const page = $(this).data('page');
            if (page && page > 0 && page <= instance.pagination.lastPage) {
                instance.currentPage = page;
                DataTableManager.loadData(instance);
            }
        });
    },

    /**
     * 缂佹垵鐣剧紓鏍帆濡剝鈧焦顢嬫禍瀣╂
     */
    bindEditModalEvents: function (instance, modalId) {
        $('#' + modalId).on('hidden.bs.modal', function () {
            if (instance.isEditing) {
                instance.isEditing = false;
                instance.editingRow = null;
                instance.editingData = {};
            }
        });
    },

    /**
     * 閺嶈宓佹禒缁樺壈閺嶅洩鐦戠粭锕佸箯閸欐牕鐤勬笟瀣剁礄闁氨鏁ら弻銉﹀閺傝纭堕敍?
     * 閺€顖涘瘮: tableId, scope, datatable-scope-xxx 閺嶇厧绱?
     */
    getInstance: function (identifier) {
        // 1. 閻╁瓨甯撮弻銉﹀
        if (this.instances[identifier]) {
            return this.instances[identifier];
        }
        
        // 2. 鐏忔繆鐦?scope- 閸撳秶绱?
        const scopeKey = 'scope-' + identifier;
        if (this.instances[scopeKey]) {
            return this.instances[scopeKey];
        }
        
        // 3. 鐏忔繆鐦禒?datatable-scope-xxx 閺嶇厧绱℃稉顓熷絹閸?scope
        if (identifier.startsWith('datatable-scope-')) {
            const extractedScope = identifier.replace('datatable-scope-', '');
            const extractedScopeKey = 'scope-' + extractedScope;
            if (this.instances[extractedScopeKey]) {
                return this.instances[extractedScopeKey];
            }
        }
        
        // 4. 闁秴宸婚幍鈧張澶婄杽娓氬鐓￠幍鎯у爱闁板秶娈?scope 閹?tableId
        for (const instanceKey in this.instances) {
            const instance = this.instances[instanceKey];
            if (instance.scope === identifier || 
                instance.tableId === identifier ||
                (instance.options && instance.options.scope === identifier)) {
                return instance;
            }
        }
        
        return null;
    },

    /**
     * 閺嶈宓乻cope閼惧嘲褰囩€圭偘绶?
     */
    getInstanceByScope: function (scope) {
        return this.getInstance(scope);
    },

    /**
     * 娣囨繂鐡ㄧ悰灞炬殶?
     */
    saveRowData: function (instance, row) {
        this.requestJson(instance, 'save-data', {
            model: instance.options.model,
            data: row
        })
            .then(response => {
                if (response.code !== 200) {
                    this.showError(instance.container.attr('id'), response.msg);
                }
            })
            .catch(() => {
                this.showError(instance.container.attr('id'), __('娣囨繂鐡ㄦ径杈Е'));
            });
    },

    /**
     * 閺勫墽銇氶幋鎰娣団剝浼?
     */
    /**
     * 閺勫墽銇氶幋鎰娣団剝浼呴敍鍫濐杻瀵櫣澧楅敍?
     */
    showSuccess: function (tableId, message, options = {}) {
        const {
            autoHide = true,
            hideDelay = 3000
        } = options;

        const container = document.getElementById('w-datatable-' + tableId) || document.getElementById(tableId);
        if (!container) {
            console.error('DataTable container not found:', tableId);
            return;
        }

        // 缁夊娅庡鎻掔摠閸︺劎娈戦幋鎰濞戝牊浼?
        const existingSuccess = container.querySelector('.datatable-success-message');
        if (existingSuccess) {
            existingSuccess.remove();
        }

        // 閸掓稑缂撻幋鎰濞戝牊浼呴崗鍐
        const successHtml = `
            <div class="datatable-success-message">
                <i class="fas fa-check-circle"></i>
                <span>${message}</span>
            </div>
        `;

        // 閸︺劏銆冮弽鐓庮啇閸ｃ劑銆婇柈銊︽▔缁€鐑樺灇閸旂喐绉烽幁?
        const toolbar = container.querySelector('.w-datatable-toolbar');
        if (toolbar) {
            toolbar.insertAdjacentHTML('afterend', successHtml);
        } else {
            container.insertAdjacentHTML('afterbegin', successHtml);
        }

        // 閼奉亜濮╅梾鎰
        if (autoHide) {
            setTimeout(() => {
                const successMsg = container.querySelector('.datatable-success-message');
                if (successMsg) {
                    successMsg.style.opacity = '0';
                    successMsg.style.transition = 'opacity 0.3s';
                    setTimeout(() => successMsg.remove(), 300);
                }
            }, hideDelay);
        }
    },

    /**
     * 閺勫墽銇氶崝鐘烘祰閻樿埖鈧?
     */
    showLoading: function (tableId, message = __('閸旂姾娴囨稉?..')) {
        const container = document.getElementById(tableId);
        if (!container) return;

        // 缁夊娅庡鎻掔摠閸︺劎娈戦崝鐘烘祰閹绘劗銇?
        const existingLoading = container.querySelector('.loading-overlay');
        if (existingLoading) {
            existingLoading.remove();
        }

        // 閸掓稑缂撻崝鐘烘祰鐟曞棛娲婄仦?
        const loadingHtml = `
            <div class="loading-overlay" style="
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(255, 255, 255, 0.8);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 1000;
            ">
                <div class="text-center">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <div class="mt-2">${message}</div>
                </div>
            </div>
        `;

        // 绾喕绻氱€圭懓娅掗張澶屾祲鐎电懓鐣炬担?
        if (getComputedStyle(container).position === 'static') {
            container.style.position = 'relative';
        }

        container.insertAdjacentHTML('beforeend', loadingHtml);
    },

    /**
     * 闂呮劘妫岄崝鐘烘祰閻樿埖鈧?
     */
    hideLoading: function (tableId) {
        const container = document.getElementById(tableId);
        if (!container) return;

        const loadingOverlay = container.querySelector('.loading-overlay');
        if (loadingOverlay) {
            loadingOverlay.remove();
        }
    },

    /**
     * 閺勫墽銇氱拃锕€鎲℃穱鈩冧紖
     */
    showWarning: function (tableId, message) {
        const warningHtml = `
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>鐠€锕€鎲￠敍?/strong>${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        `;

        // 閸︺劏銆冮弽鐓庮啇閸ｃ劑銆婇柈銊︽▔缁€楦款劅閸涘﹥绉烽幁?
        const container = document.getElementById(tableId);
        if (container) {
            const existingAlert = container.querySelector('.alert-warning');
            if (existingAlert) {
                existingAlert.remove();
            }
            container.insertAdjacentHTML('afterbegin', warningHtml);

            // 3缁夋帒鎮楅懛顏勫З闂呮劘妫?
            setTimeout(() => {
                const alert = container.querySelector('.alert-warning');
                if (alert) {
                    alert.remove();
                }
            }, 3000);
        }
    },

    /**
     * 閺勫墽銇氶柨娆掝嚖娣団剝浼呴敍鍫濐杻瀵櫣澧楅敍?
     */
    showError: function (tableId, message, options = {}) {
        const {
            autoHide = true,
            hideDelay = 5000,
            showRetry = false,
            retryCallback = null
        } = options;

        const container = document.getElementById('w-datatable-' + tableId) || document.getElementById(tableId);
        if (!container) {
            console.error('DataTable container not found:', tableId);
            return;
        }

        // 缁夊娅庡鎻掔摠閸︺劎娈戦柨娆掝嚖濞戝牊浼?
        const existingError = container.querySelector('.datatable-error-message');
        if (existingError) {
            existingError.remove();
        }

        // 閸掓稑缂撻柨娆掝嚖濞戝牊浼呴崗鍐
        const retryId = showRetry && retryCallback ? 'datatable-retry-' + Date.now() + '-' + Math.random().toString(36).slice(2) : '';
        if (retryId) {
            window._datatableRetryCallbacks = window._datatableRetryCallbacks || {};
            window._datatableRetryCallbacks[retryId] = retryCallback;
        }

        const errorHtml = `
            <div class="datatable-error-message">
                <i class="fas fa-exclamation-circle"></i>
                <span>${message}</span>
                ${showRetry && retryCallback ? `
                    <button type="button" class="btn btn-sm btn-outline-danger ms-auto" data-datatable-action="retry-error" data-retry-id="${retryId}">
                        <i class="fas fa-redo"></i> ${__('闁插秷鐦?')}
                    </button>
                ` : ''}
            </div>
        `;

        // 閸︺劏銆冮弽鐓庮啇閸ｃ劑銆婇柈銊︽▔缁€娲晩鐠囶垱绉烽幁?
        const toolbar = container.querySelector('.w-datatable-toolbar');
        if (toolbar) {
            toolbar.insertAdjacentHTML('afterend', errorHtml);
        } else {
            container.insertAdjacentHTML('afterbegin', errorHtml);
        }

        // 閼奉亜濮╅梾鎰
        if (autoHide) {
            setTimeout(() => {
                const errorMsg = container.querySelector('.datatable-error-message');
                if (errorMsg) {
                    errorMsg.style.opacity = '0';
                    errorMsg.style.transition = 'opacity 0.3s';
                    setTimeout(() => errorMsg.remove(), 300);
                }
            }, hideDelay);
        }
    },

    /**
     * 閸掓繂顫愰崠鏍€冮弽闂村瘜娴?
     */
    initBody: function (scope, options) {
        console.log('閸掓繂顫愰崠鏍€冮弽闂村瘜?', scope, options);
        const instance = this.getInstanceByScope(scope.replace('-body', ''));
        if (instance) {
            instance.bodyConfig = options;
            // 閸欘垯浜掗崷銊ㄧ箹闁插本鍧婇崝鐘恒€冮弽闂村瘜娴ｆ挾娈戦悧鐟扮暰閸掓繂顫愰崠鏍偓鏄忕帆
        }
    },

    /**
     * 閸掓繂顫愰崠鏍€冮弽鐓庣俺?
     */
    initFooter: function (scope, options) {
        console.log('閸掓繂顫愰崠鏍€冮弽鐓庣俺?', scope, options);
        const instance = this.getInstanceByScope(scope.replace('-footer', ''));
        if (instance) {
            instance.footerConfig = options;
            // 閸欘垯浜掗崷銊ㄧ箹闁插本鍧婇崝鐘恒€冮弽鐓庣俺闁劎娈戦悧鐟扮暰閸掓繂顫愰崠鏍偓鏄忕帆
        }
    },

    /**
     * 閸掓繂顫愰崠鏍€?
     */
    initHeader: function (scope, options) {
        console.log('閸掓繂顫愰崠鏍€?', scope, options);
        const instance = this.getInstanceByScope(scope.replace('-header', ''));
        if (instance) {
            instance.headerConfig = options;
            // 閸欘垯浜掗崷銊ㄧ箹闁插本鍧婇崝鐘恒€冩径瀵告畱閻楃懓鐣鹃崚婵嗩潗閸栨牠鈧槒绶?
        }
    },

    /**
     * 閸掓繂顫愰崠鏍箖濠娿倕娅?
     */
    initFilter: function (scope, options) {
        console.log('閸掓繂顫愰崠鏍箖濠娿倕娅?', scope, options);
        const instance = this.getInstanceByScope(scope.replace('-filter', ''));
        if (instance) {
            instance.filterConfig = options;
            // 閸欘垯浜掗崷銊ㄧ箹闁插本鍧婇崝鐘虹箖濠娿倕娅掗惃鍕鐎规艾鍨垫慨瀣闁槒绶?
        }
    },

    /**
     * 鐎涙顔岄柊宥囩枂瀵湱鐛ab閸掑洦宕查敍鍫ｅ殰鐎规矮绠焪-閸撳秶绱?
     */
    bindFieldConfigTabs: function (tableId) {
        var modal = document.getElementById('w-field-config-modal-' + tableId);
        if (!modal) return;
        var tabLinks = modal.querySelectorAll('.w-nav-link');
        tabLinks.forEach(function (link) {
            link.onclick = function () {
                // 閸欐牗绉烽幍鈧張濉糰b濠碘偓?
                tabLinks.forEach(function (l) { l.classList.remove('active'); });
                link.classList.add('active');
                // 閸掑洦宕查崘鍛啇?
                var target = link.getAttribute('data-w-target');
                var tabPanes = modal.querySelectorAll('.w-tab-pane');
                tabPanes.forEach(function (pane) {
                    pane.classList.remove('w-show', 'active');
                });
                var showPane = modal.querySelector(target);
                if (showPane) {
                    showPane.classList.add('w-show', 'active');
                }
            };
        });
    },

    // 娣囶喗鏁紀penFieldConfig閿涘苯鑴婄粣妤佸ⅵ瀵偓閺冨墎绮︾€规ab閸掑洦宕?
    openFieldConfig: function (tableId) {
        document.querySelectorAll('.w-modal').forEach(function (modal) {
            modal.style.display = 'none';
        });
        var modal = document.getElementById('w-field-config-modal-' + tableId);
        if (modal) {
            modal.style.display = 'flex';

            // 濡偓閺屻儲妲搁崥锕€鍑＄紒蹇旀箒缂傛挸鐡ㄩ惃鍕摟濞堝灚鏆熼幑?
            const instance = DataTableManager.getInstance(tableId);
            if (instance && instance.allFields && instance.allFields.length > 0) {
                console.log('openFieldConfig: 娴ｈ法鏁ょ紓鎾崇摠閻ㄥ嫬鐡у▓鍨殶閹?', {
                    allFields: instance.allFields.length,
                    displayFields: instance.displayFields.length,
                    filterFields: instance.filterFields.length
                });

                // 閻╁瓨甯村〒鍙夌厠缂傛挸鐡ㄩ惃鍕摟濞堝灚鏆熼幑顕嗙礉娑撳秷袝閸欐垼銆冮弽濂稿櫢閺傜増鐎?
                DataTableManager.renderModelFieldsFromData(tableId, {
                    all_fields: instance.allFields,
                    display_fields: instance.displayFields,
                    filter_fields: instance.filterFields
                });
            } else {
                // 閸欘亜婀€涙顔岀拋鍓х枂娑擃厽鐥呴張澶嬫殶閹诡喗妞傞幍宥呭鏉?
                console.log('openFieldConfig: 鐎涙顔岀拋鍓х枂娑擃厽鐥呴張澶嬫殶閹诡噯绱濆鈧慨瀣鏉?');
                DataTableManager.loadModelFieldsForConfig(tableId);
            }

            DataTableManager.bindFieldConfigTabs(tableId);
            
            // 绾喕绻氶幏鏍ㄥ閸旂喕鍏橀崷鈥昈M濞撳弶鐓嬬€瑰本鍨氶崥搴″灥婵瀵?
            setTimeout(function () {
                DataTableManager.initDragSort(tableId);
                var firstInput = modal.querySelector('input,select,textarea,button');
                if (firstInput) firstInput.focus();
            }, 200);
        }
    },

    /**
     * 閸忔娊妫寸€涙顔岄柊宥囩枂閼奉亜鐣炬稊澶婅剨缁愭绱檞-modal閿?
     */
    closeFieldConfig: function (tableId) {
        var modal = document.getElementById('w-field-config-modal-' + tableId);
        if (modal) {
            modal.style.display = 'none';
            // 閸忔娊妫撮弮鍫曞櫢缂冾喖濮炴潪鑺ョ垼鐠?
            delete modal.dataset.wFieldsLoaded;
        }
    },

    /**
     * 娑撴捇妫稉鍝勭摟濞堢敻鍘ょ純顔煎鏉炶姤膩閸ㄥ鐡у▓纰夌礄娑撳秳绱扮憴锕€褰傜悰銊︾壐闁插秵鏌婇弸鍕紦閿?
     */
    loadModelFieldsForConfig: function (tableId) {
        const instance = this.instances[tableId];
        if (!instance) return;
        if (!instance.apiUrl) {
            console.error('[DataTableManager] apiUrl閺堫亣顔曠純顕嗙礉閺冪姵纭堕崝鐘烘祰鐎涙顔岄柊宥囩枂');
            return;
        }

        // 1. 閹绘劕褰囧Ο鈩冩緲鐎涙顔岄敍鍧抜eld閹稿洤鐣剧€涙顔岄敍?
        const templateFields = this.extractFieldsFromDOM(tableId, 'display');
        const templateFilterFields = this.extractFieldsFromDOM(tableId, 'filter');
        console.log('loadModelFieldsForConfig: 濡剝婢樼€涙顔?', templateFields);
        console.log('loadModelFieldsForConfig: 濡剝婢樼粵娑⑩偓澶婄摟濞?', templateFilterFields);
        instance.templateFields = templateFields;
        instance.templateFilterFields = templateFilterFields;

        console.log('loadModelFieldsForConfig: 瀵偓婵濮炴潪钘夌摟濞堢敻鍘ょ純?', {
            tableId,
            model: instance.options.model,
            scope: instance.options.scope
        });

        // 閺勫墽銇歭oading
        const availableFields = document.getElementById('w-available-fields-' + tableId);
        const availableFieldsFilter = document.getElementById('w-available-fields-filter-' + tableId);
        if (availableFields) {
            availableFields.innerHTML = '<div class="w-text-center w-text-muted w-py-4"><i class="fas fa-spinner fa-spin"></i> ' + __("閸旂姾娴囨稉?..") + '</div>';
        }
        if (availableFieldsFilter) {
            availableFieldsFilter.innerHTML = '<div class="w-text-center w-text-muted w-py-4"><i class="fas fa-spinner fa-spin"></i> ' + __("閸旂姾娴囨稉?..") + '</div>';
        }

        this.requestJson(instance, 'fields', {
            table_id: tableId,
            model: instance.options.model,
            scope: instance.options.scope
        })
            .then(response => {
                // 3. 閸氬牆鑻熷Ο鈩冩緲鐎涙顔岄崪灞惧复閸欙絽鐡у▓纰夌礄閻劋绨?閸欘垳鏁ょ€涙顔?閸掓銆冮敍?
                let apiFields = (response.data && response.data.all_fields) ? response.data.all_fields : [];
                let mergedFields = this.mergeTemplateAndApiFields(templateFields, apiFields);
                // 閸氬牆鑻焒ilter鐎涙顔?
                let apiFilterFields = (response.data && response.data.filter_fields) ? response.data.filter_fields : [];
                let mergedFilterFields = this.mergeTemplateAndApiFields(templateFilterFields, apiFilterFields);

                // 4. 绾喖鐣鹃弰鍓с仛鐎涙顔岄敍姘喘閸忓牏楠囨稉?缂傛挸鐡ㄩ柊宥囩枂 > 濡剝婢樼€涙顔?> API姒涙顓荤€涙顔?
                let displayFields;
                const cachedDisplayFields = response.data.cached_display_fields;
                const templateFieldNames = new Set(templateFields.map(f => f.name));

                if (cachedDisplayFields && cachedDisplayFields.length > 0) {
                    // 閺堝绱︾€涙﹢鍘ょ純顕嗙礉娴ｈ法鏁ょ紓鎾崇摠闁板秶鐤嗛敍灞肩稻绾喕绻氬Ο鈩冩緲鐎涙顔岀仦鐐粹偓褌绱崗?
                    displayFields = cachedDisplayFields.map(cachedField => {
                        const templateField = templateFields.find(t => t.name === cachedField.name);
                        return templateField ? { ...cachedField, ...templateField } : cachedField;
                    });
                    console.log('loadModelFieldsForConfig: 娴ｈ法鏁ょ紓鎾崇摠闁板秶鐤?', displayFields);
                } else if (templateFields.length > 0) {
                    // 濞屸剝婀佺紓鎾崇摠闁板秶鐤嗛敍灞肩稻閺堝膩閺夊灝鐡у▓纰夌礉閸欘亝妯夌粈鐑樐侀弶鍨摟濞?
                    displayFields = [...templateFields];
                    console.log('loadModelFieldsForConfig: 娴ｈ法鏁ゅΟ鈩冩緲鐎涙顔岄敍鍫ョ帛鐠併倕褰ч弰鍓с仛濡剝婢樻稉顓熷瘹鐎规氨娈戠€涙顔岄敍?', displayFields);
                } else {
                    // 濞屸剝婀佺紓鎾崇摠闁板秶鐤嗘稊鐔哥梾閺堝膩閺夊灝鐡у▓纰夌礉娴ｈ法鏁PI姒涙顓荤€涙顔?
                    displayFields = response.data.display_fields || [];
                    console.log('loadModelFieldsForConfig: 娴ｈ法鏁PI姒涙顓荤€涙顔?', displayFields);
                }

                // 5. 鐠佹澘缍嶉悽銊﹀煕闁瀚ㄩ惃鍕摟濞堢绱欓棃鐐茨侀弶鍨摟濞堢绱?
                const userSelectedFields = displayFields.filter(field => !templateFieldNames.has(field.name));
                console.log('loadModelFieldsForConfig: 閻劍鍩涢柅澶嬪閻ㄥ嫬鐡у▓?', userSelectedFields);

                // 6. 婢跺嫮鎮婇崣妞剧箽閹躲倕鐡у▓鐢垫畱闁板秶鐤?
                displayFields = displayFields.map(field => {
                    const isProtected = this.isFieldProtected(field);
                    const isPrimaryOrIndex = field.is_primary === true || field.primary === true || field.primary_key === true || field.pk === true || ['id', 'ID', 'Id', 'primary', 'pk', 'primary_key', 'is_primary'].includes(field.name);
                    if (isProtected) {
                        // 娑撳鏁?缁便垹绱╃€涙顔屾稉宥堝厴閹烘帒绨崪宀€些閸?
                        if (isPrimaryOrIndex) {
                            return {
                                ...field,
                                sortable: false,
                                editable: field.editable === true || field.editable === 'true',
                                searchable: field.searchable !== false,
                                resizable: field.resizable !== false,
                                visible: field.visible !== false,
                                display_orderable: false
                            };
                        }
                        // 閸忚泛鐣犻崣妞剧箽閹躲倕鐡у▓鐢哥帛鐠併倕褰叉禒銉﹀笓鎼村繐鎷扮粔璇插З
                        return {
                            ...field,
                            sortable: field.sortable !== false && field.sortable !== 'false',
                            editable: field.editable === true || field.editable === 'true',
                            searchable: field.searchable !== false,
                            resizable: field.resizable !== false,
                            visible: field.visible !== false,
                            display_orderable: field.display_orderable !== false && field.display_orderable !== 0 && field.display_orderable !== 'false' && field.display_orderable !== '0'
                        };
                    }
                    return field;
                });

                // 7. 绾喕绻氶幐鍥х暰鐎涙顔岄幒鎺戝煂閸撳秹娼?
                const displayTemplateFields = displayFields.filter(field =>
                    field.template_defined || field.field_defined || field.from_field
                );
                const userFields = displayFields.filter(field =>
                    !field.template_defined && !field.field_defined && !field.from_field
                );

                // 闁插秵鏌婇幒鎺戠碍閿涙碍膩閺夊灝鐡у▓闈涙躬閸撳稄绱濋悽銊﹀煕鐎涙顔岄崷銊ユ倵
                displayFields = [...displayTemplateFields, ...userFields];

                // 8. 閸欘亝瑕嗛弻鎾崇摟濞堢敻鍘ょ純顔艰剨缁愭绱濇稉宥埿曢崣鎴ｃ€冮弽濂稿櫢閺傜増鐎?
                this.renderModelFieldsFromData(tableId, {
                    all_fields: mergedFields,
                    display_fields: displayFields,
                    filter_fields: mergedFilterFields
                });
            })
            .catch(error => {
                console.error('loadModelFieldsForConfig: 閸旂姾娴囩€涙顔岄柊宥囩枂婢惰精瑙?', error);
                const availableFields = document.getElementById('w-available-fields-' + tableId);
                const availableFieldsFilter = document.getElementById('w-available-fields-filter-' + tableId);
                if (availableFields) {
                    availableFields.innerHTML = '<div class="w-text-center w-text-danger w-py-4"><i class="fas fa-exclamation-triangle"></i> ' + __("閸旂姾娴囨径杈Е") + '</div>';
                }
                if (availableFieldsFilter) {
                    availableFieldsFilter.innerHTML = '<div class="w-text-center w-text-danger w-py-4"><i class="fas fa-exclamation-triangle"></i> ' + __("閸旂姾娴囨径杈Е") + '</div>';
                }
            });
    },

    // 閸氬牆鑻熷Ο鈩冩緲鐎涙顔岄崪灞惧复閸欙絽鐡у▓纰夌礉濡剝婢樼€涙顔屾导妯哄帥
    mergeTemplateAndApiFields: function (templateFields, apiFields) {
        const map = {};
        templateFields.forEach(f => map[f.name] = f);
        apiFields.forEach(f => {
            if (!map[f.name]) map[f.name] = f;
        });
        return Object.values(map);
    },

    /**
     * 鎼存梻鏁ょ€涙顔岄柊宥囩枂閸掓媽銆?
     */
    applyFieldsToTable: function (tableId) {
        const instance = this.instances[tableId];
        if (!instance) return;

        // 绾喕绻氬Ο鈩冩緲鐎涙顔岄崷銊ュ?
        const templateFields = instance.displayFields.filter(field =>
            field.template_defined || field.field_defined || field.from_field
        );
        const userFields = instance.displayFields.filter(field =>
            !field.template_defined && !field.field_defined && !field.from_field
        );

        // 闁插秵鏌婇幒鎺戠碍閿涙碍膩閺夊灝鐡у▓闈涙躬閸撳稄绱濋悽銊﹀煕鐎涙顔岄崷銊ユ倵
        const orderedDisplayFields = [...templateFields, ...userFields];

        // 鎼存梻鏁ょ€涙顔岄柊宥囩枂閸掓媽銆冮弽鐓庛仈?
        this.renderHeader(tableId, orderedDisplayFields);

        // 鎼存梻鏁ょ€涙顔岄柊宥囩枂閸掓壆鐡柅澶婂隘?
        this.renderFilter(tableId, instance.filterFields);

        // 閺囧瓨鏌婄€圭偘绶ユ稉顓犳畱鐎涙顔屾い鍝勭碍
        instance.displayFields = orderedDisplayFields;

        // 娣囨繂鐡ㄩ柊宥囩枂閸掓壆绱?
        this.saveFieldConfigToCache(tableId);
    },

    /**
     * 濞撳弶鐓嬬€涙顔岀猾璇茬€锋稉瀣
     */
    renderFieldTypeSelect: function (tableId, field, type) {
        const options = DataTableManager.fieldTypeOptions;
        const selectId = `w-field-type-select-${type}-${tableId}-${field.name}`;
        let html = `<select class="w-field-type-select w-btn-sm" id="${selectId}" data-table="${tableId}" data-field="${field.name}" data-type="${type}">`;
        options.forEach(opt => {
            html += `<option value="${opt.value}"${field.type === opt.value ? ' selected' : ''}>${opt.label}</option>`;
        });
        html += '</select>';
        return html;
    },

    /**
     * 鐎涙顔岀猾璇茬€锋稉瀣閸欐ɑ娲挎禍瀣╂
     */
    bindFieldTypeChange: function (tableId) {
        // 鐟欙絿绮﹂崘宥囩拨鐎规熬绱濋梼鍙夘剾闁插秴顦?
        const modal = document.getElementById('w-field-config-modal-' + tableId) || document;
        // 閸忓牏些闂勩倓绠ｉ崜宥囨畱娴滃娆?
        if (modal._fieldTypeChangeHandler) {
            modal.removeEventListener('change', modal._fieldTypeChangeHandler, true);
        }
        modal._fieldTypeChangeHandler = function (e) {
            const target = e.target;
            if (target.classList.contains('w-field-type-select')) {
                const tableId = target.dataset.table;
                const fieldName = target.dataset.field;
                const type = target.dataset.type;
                const value = target.value;
                const instance = DataTableManager.instances[tableId];
                if (!instance) return;
                let fieldList = type === 'display' ? instance.displayFields : instance.filterFields;
                const field = fieldList.find(f => f.name === fieldName);
                if (field) {
                    field.type = value;
                    // 閸欘亝娲块弬鏉跨唨閺堫兛淇婇幁顖涙▔缁€鐚寸礉娑撳秹鍣搁弬鐗堣閺屾挻鏆ｆ稉顏勫灙
                    const fieldItem = document.querySelector(`#w-${type}-fields-${tableId} .w-field-item[data-field="${fieldName}"]`);
                    if (fieldItem) {
                        const typeBadge = fieldItem.querySelector('.w-field-basic-info .w-field-type-badge');
                        if (typeBadge) {
                            typeBadge.textContent = value;
                        }
                    }
                }
            }
        };
        modal.addEventListener('change', modal._fieldTypeChangeHandler, true);
    },

    /**
     * 鐎涙顔宭abel/placeholder鏉堟挸鍙嗛崣妯绘纯娴滃娆?
     */
    bindFieldLabelInput: function (tableId) {
        const modal = document.getElementById('w-field-config-modal-' + tableId) || document;
        // 閸忓牏些闂勩倓绠ｉ崜宥囨畱娴滃娆?
        if (modal._fieldLabelInputHandler) {
            modal.removeEventListener('input', modal._fieldLabelInputHandler, true);
        }
        modal._fieldLabelInputHandler = function (e) {
            const target = e.target;
            if (target.classList.contains('w-field-label-input')) {
                const tableId = target.dataset.table;
                const fieldName = target.dataset.field;
                const type = target.dataset.type;
                const value = target.value;
                const instance = DataTableManager.instances[tableId];
                if (!instance) return;
                let fieldList = type === 'display' ? instance.displayFields : instance.filterFields;
                const field = fieldList.find(f => f.name === fieldName);
                if (field) {
                    field.label = value;
                    // 閸欘亝娲块弬鏉跨唨閺堫兛淇婇幁顖涙▔缁€鐚寸礉娑撳秹鍣搁弬鐗堣閺屾挻鏆ｆ稉顏勫灙
                    const fieldItem = document.querySelector(`#w-${type}-fields-${tableId} .w-field-item[data-field="${fieldName}"]`);
                    if (fieldItem) {
                        const fieldNameEl = fieldItem.querySelector('.w-field-basic-info .w-field-name');
                        if (fieldNameEl) {
                            fieldNameEl.textContent = value || field.name;
                        }
                    }
                }
            }
            // placeholder
            if (target.classList.contains('w-field-placeholder-input')) {
                const tableId = target.dataset.table;
                const fieldName = target.dataset.field;
                const type = target.dataset.type;
                const value = target.value;
                const instance = DataTableManager.instances[tableId];
                if (!instance) return;
                let fieldList = type === 'display' ? instance.displayFields : instance.filterFields;
                const field = fieldList.find(f => f.name === fieldName);
                if (field) {
                    field.placeholder = value;
                }
            }
        };
        modal.addEventListener('input', modal._fieldLabelInputHandler, true);
    },

    /**
     * 鐎涙顔岄弽锟犵崣鏉堟挸鍙嗛崣妯绘纯娴滃娆?
     */
    bindFieldValidationInput: function (tableId) {
        const modal = document.getElementById('w-field-config-modal-' + tableId) || document;
        if (modal._fieldValidationInputHandler) {
            modal.removeEventListener('input', modal._fieldValidationInputHandler, true);
        }
        modal._fieldValidationInputHandler = function (e) {
            const target = e.target;
            if (target.classList.contains('w-validation-min') || target.classList.contains('w-validation-max') || target.classList.contains('w-validation-pattern')) {
                const tableId = target.dataset.table;
                const fieldName = target.dataset.field;
                const type = target.dataset.type;
                const value = target.value;
                const instance = DataTableManager.instances[tableId];
                if (!instance) return;
                let fieldList = type === 'display' ? instance.displayFields : instance.filterFields;
                const field = fieldList.find(f => f.name === fieldName);
                if (field) {
                    if (!field.validation) field.validation = {};
                    if (target.classList.contains('w-validation-min')) field.validation.min = value;
                    if (target.classList.contains('w-validation-max')) field.validation.max = value;
                    if (target.classList.contains('w-validation-pattern')) field.validation.pattern = value;
                }
            }
        };
        modal.addEventListener('input', modal._fieldValidationInputHandler, true);
    },

    /**
     * 娴犲孩鏆熼幑顔借閺屾挻膩閸ㄥ鐡у▓纰夌礄闁倿鍘-閸撳秶绱慶lass/id?
     */
    renderModelFieldsFromData: function (tableId, data) {
        const instance = this.instances[tableId];
        if (!instance) return;

        const $availableFields = $('#w-available-fields-' + tableId);
        const $availableFieldsFilter = $('#w-available-fields-filter-' + tableId);
        const $displayFields = $('#w-display-fields-' + tableId);
        const $filterFields = $('#w-filter-fields-' + tableId);

        // 婢跺嫮鎮婇弫鐗堝祦缂佹挻鐎?
        let allFields = [];
        let displayFields;
        let filterFields;
        if (data && typeof data === 'object') {
            allFields = data.all_fields || data.fields || [];
            // 娴兼ê鍘涢悽銊﹀复閸欙綀绻戦崶鐐垫畱display_fields/filter_fields閿涘牆宓嗘担澶歌礋缁岃桨绡冮悽顭掔礆
            if ('display_fields' in data) {
                displayFields = data.display_fields || [];
                console.log('renderModelFieldsFromData: 閹恒儱褰涙潻鏂挎礀display_fields', displayFields);
            }
            if ('filter_fields' in data) {
                filterFields = data.filter_fields || [];
                console.log('renderModelFieldsFromData: 閹恒儱褰涙潻鏂挎礀filter_fields', filterFields);
            }
        }

        // 婵″倹鐏夊▽鈩冩箒鏉╂柨娲栭敍灞煎▏閻劑绮拋銈夆偓鏄忕帆
        if (typeof displayFields === 'undefined') {
            displayFields = this.getDefaultDisplayFields(allFields);
            console.log('renderModelFieldsFromData: 娴ｈ法鏁ゆ妯款吇displayFields', displayFields);
        }
        if (typeof filterFields === 'undefined') {
            filterFields = [];
            console.log('renderModelFieldsFromData: 娴ｈ法鏁ゆ妯款吇filterFields', filterFields);
        }

        // 娣囨繂鐡ㄩ崚鏉跨杽娓氬鑵戦敍灞肩返閸氬海鐢绘担璺ㄦ暏
        instance.allFields = allFields;
        instance.displayFields = displayFields;
        instance.filterFields = filterFields;

        console.log('renderModelFieldsFromData: 閺堚偓缂佸牊鏆熼幑?', {
            allFields: allFields.length,
            displayFields: displayFields.length,
            filterFields: filterFields.length
        });

        // 鐠侊紕鐣婚崣顖滄暏鐎涙顔岄敍鍫濆瀻閸掝偉顓哥粻妞捐⒈娑撶尲ab閻ㄥ嫬褰查悽銊ョ摟濞堢绱?
        // displayFields: 濡剝婢樼€涙顔?閻劍鍩涢柊宥囩枂鐎涙顔?
        // allFields: 閹恒儱褰涙潻鏂挎礀閻ㄥ嫭澧嶉張澶婄摟濞?
        const displayFieldNames = new Set(displayFields.map(f => f.name));
        const availableFieldsForDisplay = allFields.filter(field => !displayFieldNames.has(field.name));

        // 閸欐ぞ绻氶幎銈呯摟濞堥潧鐣炬稊?
        function isProtectedField(field) {
            return DataTableManager.isFieldProtected(field) || DataTableManager.isPrimaryOrIndexField(field);
        }

        const filterFieldNames = new Set(filterFields.map(f => f.name));
        // 閸欘垳鏁ょ粵娑⑩偓澶婄摟濞堢绱伴幒鎺楁珟閸欐ぞ绻氶幎銈呯摟濞?
        const availableFieldsForFilter = allFields.filter(field => !filterFieldNames.has(field.name) && !isProtectedField(field));

        // 閸欐ぞ绻氶幎銈呯摟濞堥潧绨叉慨瀣矒閸︺劌鍑￠柅澶岀摣闁鐡у▓鍏歌厬
        // 鏉╁洦鎶ら崙鐑樺閺堝褰堟穱婵囧Б鐎涙顔?
        const protectedFilterFields = allFields.filter(field => isProtectedField(field));
        // 閸氬牆鑻熼敍姘綀娣囨繃濮㈢€涙顔?+ 閸忚泛鐣犲鏌モ偓澶婄摟濞堢绱欓崢濠氬櫢閿?
        const filterFieldsNoProtected = filterFields.filter(f => !isProtectedField(f));
        const finalFilterFields = [...protectedFilterFields, ...filterFieldsNoProtected.filter(f => !protectedFilterFields.some(pf => pf.name === f.name))];
        instance.filterFields = finalFilterFields;

        console.log('renderModelFieldsFromData: 閸欘垳鏁ょ€涙顔岀拋锛勭暬', {
            availableFieldsForDisplay: availableFieldsForDisplay.length,
            availableFieldsForFilter: availableFieldsForFilter.length
        });

        // 濞撳弶鐓嬬€涙顔岄弶锛勬窗閺冭绱濈亸鍡樺閺堝鐫橀幀褑顔曠純顔昏礋data-鐏?
        function getFieldDataAttrs(field) {
            let attrs = '';
            for (const key in field) {
                if (Object.prototype.hasOwnProperty.call(field, key) && field[key] !== undefined) {
                    // 鐏忓棝鈹樺畡鎷屾祮娑撹桨鑵戦崚鎺斿殠
                    const dataKey = key.replace(/([A-Z])/g, '-$1').toLowerCase();
                    attrs += ` data-${dataKey}="${String(field[key]).replace(/"/g, '&quot;')}"`;
                }
            }
            return attrs;
        }

        // 濞撳弶鐓嬮崚妤勵啎缂冪晧ab閻ㄥ嫬褰查悽銊ョ摟濞?
        let availableHtmlForDisplay = '';
        if (availableFieldsForDisplay.length > 0) {
            availableFieldsForDisplay.forEach(field => {
                const isProtected = this.isFieldProtected(field);
                const disabledAttr = isProtected ? 'disabled' : '';
                const disabledClass = isProtected ? 'disabled' : '';
                const protectionBadge = isProtected ? '<span class="w-badge w-badge-protected">' + __("閸欐ぞ绻氶幎?") + '</span>' : '';

                availableHtmlForDisplay += `
    <div class="w-field-item ${disabledClass}" data-field="${field.name}" ${getFieldDataAttrs(field)}>
        <div class="w-field-info">
            <span class="w-field-name">${field.label || field.name}</span>
            <small class="w-text-muted">${field.name}</small>
            <span class="w-field-type-badge">${field.type || 'text'}</span>
            ${protectionBadge}
        </div>
        <div class="w-field-actions">
            <button type="button" class="w-btn w-btn-sm w-btn-outline-primary" 
                    data-datatable-action="add-field" data-table="${tableId}" data-field="${field.name}" data-field-type="display"
                    ${disabledAttr}>
                <i class="fas fa-table"></i> ${__("閺勫墽銇?")}
            </button>
        </div>
    </div>`;
            });
        } else {
            availableHtmlForDisplay = `
    <div class="w-text-center w-text-muted w-py-4">
        <i class="fas fa-info-circle"></i> ${__("閹碘偓閺堝鐡у▓鐢稿厴瀹告煡鍘ょ純?")}
    </div>`;
        }

        // 濞撳弶鐓嬬粵娑⑩偓澶庮啎缂冪晧ab閻ㄥ嫬褰查悽銊ョ摟濞?
        let availableHtmlForFilter = '';
        if (availableFieldsForFilter.length > 0) {
            availableFieldsForFilter.forEach(field => {
                const isProtected = isProtectedField(field);
                const disabledAttr = isProtected ? 'disabled' : '';
                const disabledClass = isProtected ? 'disabled' : '';
                const protectionBadge = isProtected ? '<span class="w-badge w-badge-protected">' + __("閸欐ぞ绻氶幎?") + '</span>' : '';

                availableHtmlForFilter += `
    <div class="w-field-item ${disabledClass}" data-field="${field.name}" ${getFieldDataAttrs(field)}>
        <div class="w-field-info">
            <span class="w-field-name">${field.label || field.name}</span>
            <small class="w-text-muted">${field.name}</small>
            <span class="w-field-type-badge">${field.type || 'text'}</span>
            ${protectionBadge}
        </div>
        <div class="w-field-actions">
            <button type="button" class="w-btn w-btn-sm w-btn-outline-success" 
                    data-datatable-action="add-field" data-table="${tableId}" data-field="${field.name}" data-field-type="filter"
                    ${disabledAttr}>
                <i class="fas fa-filter"></i> ${__("缁涙盯鈧?")}
            </button>
        </div>
    </div>`;
            });
        } else {
            availableHtmlForFilter = `
    <div class="w-text-center w-text-muted w-py-4">
        <i class="fas fa-info-circle"></i> ${__("閹碘偓閺堝鐡у▓鐢稿厴瀹告煡鍘ょ純?")}
    </div>`;
        }

        // 閸掑棗鍩嗛弴瀛樻煀娑撱倓閲滈崣顖滄暏鐎涙顔岄崠鍝勭厵
        var availableFieldsEl = document.getElementById('w-available-fields-' + tableId);
        if (availableFieldsEl) availableFieldsEl.innerHTML = availableHtmlForDisplay;
        var availableFieldsFilterEl = document.getElementById('w-available-fields-filter-' + tableId);
        if (availableFieldsFilterEl) availableFieldsFilterEl.innerHTML = availableHtmlForFilter;

        // 濞撳弶鐓嬮弰鍓с仛鐎涙顔?
        let displayHtml = '';
        if (displayFields.length > 0) {
            console.log('renderModelFieldsFromData: 瀵偓婵瑕嗛弻鎾存▔缁€鍝勭摟濞?', displayFields);
            displayFields.forEach((field, index) => {
                console.log('renderModelFieldsFromData: 濞撳弶鐓嬮弰鍓с仛鐎涙顔?', index, field);
                const isProtected = this.isFieldProtected(field);
                const isFromScope = field.from_scope === true;
                const isTemplateField = field.field_defined === true || field.template_defined === true || field.from_field === true;
                const isUserSelected = field.user_selected === true;
                const disabledAttr = isProtected ? 'disabled' : '';
                const disabledClass = isProtected ? 'disabled' : '';
                const protectionBadge = isProtected ? '<span class="w-badge w-badge-protected">' + __("閸欐ぞ绻氶幎?") + '</span>' : '';
                const scopeBadge = isFromScope ? '<span class="w-badge" style="background:#bbf7d0;color:#166534;">' + __("瀹歌弓绻氱€?") + '</span>' : '';
                const userSelectedBadge = isUserSelected ? '<span class="w-badge" style="background:#dbeafe;color:#1e40af;">' + __("閻劍鍩涢柅澶嬪") + '</span>' : '';
                let validationHtml = '';

                if (field.validation) {
                    const validation = field.validation;
                    validationHtml = `
                    <div class="w-validation-settings">
                        <input class="w-validation-min w-btn-sm" type="number" value="${validation.min || ''}" data-table="${tableId}" data-field="${field.name}" data-type="display" placeholder="${__("閺堚偓鐏忓繘鏆辨惔?")}" style="width:80px;" />
                        <input class="w-validation-max w-btn-sm" type="number" value="${validation.max || ''}" data-table="${tableId}" data-field="${field.name}" data-type="display" placeholder="${__("閺堚偓婢堆囨毐鎼?")}" style="width:80px;" />
                        <input class="w-validation-pattern w-btn-sm" type="text" value="${validation.pattern || ''}" data-table="${tableId}" data-field="${field.name}" data-type="display" placeholder="${__("濮濓絽鍨悰銊ㄦ彧瀵?")}" style="width:120px;" />
                    </div>`;
                }

                // 娑撳鏁?缁便垹绱╃€涙顔屾稉宥堝厴闂呮劘妫?
                const isPrimaryOrIndex = DataTableManager.isPrimaryOrIndexField(field);
                // 鐎甸€涚艾濡剝婢樼€涙顔岄崪灞煎瘜闁?缁便垹绱╃€涙顔岄敍灞肩瑝閺勫墽銇氶梾鎰閹稿鎸?
                const hideButtonHtml = (isTemplateField || isPrimaryOrIndex) ? '' : `
            <button type="button" class="w-btn w-btn-sm w-btn-outline-danger" 
                    data-datatable-action="remove-field" data-table="${tableId}" data-field="${field.name}" data-field-type="display"
                    ${disabledAttr}>
                <i class="fas fa-eye-slash"></i> ${__("闂呮劘妫?")}
            </button>`;
                // 娑撳鏁?缁便垹绱╃€涙顔屾稉宥堝厴缁夎濮?
                const canMove = field.display_orderable !== false && field.display_orderable !== 'false' && field.display_orderable !== 0 && field.display_orderable !== '0';
                const moveUpButtonHtml = canMove ? `
            <button type="button" class="w-btn w-btn-sm w-btn-outline-secondary" 
                    data-datatable-action="move-field" data-table="${tableId}" data-field="${field.name}" data-direction="up" data-field-type="display"
                    ${index === 0 ? 'disabled' : ''}>
                <i class="fas fa-arrow-up"></i> ${__("娑撳﹦些")}
            </button>` : '';
                const moveDownButtonHtml = canMove ? `
            <button type="button" class="w-btn w-btn-sm w-btn-outline-secondary" 
                    data-datatable-action="move-field" data-table="${tableId}" data-field="${field.name}" data-direction="down" data-field-type="display"
                    ${index === displayFields.length - 1 ? 'disabled' : ''}>
                <i class="fas fa-arrow-down"></i> ${__("娑撳些")}
            </button>` : '';

                displayHtml += `
    <div class="w-field-item ${disabledClass}" data-field="${field.name}" ${getFieldDataAttrs(field)}>
        <div class="w-field-info" style="flex:1;min-width:0;">
            <div class="w-field-basic-info">
                <small class="w-text-muted">${field.name}</small>
                <span class="w-field-name">${field.label || field.name}</span>
                <span class="w-field-type-badge">${field.type || 'text'}</span>
                <div class="w-field-badges">
                    ${protectionBadge}
                    ${scopeBadge}
                    ${userSelectedBadge}
                </div>
            </div>
            <div class="w-field-detail-config" style="display:none;margin-top:8px;padding-top:8px;border-top:1px solid var(--datatable-border);">
                <input class="w-field-label-input w-btn-sm" type="text" value="${field.label || field.name}" data-table="${tableId}" data-field="${field.name}" data-type="display" placeholder="${__("鐎涙顔岄弽鍥暯")}" style="margin-bottom:4px;max-width:120px;" ${isProtected ? 'disabled' : ''} />
                <span class="w-field-type-badge">${isProtected ? field.type || 'text' : DataTableManager.renderFieldTypeSelect(tableId, field, 'display')}</span>
                <input class="w-field-placeholder-input w-btn-sm" type="text" value="${field.placeholder || ''}" data-table="${tableId}" data-field="${field.name}" data-type="display" placeholder="${__("閸楃姳缍呯粭锔肩礄閸欘垶鈧绱?")}" style="margin-top:4px;max-width:120px;" ${isProtected ? 'disabled' : ''} />
                ${field.type === 'select' ? `<input class="w-field-options-input w-btn-sm" type="text" value="${field.options || ''}" data-table="${tableId}" data-field="${field.name}" data-type="display" placeholder="${__("闁銆??:閸氼垳鏁?0:缁備胶鏁?")}" style="margin-top:4px;max-width:120px;" ${isProtected ? 'disabled' : ''} />` : ''}
                ${validationHtml}
            </div>
        </div>
        <div class="w-field-actions" style="flex-direction:column;gap:6px;align-items:flex-end;min-width:70px;">
            <button type="button" class="w-btn w-btn-sm w-btn-outline-secondary w-btn-toggle-config" 
                    data-datatable-action="toggle-field-config" data-table="${tableId}" data-field="${field.name}" data-field-type="display"
                    data-type="display" ${isProtected ? 'disabled' : ''}>
                <i class="fas fa-cog"></i> ${__("鐠佸墽鐤?")}
            </button>
            ${hideButtonHtml}
            ${moveUpButtonHtml}
            ${moveDownButtonHtml}
        </div>
    </div>`;
            });
        } else {
            displayHtml = `
    <div class="w-text-center w-text-muted w-py-4">
        <i class="fas fa-info-circle"></i> ${__("閺嗗倹妫ら弰鍓с仛鐎涙顔?")}
        <br><small>${__("閹劌褰叉禒銉ユ躬閸欏厖鏅剁拫鍐╂殻鐎涙顔岄柊宥囩枂")}</small>
    </div>`;
        }
        var displayFieldsEl = document.getElementById('w-display-fields-' + tableId);
        if (displayFieldsEl) displayFieldsEl.innerHTML = displayHtml;

        // 濞撳弶鐓嬬粵娑⑩偓澶婄摟濞?
        let filterHtml = '';
        if (finalFilterFields.length > 0) {
            console.log('renderModelFieldsFromData: 瀵偓婵瑕嗛弻鎾剁摣闁鐡у▓?', finalFilterFields);
            finalFilterFields.forEach((field, index) => {
                console.log('renderModelFieldsFromData: 濞撳弶鐓嬬粵娑⑩偓澶婄摟濞?', index, field);
                const isProtected = isProtectedField(field);
                const isFromScope = field.from_scope === true;
                const isTemplateField = field.field_defined === true || field.template_defined === true || field.from_field === true;
                const isUserSelected = field.user_selected === true;
                const disabledAttr = isProtected ? 'disabled' : '';
                const disabledClass = isProtected ? 'disabled' : '';
                const protectionBadge = isProtected ? '<span class="w-badge w-badge-protected">' + __("閸欐ぞ绻氶幎?") + '</span>' : '';
                const scopeBadge = isFromScope ? '<span class="w-badge" style="background:#bbf7d0;color:#166534;">' + __("瀹歌弓绻氱€?") + '</span>' : '';
                const userSelectedBadge = isUserSelected ? '<span class="w-badge" style="background:#dbeafe;color:#1e40af;">' + __("閻劍鍩涢柅澶嬪") + '</span>' : '';
                let validationHtml = '';

                if (field.validation) {
                    const validation = field.validation;
                    validationHtml = `
                    <div class="w-validation-settings">
                        <input class="w-validation-min w-btn-sm" type="number" value="${validation.min || ''}" data-table="${tableId}" data-field="${field.name}" data-type="filter" placeholder="${__("閺堚偓鐏忓繘鏆辨惔?")}" style="width:80px;" />
                        <input class="w-validation-max w-btn-sm" type="number" value="${validation.max || ''}" data-table="${tableId}" data-field="${field.name}" data-type="filter" placeholder="${__("閺堚偓婢堆囨毐鎼?")}" style="width:80px;" />
                        <input class="w-validation-pattern w-btn-sm" type="text" value="${validation.pattern || ''}" data-table="${tableId}" data-field="${field.name}" data-type="filter" placeholder="${__("濮濓絽鍨悰銊ㄦ彧瀵?")}" style="width:120px;" />
                    </div>`;
                }

                // 閸欐ぞ绻氶幎銈呯摟濞堝吀绗夐弰鍓с仛缁夊娅庨幐澶愭尦
                const removeButtonHtml = isProtected ? '' : `
            <button type="button" class="w-btn w-btn-sm w-btn-outline-danger" 
                    data-datatable-action="remove-field" data-table="${tableId}" data-field="${field.name}" data-field-type="filter"
                    ${disabledAttr}>
                <i class="fas fa-eye-slash"></i> ${__("缁夊娅?")}
            </button>`;

                // 缁涙盯鈧鐡у▓鐢垫畱缁夎濮╅幐澶愭尦
                const canMove = field.filter_orderable !== false && field.filter_orderable !== 'false' && field.filter_orderable !== 0 && field.filter_orderable !== '0';
                const moveUpButtonHtml = canMove ? `
            <button type="button" class="w-btn w-btn-sm w-btn-outline-secondary" 
                    data-datatable-action="move-field" data-table="${tableId}" data-field="${field.name}" data-direction="up" data-field-type="filter"
                    ${index === 0 ? 'disabled' : ''}>
                <i class="fas fa-arrow-up"></i> ${__("娑撳﹦些")}
            </button>` : '';
                const moveDownButtonHtml = canMove ? `
            <button type="button" class="w-btn w-btn-sm w-btn-outline-secondary" 
                    data-datatable-action="move-field" data-table="${tableId}" data-field="${field.name}" data-direction="down" data-field-type="filter"
                    ${index === finalFilterFields.length - 1 ? 'disabled' : ''}>
                <i class="fas fa-arrow-down"></i> ${__("娑撳些")}
            </button>` : '';

                filterHtml += `
    <div class="w-field-item ${disabledClass}" data-field="${field.name}" ${getFieldDataAttrs(field)}>
        <div class="w-field-info" style="flex:1;min-width:0;">
            <div class="w-field-basic-info">
                <small class="w-text-muted">${field.name}</small>
                <span class="w-field-name">${field.label || field.name}</span>
                <span class="w-field-type-badge">${field.type || 'text'}</span>
                <div class="w-field-badges">
                    ${protectionBadge}
                    ${scopeBadge}
                    ${userSelectedBadge}
                </div>
            </div>
            <div class="w-field-detail-config" style="display:none;margin-top:8px;padding-top:8px;border-top:1px solid var(--datatable-border);">
                <input class="w-field-label-input w-btn-sm" type="text" value="${field.label || field.name}" data-table="${tableId}" data-field="${field.name}" data-type="filter" placeholder="${__("鐎涙顔岄弽鍥暯")}" style="margin-bottom:4px;max-width:120px;" ${isProtected ? 'disabled' : ''} />
                <span class="w-field-type-badge">${isProtected ? field.type || 'text' : DataTableManager.renderFieldTypeSelect(tableId, field, 'filter')}</span>
                <input class="w-field-placeholder-input w-btn-sm" type="text" value="${field.placeholder || ''}" data-table="${tableId}" data-field="${field.name}" data-type="filter" placeholder="${__("閸楃姳缍呯粭锔肩礄閸欘垶鈧绱?")}" style="margin-top:4px;max-width:120px;" ${isProtected ? 'disabled' : ''} />
                ${field.type === 'select' ? `<input class="w-field-options-input w-btn-sm" type="text" value="${field.options || ''}" data-table="${tableId}" data-field="${field.name}" data-type="filter" placeholder="${__("闁銆?閸?閺嶅洨顒?閸?閺嶅洨顒?")}" style="margin-top:4px;max-width:120px;" ${isProtected ? 'disabled' : ''} />` : ''}
                ${validationHtml}
            </div>
        </div>
        <div class="w-field-actions" style="flex-direction:column;gap:6px;align-items:flex-end;min-width:70px;">
            <button type="button" class="w-btn w-btn-sm w-btn-outline-secondary w-btn-toggle-config" 
                    data-datatable-action="toggle-field-config" data-table="${tableId}" data-field="${field.name}" data-field-type="filter"
                    data-type="filter" ${isProtected ? 'disabled' : ''}>
                <i class="fas fa-cog"></i> ${__("鐠佸墽鐤?")}
            </button>
            ${removeButtonHtml}
            ${moveUpButtonHtml}
            ${moveDownButtonHtml}
        </div>
    </div>`;
            });
        } else {
            filterHtml = `
    <div class="w-text-center w-text-muted w-py-4">
        <i class="fas fa-info-circle"></i> ${__("閺嗗倹妫ょ粵娑⑩偓澶婄摟濞?")}
        <br><small>${__("閹劌褰叉禒銉ユ躬閸欏厖鏅剁拫鍐╂殻鐎涙顔岄柊宥囩枂")}</small>
    </div>`;
        }
        var filterFieldsEl = document.getElementById('w-filter-fields-' + tableId);
        if (filterFieldsEl) filterFieldsEl.innerHTML = filterHtml;

        // 缂佹垵鐣炬禍瀣╂
        this.bindFieldEvents(tableId);

        // 閸掓繂顫愰崠鏍ㄥ珛閹疯姤甯撴惔?
        this.initDragSort(tableId);
    },

    /**
     * 闁插秶鐤嗘稉娲帛鐠併倕鐡у▓鐢稿帳?
     */
    resetToDefault: async function (tableId) {
        const instance = this.instances[tableId];
        if (!instance) return;

        if (typeof BackendConfirm === 'undefined') {
            console.warn('BackendConfirm is missing');
            return;
        }

        const confirmed = await BackendConfirm.show(__('绾喖鐣剧憰渚€鍣哥純顔昏礋姒涙顓荤€涙顔岄柊宥囩枂閸氭绱垫潻娆忕殺閺勫墽銇氶幍鈧張澶婂讲閻劌鐡у▓纰夌吹'), { type: 'warning' });
        if (confirmed) {
            // 濞撳懘娅庣紓鎾崇摠
            const cacheKey = `datatable_fields_${tableId}_${instance.options.model}_${instance.options.scope}`;
            localStorage.removeItem(cacheKey);
            // 闁插秵鏌婇崝鐘烘祰鐎涙顔岄弫鐗堝祦
            this.loadModelFields(tableId);
        }
    },

    /**
     * 濞ｈ濮炵€涙顔岄崚鐗堟▔缁€鐑樺灗缁涙盯鈧鍨?
     */
    addField: function (tableId, fieldName, type) {
        const instance = this.instances[tableId];
        if (!instance) return;

        const field = instance.allFields.find(f => f.name === fieldName);
        if (!field) return;

        let targetList = type === 'display' ? instance.displayFields : instance.filterFields;
        const existingIndex = targetList.findIndex(f => f.name === fieldName);
        if (existingIndex !== -1) return;

        // 閺嶅洩顔囨稉铏规暏閹寸兘鈧瀚ㄩ惃鍕摟?
        const fieldToAdd = {
            ...field,
            user_selected: true,
            sortable: field.sortable !== false,
            editable: field.editable !== false,
            searchable: field.searchable !== false,
            resizable: field.resizable !== false,
            visible: field.visible !== false
        };

        if (type === 'display') {
            // 鐎甸€涚艾閺勫墽銇氱€涙顔岄敍宀€鏁ら幋鐑解偓澶嬪閻ㄥ嫬鐡у▓闈涚安鐠囥儲褰冮崗銉ュ煂濡剝婢樼€涙顔屾稊瀣倵
            const templateFieldCount = instance.displayFields.filter(f =>
                f.template_defined || f.field_defined || f.from_field
            ).length;
            instance.displayFields.splice(templateFieldCount, 0, fieldToAdd);
        } else {
            // 鐎甸€涚艾缁涙盯鈧鐡у▓纰夌礉閻╁瓨甯村ǎ璇插閸掔増婀?
            instance.filterFields.push(fieldToAdd);
        }

        // 娣囨繂鐡ㄩ柊宥囩枂閸掓壆绱?
        this.saveFieldConfigToCache(tableId);

        this.renderModelFieldsFromData(tableId, {
            all_fields: instance.allFields,
            display_fields: instance.displayFields,
            filter_fields: instance.filterFields
        });
    },

    /**
     * 娴犲孩妯夌粈鍝勫灙鐞涖劍鍨ㄧ粵娑⑩偓澶婂灙鐞涖劎些闂勩倕鐡?
     */
    removeField: function (tableId, fieldName, type) {
        const instance = this.instances[tableId];
        if (!instance) return;
        let fieldList = type === 'display' ? instance.displayFields : instance.filterFields;
        const idx = fieldList.findIndex(f => f.name === fieldName);
        if (idx !== -1) {
            const field = fieldList[idx];
            // 濡偓閺屻儱鐡у▓鍨Ц閸氾箑褰堟穱婵囧Б
            if (this.isFieldProtected(field)) {
                console.warn('removeField: 鐏忔繆鐦崚鐘绘珟閸欐ぞ绻氶幎銈囨畱鐎涙顔?', fieldName);
                return;
            }
            // 妫版繂顦诲Λ鈧弻銉︽Ц閸氾缚璐熷Ο鈩冩緲鐎涙顔?
            const isTemplateField = field.field_defined === true || field.template_defined === true || field.from_field === true;
            if (isTemplateField) {
                console.warn('removeField: 鐏忔繆鐦崚鐘绘珟濡剝婢樼€涙顔?', fieldName);
                return;
            }
            fieldList.splice(idx, 1);
            this.renderModelFieldsFromData(tableId, {
                all_fields: instance.allFields,
                display_fields: instance.displayFields,
                filter_fields: instance.filterFields
            });
        }
    },

    /**
     * 缁夎濮╃€涙顔屾担宥囩枂
     */
    moveField: function (tableId, fieldName, direction, type) {
        const instance = this.instances[tableId];
        if (!instance) return;
        let fieldList = type === 'display' ? instance.displayFields : instance.filterFields;
        const idx = fieldList.findIndex(f => f.name === fieldName);
        if (idx === -1) return;

        const field = fieldList[idx];
        const isPrimary = DataTableManager.isPrimaryOrIndexField(field);
        // 閸欘亝婀侀弰搴ｂ€樼拋鍓х枂display_orderable娑撶alse閻ㄥ嫬鐡у▓闈涙嫲娑撳鏁€涙顔岄幍宥勭瑝閸忎浇顔忕粔璇插З
        const canMove = !isPrimary && (field.display_orderable !== false && field.display_orderable !== 'false' && field.display_orderable !== 0 && field.display_orderable !== '0');

        if (!canMove) {
            console.warn('moveField: 鐎涙顔屾稉宥呭帒鐠佸摜些閸?', fieldName);
            return;
        }

        let newIdx = direction === 'up' ? idx - 1 : idx + 1;
        if (newIdx < 0 || newIdx >= fieldList.length) return;

        // 濡偓閺屻儳娲伴弽鍥︾秴缂冾喖鐡у▓鍨Ц閸氾箑鍘戠拋鍝バ╅崝?
        const targetField = fieldList[newIdx];
        const targetIsPrimary = DataTableManager.isPrimaryOrIndexField(targetField);
        const targetCanMove = !targetIsPrimary && (targetField.display_orderable !== false && targetField.display_orderable !== 'false' && targetField.display_orderable !== 0 && targetField.display_orderable !== '0');

        if (!targetCanMove) {
            console.warn('moveField: 閻╊喗鐖ｆ担宥囩枂鐎涙顔屾稉宥呭帒鐠佸摜些閸?', targetField.name);
            return;
        }

        // 閹笛嗩攽缁夎濮?
        const temp = fieldList[idx];
        fieldList.splice(idx, 1);
        fieldList.splice(newIdx, 0, temp);

        // 娣囨繂鐡ㄩ柊宥囩枂閸掓壆绱︾€?
        this.saveFieldConfigToCache(tableId);

        this.renderModelFieldsFromData(tableId, {
            all_fields: instance.allFields,
            display_fields: instance.displayFields,
            filter_fields: instance.filterFields
        });
    },

    /**
     * 娣囨繂鐡ㄧ€涙顔岄柊宥囩枂閸掓壆绱?
     */
    saveFieldConfigToCache: function (tableId) {
        const instance = this.instances[tableId];
        if (!instance) return;
        // 瀵搫鍩楁稉濠氭暛/缁便垹绱╃€涙顔屾慨瀣矒鐎涙ê婀禍搴㈡▔缁€鍝勭摟濞堥潧鍨?
        const allPrimaryOrIndexFields = (instance.allFields || []).filter(DataTableManager.isPrimaryOrIndexField);
        allPrimaryOrIndexFields.forEach(pkField => {
            if (!instance.displayFields.some(f => f.name === pkField.name)) {
                instance.displayFields.unshift(pkField);
            }
        });
        const cacheKey = `datatable_fields_${tableId}_${instance.options.model}_${instance.options.scope}`;
        const configData = {
            all_fields: instance.allFields,
            display_fields: instance.displayFields,
            filter_fields: instance.filterFields
        };
        localStorage.setItem(cacheKey, JSON.stringify(configData));

        console.log('鐎涙顔岄柊宥囩枂瀹歌尪鍤滈崝銊ょ箽鐎涙ê鍩岀紓鎾崇摠:', configData);
    },

    /**
     * 閸掗攱鏌婇弫鐗堝祦
     */
    refreshData: function (tableId) {
        const instance = this.getInstance(tableId);
        
        if (instance) {
            this.loadData(instance);
        } else {
            console.error('DataTable instance not found for:', tableId, 'Available instances:', Object.keys(this.instances));
        }
    },

    /**
     * 閸掑洦宕叉妯奸獓鏉╁洦鎶?
     */
    toggleAdvancedFilter: function (scope) {
        const instance = this.getInstanceByScope(scope);
        if (instance) {
            const $filter = instance.container.find('.datatable-filter');
            $filter.find('.advanced-filters').toggle();
        }
    },

    /**
     * 鐠哄疇娴嗛崚鐗堝瘹鐎规岸銆?
     */
    goToPage: function (scope, page) {
        const instance = this.getInstanceByScope(scope.replace('-footer', ''));
        if (instance) {
            if (page === 'prev') {
                page = Math.max(1, instance.currentPage - 1);
            } else if (page === 'next') {
                page = Math.min(instance.pagination.lastPage, instance.currentPage + 1);
            } else if (page === 'last') {
                page = instance.pagination.lastPage;
            }

            if (page !== instance.currentPage) {
                instance.currentPage = page;
                this.loadData(instance);
            }
        }
    },

    /**
     * 閺€鐟板綁濮ｅ繘銆夐弰鍓с仛閺佷即鍣?
     */
    changePageSize: function (scope, pageSize) {
        const instance = this.getInstanceByScope(scope.replace('-footer', ''));
        if (instance) {
            instance.pageSize = parseInt(pageSize);
            instance.currentPage = 1;
            this.loadData(instance);
        }
    },

    /**
     * 娣囨繂鐡ㄧ€涙顔岄柊宥囩枂
     */
    saveFieldConfig: function (tableId) {
        const instance = this.getInstance(tableId);
        if (!instance) return;

        const displayFields = instance.displayFields || [];
        const filterFields = instance.filterFields || [];

        console.log('saveFieldConfig: 娣囨繂鐡ㄩ柊宥囩枂', {
            tableId,
            displayFields: displayFields.length,
            filterFields: filterFields.length
        });

        const configData = {
            table_id: tableId,
            display_fields: displayFields,
            filter_fields: filterFields,
            page_size: 20,
            sort_field: '',
            sort_direction: 'asc'
        };

        var $saveBtn = document.querySelector(`#w-field-config-modal-${tableId} .w-btn-primary`);
        if ($saveBtn) {
            var originalText = $saveBtn.innerHTML;
            $saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 娣囨繂鐡?..';
            $saveBtn.disabled = true;
        }

        this.requestJson(instance, 'save-config', { scope: instance.options.scope, config: configData }).then(response => {
            // 閸忕厧顔?code 娑撳搫鐡х粭锔胯閹存牗鏆熺€?
            if (response.code == 200 || response.code === '200' || response.success) {
                console.log('saveFieldConfig: 娣囨繂鐡ㄩ幋鎰閿涘苯绱戞慨瀣櫢閺傜増瑕嗛弻鎾广€?');

                // 閸忔娊妫撮柊宥囩枂瀵湱鐛?
                DataTableManager.closeFieldConfig(tableId);

                // 閺嶈宓侀弬鎵畱鐎涙顔岄柊宥囩枂闁插秵鏌婂〒鍙夌厠鐞涖劍鐗?
                DataTableManager.rebuildTableFromConfig(tableId, displayFields, filterFields);

            } else {
                DataTableManager.showError(tableId, response.msg || response.message || __('娣囨繂鐡ㄦ径杈Е'));
            }
        }).catch(error => {
            console.error('saveFieldConfig: 娣囨繂鐡ㄦ径杈Е', error);
            DataTableManager.showError(tableId, __('娣囨繂鐡ㄦ径杈Е: %{1}', [error.message || '閺堫亞鐓￠柨娆掝嚖']));
        }).finally(() => {
            if ($saveBtn) {
                $saveBtn.innerHTML = originalText;
                $saveBtn.disabled = false;
            }
        });
    },

    /**
     * 閺嶈宓侀柊宥囩枂闁插秵鏌婇弸鍕紦鐞涖劍鐗?
     */
    rebuildTableFromConfig: function (tableId, displayFields, filterFields) {
        const instance = this.instances[tableId];
        if (!instance) return;

        console.log('rebuildTableFromConfig: 瀵偓婵鍣搁弬鐗堢€楦裤€?', {
            displayFields: displayFields.length,
            filterFields: filterFields.length
        });

        // 缁楊兛绔村銉窗濞撳懐鈹栭弮褎鏆熼幑顔兼嫲閻樿埖鈧?
        instance.data = [];
        instance.currentPage = 1;
        instance.filters = {};
        instance.search = '';
        instance.sorts = {};

        // 缁楊兛绨╁銉窗閺囧瓨鏌婄€圭偘绶ユ稉顓犳畱鐎涙顔岄柊宥囩枂
        instance.config.fields = displayFields.map(field => ({
            name: field.name,
            label: field.label || field.name,
            visible: true,
            sortable: field.sortable !== false,
            searchable: field.searchable !== false,
            editable: field.editable !== false,
            width: field.width || 'auto',
            minWidth: field.minWidth || 'auto',
            maxWidth: field.maxWidth || 'auto',
            resizable: field.resizable !== false,
            type: field.type || 'text',
            placeholder: field.placeholder || '',
            options: field.options || ''
        }));

        // 缁楊兛绗佸銉窗閺囧瓨鏌婄粵娑⑩偓澶婃珤闁板秶鐤?
        instance.filterConfig = filterFields.map(field => ({
            name: field.name,
            label: field.label || field.name,
            type: field.type || 'text',
            searchable: field.searchable !== false,
            placeholder: field.placeholder || `鐠囩柉绶崗?{field.label || field.name}`,
            options: field.options || ''
        }));

        // 閸氬本妞傞弴瀛樻煀鐎圭偘绶ユ稉顓犳畱filterFields
        instance.filterFields = filterFields;

        console.log('rebuildTableFromConfig: 鐎涙顔岄柊宥囩枂瀹稿弶娲块弬?', {
            configFields: instance.config.fields.length,
            filterConfig: instance.filterConfig.length,
            filterFields: instance.filterFields.length
        });

        // 缁楊剙娲撳銉窗闁插秵鏌婇弸鍕紦鐞涖劌銇?
        console.log('rebuildTableFromConfig: 瀵偓婵鍣搁弬鐗堢€楦裤€冩径?');
        const container = instance.container[0] || instance.container;
        const thead = container.querySelector('thead');
        if (thead) {
            this.renderHeader(tableId, displayFields);
            console.log('rebuildTableFromConfig: 鐞涖劌銇旈柌宥嗘煀閺嬪嫬缂撶€瑰本鍨?');

            // 妤犲矁鐦夌悰銊ャ仈閺嬪嫬缂撶紒鎾寸亯
            const headerCells = thead.querySelectorAll('th');
            console.log('rebuildTableFromConfig: 鐞涖劌銇旀宀冪槈', {
                expectedFields: instance.config.fields.length,
                actualCells: headerCells.length,
                headerTexts: Array.from(headerCells).map(cell => cell.textContent.trim())
            });
        } else {
            console.error('rebuildTableFromConfig: 閺堫亝澹橀崚鎷屻€冩径鏉戝敶鐎?');
        }

        // 缁楊兛绨插銉窗闁插秵鏌婇弸鍕紦缁涙盯鈧娅?
        console.log('rebuildTableFromConfig: 瀵偓婵鍣搁弬鐗堢€铏圭摣闁娅?');
        this.renderFilter(tableId, instance.filterFields);
        console.log('rebuildTableFromConfig: 缁涙盯鈧娅掗柌宥嗘煀閺嬪嫬缂撶€瑰本鍨?');

        // 妤犲矁鐦夌粵娑⑩偓澶婃珤閺嬪嫬缂撶紒鎾寸亯
        const filterContainers = [
            '.datatable-filter',
            '.datatable-filter-toolbar',
            '.datatable-filter-form'
        ];

        filterContainers.forEach(selector => {
            const filterContainer = container.querySelector(selector);
            if (filterContainer) {
                const filterInputs = filterContainer.querySelectorAll('[data-field]');
                console.log(`rebuildTableFromConfig: 缁涙盯鈧娅掓宀冪槈 ${selector}`, {
                    expectedFilters: instance.filterConfig.length,
                    actualInputs: filterInputs.length,
                    filterFields: Array.from(filterInputs).map(input => input.getAttribute('data-field'))
                });
            } else {
                console.warn(`rebuildTableFromConfig: 閺堫亝澹橀崚鎵摣闁娅掔€圭懓娅?${selector}`);
            }
        });

        // 缁楊剙鍙氬銉窗闁插秵鏌婄紒鎴濈暰娴滃娆?
        console.log('rebuildTableFromConfig: 瀵偓婵鍣搁弬鎵拨鐎规矮绨ㄦ禒?');
        this.bindEvents(instance);
        console.log('rebuildTableFromConfig: 娴滃娆㈢紒鎴濈暰鐎瑰本鍨?');

        // 缁楊兛绔峰銉窗闁插秵鏌婇弸鍕紦鐞涖劍鐗告稉璁崇秼
        console.log('rebuildTableFromConfig: 瀵偓婵鍣搁弬鐗堢€楦裤€冮弽闂村瘜娴?');
        this.renderTable(instance);
        console.log('rebuildTableFromConfig: 鐞涖劍鐗告稉璁崇秼闁插秵鏌婇弸鍕紦鐎瑰本鍨?');

        // 妤犲矁鐦夌悰銊︾壐閺嬪嫬缂撶紒鎾寸亯
        const tbody = container.querySelector('tbody');
        if (tbody) {
            const tbodyRows = tbody.querySelectorAll('tr');
            console.log('rebuildTableFromConfig: 鐞涖劍鐗告稉璁崇秼妤犲矁鐦?', {
                rows: tbodyRows.length,
                hasData: tbodyRows.length > 0 && !tbodyRows[0].querySelector('td')?.textContent.includes('閺嗗倹妫ら弫鐗堝祦')
            });
        }

        // 缁楊剙鍙撳銉窗闁插秵鏌婇崝鐘烘祰閺佺増宓?
        console.log('rebuildTableFromConfig: 瀵偓婵鍣搁弬鏉垮鏉炶姤鏆熼幑?');
        this.loadData(instance);
        console.log('rebuildTableFromConfig: 閺佺増宓侀崝鐘烘祰鐎瑰本鍨?');

        // 閺堚偓缂佸牓鐛欑拠?
        setTimeout(() => {
            console.log('rebuildTableFromConfig: 閺堚偓缂佸牓鐛欑拠?', {
                tableId: tableId,
                configFields: instance.config.fields?.length || 0,
                filterConfig: instance.filterConfig?.length || 0,
                data: instance.data?.length || 0,
                headerCells: container.querySelectorAll('thead th').length,
                filterInputs: container.querySelectorAll('.datatable-filter [data-field]').length,
                toolbarInputs: container.querySelectorAll('.datatable-filter-toolbar [data-field]').length,
                formInputs: container.querySelectorAll('.datatable-filter-form [data-field]').length
            });
        }, 100);

        console.log('rebuildTableFromConfig: 鐞涖劍鐗搁柌宥嗘煀閺嬪嫬缂撶€瑰本鍨?');
    },

    /**
     * 閺囧瓨鏌婄悰銊︾壐鐎涙顔岄柊宥囩枂
     */
    updateTableFields: function (tableId, displayFields) {
        const instance = this.instances[tableId];
        if (!instance) return;

        // 閺囧瓨鏌婄€圭偘绶ユ稉顓犳畱鐞涖劍鐗哥€涙顔岄柊宥囩枂
        instance.config.fields = displayFields.map(field => ({
            name: field.name,
            label: field.label || field.name,
            visible: true,
            sortable: field.sortable !== false,
            searchable: field.searchable !== false,
            editable: field.editable !== false,
            width: field.width || 'auto',
            minWidth: field.minWidth || 'auto',
            maxWidth: field.maxWidth || 'auto',
            resizable: field.resizable !== false,
            type: field.type || 'text'
        }));

        // 闁插秵鏌婂〒鍙夌厠鐞涖劌銇?
        this.renderHeader(tableId, displayFields);

        // 闁插秵鏌婂〒鍙夌厠鐞涖劍鐗搁弫鐗堝祦
        this.renderTable(instance);

        // 闁插秵鏌婇崝鐘烘祰閺佺増宓?
        this.loadData(instance);
    },

    /**
     * 閺囧瓨鏌婄粵娑⑩偓澶婃珤鐎涙顔岄柊宥囩枂
     */
    updateFilterFields: function (tableId, filterFields) {
        const instance = this.instances[tableId];
        if (!instance) return;

        // 閺囧瓨鏌婄粵娑⑩偓澶婃珤鐎涙顔岄柊宥囩枂
        instance.filterConfig = filterFields.map(field => ({
            name: field.name,
            label: field.label || field.name,
            type: field.type || 'text',
            searchable: field.searchable !== false,
            placeholder: field.placeholder || `鐠囩柉绶崗?{field.label || field.name}`,
            options: field.options || ''
        }));

        // 闁插秵鏌婂〒鍙夌厠缁涙盯鈧娅?
        this.renderFilter(tableId, filterFields);
    },

    /**
     * 濞撳弶鐓嬬粵娑⑩偓澶婃珤
     */
    renderFilter: function (tableId, fields) {
        const instance = this.instances[tableId];
        if (!instance) return;

        const container = instance.container[0] || instance.container;

        console.log('renderFilter: 瀵偓婵瑕嗛弻鎾剁摣闁娅?', {
            tableId,
            fieldsCount: fields.length,
            fields: fields.map(f => ({ name: f.name, label: f.label, type: f.type }))
        });

        // 濞撳弶鐓嬫稉鏄忣洣閻ㄥ嫮鐡柅澶婃珤鐎圭懓娅?
        this.renderFilterContainer(tableId, fields, '.datatable-filter');

        // 濞撳弶鐓嬬粵娑⑩偓澶婃珤瀹搞儱鍙块弽?
        this.renderFilterContainer(tableId, fields, '.datatable-filter-toolbar');

        // 濞撳弶鐓嬬粵娑⑩偓澶婃珤鐞涖劌宕?
        this.renderFilterContainer(tableId, fields, '.datatable-filter-form');

        console.log('renderFilter: 缁涙盯鈧娅掑〒鍙夌厠鐎瑰本鍨?');
    },

    /**
     * 濞撳弶鐓嬮幐鍥х暰閻ㄥ嫮鐡柅澶婃珤鐎圭懓娅?
     */
    renderFilterContainer: function (tableId, fields, selector) {
        const instance = this.instances[tableId];
        if (!instance) return;

        const container = instance.container[0] || instance.container;
        const filterContainer = container.querySelector(selector);
        if (!filterContainer) {
            console.warn(`renderFilterContainer: 閺堫亝澹橀崚鎵摣闁娅掔€圭懓娅?${selector}`);
            return;
        }

        console.log(`renderFilterContainer: 瀵偓婵瑕嗛弻鎾剁摣闁娅掔€圭懓娅?${selector}`, {
            tableId,
            fieldsCount: fields.length,
            fields: fields.map(f => ({ name: f.name, label: f.label, type: f.type }))
        });

        // 绾喕绻氱€涙顔屾い鍝勭碍濮濓絿鈥?
        const templateFields = fields.filter(field =>
            field.template_defined || field.field_defined || field.from_field
        );
        const userFields = fields.filter(field =>
            !field.template_defined && !field.field_defined && !field.from_field
        );
        const orderedFields = [...templateFields, ...userFields];

        let filterHtml = '';

        // 娑撹桨绗夐崥宀€娈戠€圭懓娅掗幓鎰返娑撳秴鎮撻惃鍕閺屾捇鈧槒绶?
        if (selector === '.datatable-filter-form') {
            // 缁涙盯鈧娅掔悰銊ュ礋鐎圭懓娅掗惃鍕濞堝﹥瑕嗛弻鎾烩偓鏄忕帆
            filterHtml = this.renderFilterFormHtml(tableId, orderedFields);
        } else if (selector === '.datatable-filter-toolbar') {
            // 缁涙盯鈧娅掑銉ュ徔閺嶅繒娈戦幍瀣棑閻炴潙绱″〒鍙夌厠闁槒绶?
            filterHtml = this.renderFilterToolbarHtml(tableId, orderedFields);
        } else {
            // 閸忔湹绮€圭懓娅掗惃鍕垼閸戝棙瑕嗛弻鎾烩偓鏄忕帆
            filterHtml = this.renderStandardFilterHtml(tableId, orderedFields);
        }

        // 閻╁瓨甯寸拋鍓х枂鐎圭懓娅掗惃鍑ML閸愬懎顔?
        filterContainer.innerHTML = filterHtml;

        console.log('renderFilterContainer', {
            selector,
            renderedFields: filterContainer.querySelectorAll('[data-field]').length,
            containerHtml: filterContainer.innerHTML.substring(0, 200) + '...'
        });

        // 缂佹垵鐣剧粵娑⑩偓澶夌皑娴?
        filterContainer.querySelectorAll('.filter-input').forEach(input => {
            input.addEventListener('input', function () {
                const fieldName = this.getAttribute('data-field');
                const value = this.value;
                DataTableManager.applyFilter(tableId, fieldName, value);
            });

            input.addEventListener('change', function () {
                const fieldName = this.getAttribute('data-field');
                const value = this.value;
                DataTableManager.applyFilter(tableId, fieldName, value);
            });
        });

        // 閸掓繂顫愰崠鏍ㄥ妞嬪海鎯旈崝鐔诲厴閿涘牆顩ч弸婊勬Ц瀹搞儱鍙块弽蹇ョ礆
        if (selector === '.datatable-filter-toolbar') {
            this.initFilterAccordion(tableId);
        }
    },

    /**
     * 濞撳弶鐓嬮弽鍥у櫙缁涙盯鈧娅扝TML
     */
    renderStandardFilterHtml: function (tableId, fields) {
        let filterHtml = '';
        fields.forEach(field => {
            filterHtml += this.renderFilterFieldHtml(tableId, field, 'standard');
        });
        return filterHtml;
    },

    /**
     * 濞撳弶鐓嬬粵娑⑩偓澶婃珤鐞涖劌宕烪TML
     */
    renderFilterFormHtml: function (tableId, fields) {
        let filterHtml = '';
        fields.forEach(field => {
            const isProtected = this.isFieldProtected(field);
            const canSearch = isProtected ?
                (field.searchable === true || field.searchable === 'true') :
                (field.searchable !== false);

            if (canSearch) {
                const fieldType = field.type || 'text';
                const placeholder = field.placeholder || `鐠囩柉绶崗?{field.label || field.name}`;
                const fieldId = 'filter-form-' + field.name;

                if (fieldType === 'select') {
                    let optionsHtml = '<option value="">鐠囩兘鈧瀚?/option>';
                    if (field.options) {
                        const optionPairs = field.options.split(',');
                        optionPairs.forEach(pair => {
                            const [value, label] = pair.split(':');
                            if (value && label) {
                                optionsHtml += `<option value="${value.trim()}">${label.trim()}</option>`;
                            }
                        });
                    }

                    filterHtml += `
                        <div class="filter-field" data-field="${field.name}">
                            <label for="${fieldId}" class="form-label">${field.label || field.name}</label>
                            <select class="form-control form-control-sm filter-input" id="${fieldId}" name="filter[${field.name}]" data-field="${field.name}">
                                ${optionsHtml}
                            </select>
                        </div>`;
                } else if (fieldType === 'date') {
                    filterHtml += `
                        <div class="filter-field" data-field="${field.name}">
                            <label for="${fieldId}" class="form-label">${field.label || field.name}</label>
                            <input type="date" class="form-control form-control-sm filter-input" id="${fieldId}" name="filter[${field.name}]" data-field="${field.name}" placeholder="${placeholder}">
                        </div>`;
                } else if (fieldType === 'number') {
                    filterHtml += `
                        <div class="filter-field" data-field="${field.name}">
                            <label for="${fieldId}" class="form-label">${field.label || field.name}</label>
                            <input type="number" class="form-control form-control-sm filter-input" id="${fieldId}" name="filter[${field.name}]" data-field="${field.name}" placeholder="${placeholder}">
                        </div>`;
                } else {
                    filterHtml += `
                        <div class="filter-field" data-field="${field.name}">
                            <label for="${fieldId}" class="form-label">${field.label || field.name}</label>
                            <input type="text" class="form-control form-control-sm filter-input" id="${fieldId}" name="filter[${field.name}]" data-field="${field.name}" placeholder="${placeholder}">
                        </div>`;
                }
            }
        });
        return filterHtml;
    },

    /**
     * 濞撳弶鐓嬬粵娑⑩偓澶婃珤瀹搞儱鍙块弽寤怲ML閿涘牊澧滄搴ｆ償瀵骏绱?
     */
    renderFilterToolbarHtml: function (tableId, fields) {
        // 閸掑棛顬囬幐鍥х暰鐎涙顔岄崪灞藉従娴犳牕鐡у▓?
        const specifiedFields = fields.filter(field =>
            field.field_defined === true || field.template_defined === true || field.from_field === true
        );
        const otherFields = fields.filter(field =>
            !field.field_defined && !field.template_defined && !field.from_field
        );

        let filterHtml = '';

        // 濞撳弶鐓嬮幐鍥х暰鐎涙顔岄敍鍫㈡纯閹恒儲妯夌粈鐚寸礆
        if (specifiedFields.length > 0) {
            filterHtml += '<div class="filter-specified-fields">';
            specifiedFields.forEach(field => {
                filterHtml += this.renderFilterFieldHtml(tableId, field, 'toolbar');
            });
            filterHtml += '</div>';
        }

        // 濞撳弶鐓嬮崗鏈电铂鐎涙顔岄敍鍫熷妞嬪海鎯斿蹇ョ礆
        if (otherFields.length > 0) {
            filterHtml += `
                <div class="filter-accordion">
                    <div class="filter-accordion-header" data-datatable-action="toggle-filter-accordion" data-table="${tableId}">
                        <i class="fas fa-filter"></i>
                        <span>閺囨潙顦跨粵娑⑩偓澶嬫蒋娴?(${otherFields.length})</span>
                        <i class="fas fa-chevron-down filter-accordion-icon"></i>
                    </div>
                    <div class="filter-accordion-content" style="display: none;">
                        <div class="filter-accordion-fields">`;

            otherFields.forEach(field => {
                filterHtml += this.renderFilterFieldHtml(tableId, field, 'toolbar');
            });

            filterHtml += `
                        </div>
                    </div>
                </div>`;
        }

        return filterHtml;
    },

    /**
     * 濞撳弶鐓嬮崡鏇氶嚋缁涙盯鈧鐡у▓绀朤ML
     */
    renderFilterFieldHtml: function (tableId, field, containerType = 'toolbar') {
        const isProtected = this.isFieldProtected(field);
        const canSearch = isProtected ?
            (field.searchable === true || field.searchable === 'true') :
            (field.searchable !== false);

        if (!canSearch) return '';

        const fieldType = field.type || 'text';
        const placeholder = field.placeholder || `鐠囩柉绶崗?{field.label || field.name}`;
        const fieldId = `filter-${containerType}-${field.name}`;

        // 閺嶈宓佺€圭懓娅掔猾璇茬€风拋鍓х枂CSS缁?
        let containerClass;
        switch (containerType) {
            case 'form':
                containerClass = 'filter-field';
                break;
            case 'standard':
                containerClass = 'filter-item';
                break;
            default:
                containerClass = 'filter-toolbar-item';
        }

        if (fieldType === 'select') {
            let optionsHtml = '<option value="">鐠囩兘鈧瀚?/option>';
            if (field.options) {
                const optionPairs = field.options.split(',');
                optionPairs.forEach(pair => {
                    const [value, label] = pair.split(':');
                    if (value && label) {
                        optionsHtml += `<option value="${value.trim()}">${label.trim()}</option>`;
                    }
                });
            }

            return `
                <div class="${containerClass}" data-field="${field.name}">
                    <label for="${fieldId}">${field.label || field.name}:</label>
                    <select class="filter-input" id="${fieldId}" data-field="${field.name}">
                        ${optionsHtml}
                    </select>
                </div>`;
        } else if (fieldType === 'date') {
            return `
                <div class="${containerClass}" data-field="${field.name}">
                    <label for="${fieldId}">${field.label || field.name}:</label>
                    <input type="date" class="filter-input" id="${fieldId}" data-field="${field.name}" placeholder="${placeholder}" />
                </div>`;
        } else if (fieldType === 'number') {
            return `
                <div class="${containerClass}" data-field="${field.name}">
                    <label for="${fieldId}">${field.label || field.name}:</label>
                    <input type="number" class="filter-input" id="${fieldId}" data-field="${field.name}" placeholder="${placeholder}" />
                </div>`;
        } else {
            return `
                <div class="${containerClass}" data-field="${field.name}">
                    <label for="${fieldId}">${field.label || field.name}:</label>
                    <input type="text" class="filter-input" id="${fieldId}" data-field="${field.name}" placeholder="${placeholder}" />
                </div>`;
        }
    },

    /**
     * 閸掑洦宕茬粵娑⑩偓澶婃珤閹靛顥撻悶?
     */
    toggleFilterAccordion: function (tableId) {
        const container = this.instances[tableId]?.container[0] || this.instances[tableId]?.container;
        if (!container) return;

        const accordionContent = container.querySelector('.filter-accordion-content');
        const accordionIcon = container.querySelector('.filter-accordion-icon');

        if (accordionContent && accordionIcon) {
            const isVisible = accordionContent.style.display !== 'none';
            accordionContent.style.display = isVisible ? 'none' : 'block';
            accordionIcon.className = isVisible ? 'fas fa-chevron-down' : 'fas fa-chevron-up';
        }
    },

    /**
     * 閸掓繂顫愰崠鏍摣闁娅掗幍瀣棑閻炴潙濮涢懗?
     */
    initFilterAccordion: function (tableId) {
        const container = this.instances[tableId]?.container[0] || this.instances[tableId]?.container;
        if (!container) return;

        const accordionHeader = container.querySelector('.filter-accordion-header');
        if (accordionHeader) {
            // 缁夊娅庨弮褏娈戞禍瀣╂閻╂垵鎯夐崳?
            accordionHeader.removeEventListener('click', this._accordionClickHandler);

            // 濞ｈ濮為弬鎵畱娴滃娆㈤惄鎴濇儔閸?
            this._accordionClickHandler = () => this.toggleFilterAccordion(tableId);
            accordionHeader.addEventListener('click', this._accordionClickHandler);
        }
    },

    /**
     * 鐎涙顔岄幏鏍ㄥ閹烘帒绨敍鍧?閸撳秶绱戦敍?
     */
    bindFieldDragSort: function (tableId) {
        ['display', 'filter'].forEach(type => {
            const container = document.getElementById(type === 'display' ? 'w-display-fields-' + tableId : 'w-filter-fields-' + tableId);
            if (!container) return;
            let dragSrc = null;
            container.querySelectorAll('.w-field-item').forEach(item => {
                item.draggable = true;
                item.ondragstart = function (e) {
                    dragSrc = this;
                    this.classList.add('w-dragging');
                    e.dataTransfer.effectAllowed = 'move';
                };
                item.ondragover = function (e) {
                    e.preventDefault();
                    if (this !== dragSrc) this.classList.add('w-drag-over');
                };
                item.ondragleave = function () {
                    this.classList.remove('w-drag-over');
                };
                item.ondrop = function (e) {
                    e.preventDefault();
                    this.classList.remove('w-drag-over');
                    if (this !== dragSrc) {
                        const items = Array.from(container.querySelectorAll('.w-field-item'));
                        const from = items.indexOf(dragSrc);
                        const to = items.indexOf(this);
                        if (from !== -1 && to !== -1) {
                            let fieldList = type === 'display' ? DataTableManager.instances[tableId].displayFields : DataTableManager.instances[tableId].filterFields;
                            const moved = fieldList.splice(from, 1)[0];
                            fieldList.splice(to, 0, moved);
                            DataTableManager.renderModelFieldsFromData(tableId, {
                                all_fields: DataTableManager.instances[tableId].allFields,
                                display_fields: DataTableManager.instances[tableId].displayFields,
                                filter_fields: DataTableManager.instances[tableId].filterFields
                            });
                        }
                    }
                };
                item.ondragend = function () {
                    this.classList.remove('w-dragging');
                    container.querySelectorAll('.w-field-item').forEach(i => i.classList.remove('w-drag-over'));
                };
            });
        });
    },

    /**
     * 缂佹垵鐣緊ptions鏉堟挸鍙嗘禍瀣╂
     */
    bindFieldOptionsInput: function (tableId) {
        // 鐟欙絿绮﹂崘宥囩拨鐎规熬绱濋梼鍙夘剾闁插秴顦?
        $(document).off('input', '.w-field-options-input');
        $(document).on('input', '.w-field-options-input', function () {
            const tableId = $(this).data('table');
            const fieldName = $(this).data('field');
            const type = $(this).data('type');
            const value = $(this).val();
            const instance = DataTableManager.instances[tableId];
            if (!instance) return;
            let fieldList = type === 'display' ? instance.displayFields : instance.filterFields;
            const field = fieldList.find(f => f.name === fieldName);
            if (field) {
                field.options = value;
                // 娑撳秹鍣搁弬鐗堣閺屾搫绱濋崣顏呮纯閺傜増鏆?
            }
        });
    },

    /**
     * 鐎涙顔岄崣顏囶嚢/韫囧懎锝瀋heckbox閸欐ɑ娲挎禍瀣╂
     */
    bindFieldCheckboxInput: function (tableId) {
        // 鐟欙絿绮﹂崘宥囩拨鐎规熬绱濋梼鍙夘剾闁插秴顦?
        $(document).off('change', '.w-field-readonly-checkbox');
        $(document).on('change', '.w-field-readonly-checkbox', function () {
            const tableId = $(this).data('table');
            const fieldName = $(this).data('field');
            const type = $(this).data('type');
            const checked = $(this).is(':checked');
            const instance = DataTableManager.instances[tableId];
            if (!instance) return;
            let fieldList = type === 'display' ? instance.displayFields : instance.filterFields;
            const field = fieldList.find(f => f.name === fieldName);
            if (field) {
                field.readonly = checked;
                // 娑撳秹鍣搁弬鐗堣閺屾搫绱濋崣顏呮纯閺傜増鏆?
            }
        });
        $(document).off('change', '.w-field-required-checkbox');
        $(document).on('change', '.w-field-required-checkbox', function () {
            const tableId = $(this).data('table');
            const fieldName = $(this).data('field');
            const type = $(this).data('type');
            const checked = $(this).is(':checked');
            const instance = DataTableManager.instances[tableId];
            if (!instance) return;
            let fieldList = type === 'display' ? instance.displayFields : instance.filterFields;
            const field = fieldList.find(f => f.name === fieldName);
            if (field) {
                field.required = checked;
                // 娑撳秹鍣搁弬鐗堣閺屾搫绱濋崣顏呮纯閺傜増鏆?
            }
        });
    },

    /**
     * 缂佹垵鐣剧€涙顔岄柊宥囩枂閻╃鍙ф禍瀣╂
     */
    bindFieldEvents: function (tableId) {
        const instance = this.instances[tableId];
        if (!instance) return;

        // 缂佹垵鐣剧€涙顔岄弽鍥╊劮鏉堟挸鍙嗘禍瀣╂
        $(`#w-field-config-modal-${tableId} .w-field-label-input`).off('input').on('input', function () {
            const fieldName = $(this).data('field');
            const type = $(this).data('type');
            const value = $(this).val();

            if (type === 'display') {
                const field = instance.displayFields.find(f => f.name === fieldName);
                if (field) {
                    field.label = value;
                    // 閸欘亝娲块弬鏉跨唨閺堫兛淇婇幁顖涙▔缁€?
                    const fieldItem = document.querySelector(`#w-display-fields-${tableId} .w-field-item[data-field="${fieldName}"]`);
                    if (fieldItem) {
                        const fieldNameElement = fieldItem.querySelector('.w-field-basic-info .w-field-name');
                        if (fieldNameElement) {
                            fieldNameElement.textContent = value || field.name;
                        }
                    }
                }
            } else if (type === 'filter') {
                const field = instance.filterFields.find(f => f.name === fieldName);
                if (field) {
                    field.label = value;
                    // 閸欘亝娲块弬鏉跨唨閺堫兛淇婇幁顖涙▔缁€?
                    const fieldItem = document.querySelector(`#w-filter-fields-${tableId} .w-field-item[data-field="${fieldName}"]`);
                    if (fieldItem) {
                        const fieldNameElement = fieldItem.querySelector('.w-field-basic-info .w-field-name');
                        if (fieldNameElement) {
                            fieldNameElement.textContent = value || field.name;
                        }
                    }
                }
            }
        });

        // 缂佹垵鐣剧€涙顔岄崡鐘辩秴缁楋箒绶崗銉ょ皑?
        $(`#w-field-config-modal-${tableId} .w-field-placeholder-input`).off('input').on('input', function () {
            const fieldName = $(this).data('field');
            const type = $(this).data('type');
            const value = $(this).val();

            if (type === 'display') {
                const field = instance.displayFields.find(f => f.name === fieldName);
                if (field) field.placeholder = value;
            } else if (type === 'filter') {
                const field = instance.filterFields.find(f => f.name === fieldName);
                if (field) field.placeholder = value;
            }
        });

        // 缂佹垵鐣剧€涙顔岄柅澶愩€嶆潏鎾冲弳娴滃娆?
        $(`#w-field-config-modal-${tableId} .w-field-options-input`).off('input').on('input', function () {
            const fieldName = $(this).data('field');
            const type = $(this).data('type');
            const value = $(this).val();

            if (type === 'display') {
                const field = instance.displayFields.find(f => f.name === fieldName);
                if (field) field.options = value;
            } else if (type === 'filter') {
                const field = instance.filterFields.find(f => f.name === fieldName);
                if (field) field.options = value;
            }
        });

        // 缂佹垵鐣剧€涙顔岀猾璇茬€烽柅澶嬪娴滃娆?
        $(`#w-field-config-modal-${tableId} .w-field-type-select`).off('change').on('change', function () {
            const fieldName = $(this).data('field');
            const type = $(this).data('type');
            const value = $(this).val();

            if (type === 'display') {
                const field = instance.displayFields.find(f => f.name === fieldName);
                if (field) {
                    field.type = value;
                    // 閸欘亝娲块弬鏉跨唨閺堫兛淇婇幁顖涙▔缁€?
                    const fieldItem = document.querySelector(`#w-display-fields-${tableId} .w-field-item[data-field="${fieldName}"]`);
                    if (fieldItem) {
                        const typeBadge = fieldItem.querySelector('.w-field-basic-info .w-field-type-badge');
                        if (typeBadge) {
                            typeBadge.textContent = value;
                        }
                    }
                }
            } else if (type === 'filter') {
                const field = instance.filterFields.find(f => f.name === fieldName);
                if (field) {
                    field.type = value;
                    // 閸欘亝娲块弬鏉跨唨閺堫兛淇婇幁顖涙▔缁€?
                    const fieldItem = document.querySelector(`#w-filter-fields-${tableId} .w-field-item[data-field="${fieldName}"]`);
                    if (fieldItem) {
                        const typeBadge = fieldItem.querySelector('.w-field-basic-info .w-field-type-badge');
                        if (typeBadge) {
                            typeBadge.textContent = value;
                        }
                    }
                }
            }
        });

        // 缂佹垵鐣鹃弽锟犵崣鐟欏嫬鍨潏鎾冲弳娴滃娆?
        $(`#w-field-config-modal-${tableId} .w-validation-min, #w-field-config-modal-${tableId} .w-validation-max, #w-field-config-modal-${tableId} .w-validation-pattern`).off('input').on('input', function () {
            const fieldName = $(this).data('field');
            const type = $(this).data('type');
            const validationType = $(this).hasClass('w-validation-min') ? 'min' :
                $(this).hasClass('w-validation-max') ? 'max' : 'pattern';
            const value = $(this).val();

            if (type === 'display') {
                const field = instance.displayFields.find(f => f.name === fieldName);
                if (field) {
                    if (!field.validation) field.validation = {};
                    field.validation[validationType] = value;
                }
            } else if (type === 'filter') {
                const field = instance.filterFields.find(f => f.name === fieldName);
                if (field) {
                    if (!field.validation) field.validation = {};
                    field.validation[validationType] = value;
                }
            }
        });
    },

    /**
     * 閸掓繂顫愰崠鏍ㄥ珛閹疯姤甯?
     */
    initDragSort: function (tableId) {
        const instance = this.instances[tableId];
        if (!instance) return;

        // 娑撳搫鐡у▓鐢稿帳缂冾喖鑴婄粣妞捐厬閻ㄥ嫬鐡у▓鐢搞€嶅ǎ璇插閹锋牗瀚块幒鎺戠碍閸旂喕鍏?
        const displayFieldsContainer = document.getElementById('w-display-fields-' + tableId);
        const filterFieldsContainer = document.getElementById('w-filter-fields-' + tableId);

        if (displayFieldsContainer) {
            this.initContainerDragSort(displayFieldsContainer, tableId, 'display');
        }
        if (filterFieldsContainer) {
            this.initContainerDragSort(filterFieldsContainer, tableId, 'filter');
        }
    },

    /**
     * 娑撳搫顔愰崳銊ュ灥婵瀵查幏鏍ㄥ閹烘帒绨?
     */
    initContainerDragSort: function (container, tableId, type) {
        const fieldItems = container.querySelectorAll('.w-field-item');

        fieldItems.forEach(item => {
            const fieldName = item.getAttribute('data-field');
            if (!fieldName) return;

            // 濡偓閺屻儱鐡у▓鍨Ц閸氾箑鍘戠拋鍛婂珛閸?
            const instance = this.instances[tableId];
            if (!instance) return;

            const fieldList = type === 'display' ? instance.displayFields : instance.filterFields;
            const field = fieldList.find(f => f.name === fieldName);
            if (!field) return;

            // 閸欘亝婀侀弰搴ｂ€樼拋鍓х枂display_orderable娑撶alse閻ㄥ嫬鐡у▓鍨娑撳秴鍘戠拋鍛婂珛閸?
            const canDrag = field.display_orderable !== false && field.display_orderable !== 'false' && field.display_orderable !== 0 && field.display_orderable !== '0';

            if (!canDrag) {
                // 娑撳秴鍘戠拋鍛婂珛閸斻劎娈戠€涙顔岄敍宀€些闂勩倖瀚嬮幏鐣屾祲閸忚櫕鐗卞蹇撴嫲鐏炵偞鈧?
                item.style.cursor = 'default';
                item.removeAttribute('draggable');
                return;
            }

            // 濞ｈ濮為幏鏍ㄥ閺嶅嘲绱?
            item.style.cursor = 'move';
            item.setAttribute('draggable', 'true');

            // 缂佹垵鐣鹃幏鏍ㄥ娴滃娆?
            item.addEventListener('dragstart', function (e) {
                e.dataTransfer.setData('text/plain', fieldName);
                e.dataTransfer.setData('type', type);
                item.classList.add('dragging');
            });

            item.addEventListener('dragend', function () {
                item.classList.remove('dragging');
            });

            item.addEventListener('dragover', function (e) {
                e.preventDefault();
                item.classList.add('drag-over');
            });

            item.addEventListener('dragleave', function () {
                item.classList.remove('drag-over');
            });

            item.addEventListener('drop', function (e) {
                e.preventDefault();
                item.classList.remove('drag-over');

                const draggedFieldName = e.dataTransfer.getData('text/plain');
                const draggedType = e.dataTransfer.getData('type');

                if (draggedFieldName && draggedFieldName !== fieldName && draggedType === type) {
                    // 閹笛嗩攽鐎涙顔岀粔璇插З
                    DataTableManager.moveFieldByDrag(tableId, draggedFieldName, fieldName, type);
                }
            });
        });
    },

    /**
     * 闁俺绻冮幏鏍ㄥ缁夎濮╃€涙顔?
     */
    moveFieldByDrag: function (tableId, draggedFieldName, targetFieldName, type) {
        const instance = this.instances[tableId];
        if (!instance) return;

        const fieldList = type === 'display' ? instance.displayFields : instance.filterFields;
        const draggedIndex = fieldList.findIndex(f => f.name === draggedFieldName);
        const targetIndex = fieldList.findIndex(f => f.name === targetFieldName);

        if (draggedIndex === -1 || targetIndex === -1) return;

        const draggedField = fieldList[draggedIndex];
        const targetField = fieldList[targetIndex];

        // 濡偓閺屻儱鐡у▓鍨Ц閸氾箑鍘戠拋鍝バ╅崝?- 閸欘亝婀侀弰搴ｂ€樼拋鍓х枂display_orderable娑撶alse閻ㄥ嫬鐡у▓鍨娑撳秴鍘戠拋鍝バ╅崝?
        const draggedCanMove = draggedField.display_orderable !== false && draggedField.display_orderable !== 'false' && draggedField.display_orderable !== 0 && draggedField.display_orderable !== '0';
        const targetCanMove = targetField.display_orderable !== false && targetField.display_orderable !== 'false' && targetField.display_orderable !== 0 && targetField.display_orderable !== '0';

        if (!draggedCanMove) {
            console.warn('moveFieldByDrag: 鐎涙顔屾稉宥呭帒鐠佸摜些閸?', draggedFieldName);
            return;
        }

        if (!targetCanMove) {
            console.warn('moveFieldByDrag: 閻╊喗鐖ｆ担宥囩枂鐎涙顔屾稉宥呭帒鐠佸摜些閸?', targetFieldName);
            return;
        }

        // 閹笛嗩攽缁夎濮?
        const temp = fieldList[draggedIndex];
        fieldList.splice(draggedIndex, 1);
        fieldList.splice(targetIndex, 0, temp);

        // 缁斿宓嗘穱婵嗙摠閻劍鍩涢柊宥囩枂
        this.saveFieldConfigToCache(tableId);

        // 闁插秵鏌婂〒鍙夌厠鐎涙顔岄柊宥囩枂瀵湱鐛?
        this.renderModelFieldsFromData(tableId, {
            all_fields: instance.allFields,
            display_fields: instance.displayFields,
            filter_fields: instance.filterFields
        });

        console.log('moveFieldByDrag: 鐎涙顔岄幏鏍ㄥ缁夎濮╃€瑰本鍨?', {
            type: type,
            dragged: draggedFieldName,
            target: targetFieldName,
            newOrder: fieldList.map(f => f.name)
        });
    },

    /**
     * 濞撳懐鎮婄悰銊ャ仈鐎涙顔岄柊宥囩枂
     * @param {string} tableId 鐞涖劍鐗窱D
     */
    clearHeaderConfig: async function (tableId) {
        const instance = this.getInstance(tableId);
        if (!instance) {
            console.error('Table instance not found:', tableId);
            return;
        }

        if (typeof BackendConfirm === 'undefined') {
            console.warn('BackendConfirm is missing');
            return;
        }

        const confirmed = await BackendConfirm.show(__('绾喖鐣剧憰渚€鍣哥純顔裤€冩径鏉戠摟濞堢敻鍘ょ純顔兼偋閿涚喕绻栫亸鍡樼闂勩倖澧嶉張澶庡殰鐎规矮绠熼惃鍕▔缁€鍝勭摟濞堜絻顔曠純?'), { type: 'warning' });
        if (confirmed) {
            this.clearConfig(tableId, 'header');
        }
    },

    /**
     * 濞撳懐鎮婄粵娑⑩偓澶婄摟濞堢敻鍘?
     * @param {string} tableId 鐞涖劍鐗窱D
     */
    clearFilterConfig: async function (tableId) {
        const instance = this.getInstance(tableId);
        if (!instance) {
            console.error('Table instance not found:', tableId);
            return;
        }

        if (typeof BackendConfirm === 'undefined') {
            console.warn('BackendConfirm is missing');
            return;
        }

        const confirmed = await BackendConfirm.show(__('绾喖鐣剧憰渚€鍣哥純顔剧摣闁鐡у▓鐢稿帳缂冾喖鎮ч敍鐔荤箹鐏忓棙绔婚梽銈嗗閺堝鍤滅€规矮绠熼惃鍕摣闁鐡у▓浣冾啎缂?'), { type: 'warning' });
        if (confirmed) {
            this.clearConfig(tableId, 'filter');
        }
    },

    /**
     * 濞撳懐鎮婇崗銊╁劥闁板秶鐤?
     * @param {string} tableId 鐞涖劍鐗窱D
     */
    clearAllConfig: async function (tableId) {
        const instance = this.getInstance(tableId);
        if (!instance) {
            console.error('Table instance not found:', tableId);
            return;
        }

        if (typeof BackendConfirm === 'undefined') {
            console.warn('BackendConfirm is missing');
            return;
        }

        const confirmed = await BackendConfirm.show(__('绾喖鐣剧憰渚€鍣哥純顔煎弿闁劑鍘ょ純顔兼偋閿涚喕绻栫亸鍡樼闂勩倖澧嶉張澶庡殰鐎规矮绠熼惃鍕€冩径鏉戠摟濞堥潧鎷扮粵娑⑩偓澶婄摟濞堜絻顔曠純?'), { type: 'warning' });
        if (confirmed) {
            this.clearConfig(tableId, 'all');
        }
    },

    /**
     * 濞撳懐鎮婇柊宥囩枂閻ㄥ嫭鐗宠箛鍐╂煙濞?
     * @param {string} tableId 鐞涖劍鐗窱D
     * @param {string} type 濞撳懐鎮婄猾璇茬€烽敍姝╡ader閵嗕公ilter閵嗕工ll
     */
    clearConfig: function (tableId, type) {
        const instance = this.instances[tableId];
        if (!instance) {
            console.error('Table instance not found:', tableId);
            return;
        }

        // 閺勫墽銇氶崝鐘烘祰閻樿埖鈧?
        const container = instance.container[0] || instance.container; // 绾喕绻氶弰鐤峅M閸忓啰绀?
        container.classList.add('loading');

        // 鐠嬪啰鏁ら崥搴ｎ伂API濞撳懐鎮婇柊宥囩枂
        this.requestJson(instance, 'clear-config', {
            scope: instance.options.scope,
            type: type
        })
            .then(response => {
                if (response.success) {
                    // 閺囧瓨鏌婇張顒€婀撮柊宥囩枂
                    if (type === 'header' || type === 'all') {
                        instance.displayFields = [];
                    }
                    if (type === 'filter' || type === 'all') {
                        instance.filterFields = [];
                    }

                    // 闁插秵鏌婇崝鐘烘祰鐎涙顔岄柊宥囩枂
                    this.loadModelFields(tableId);

                    // 閺勫墽銇氶幋鎰濞戝牊浼?
                    this.showMessage(__('闁板秶鐤嗗鏌ュ櫢缂?'), 'success');
                } else {
                    this.showMessage(response.message || __('闁插秶鐤嗘径杈Е'), 'error');
                }
            })
            .catch(error => {
                console.error('Clear config error:', error);
                this.showMessage(__('闁插秶鐤嗛柊宥囩枂婢惰精瑙﹂敍宀冾嚞缁嬪秴鎮楅柌宥堢槸'), 'error');
            })
            .finally(() => {
                container.classList.remove('loading');
            });
    },

    /**
     * 閺勫墽銇氬☉鍫熶紖閹绘劗銇?
     * @param {string} message 濞戝牊浼呴崘鍛啇
     * @param {string} type 濞戝牊浼呯猾璇茬€烽敍姝磚ccess閵嗕躬rror閵嗕簚arning閵嗕巩nfo
     */
    showMessage: function (message, type = 'info') {
        // 閸掓稑缂撳☉鍫熶紖閸忓啰绀?
        const alertClass = type === 'success' ? 'success' : type === 'error' ? 'danger' : type === 'warning' ? 'warning' : 'info';
        const messageElement = document.createElement('div');
        messageElement.className = `alert alert-${alertClass} alert-dismissible fade show`;
        messageElement.setAttribute('role', 'alert');
        messageElement.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        `;

        // 濞ｈ濮為崚浼淬€夐棃銏ゃ€婇柈?
        document.body.insertBefore(messageElement, document.body.firstChild);

        // 3缁夋帒鎮楅懛顏勫З濞戝牆銇?
        setTimeout(() => {
            if (messageElement.parentNode) {
                messageElement.style.opacity = '0';
                setTimeout(() => {
                    if (messageElement.parentNode) {
                        messageElement.parentNode.removeChild(messageElement);
                    }
                }, 300);
            }
        }, 3000);
    },

    /**
     * 閸掑洦宕茬€涙顔岄柊宥囩枂鐏炴洖绱?閺€鎯版崳
     */
    toggleFieldConfig: function (tableId, fieldName, type) {
        const fieldItem = document.querySelector(`#w-${type}-fields-${tableId} .w-field-item[data-field="${fieldName}"]`);
        if (!fieldItem) return;

        const detailConfig = fieldItem.querySelector('.w-field-detail-config');
        const toggleBtn = fieldItem.querySelector('.w-btn-toggle-config');

        if (detailConfig.style.display === 'none') {
            detailConfig.style.display = 'block';
            toggleBtn.innerHTML = '<i class="fas fa-chevron-up"></i> ' + __("閺€鎯版崳");
            toggleBtn.classList.add('active');
        } else {
            detailConfig.style.display = 'none';
            toggleBtn.innerHTML = '<i class="fas fa-cog"></i> ' + __("鐠佸墽鐤?");
            toggleBtn.classList.remove('active');
        }
    },

    /**
     * 濡偓閺屻儱鐡у▓鍨Ц閸氾箑褰堟穱婵囧Б閿涘牅绗夐崗浣筋啅闁板秶鐤嗛妴浣稿灩闂勩們鈧焦娲块弨鐧哥礆
     */
    isFieldProtected: function (field) {
        // 娑撳鏁€涙顔屾穱婵囧Б
        if (field.is_primary === true || field.primary === true) {
            return true;
        }

        // 濡剝婢樼€规矮绠熼惃鍕摟濞堝吀绻?
        if (field.template_defined === true) {
            return true;
        }

        // field閹稿洤鐣鹃惃鍕摟濞堝吀绻?
        if (field.field_defined === true || field.from_field === true) {
            return true;
        }

        // 濡偓閺屻儱鐡у▓闈涙倳閺勵垰鎯佹稉鍝勭埗鐟欎椒瀵?
        const primaryKeyNames = ['id', 'ID', 'Id', 'primary', 'pk', 'primary_key', 'is_primary'];
        if (primaryKeyNames.includes(field.name)) {
            return true;
        }

        // 濡偓閺岊櫔ata-鐏炵偞鈧傝厬閻ㄥ嫬鐡у▓闈涚暰?
        if (field.dataset) {
            if (field.dataset.fieldDefined === 'true' ||
                field.dataset.templateDefined === 'true' ||
                field.dataset.fromField === 'true') {
                return true;
            }
        }

        return false;
    },

    /**
     * 閼惧嘲褰囨妯款吇閺勫墽銇氱€涙顔岄敍鍫濆瘶閸氼偄褰堟穱婵囧Б閻ㄥ嫬鐡у▓纰夌礆
     */
    getDefaultDisplayFields: function (allFields) {
        const defaultFields = [];

        // 妫ｆ牕鍘涘ǎ璇插閹碘偓閺堝褰堟穱婵囧Б閻ㄥ嫬鐡?
        allFields.forEach(field => {
            if (this.isFieldProtected(field)) {
                defaultFields.push({ ...field, template_defined: true });
            }
        });

        // 閻掕泛鎮楀ǎ璇插閸忔湹绮€涙顔岄敍鍫熸付?娑擃亷绱?
        const remainingFields = allFields.filter(field => !this.isFieldProtected(field));
        const maxFields = Math.max(0, 8 - defaultFields.length);
        const additionalFields = remainingFields.slice(0, maxFields);

        return [...defaultFields, ...additionalFields];
    },

    // 閸︺劌鐡у▓鐢稿帳缂冾喖鑴婄粣妤€鍨垫慨瀣閺冭埖褰侀崣鏍х摟濞堝吀淇婇幁?
    extractFieldsFromDOM: function (tableId, type) {
        const instance = this.instances[tableId];
        if (!instance) return [];

        const container = instance.container[0] || instance.container; // 绾喕绻氶弰鐤峅M閸忓啰绀?
        let fields = [];

        if (type === 'display') {
            // 娴犲氦銆冮弽鐓庛仈闁劍褰侀崣鏍х摟濞?
            const thElements = container.querySelectorAll('th[data-field]');
            thElements.forEach(function (th) {
                const fieldName = th.getAttribute('data-field');
                const dataWField = th.getAttribute('data-w-field');

                let fieldConfig = {
                    name: fieldName,
                    type: th.getAttribute('data-type') || 'text',
                    sortable: th.getAttribute('data-sortable') === 'true',
                    visible: th.getAttribute('data-visible') !== 'false',
                    editable: th.getAttribute('data-editable') === 'true',
                    searchable: th.getAttribute('data-searchable') === 'true',
                    resizable: th.getAttribute('data-resizable') === 'true',
                    width: th.getAttribute('data-width') || '',
                    min_width: th.getAttribute('data-min-width') || '',
                    max_width: th.getAttribute('data-max-width') || '',
                    placeholder: th.getAttribute('data-placeholder') || '',
                    options: th.getAttribute('data-options') || '',
                    class: th.getAttribute('data-class') || '',
                    style: th.getAttribute('data-style') || '',
                    formatter: th.getAttribute('data-formatter') || '',
                    validator: th.getAttribute('data-validator') || '',
                    default: th.getAttribute('data-default') || '',
                    belong: th.getAttribute('data-belong') || 't-header',
                    template_defined: th.getAttribute('data-template-defined') === 'true',
                    field_defined: th.getAttribute('data-field-defined') === 'true',
                    from_field: th.getAttribute('data-from-field') === 'true',
                    content: th.getAttribute('data-content') || fieldName,
                    label: th.getAttribute('data-content') || fieldName,
                    // 閹稿洤鐣剧€涙顔屾妯款吇閸欘垯浜掔粔璇插З閿涘矂娅庨棃鐐存绾喛顔曠純顔昏礋false
                    display_orderable: th.getAttribute('data-display-orderable') !== 'false' && th.getAttribute('data-display-orderable') !== '0'
                };

                // 婵″倹鐏夐張濉猘ta-w-field鐏炵偞鈧嶇礉鐟欙絾鐎絁SON闁板秶鐤?
                if (dataWField) {
                    try {
                        const jsonConfig = JSON.parse(dataWField);
                        fieldConfig = { ...fieldConfig, ...jsonConfig };
                    } catch (e) {
                        console.warn('extractFieldsFromDOM: 鐟欙絾鐎絛ata-w-field婢惰精瑙?', e);
                    }
                }

                // 閸欘亝褰侀崣鏍侀弶鍨暰娑斿娈戠€涙顔?
                if (fieldConfig.template_defined || fieldConfig.field_defined || fieldConfig.from_field) {
                    fields.push(fieldConfig);
                }
            });
        } else if (type === 'filter') {
            // 娴犲海鐡柅澶婃珤閹绘劕褰囩€涙顔?
            const filterElements = container.querySelectorAll('.filter-field[data-field]');
            filterElements.forEach(function (filter) {
                const fieldName = filter.getAttribute('data-field');
                const dataWField = filter.getAttribute('data-w-field');

                let fieldConfig = {
                    name: fieldName,
                    type: filter.getAttribute('data-type') || 'text',
                    visible: filter.getAttribute('data-visible') !== 'false',
                    searchable: filter.getAttribute('data-searchable') === 'true',
                    placeholder: filter.getAttribute('data-placeholder') || '',
                    options: filter.getAttribute('data-options') || '',
                    class: filter.getAttribute('data-class') || '',
                    style: filter.getAttribute('data-style') || '',
                    validator: filter.getAttribute('data-validator') || '',
                    default: filter.getAttribute('data-default') || '',
                    belong: filter.getAttribute('data-belong') || 't-filter',
                    template_defined: filter.getAttribute('data-template-defined') === 'true',
                    field_defined: filter.getAttribute('data-field-defined') === 'true',
                    from_field: filter.getAttribute('data-from-field') === 'true',
                    content: filter.getAttribute('data-content') || fieldName,
                    label: filter.getAttribute('data-content') || fieldName,
                    // 閹稿洤鐣剧€涙顔屾妯款吇閸欘垯浜掔粔璇插З閿涘矂娅庨棃鐐存绾喛顔曠純顔昏礋false
                    display_orderable: filter.getAttribute('data-display-orderable') !== 'false' && filter.getAttribute('data-display-orderable') !== '0'
                };

                // 婵″倹鐏夐張濉猘ta-w-field鐏炵偞鈧嶇礉鐟欙絾鐎絁SON闁板秶鐤?
                if (dataWField) {
                    try {
                        const jsonConfig = JSON.parse(dataWField);
                        fieldConfig = { ...fieldConfig, ...jsonConfig };
                    } catch (e) {
                        console.warn('extractFieldsFromDOM: 鐟欙絾鐎絛ata-w-field婢惰精瑙?', e);
                    }
                }

                // 閸欘亝褰侀崣鏍侀弶鍨暰娑斿娈戠€涙顔?
                if (fieldConfig.template_defined || fieldConfig.field_defined || fieldConfig.from_field) {
                    fields.push(fieldConfig);
                }
            });
        }

        console.log('extractFieldsFromDOM: 閹绘劕褰囬崚鐗埬侀弶鍨摟濞?', type, fields);
        return fields;
    },

    // 娣囶喗鏁煎〒鍙夌厠闁槒绶敍灞芥祼閸栨潊ield鐎涙顔岀悰灞艰礋
    isFieldConfigLocked: function (field) {
        return field.field_defined === true || field.field_defined === 'true' || field.template_defined === true || field.template_defined === 'true';
    },

    // 閸︺劍瑕嗛弻鎾村瘻闁筋喖鎷版潏鎾冲弳閺冭绱?
    // 娓氬顩ч梾鎰閹稿鎸?
    // <button ... ${DataTableManager.isFieldConfigLocked(field) ? 'disabled style="display:none"' : ''}>
    // 娓氬顩ч幒鎺戠碍閹稿鎸?
    // <button ... ${DataTableManager.isFieldConfigLocked(field) ? 'disabled style="display:none"' : ''}>
    // 閸忚泛鐣犳潏鎾冲弳?
    // <input ... ${DataTableManager.isFieldConfigLocked(field) ? 'disabled' : ''}>
    // 閸忔湹缍戦崝鐔诲厴閹稿鍘ょ純顔款啎?

    // 閸掋倖鏌囩€涙顔岄弰顖氭儊閸忎浇顔忛梾鎰
    isFieldHideAllowed: function (field) {
        // field_defined/template_defined鐎涙顔屽姝岀箼娑撳秴褰查梾鎰
        if (field.field_defined === true || field.field_defined === 'true' || field.template_defined === true || field.template_defined === 'true') {
            return false;
        }
        // 閸忚泛鐣犵€涙顔岄幐濉縤sible闁板秶鐤?
        return field.visible !== false && field.visible !== 'false';
    },

    // 閸掋倖鏌囩€涙顔岄弰顖氭儊閸忎浇顔忛幒鎺戠碍
    isFieldSortable: function (field) {
        return field.sortable === true || field.sortable === 'true';
    },

    // 閸掋倖鏌囩€涙顔岄弰顖氭儊閸忎浇顔忕紓鏍帆
    isFieldEditable: function (field) {
        return field.editable === true || field.editable === 'true';
    },

    // 濞撳弶鐓嬮幐澶愭尦閸滃矁绶崗銉︽娑撱儲鐗搁幐濉猘ta-鐏炵偞鈧勫付?
    // 闂呮劘妫岄幐澶愭尦?
    // <button ... ${DataTableManager.isFieldHideAllowed(field) ? '' : 'disabled style="display:none"'}>
    // 閹烘帒绨幐澶愭尦?
    // <button ... ${DataTableManager.isFieldSortable(field) ? '' : 'disabled style="display:none"'}>
    // 缂傛牞绶潏鎾冲弳?
    // <input ... ${DataTableManager.isFieldEditable(field) ? '' : 'disabled'}>
    // 閸忚泛鐣犻幙宥勭稊閸氬瞼鎮?

    /**
     * 濞撳弶鐓嬬粵娑⑩偓澶婂隘閸?
     */
    renderFilter: function (tableId, fields) {
        const instance = this.instances[tableId];
        if (!instance) return;

        const filterContainer = (instance.container[0] || instance.container).querySelector('.datatable-filter');
        if (!filterContainer) return;

        // 绾喕绻氱€涙顔屾い鍝勭碍濮濓絿鈥?
        const templateFields = fields.filter(field =>
            field.template_defined || field.field_defined || field.from_field
        );
        const userFields = fields.filter(field =>
            !field.template_defined && !field.field_defined && !field.from_field
        );
        const orderedFields = [...templateFields, ...userFields];

        let filterHtml = '';
        orderedFields.forEach(field => {
            const isProtected = this.isFieldProtected(field);
            const canSearch = isProtected ?
                (field.searchable === true || field.searchable === 'true') :
                (field.searchable !== false);

            if (canSearch) {
                const inputType = field.type === 'select' ? 'select' : 'text';
                const placeholder = field.placeholder || `鐠囩柉绶崗?{field.label || field.name}`;

                if (inputType === 'select') {
                    const options = field.options ? field.options.split(',').map(opt => {
                        const [value, label] = opt.split(':');
                        return `<option value="${value}">${label || value}</option>`;
                    }).join('') : '';

                    filterHtml += `
                        <div class="filter-item" data-field="${field.name}">
                            <label>${field.label || field.name}:</label>
                            <select class="filter-input" data-field="${field.name}" placeholder="${placeholder}">
                                <option value="">${placeholder}</option>
                                ${options}
                            </select>
                        </div>`;
                } else {
                    filterHtml += `
                        <div class="filter-item" data-field="${field.name}">
                            <label>${field.label || field.name}:</label>
                            <input type="text" class="filter-input" data-field="${field.name}" placeholder="${placeholder}" />
                        </div>`;
                }
            }
        });

        filterContainer.innerHTML = filterHtml;

        // 缂佹垵鐣剧粵娑⑩偓澶夌皑娴?
        filterContainer.querySelectorAll('.filter-input').forEach(input => {
            input.addEventListener('input', function () {
                const fieldName = this.getAttribute('data-field');
                const value = this.value;
                DataTableManager.applyFilter(tableId, fieldName, value);
            });

            input.addEventListener('change', function () {
                const fieldName = this.getAttribute('data-field');
                const value = this.value;
                DataTableManager.applyFilter(tableId, fieldName, value);
            });
        });
    },

    /**
     * 閹烘帒绨悰銊︾壐
     */
    sortTable: function (tableId, fieldName) {
        const instance = this.instances[tableId];
        if (!instance) return;

        const field = instance.displayFields.find(f => f.name === fieldName);
        if (!field) return;

        const isProtected = this.isFieldProtected(field);
        const canSort = isProtected ?
            (field.sortable === true || field.sortable === 'true') :
            (field.sortable !== false);

        if (!canSort) {
            console.warn('sortTable: 鐎涙顔屾稉宥呭帒鐠佸憡甯撴惔?', fieldName);
            return;
        }

        // 閸掑洦宕查幒鎺戠碍閺傜懓鎮?
        const currentSort = instance.sorts[fieldName];
        const newSort = currentSort === 'asc' ? 'desc' : 'asc';

        // 濞撳懘娅庨崗鏈电铂鐎涙顔岄惃鍕笓?
        instance.sorts = {};
        instance.sorts[fieldName] = newSort;

        // 闁插秵鏌婇崝鐘烘祰閺佺増宓?
        this.loadData(instance);
    },

    /**
     * 鎼存梻鏁ょ粵?
     */
    applyFilter: function (tableId, fieldName, value) {
        const instance = this.instances[tableId];
        if (!instance) return;

        if (value) {
            instance.filters[fieldName] = value;
        } else {
            delete instance.filters[fieldName];
        }

        // 闁插秶鐤嗛崚鎵儑娑撯偓?
        instance.currentPage = 1;

        // 闁插秵鏌婇崝鐘烘祰閺佺増宓?
        this.loadData(instance);
    },

    /**
     * 閸旂姾娴囬弫鐗堝祦
     */
    loadData: function (instance) {
        // 鏉╂瑩鍣锋惔鏃囶嚉鐎圭偟骞囬弫鐗堝祦閸旂姾娴囬柅鏄忕帆
        // 閺嶈宓乮nstance.filters, instance.sorts, instance.currentPage缁涘寮?
        console.log('loadData: 閸旂姾娴囬弫鐗堝祦', {
            filters: instance.filters,
            sorts: instance.sorts,
            page: instance.currentPage
        });
    },

    // 濡偓閺屻儲妲搁崥锔胯礋濡剝婢樼€涙顔?
    isPrimaryOrIndexField: function (field) {
        return (
            field.is_primary === true ||
            field.primary === true ||
            field.primary_key === true ||
            field.pk === true ||
            ['id', 'ID', 'Id', 'primary', 'pk', 'primary_key', 'is_primary'].includes(field.name)
        );
    },

    /**
     * 閸旂姾娴囧Ο鈥崇€风€涙顔岄柊宥囩枂閿涘牆甯慨瀣煙濞夋洩绱濇导姘承曢崣鎴ｃ€冮弽濂稿櫢閺傜増鐎鐚寸礆
     */
    loadModelFields: function (tableId) {
        const instance = this.instances[tableId];
        if (!instance) return;
        if (!instance.apiUrl) {
            console.error('[DataTableManager] apiUrl閺堫亣顔曠純顕嗙礉閺冪姵纭堕崝鐘烘祰鐎涙顔岄柊宥囩枂');
            return;
        }

        // 1. 閹绘劕褰囧Ο鈩冩緲鐎涙顔岄敍鍧抜eld閹稿洤鐣剧€涙顔岄敍?
        const templateFields = this.extractFieldsFromDOM(tableId, 'display');
        const templateFilterFields = this.extractFieldsFromDOM(tableId, 'filter');
        console.log('loadModelFields: 濡剝婢樼€涙顔?', templateFields);
        console.log('loadModelFields: 濡剝婢樼粵娑⑩偓澶婄摟濞?', templateFilterFields);
        instance.templateFields = templateFields;
        instance.templateFilterFields = templateFilterFields;

        console.log('loadModelFields: 瀵偓婵濮炴潪钘夌摟濞堢敻鍘ょ純?', {
            tableId,
            model: instance.options.model,
            scope: instance.options.scope
        });

        // 2. 鐠囬攱鐪伴幒銉ュ經
        const isInFieldConfig = document.querySelector('#w-field-config-modal-' + tableId) !== null;
        if (instance.allFields && instance.allFields.length > 0) {
            // 婵″倹鐏夐張澶岀处鐎涙ɑ鏆熼幑顕嗙礉閻╁瓨甯存担璺ㄦ暏
            console.log('loadModelFields: 娴ｈ法鏁ょ紓鎾崇摠閻ㄥ嫬鐡у▓鍨殶閹?');
            if (isInFieldConfig) {
                this.renderModelFieldsFromData(tableId, {
                    all_fields: instance.allFields,
                    display_fields: instance.displayFields,
                    filter_fields: instance.filterFields
                });
            }
            return;
        }

        if (isInFieldConfig) {
            // 閸︺劌鐡у▓鐢稿帳缂冾喖鑴婄粣妞捐厬閺勫墽銇歭oading
            const availableFields = document.getElementById('w-available-fields-' + tableId);
            const availableFieldsFilter = document.getElementById('w-available-fields-filter-' + tableId);
            if (availableFields) {
                availableFields.innerHTML = '<div class="w-text-center w-text-muted w-py-4"><i class="fas fa-spinner fa-spin"></i> ' + __("閸旂姾娴囨稉?..") + '</div>';
            }
            if (availableFieldsFilter) {
                availableFieldsFilter.innerHTML = '<div class="w-text-center w-text-muted w-py-4"><i class="fas fa-spinner fa-spin"></i> ' + __("閸旂姾娴囨稉?..") + '</div>';
            }
        }

        this.requestJson(instance, 'fields', {
            table_id: tableId,
            model: instance.options.model,
            scope: instance.options.scope
        })
            .then(response => {
                // 3. 閸氬牆鑻熷Ο鈩冩緲鐎涙顔岄崪灞惧复閸欙絽鐡у▓?
                let apiFields = (response.data && response.data.all_fields) ? response.data.all_fields : [];
                let mergedFields = this.mergeTemplateAndApiFields(templateFields, apiFields);
                // 閸氬牆鑻焒ilter鐎涙顔?
                let apiFilterFields = (response.data && response.data.filter_fields) ? response.data.filter_fields : [];
                let mergedFilterFields = this.mergeTemplateAndApiFields(templateFilterFields, apiFilterFields);

                // 4. 娣囨繃濮㈠Ο鈩冩緲鐎涙顔岄敍宀€鈥樻穱婵嗙暊娴狀剙顫愮紒鍫濇躬閺勫墽銇氱€涙顔屾稉?
                let displayFields = response.data.display_fields || [];
                const templateFieldNames = new Set(templateFields.map(f => f.name));

                // 绾喕绻氬Ο鈩冩緲鐎涙顔屾慨瀣矒閸︺劍妯夌粈鍝勭摟濞堝吀鑵?
                templateFields.forEach(templateField => {
                    const existingIndex = displayFields.findIndex(f => f.name === templateField.name);
                    if (existingIndex === -1) {
                        // 濡剝婢樼€涙顔屾稉宥呮躬閺勫墽銇氱€涙顔屾稉顓ㄧ礉濞ｈ濮為崚鏉跨磻婢?
                        displayFields.unshift(templateField);
                    } else {
                        // 濡剝婢樼€涙顔屽鎻掔摠閸︻煉绱濋悽銊δ侀弶鍨摟濞堝灚娴涢幑顫礄娣囨繃濮㈠Ο鈩冩緲闁板秶鐤嗛敍?
                        displayFields[existingIndex] = templateField;
                    }
                });

                // 5. 濞ｈ濮為悽銊﹀煕闁瀚ㄩ惃鍕摟濞堢绱欓棃鐐茨侀弶鍨摟濞堢绱?
                const userSelectedFields = displayFields.filter(field => !templateFieldNames.has(field.name));
                console.log('loadModelFields: 閻劍鍩涢柅澶嬪閻ㄥ嫬鐡у▓?', userSelectedFields);

                // 6. 婢跺嫮鎮婇崣妞剧箽閹躲倕鐡у▓鐢垫畱闁板秶鐤?
                displayFields = displayFields.map(field => {
                    const isProtected = this.isFieldProtected(field);
                    const isPrimaryOrIndex = field.is_primary === true || field.primary === true || field.primary_key === true || field.pk === true || ['id', 'ID', 'Id', 'primary', 'pk', 'primary_key', 'is_primary'].includes(field.name);
                    if (isProtected) {
                        // 娑撳鏁?缁便垹绱╃€涙顔屾稉宥堝厴閹烘帒绨崪宀€些閸?
                        if (isPrimaryOrIndex) {
                            return {
                                ...field,
                                sortable: false,
                                editable: field.editable === true || field.editable === 'true',
                                searchable: field.searchable !== false,
                                resizable: field.resizable !== false,
                                visible: field.visible !== false,
                                display_orderable: false
                            };
                        }
                        // 閸忚泛鐣犻崣妞剧箽閹躲倕鐡у▓鐢哥帛鐠併倕褰叉禒銉﹀笓鎼村繐鎷扮粔璇插З
                        return {
                            ...field,
                            sortable: field.sortable !== false && field.sortable !== 'false',
                            editable: field.editable === true || field.editable === 'true',
                            searchable: field.searchable !== false,
                            resizable: field.resizable !== false,
                            visible: field.visible !== false,
                            display_orderable: field.display_orderable !== false && field.display_orderable !== 0 && field.display_orderable !== 'false' && field.display_orderable !== '0'
                        };
                    }
                    return field;
                });

                // 7. 绾喕绻氶幐鍥х暰鐎涙顔岄幒鎺戝煂閸撳秹娼?
                const displayTemplateFields = displayFields.filter(field =>
                    field.template_defined || field.field_defined || field.from_field
                );
                const userFields = displayFields.filter(field =>
                    !field.template_defined && !field.field_defined && !field.from_field
                );

                // 闁插秵鏌婇幒鎺戠碍閿涙碍膩閺夊灝鐡у▓闈涙躬閸撳稄绱濋悽銊﹀煕鐎涙顔岄崷銊ユ倵
                displayFields = [...displayTemplateFields, ...userFields];

                // 8. 娴肩娀鈧帒鎮庨獮璺烘倵閻ㄥ嫬鐡у▓闈涘煂濞撳弶鐓?
                if (isInFieldConfig) {
                    this.renderModelFieldsFromData(tableId, {
                        all_fields: mergedFields,
                        display_fields: displayFields,
                        filter_fields: mergedFilterFields
                    });
                } else {
                    this.rebuildTableFromConfig(tableId, mergedFields, mergedFilterFields);
                }
            })
            .catch(error => {
                this.showError(tableId, error || __('閼惧嘲褰囩€涙顔屾径杈Е'));
            });
    },

    /**
     * 閸掓繂顫愰崠鏍х杽閺冨墎绱潏鎴濆閼虫枻绱欑€圭偘绶ラ梾鏃傤瀲閿?
     */
    initInlineEdit: function (tableId) {
        const instance = this.instances[tableId];
        if (!instance) return;
        
        const table = document.getElementById(tableId);
        if (!table) return;

        // 缂佹垵鐣鹃崡鏇炲帗閺嶇厧寮婚崙璁崇皑娴犺绱欐担璺ㄦ暏鐎圭偘绶ラ崨钘夋倳缁屾椽妫块敍?
        const dblClickHandler = (e) => {
            const cell = e.target.closest('td[data-editable="true"]');
            if (cell && !instance.editingState.isEditing) {
                this.startCellEdit(cell, tableId);
            }
        };
        table.addEventListener('dblclick', dblClickHandler);
        // 鐎涙ê鍋嶆禍瀣╂婢跺嫮鎮婇崳顭掔礉閻劋绨〒鍛倞
        if (!instance.eventHandlers) instance.eventHandlers = {};
        instance.eventHandlers['dblclick'] = dblClickHandler;

        // 缂佹垵鐣鹃柨顔炬磸娴滃娆㈤敍鍫滃▏閻劌鐤勬笟瀣嚒閸氬秶鈹栭梻杈剧礉绾喕绻氶崣顏勵槱閻炲棗缍嬮崜宥呯杽娓氬娈戠紓鏍帆閿?
        const keydownHandler = (e) => {
            if (instance.editingState.isEditing) {
                if (e.key === 'Enter') {
                    this.saveCellEdit(tableId);
                } else if (e.key === 'Escape') {
                    this.cancelCellEdit(tableId);
                }
            }
        };
        document.addEventListener('keydown', keydownHandler);
        instance.eventHandlers['keydown'] = keydownHandler;
    },

    /**
     * 瀵偓婵宕熼崗鍐╃壐缂傛牞绶敍鍫濈杽娓氬娈х粋浼欑礆
     */
    startCellEdit: function (cell, tableId) {
        // 闁俺绻?cell 閹垫儳鍩?tableId閿涘牆顩ч弸婊勬弓閹绘劒绶甸敍?
        if (!tableId) {
            const table = cell.closest('table, .w-datatable');
            if (table) {
                tableId = table.id || table.closest('[id]')?.id;
            }
        }
        
        const instance = this.instances[tableId];
        if (!instance) {
            console.warn('DataTable instance not found for tableId:', tableId);
            return;
        }
        
        if (instance.editingState.isEditing) return;

        instance.editingState.isEditing = true;
        instance.editingState.currentCell = cell;
        instance.editingState.originalValue = cell.textContent.trim();
        instance.editingState.editingRow = cell.closest('tr');

        const fieldType = cell.getAttribute('data-field-type') || 'text';
        const fieldName = cell.getAttribute('data-field');

        // 閸掓稑缂撶紓鏍帆閸?
        const editor = this.createCellEditor(fieldType, instance.editingState.originalValue);

        // 閺囨寧宕查崡鏇炲帗閺嶇厧鍞寸€?
        cell.innerHTML = '';
        cell.appendChild(editor);

        // 閼辨氨鍔嶇紓鏍帆閸?
        editor.focus();
        if (editor.select) editor.select();

        // 濞ｈ濮炵紓鏍帆閻樿埖鈧焦鐗卞?
        cell.classList.add('editing');
    },

    /**
     * 閸掓稑缂撻崡鏇炲帗閺嶈偐绱潏鎴濇珤
     */
    createCellEditor: function (type, value) {
        let editor;

        switch (type) {
            case 'select':
                editor = document.createElement('select');
                editor.className = 'form-control form-control-sm';
                // 鏉╂瑩鍣烽棁鈧憰浣圭壌閹诡喖鐡у▓鐢稿帳缂冾喗鍧婇崝鐘烩偓澶愩€?
                break;

            case 'textarea':
                editor = document.createElement('textarea');
                editor.className = 'form-control form-control-sm';
                editor.rows = 2;
                break;

            case 'number':
                editor = document.createElement('input');
                editor.type = 'number';
                editor.className = 'form-control form-control-sm';
                break;

            case 'date':
                editor = document.createElement('input');
                editor.type = 'date';
                editor.className = 'form-control form-control-sm';
                break;

            default:
                editor = document.createElement('input');
                editor.type = 'text';
                editor.className = 'form-control form-control-sm';
        }

        editor.value = value;

        // 缂佹垵鐣炬径杈╁妽娴滃娆?
        editor.addEventListener('blur', () => {
            setTimeout(() => this.saveCellEdit(), 100);
        });

        return editor;
    },

    /**
     * 娣囨繂鐡ㄩ崡鏇炲帗閺嶈偐绱潏鎴礄鐎圭偘绶ラ梾鏃傤瀲閿?
     */
    saveCellEdit: function (tableId) {
        // 闁俺绻冭ぐ鎾冲缂傛牞绶悩鑸碘偓浣瑰閸?tableId閿涘牆顩ч弸婊勬弓閹绘劒绶甸敍?
        if (!tableId) {
            for (const id in this.instances) {
                if (this.instances[id].editingState.isEditing) {
                    tableId = id;
                    break;
                }
            }
        }
        
        const instance = this.instances[tableId];
        if (!instance || !instance.editingState.isEditing) return;

        const cell = instance.editingState.currentCell;
        if (!cell) return;
        
        const editor = cell.querySelector('input, select, textarea');
        const newValue = editor ? editor.value : '';

        if (newValue !== instance.editingState.originalValue) {
            // 閸欐垿鈧椒绻氱€涙顕Ч?
            this.saveCellValue(cell, newValue, tableId);
        } else {
            // 閸婂吋婀弨鐟板綁閿涘瞼娲块幒銉︿划婢?
            this.restoreCellContent(cell, instance.editingState.originalValue);
        }
        this.resetEditingState(tableId);
    },

    /**
     * 閸欐牗绉烽崡鏇炲帗閺嶈偐绱潏鎴礄鐎圭偘绶ラ梾鏃傤瀲閿?
     */
    cancelCellEdit: function (tableId) {
        // 闁俺绻冭ぐ鎾冲缂傛牞绶悩鑸碘偓浣瑰閸?tableId閿涘牆顩ч弸婊勬弓閹绘劒绶甸敍?
        if (!tableId) {
            for (const id in this.instances) {
                if (this.instances[id].editingState.isEditing) {
                    tableId = id;
                    break;
                }
            }
        }
        
        const instance = this.instances[tableId];
        if (!instance || !instance.editingState.isEditing) return;

        const cell = instance.editingState.currentCell;
        if (!cell) return;
        
        this.restoreCellContent(cell, instance.editingState.originalValue);
        this.resetEditingState(tableId);
    },

    /**
     * 閹垹顦查崡鏇炲帗閺嶇厧鍞寸€?
     */
    restoreCellContent: function (cell, value) {
        cell.innerHTML = value;
        cell.classList.remove('editing');
    },

    /**
     * 闁插秶鐤嗙紓鏍帆閻樿埖鈧緤绱欑€圭偘绶ラ梾鏃傤瀲閿?
     */
    resetEditingState: function (tableId) {
        const instance = this.instances[tableId];
        if (!instance) return;
        
        instance.editingState.isEditing = false;
        instance.editingState.currentCell = null;
        instance.editingState.originalValue = null;
        instance.editingState.editingRow = null;
    },

    /**
     * 娣囨繂鐡ㄩ崡鏇炲帗閺嶇厧鈧厧鍩岄張宥呭閸ｎ煉绱欑€圭偘绶ラ梾鏃傤瀲閿?
     */
    saveCellValue: function (cell, newValue, tableId) {
        // 闁俺绻?cell 閹垫儳鍩?tableId閿涘牆顩ч弸婊勬弓閹绘劒绶甸敍?
        if (!tableId) {
            const table = cell.closest('.w-datatable');
            if (table) {
                tableId = table.id;
            }
        }
        
        const instance = this.instances[tableId];
        if (!instance) {
            console.warn('DataTable instance not found for tableId:', tableId);
            return;
        }
        
        const row = cell.closest('tr');
        const recordId = row.getAttribute('data-id');
        const fieldName = cell.getAttribute('data-field');
        const model = instance.options.model || instance.container.getAttribute('data-model');

        // 閺勫墽銇氭穱婵嗙摠閻樿埖鈧?
        cell.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

        // 閸欐垿鈧椒绻氱€涙顕Ч鍌︾礄娴ｈ法鏁ょ€圭偘绶ラ惃?API URL閿?
        this.requestJson(instance, 'update', {
            model: model,
            id: recordId,
            data: {
                [fieldName]: newValue
            }
        })
            .then(data => {
                if (data.success) {
                    // 娣囨繂鐡ㄩ幋鎰
                    cell.innerHTML = newValue;
                    cell.classList.add('save-success');
                    setTimeout(() => cell.classList.remove('save-success'), 2000);
                } else {
                    // 娣囨繂鐡ㄦ径杈Е
                    this.restoreCellContent(cell, instance.editingState.originalValue);
                    this.showError(tableId, data.message || __('娣囨繂鐡ㄦ径杈Е'));
                }
            })
            .catch(error => {
                // 缂冩垹绮堕柨娆掝嚖
                this.restoreCellContent(cell, instance.editingState.originalValue);
                this.showError(tableId, __('缂冩垹绮堕柨娆掝嚖閿?{1}', error.message));
            });
    }
    };
    
    // 鐏?DataTableManager 閺嗘挳婀堕崚?window 娑?
    if (typeof window !== 'undefined') {
        window.DataTableManager = DataTableManager;
    }
}

// 閸忋劌鐪禍瀣╂婵梹澧敍灞炬暜閹镐礁濮╅幀浣瑰絻閸忋儳娈戠€涙顔岀拋鍓х枂閹稿鎸?
document.addEventListener('click', function (e) {
    const btn = e.target.closest('.w-btn[data-w-action="field-config"]');
    if (btn && window.DataTableManager) {
        const tableId = btn.getAttribute('data-table');
        if (tableId) {
            window.DataTableManager.openFieldConfig(tableId);
        }
    }
});

if (typeof window !== 'undefined' && !window._datatableActionDelegated) {
    window._datatableActionDelegated = true;
    document.addEventListener('click', function (event) {
        const trigger = event.target.closest('[data-datatable-action]');
        if (!trigger || !window.DataTableManager) {
            return;
        }

        const manager = window.DataTableManager;
        const action = trigger.getAttribute('data-datatable-action');
        const tableId = trigger.getAttribute('data-table') || '';
        const fieldName = trigger.getAttribute('data-field') || '';
        const fieldType = trigger.getAttribute('data-field-type') || trigger.getAttribute('data-type') || '';
        const direction = trigger.getAttribute('data-direction') || '';
        const format = trigger.getAttribute('data-format') || 'excel';

        switch (action) {
            case 'refresh-data':
                event.preventDefault();
                manager.refreshData(tableId);
                break;
            case 'clear-header-config':
                event.preventDefault();
                manager.clearHeaderConfig(tableId);
                break;
            case 'clear-filter-config':
                event.preventDefault();
                manager.clearFilterConfig(tableId);
                break;
            case 'clear-all-config':
                event.preventDefault();
                manager.clearAllConfig(tableId);
                break;
            case 'export-data':
                event.preventDefault();
                manager.exportData(tableId, format);
                break;
            case 'close-field-config':
                event.preventDefault();
                manager.closeFieldConfig(tableId);
                break;
            case 'save-field-config':
                event.preventDefault();
                manager.saveFieldConfig(tableId);
                break;
            case 'cancel-export':
                event.preventDefault();
                if (typeof manager.cancelExport === 'function') {
                    manager.cancelExport();
                }
                break;
            case 'close-export-modal':
                event.preventDefault();
                document.querySelectorAll('.w-export-modal').forEach(modal => modal.remove());
                break;
            case 'save-row':
                event.preventDefault();
                manager.saveRow(tableId);
                break;
            case 'retry-error': {
                event.preventDefault();
                const retryId = trigger.getAttribute('data-retry-id') || '';
                const callback = window._datatableRetryCallbacks && window._datatableRetryCallbacks[retryId];
                if (typeof callback === 'function') {
                    callback();
                }
                break;
            }
            case 'add-field':
                event.preventDefault();
                manager.addField(tableId, fieldName, fieldType);
                break;
            case 'remove-field':
                event.preventDefault();
                manager.removeField(tableId, fieldName, fieldType);
                break;
            case 'move-field':
                event.preventDefault();
                manager.moveField(tableId, fieldName, direction, fieldType);
                break;
            case 'toggle-field-config':
                event.preventDefault();
                manager.toggleFieldConfig(tableId, fieldName, fieldType);
                break;
            case 'toggle-filter-accordion':
                event.preventDefault();
                manager.toggleFilterAccordion(tableId);
                break;
        }
    });
}

// 閸掓繂顫愰崠鏍︾瑓閹峰褰嶉崡鏇炲閼虫枻绱欓崣顏勬躬閸楁洑绶ュΟ鈥崇础娑撳澧界悰灞肩濞嗏槄绱?
if (typeof window !== 'undefined' && window.DataTableManager && !window.DataTableManager._initialized) {
    window.DataTableManager._initialized = true;
    
    // 閸掓繂顫愰崠鏍у毐閺?
    var initDataTableManager = function () {
        if (window.DataTableManager) {
            window.DataTableManager.initDropdowns();
            window.DataTableManager.initTheme();
            window.DataTableManager.initThemeConfig();
            window.DataTableManager.initImportantFlags();
            window.DataTableManager.loadThemeConfig();

            // 閸掓繂顫愰崠鏍ㄥ閺堝銆冮弽鑲╂畱鐎圭偞妞傜紓鏍帆閸旂喕鍏?
            document.querySelectorAll('.w-datatable[data-editable="true"]').forEach(table => {
                window.DataTableManager.initInlineEdit(table.id);
            });
        }
    };
    
    // 閺嶈宓佹い鐢告桨閸旂姾娴囬悩鑸碘偓浣稿枀鐎规艾顩ф担鏇炲灥婵瀵?
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDataTableManager);
    } else {
        // 妞ょ敻娼板鎻掑鏉炴枻绱濋惄瀛樺复閸掓繂顫愰崠?
        initDataTableManager();
    }
}

// 閼奉亜濮╃紙鏄忕槯閹碘偓閺堝鐢玠ata-w-i18n閻ㄥ嫬鍘撶槐?
function applyI18n() {
    document.querySelectorAll('[data-w-i18n]').forEach(function (el) {
        var key = el.getAttribute('data-w-i18n');
        if (key && typeof window.__ === 'function') {
            el.innerText = __(key);
        }
    });
}
// 妞ょ敻娼伴崝鐘烘祰閸滃本鐦″▎鈥宠剨缁愭瑕嗛弻鎾虫倵闁€熺殶閻?
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', applyI18n);
} else {
    applyI18n();
}

(function () {
    if (typeof window === 'undefined' || !window.DataTableManager) {
        return;
    }

    const manager = window.DataTableManager;

    function resolveApiUrl(url, fallback) {
        const raw = String(url || fallback || '').trim();
        if (!raw) {
            return '';
        }

        if (/^https?:\/\//i.test(raw) || raw.startsWith('/')) {
            return raw;
        }

        return '/' + raw.replace(/^\/+/, '');
    }

    function toNumber(value, fallback) {
        const parsed = Number(value);
        return Number.isFinite(parsed) ? parsed : fallback;
    }

    function showContainerLoading(instance, isLoading) {
        const container = instance.container && instance.container[0] ? instance.container[0] : instance.container;
        const loading = container ? container.querySelector('.datatable-loading') : null;
        const content = container ? container.querySelector('.datatable-content') : null;

        if (container) {
            container.classList.toggle('loading', !!isLoading);
        }
        if (loading) {
            loading.style.display = isLoading ? '' : 'none';
        }
        if (content) {
            content.style.display = isLoading ? 'none' : '';
        }
    }

    function operationName(instance, endpoint) {
        const normalized = String(endpoint || '').replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());
        const operations = instance && instance.options && instance.options.operations ? instance.options.operations : {};
        return operations[normalized] || operations[endpoint] || normalized;
    }

    async function resourceRequest(instance, endpoint, payload, requestOptions) {
        if (!instance || !instance.options || !instance.options.workerApi) {
            throw new Error('DataTable frontend requests require Weline.Api worker mode.');
        }

        if (!window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
            throw new Error('Weline.Api.resource is not available');
        }

        const provider = instance.options.apiProvider || 'datatable';
        const api = await window.Weline.Api.resource(provider);
        const method = operationName(instance, endpoint);
        if (!api || typeof api[method] !== 'function') {
            throw new Error('Weline.Api operation is not available: ' + provider + '.' + method);
        }

        return api[method](payload || {}, Object.assign({silent: true}, requestOptions || {}));
    }

    manager.resolveApiUrl = resolveApiUrl;
    manager.requestJson = resourceRequest;
    manager.buildApiUrl = function (instance, endpoint = '') {
        const baseUrl = resolveApiUrl(
            instance && instance.apiUrl ? instance.apiUrl : '',
            this.config.apiUrl
        );

        if (instance) {
            instance.apiUrl = baseUrl;
        }

        if (!endpoint) {
            return baseUrl;
        }

        return baseUrl.replace(/\/+$/, '') + '/' + String(endpoint).replace(/^\/+/, '');
    };

    manager.normalizePagination = function (payload, instance) {
        const raw = (payload && payload.pagination) || {};
        const page = Math.max(1, toNumber(raw.page ?? payload?.page ?? instance?.currentPage, 1));
        const pageSize = Math.max(1, toNumber(raw.pageSize ?? raw.limit ?? payload?.pageSize ?? payload?.limit ?? instance?.pageSize, 20));
        const total = Math.max(0, toNumber(raw.total ?? payload?.total, 0));
        const derivedLastPage = Math.max(1, Math.ceil(total / Math.max(1, pageSize)));
        const lastPage = Math.max(1, toNumber(raw.lastPage ?? payload?.pages, derivedLastPage));

        return {
            page: page,
            pageSize: pageSize,
            total: total,
            lastPage: lastPage,
            hasPrevPage: raw.hasPrevPage !== undefined ? !!raw.hasPrevPage : page > 1,
            hasNextPage: raw.hasNextPage !== undefined ? !!raw.hasNextPage : page < lastPage
        };
    };

    manager.applyFieldResponse = function (tableId, response) {
        const instance = this.instances[tableId];
        if (!instance) {
            return;
        }

        const payload = response && response.data ? response.data : response || {};
        const templateFields = this.extractFieldsFromDOM(tableId, 'display');
        const templateFilterFields = this.extractFieldsFromDOM(tableId, 'filter');

        instance.templateFields = templateFields;
        instance.templateFilterFields = templateFilterFields;

        const allFields = this.mergeTemplateAndApiFields(templateFields, payload.all_fields || []);
        const displayFieldsSource = (payload.cached_display_fields && payload.cached_display_fields.length)
            ? payload.cached_display_fields
            : (payload.display_fields || allFields);
        const filterFieldsSource = (payload.cached_filter_fields && payload.cached_filter_fields.length)
            ? payload.cached_filter_fields
            : (payload.filter_fields || templateFilterFields);

        instance.allFields = allFields;
        instance.displayFields = this.mergeTemplateAndApiFields(templateFields, displayFieldsSource);
        instance.filterFields = this.mergeTemplateAndApiFields(templateFilterFields, filterFieldsSource);

        if (!instance.displayFields.length) {
            instance.displayFields = allFields.filter(field => field.visible !== false);
        }

        const fieldConfigModal = document.getElementById('w-field-config-modal-' + tableId);
        if (fieldConfigModal && typeof this.renderModelFieldsFromData === 'function') {
            this.renderModelFieldsFromData(tableId, {
                all_fields: instance.allFields,
                display_fields: instance.displayFields,
                filter_fields: instance.filterFields
            });
        }
    };

    manager.loadModelFieldsForInit = function (tableId) {
        const instance = this.instances[tableId];
        if (!instance) {
            return;
        }

        this.requestJson(instance, 'fields', {
            table_id: tableId,
            model: instance.options.model,
            scope: instance.options.scope,
            join: instance.options.join || '',
            model_config: instance.options.modelConfig || {}
        })
            .then(response => {
                if (response.success || response.code == 200 || response.code === '200') {
                    this.applyFieldResponse(tableId, response);
                    this.loadData(instance);
                    return;
                }

                this.showError(tableId, response.msg || response.message || __('閸旂姾娴囩€涙顔屾径杈Е'));
            })
            .catch(error => {
                console.error('[DataTableManager] loadModelFieldsForInit failed', error);
                this.showError(tableId, error.message || __('閸旂姾娴囩€涙顔屾径杈Е'));
            });
    };

    manager.loadModelFields = function (tableId) {
        const instance = this.instances[tableId];
        if (!instance) {
            return;
        }

        if (instance.allFields && instance.allFields.length) {
            this.applyFieldResponse(tableId, {
                data: {
                    all_fields: instance.allFields,
                    display_fields: instance.displayFields,
                    filter_fields: instance.filterFields
                }
            });
            return;
        }

        this.requestJson(instance, 'fields', {
            table_id: tableId,
            model: instance.options.model,
            scope: instance.options.scope,
            join: instance.options.join || '',
            model_config: instance.options.modelConfig || {}
        })
            .then(response => {
                if (response.success || response.code == 200 || response.code === '200') {
                    this.applyFieldResponse(tableId, response);
                    return;
                }

                this.showError(tableId, response.msg || response.message || __('閸旂姾娴囩€涙顔屾径杈Е'));
            })
            .catch(error => {
                console.error('[DataTableManager] loadModelFields failed', error);
                this.showError(tableId, error.message || __('閸旂姾娴囩€涙顔屾径杈Е'));
            });
    };

    manager.saveFieldConfig = function (tableId) {
        const instance = this.instances[tableId];
        if (!instance) {
            return;
        }

        const configData = {
            all_fields: instance.allFields || [],
            display_fields: instance.displayFields || [],
            filter_fields: instance.filterFields || [],
            sort_direction: 'asc'
        };

        const saveButton = document.querySelector('#w-field-config-modal-' + tableId + ' .w-btn-primary');
        const originalText = saveButton ? saveButton.innerHTML : '';
        if (saveButton) {
            saveButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 娣囨繂鐡?..';
            saveButton.disabled = true;
        }

        this.requestJson(instance, 'save-config', {
            scope: instance.options.scope,
            table_id: tableId,
            display_fields: configData.display_fields,
            filter_fields: configData.filter_fields,
            config: configData
        })
            .then(response => {
                if (response.success || response.code == 200 || response.code === '200') {
                    this.closeFieldConfig(tableId);
                    this.renderTable(instance);
                    this.showSuccess(tableId, response.msg || response.message || __('闁板秶鐤嗗韫箽鐎?'));
                    return;
                }

                this.showError(tableId, response.msg || response.message || __('娣囨繂鐡ㄦ径杈Е'));
            })
            .catch(error => {
                console.error('[DataTableManager] saveFieldConfig failed', error);
                this.showError(tableId, error.message || __('娣囨繂鐡ㄦ径杈Е'));
            })
            .finally(() => {
                if (saveButton) {
                    saveButton.innerHTML = originalText;
                    saveButton.disabled = false;
                }
            });
    };

    manager.clearConfig = function (tableId, type) {
        const instance = this.instances[tableId];
        if (!instance) {
            return;
        }

        this.requestJson(instance, 'clear-config', {
            scope: instance.options.scope,
            table_id: tableId,
            type: type || 'all'
        })
            .then(response => {
                if (response.success || response.code == 200 || response.code === '200') {
                    if (!type || type === 'all' || type === 'header') {
                        instance.displayFields = [];
                    }
                    if (!type || type === 'all' || type === 'filter') {
                        instance.filterFields = [];
                    }

                    instance.allFields = [];
                    this.loadModelFields(tableId);
                    this.showSuccess(tableId, response.msg || response.message || __('闁板秶鐤嗗鏌ュ櫢缂?'));
                    return;
                }

                this.showError(tableId, response.msg || response.message || __('闁插秶鐤嗘径杈Е'));
            })
            .catch(error => {
                console.error('[DataTableManager] clearConfig failed', error);
                this.showError(tableId, error.message || __('闁插秶鐤嗘径杈Е'));
            });
    };

    manager.loadData = function (instance) {
        if (!instance) {
            return;
        }

        showContainerLoading(instance, true);

        this.requestJson(instance, 'data', {
            model: instance.options.model,
            scope: instance.options.scope,
            page: instance.currentPage || 1,
            pageSize: instance.pageSize || instance.options.pageSize || this.config.defaultPageSize,
            limit: instance.pageSize || instance.options.pageSize || this.config.defaultPageSize,
            search: instance.search || '',
            filters: instance.filters || {},
            sorts: instance.sorts || {},
            sort: instance.sorts || {},
            join: instance.options.join || '',
            model_config: instance.options.modelConfig || {}
        })
            .then(response => {
                showContainerLoading(instance, false);

                if (!(response.success || response.code == 200 || response.code === '200')) {
                    this.showError(instance.id, response.msg || response.message || __('閸旂姾娴囬弫鐗堝祦婢惰精瑙?'));
                    return;
                }

                const payload = response.data || {};
                instance.data = payload.data || [];
                instance.pagination = this.normalizePagination(payload, instance);
                instance.currentPage = instance.pagination.page;
                instance.pageSize = instance.pagination.pageSize;
                instance.totalCount = instance.pagination.total;

                this.renderTable(instance);
            })
            .catch(error => {
                console.error('[DataTableManager] loadData failed', error);
                showContainerLoading(instance, false);
                this.showError(instance.id, error.message || __('閸旂姾娴囬弫鐗堝祦婢惰精瑙?'));
            });
    };

    manager.saveCellValue = function (cell, newValue, tableId) {
        if (!tableId) {
            const table = cell.closest('.w-datatable');
            if (table) {
                tableId = table.id;
            }
        }

        const instance = this.instances[tableId];
        if (!instance) {
            return;
        }

        const row = cell.closest('tr');
        const recordId = row ? row.getAttribute('data-id') : '';
        const fieldName = cell.getAttribute('data-field');
        const model = instance.options.model || (instance.container && instance.container.getAttribute ? instance.container.getAttribute('data-model') : '');

        cell.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

        this.requestJson(instance, 'save-data', {
            model: model,
            id: recordId,
            data: {
                [fieldName]: newValue
            }
        })
            .then(data => {
                if (data.success || data.code == 200 || data.code === '200') {
                    cell.innerHTML = newValue;
                    cell.classList.add('save-success');
                    setTimeout(() => cell.classList.remove('save-success'), 2000);
                    return;
                }

                this.restoreCellContent(cell, instance.editingState.originalValue);
                this.showError(tableId, data.message || data.msg || __('娣囨繂鐡ㄦ径杈Е'));
            })
            .catch(error => {
                this.restoreCellContent(cell, instance.editingState.originalValue);
                this.showError(tableId, error.message || __('娣囨繂鐡ㄦ径杈Е'));
            });
    };

    manager.exportData = function (tableId, format = 'excel') {
        const instance = this.getInstance(tableId) || this.instances[tableId];
        if (!instance) {
            return;
        }

        return this.exportDataBatch(instance, null, format);
    };

    manager.exportDataBatch = function (instance, selectedIds = null, format = 'excel') {
        if (!instance) {
            return;
        }

        const tableId = instance.id || (instance.container && typeof instance.container.attr === 'function' ? instance.container.attr('id') : '');
        const ids = Array.isArray(selectedIds) ? selectedIds : [];
        const exportParams = {
            model: instance.options.model,
            ids: ids,
            format: format,
            fields: (instance.displayFields || []).map(field => ({
                name: field.name,
                label: field.label || field.name
            }))
        };

        this.showLoading(tableId, __('婵繐绲藉﹢顏堝礄閸℃妲甸悗鐢靛帶閸ゎ參寮悧鍫濈ウ...'));
        this.requestJson(instance, 'export-data', exportParams)
            .then(response => {
                this.hideLoading(tableId);
                if (!(response.success || response.code == 200 || response.code === '200')) {
                    this.showError(tableId, response.msg || response.message || __('閻庣數鍘ч崵顓熷緞鏉堫偉袝'));
                    return;
                }

                const payload = response.data || {};
                const content = payload.body || '';
                const contentType = payload.content_type || (format === 'json' ? 'application/json' : 'text/csv');
                const filename = payload.filename || ('export_' + Date.now() + (format === 'json' ? '.json' : '.csv'));
                this.downloadFile(new Blob([content], {type: contentType}), filename);
                this.showSuccess(tableId, response.msg || response.message || __('閻庣數鍘ч崵顓㈠箣閹邦剙顫?'));
            })
            .catch(error => {
                this.hideLoading(tableId);
                console.error('Export error:', error);
                this.showError(tableId, error.message || __('閻庣數鍘ч崵顓熷緞鏉堫偉袝'));
            });
    };

    manager.performDelete = function (instance, ids, options) {
        const tableId = instance.id || (instance.container && typeof instance.container.attr === 'function' ? instance.container.attr('id') : '');
        this.showLoading(tableId, __('婵繐绲藉﹢顏堝礆閻樼粯鐝?..'));

        this.requestJson(instance, 'delete-data', {
            model: instance.options.model,
            ids: Array.isArray(ids) ? ids : [ids],
            soft_delete: !!(options && options.softDelete)
        })
            .then(response => {
                this.hideLoading(tableId);
                if (response.code == 200 || response.code === '200' || response.success) {
                    this.showSuccess(tableId, options && options.softDelete ? __('Record moved to recycle bin') : __('Delete succeeded'));
                    this.loadData(instance);
                    this.clearSelection(instance);
                    return;
                }
                this.showError(tableId, response.msg || response.message || __('闁告帞濞€濞呭孩寰勬潏顐バ?'));
            })
            .catch(error => {
                this.hideLoading(tableId);
                console.error('Delete error:', error);
                this.showError(tableId, error.message || __('闁告帞濞€濞呭孩寰勬潏顐バ?'));
            });
    };

    manager.saveRowData = function (instance, row) {
        const tableId = instance.id || (instance.container && typeof instance.container.attr === 'function' ? instance.container.attr('id') : '');
        this.requestJson(instance, 'save-data', {
            model: instance.options.model,
            data: row
        })
            .then(response => {
                if (!(response.code == 200 || response.code === '200' || response.success)) {
                    this.showError(tableId, response.msg || response.message || __('濞ｅ洦绻傞悺銊﹀緞鏉堫偉袝'));
                }
            })
            .catch(error => {
                this.showError(tableId, error.message || __('濞ｅ洦绻傞悺銊﹀緞鏉堫偉袝'));
            });
    };

    manager.saveRow = function (tableId) {
        const instance = this.instances[tableId];
        if (!instance || !instance.isEditing) {
            return;
        }

        const modalId = 'edit-modal-' + tableId;
        const form = document.querySelector('#' + modalId + ' form');
        const formData = {};
        if (form) {
            form.querySelectorAll('[name]').forEach(control => {
                const name = control.getAttribute('name');
                formData[name] = control.type === 'checkbox' ? (control.checked ? 1 : 0) : control.value;
            });
        }
        formData.id = instance.data[instance.editingRow].id;

        this.requestJson(instance, 'save-data', {
            model: instance.options.model,
            data: formData
        })
            .then(response => {
                if (response.code == 200 || response.code === '200' || response.success) {
                    this.showSuccess(tableId, __('濞ｅ洦绻傞悺銊╁箣閹邦剙顫?'));
                    if (typeof $ === 'function') {
                        $('#' + modalId).modal('hide');
                    }
                    instance.isEditing = false;
                    instance.editingRow = null;
                    instance.editingData = {};
                    this.loadData(instance);
                    return;
                }
                this.showError(tableId, response.msg || response.message || __('濞ｅ洦绻傞悺銊﹀緞鏉堫偉袝'));
            })
            .catch(error => {
                this.showError(tableId, error.message || __('濞ｅ洦绻傞悺銊﹀緞鏉堫偉袝'));
            });
    };
})();
