/**
 * Weline Switcher Web Component
 * SEO友好的切换器组件（语言、货币、地区等）
 * 
 * 特性：
 * 1. SEO友好：内容在Light DOM中，不使用Shadow DOM
 * 2. 渐进增强：基础功能是链接，JS增强为下拉菜单
 * 3. 可访问性：支持键盘导航和屏幕阅读器
 * 4. 语义化：使用合适的HTML标签
 * 
 * 使用方式：
 * <weline-switcher type="language" current="zh_Hans_CN">
 *   <a href="/zh_Hans_CN/page" data-value="zh_Hans_CN">中文</a>
 *   <a href="/en_US/page" data-value="en_US">English</a>
 * </weline-switcher>
 */
(function (window, document) {
    'use strict';

    // 检查是否支持 Custom Elements
    if (!window.customElements) {
        console.warn('[WelineSwitcher] Custom Elements not supported');
        return;
    }

    /**
     * Weline Switcher 组件类
     */
    class WelineSwitcher extends HTMLElement {
        constructor() {
            super();
            this._isOpen = false;
            this._currentValue = null;
            this._options = [];
            this._button = null;
            this._dropdown = null;
            this._type = null;
        }

        /**
         * 组件挂载时
         */
        connectedCallback() {
            this._type = this.getAttribute('type') || 'language';
            this._currentValue = this.getAttribute('current') || '';
            
            // 解析选项
            this._parseOptions();
            
            // 如果选项少于2个，不渲染下拉菜单
            if (this._options.length < 2) {
                this._renderSimple();
                return;
            }

            // 渲染组件
            this._render();
            
            // 绑定事件
            this._bindEvents();
            
            // 初始化状态
            this._updateCurrent();
        }

        /**
         * 组件卸载时
         */
        disconnectedCallback() {
            this._unbindEvents();
        }

        /**
         * 解析选项（从子元素中提取）
         */
        _parseOptions() {
            const links = this.querySelectorAll('a[data-value]');
            this._options = Array.from(links).map(link => ({
                value: link.getAttribute('data-value'),
                label: link.textContent.trim(),
                href: link.getAttribute('href'),
                element: link
            }));

            // 如果没有找到选项，尝试从option元素解析
            if (this._options.length === 0) {
                const options = this.querySelectorAll('option');
                this._options = Array.from(options).map(option => ({
                    value: option.value,
                    label: option.textContent.trim(),
                    href: option.getAttribute('data-href') || '#',
                    element: option
                }));
            }
        }

        /**
         * 渲染简单模式（选项少于2个时）
         */
        _renderSimple() {
            // 保持原有内容，只添加样式类
            this.classList.add('weline-switcher', 'weline-switcher--simple');
            this.setAttribute('role', 'list');
        }

        /**
         * 渲染组件
         */
        _render() {
            // 保存原始内容（用于SEO）
            const originalContent = this.innerHTML;
            
            // 添加样式类
            this.classList.add('weline-switcher', `weline-switcher--${this._type}`);
            this.setAttribute('role', 'combobox');
            this.setAttribute('aria-haspopup', 'listbox');
            this.setAttribute('aria-expanded', 'false');

            // 创建按钮
            this._button = document.createElement('button');
            this._button.className = 'weline-switcher__button';
            this._button.setAttribute('type', 'button');
            this._button.setAttribute('aria-label', this._getLabel());
            this._button.setAttribute('aria-expanded', 'false');
            this._updateButtonText();

            // 创建下拉菜单
            this._dropdown = document.createElement('ul');
            this._dropdown.className = 'weline-switcher__dropdown';
            this._dropdown.setAttribute('role', 'listbox');
            this._dropdown.setAttribute('aria-label', this._getLabel());
            this._dropdown.hidden = true;

            // 渲染选项
            this._options.forEach((option, index) => {
                const li = document.createElement('li');
                li.setAttribute('role', 'option');
                li.setAttribute('data-value', option.value);
                li.setAttribute('aria-selected', option.value === this._currentValue ? 'true' : 'false');
                
                // 创建链接（保持SEO友好）
                const link = document.createElement('a');
                link.href = option.href;
                link.textContent = option.label;
                link.className = 'weline-switcher__option';
                link.setAttribute('data-value', option.value);
                
                // 如果是当前选项，添加active类
                if (option.value === this._currentValue) {
                    link.classList.add('weline-switcher__option--active');
                }

                li.appendChild(link);
                this._dropdown.appendChild(li);
            });

            // 组装结构
            this.innerHTML = '';
            this.appendChild(this._button);
            this.appendChild(this._dropdown);

            // 在组件内部添加原始内容（隐藏，用于SEO）
            const seoContent = document.createElement('div');
            seoContent.className = 'weline-switcher__seo-content';
            seoContent.setAttribute('aria-hidden', 'true');
            seoContent.style.cssText = 'position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); border: 0;';
            seoContent.innerHTML = originalContent;
            this.appendChild(seoContent);
        }

        /**
         * 更新按钮文本
         */
        _updateButtonText() {
            if (!this._button) return;
            
            const currentOption = this._options.find(opt => opt.value === this._currentValue);
            if (currentOption) {
                this._button.textContent = currentOption.label;
                this._button.setAttribute('aria-label', `${this._getLabel()}: ${currentOption.label}`);
            }
        }

        /**
         * 更新当前选项
         */
        _updateCurrent() {
            // 更新按钮
            this._updateButtonText();

            // 更新下拉菜单中的选中状态
            if (this._dropdown) {
                const options = this._dropdown.querySelectorAll('[role="option"]');
                options.forEach(option => {
                    const value = option.getAttribute('data-value');
                    const isSelected = value === this._currentValue;
                    option.setAttribute('aria-selected', isSelected ? 'true' : 'false');
                    const link = option.querySelector('a');
                    if (link) {
                        link.classList.toggle('weline-switcher__option--active', isSelected);
                    }
                });
            }
        }

        /**
         * 获取标签文本
         */
        _getLabel() {
            const labels = {
                language: '语言',
                currency: '货币',
                region: '地区',
                locale: '区域设置'
            };
            return labels[this._type] || '切换';
        }

        /**
         * 绑定事件
         */
        _bindEvents() {
            if (this._button) {
                this._button.addEventListener('click', this._handleButtonClick.bind(this));
                this._button.addEventListener('keydown', this._handleButtonKeydown.bind(this));
            }

            if (this._dropdown) {
                this._dropdown.addEventListener('click', this._handleOptionClick.bind(this));
                this._dropdown.addEventListener('keydown', this._handleDropdownKeydown.bind(this));
            }

            // 点击外部关闭
            document.addEventListener('click', this._handleDocumentClick.bind(this));
        }

        /**
         * 解绑事件
         */
        _unbindEvents() {
            document.removeEventListener('click', this._handleDocumentClick.bind(this));
        }

        /**
         * 处理按钮点击
         */
        _handleButtonClick(e) {
            e.preventDefault();
            e.stopPropagation();
            this.toggle();
        }

        /**
         * 处理按钮键盘事件
         */
        _handleButtonKeydown(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                this.open();
            } else if (e.key === 'ArrowDown') {
                e.preventDefault();
                this.open();
                this._focusFirstOption();
            } else if (e.key === 'Escape') {
                this.close();
            }
        }

        /**
         * 处理选项点击
         */
        _handleOptionClick(e) {
            const link = e.target.closest('a[data-value]');
            if (!link) return;

            e.preventDefault();
            const value = link.getAttribute('data-value');
            this.select(value);
        }

        /**
         * 处理下拉菜单键盘事件
         */
        _handleDropdownKeydown(e) {
            const options = Array.from(this._dropdown.querySelectorAll('[role="option"]'));
            const currentIndex = options.findIndex(opt => opt === document.activeElement.closest('[role="option"]'));

            switch (e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    const nextIndex = currentIndex < options.length - 1 ? currentIndex + 1 : 0;
                    options[nextIndex]?.querySelector('a')?.focus();
                    break;
                case 'ArrowUp':
                    e.preventDefault();
                    const prevIndex = currentIndex > 0 ? currentIndex - 1 : options.length - 1;
                    options[prevIndex]?.querySelector('a')?.focus();
                    break;
                case 'Enter':
                case ' ':
                    e.preventDefault();
                    const activeOption = document.activeElement.closest('[role="option"]');
                    if (activeOption) {
                        const value = activeOption.getAttribute('data-value');
                        this.select(value);
                    }
                    break;
                case 'Escape':
                    e.preventDefault();
                    this.close();
                    this._button?.focus();
                    break;
                case 'Home':
                    e.preventDefault();
                    options[0]?.querySelector('a')?.focus();
                    break;
                case 'End':
                    e.preventDefault();
                    options[options.length - 1]?.querySelector('a')?.focus();
                    break;
            }
        }

        /**
         * 处理文档点击（关闭下拉菜单）
         */
        _handleDocumentClick(e) {
            if (!this.contains(e.target)) {
                this.close();
            }
        }

        /**
         * 打开下拉菜单
         */
        open() {
            if (this._isOpen || !this._dropdown) return;

            this._isOpen = true;
            this._dropdown.hidden = false;
            this.setAttribute('aria-expanded', 'true');
            this._button?.setAttribute('aria-expanded', 'true');
            this.classList.add('weline-switcher--open');

            // 聚焦第一个选项
            this._focusFirstOption();
        }

        /**
         * 关闭下拉菜单
         */
        close() {
            if (!this._isOpen) return;

            this._isOpen = false;
            if (this._dropdown) {
                this._dropdown.hidden = true;
            }
            this.setAttribute('aria-expanded', 'false');
            this._button?.setAttribute('aria-expanded', 'false');
            this.classList.remove('weline-switcher--open');
        }

        /**
         * 切换下拉菜单
         */
        toggle() {
            if (this._isOpen) {
                this.close();
            } else {
                this.open();
            }
        }

        /**
         * 选择选项
         */
        select(value) {
            const option = this._options.find(opt => opt.value === value);
            if (!option) return;

            // 更新当前值
            this._currentValue = value;
            this.setAttribute('current', value);

            // 更新UI
            this._updateCurrent();
            this.close();

            // 触发切换事件
            this.dispatchEvent(new CustomEvent('weline:switcher:change', {
                detail: {
                    type: this._type,
                    value: value,
                    option: option
                },
                bubbles: true
            }));

            // 执行切换逻辑
            this._performSwitch(value, option);
        }

        /**
         * 执行切换逻辑
         */
        _performSwitch(value, option) {
            // 根据类型调用不同的切换方法
            if (window.Weline && window.Weline.Locale) {
                switch (this._type) {
                    case 'language':
                    case 'locale':
                        if (window.Weline.Locale.switchLang) {
                            window.Weline.Locale.switchLang(value).catch(() => {
                                // 如果切换失败，使用链接跳转
                                if (option.href && option.href !== '#') {
                                    window.location.href = option.href;
                                }
                            });
                        } else if (option.href && option.href !== '#') {
                            window.location.href = option.href;
                        }
                        break;
                    case 'currency':
                        if (window.Weline.Locale.switchCurrency) {
                            window.Weline.Locale.switchCurrency(value).catch(() => {
                                if (option.href && option.href !== '#') {
                                    window.location.href = option.href;
                                }
                            });
                        } else if (option.href && option.href !== '#') {
                            window.location.href = option.href;
                        }
                        break;
                    case 'region':
                        // 地区切换可能需要特殊处理
                        if (option.href && option.href !== '#') {
                            window.location.href = option.href;
                        }
                        break;
                    default:
                        // 默认使用链接跳转
                        if (option.href && option.href !== '#') {
                            window.location.href = option.href;
                        }
                }
            } else {
                // 如果没有Weline对象，使用链接跳转
                if (option.href && option.href !== '#') {
                    window.location.href = option.href;
                }
            }
        }

        /**
         * 聚焦第一个选项
         */
        _focusFirstOption() {
            const firstLink = this._dropdown?.querySelector('a');
            if (firstLink) {
                setTimeout(() => firstLink.focus(), 0);
            }
        }

        /**
         * 获取当前值
         */
        get currentValue() {
            return this._currentValue;
        }

        /**
         * 设置当前值
         */
        set currentValue(value) {
            this.select(value);
        }

        /**
         * 获取类型
         */
        get type() {
            return this._type;
        }
    }

    // 注册组件
    if (!customElements.get('weline-switcher')) {
        customElements.define('weline-switcher', WelineSwitcher);
    }

    // 导出到全局
    window.WelineSwitcher = WelineSwitcher;

})(window, document);

