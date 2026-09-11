/**
 * 鐎广垺婀囬張宥呭濡€虫健JavaScript
 */

/* captcha-degrade-ux-20260909 */
const CustomerServiceWidget = (function() {
    /**
     * 閸ヤ粙妾崠鏍电窗娴兼ê鍘涙担璺ㄦ暏妞ょ敻娼板▔銊ュ弳閻?__閿涘苯鎯侀崚娆撴缁狙傝礋閸楃姳缍呯粭锔芥禌閹?
     * @param {string} text
     * @param {Object|Array} params
     * @returns {string}
     */
    function interpolateTranslation(text, params) {
        if (!params) {
            return text;
        }

        let result = text;
        if (typeof params === 'object' && !Array.isArray(params)) {
            for (const key in params) {
                result = result.replace(new RegExp('%\\{' + key + '\\}', 'g'), String(params[key]));
            }
            return result;
        }

        if (Array.isArray(params)) {
            params.forEach((param, index) => {
                result = result.replace(new RegExp('%\\{' + (index + 1) + '\\}', 'g'), String(param));
            });
            return result;
        }

        return result.replace(/%\{1\}/g, String(params));
    }

    function getSupportedLocales() {
        return Array.isArray(config.supportedLocales) ? config.supportedLocales : [];
    }

    function getLocaleConfig(localeCode) {
        return getSupportedLocales().find(locale => locale.code === localeCode) || null;
    }

    function getWidgetTranslations(localeCode) {
        if (!config.widgetTranslations || typeof config.widgetTranslations !== 'object') {
            return {};
        }

        const requestedLocale = config.widgetTranslations[localeCode];
        if (requestedLocale && typeof requestedLocale === 'object') {
            return requestedLocale;
        }

        const fallbackLocale = config.widgetTranslations.zh_Hans_CN;
        return fallbackLocale && typeof fallbackLocale === 'object' ? fallbackLocale : {};
    }

    function __(text, params) {
        const widgetTranslations = getWidgetTranslations(state.locale);
        if (widgetTranslations && typeof widgetTranslations[text] === 'string') {
            return interpolateTranslation(widgetTranslations[text], params);
        }
        if (typeof window !== 'undefined' && typeof window.__ === 'function') {
            return window.__(text, params);
        }
        if (typeof window !== 'undefined' && window.Weline && window.Weline.i18n && typeof window.Weline.i18n.__ === 'function') {
            return window.Weline.i18n.__(text, params);
        }

        return interpolateTranslation(text, params);
    }

    function notifyAlert(type, message, options) {
        const text = String(message || '').trim();
        options = options && typeof options === 'object' ? options : {};
        const linkUrl = String(options.linkUrl || options.verification_url || '').trim();
        const linkText = String(options.linkText || __('打开验证链接')).trim();
        if (!text && !linkUrl) {
            return Promise.resolve(false);
        }

        const tone = type === 'success' ? 'success' : (type === 'warning' ? 'warning' : (type === 'info' ? 'info' : 'error'));
        const title = String(options.title || (
            tone === 'success' ? __('提示') :
            tone === 'warning' ? __('请注意') :
            tone === 'info' ? __('提示') :
            __('操作失败')
        ));
        const confirmText = String(options.confirmText || __('知道了'));

        const existing = document.getElementById('cs-notice-alert');
        if (existing && existing.parentNode) {
            existing.parentNode.removeChild(existing);
        }

        function appendLinkedBody(container, rawText, explicitUrl) {
            container.textContent = '';
            const url = String(explicitUrl || '').trim();
            if (url) {
                if (rawText) {
                    const p = document.createElement('p');
                    p.className = 'cs-notice-alert__copy';
                    p.textContent = rawText;
                    container.appendChild(p);
                }
                const a = document.createElement('a');
                a.className = 'cs-notice-alert__link w-button cs-btn cs-btn-primary';
                a.href = url;
                a.target = '_blank';
                a.rel = 'noopener noreferrer';
                a.textContent = linkText;
                container.appendChild(a);
                return;
            }

            // Fallback: turn bare http(s) URLs in the message into new-tab links.
            const pattern = /(https?:\/\/[^\s<>"']+)/g;
            let lastIndex = 0;
            let match;
            const source = String(rawText || '');
            while ((match = pattern.exec(source)) !== null) {
                if (match.index > lastIndex) {
                    container.appendChild(document.createTextNode(source.slice(lastIndex, match.index)));
                }
                const a = document.createElement('a');
                a.className = 'cs-notice-alert__inline-link';
                a.href = match[1];
                a.target = '_blank';
                a.rel = 'noopener noreferrer';
                a.textContent = match[1];
                container.appendChild(a);
                lastIndex = match.index + match[0].length;
            }
            if (lastIndex < source.length) {
                container.appendChild(document.createTextNode(source.slice(lastIndex)));
            }
            if (!container.childNodes.length) {
                container.textContent = source;
            }
        }

        return new Promise(function (resolve) {
            const overlay = document.createElement('div');
            overlay.id = 'cs-notice-alert';
            overlay.className = 'cs-notice-alert cs-notice-alert--' + tone;
            overlay.setAttribute('role', 'alertdialog');
            overlay.setAttribute('aria-modal', 'true');
            overlay.setAttribute('aria-labelledby', 'cs-notice-alert-title');
            overlay.setAttribute('aria-describedby', 'cs-notice-alert-message');
            overlay.innerHTML = [
                '<section class="cs-notice-alert__dialog">',
                '<header class="cs-notice-alert__header">',
                '<h2 class="cs-notice-alert__title" id="cs-notice-alert-title"></h2>',
                '</header>',
                '<div class="cs-notice-alert__body" id="cs-notice-alert-message"></div>',
                '<footer class="cs-notice-alert__actions">',
                '<button type="button" class="w-button cs-btn cs-btn-primary cs-notice-alert__confirm" data-cs-notice-confirm></button>',
                '</footer>',
                '</section>'
            ].join('');

            overlay.querySelector('.cs-notice-alert__title').textContent = title;
            appendLinkedBody(overlay.querySelector('.cs-notice-alert__body'), text, linkUrl);
            const confirmButton = overlay.querySelector('[data-cs-notice-confirm]');
            confirmButton.textContent = confirmText;

            const close = function () {
                overlay.classList.remove('is-open');
                setTimeout(function () {
                    if (overlay.parentNode) {
                        overlay.parentNode.removeChild(overlay);
                    }
                    resolve(true);
                }, 160);
            };

            confirmButton.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                close();
            });
            overlay.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === 'Escape') {
                    event.preventDefault();
                    close();
                }
            });

            document.body.appendChild(overlay);
            requestAnimationFrame(function () {
                overlay.classList.add('is-open');
                const focusEl = overlay.querySelector('.cs-notice-alert__link') || confirmButton;
                focusEl.focus();
            });
        });
    }

    function notify(type, message, options) {
        const text = String(message || '').trim();
        if (!text) {
            return;
        }

        // Bind / form feedback must require an explicit confirm click.
        // Soft toasts are easy to miss on mobile and Motortheme may not expose UI.toast.
        if (type === 'error' || type === 'warning' || type === 'success' || (options && options.requireConfirm)) {
            notifyAlert(type, text, options);
            return;
        }

        const themeNotice = window.Weline && window.Weline.Theme && window.Weline.Theme.Notice
            ? window.Weline.Theme.Notice
            : (window.Weline && window.Weline.Toast ? window.Weline.Toast : null);

        try {
            if (themeNotice && typeof themeNotice[type] === 'function') {
                themeNotice[type](text);
                return;
            }

            if (themeNotice && typeof themeNotice.show === 'function') {
                themeNotice.show(text, type);
                return;
            }

            const uiToast = window.Weline && window.Weline.UI && window.Weline.UI.toast
                ? window.Weline.UI.toast
                : null;
            if (uiToast && typeof uiToast.show === 'function') {
                uiToast.show(text, {tone: type || 'info'});
                return;
            }
        } catch (error) {
            console.error('CustomerService notify fallback', error);
        }

        notifyAlert(type || 'info', text, options);
    }

    function extractErrorMessage(error, fallback) {
        if (!error) {
            return fallback;
        }
        if (typeof error === 'string' && error.trim() !== '') {
            return humanizeBindError(error.trim(), fallback);
        }
        const direct = String(error.message || error.error || '').trim();
        if (direct && direct.indexOf('Unknown frontend worker param') === -1) {
            return humanizeBindError(direct, fallback);
        }
        const nested = error.data && (error.data.message || error.data.error);
        if (nested) {
            return humanizeBindError(String(nested).trim(), fallback);
        }
        const responseMessage = error.response && error.response.message;
        if (responseMessage) {
            return humanizeBindError(String(responseMessage).trim(), fallback);
        }
        return humanizeBindError(direct, fallback) || fallback;
    }

    function humanizeBindError(message, fallback) {
        const text = String(message || '').trim();
        if (!text) {
            return fallback;
        }
        if (/exceeds max length:\s*captcha_response/i.test(text)) {
            return __('人机验证凭证异常，请刷新页面后重试');
        }
        if (/Captcha verification failed or expired/i.test(text)) {
            return __('人机验证失败或已过期，请重试');
        }
        if (/Param string exceeds max length/i.test(text)) {
            return __('请求参数过长，请刷新页面后重试');
        }
        if (/Unknown frontend worker param/i.test(text)) {
            return __('上传参数异常，请刷新页面后重试');
        }
        return text;
    }

    let config = {
        chatUrl: '',
        bindUrl: '',
        customerId: null,
        isLoggedIn: false,
        showGuestBindPrompt: false,
        supportedLocales: [],
        widgetTranslations: {},
        bindCaptchaEnabled: false,
        bindCaptchaChallengeUrl: '',
        html2canvasUrl: '',
        modernScreenshotUrl: ''
    };

    function getCustomerServiceApi() {
        if (!customerServiceApiPromise) {
            customerServiceApiPromise = Promise.resolve(window.Weline.Api.resource('customerService'));
        }

        return customerServiceApiPromise;
    }

    let sessionInitializationPromise = null;
    let guestBindPromptShown = false;
    let customerServiceApiPromise = null;
    let miniCartStateObserver = null;
    let widgetControlsBound = false;
    
    let state = {
        sessionId: null,
        sessionToken: null,
        lastMessageId: 0,
        isOpen: false,
        isPolling: false,
        isSending: false,
        pollInterval: null,
        statusPollInterval: null,
        locale: 'zh_Hans_CN',
        displayMode: 'translated', // translated, both, original
        settingsOpen: false,
        serviceStatus: 'offline', // online, ai, offline
        unreadCount: 0,
        lastDayKey: '',
        identity: {
            kind: 'guest',
            display_name: '',
            email: '',
            avatar_url: '',
            can_change_email: true
        },
        guestSend: {
            gate_active: false,
            email_bound: true,
            awaiting_reply: false,
            can_send: true,
            message: ''
        }
    };

    function isValidBindEmail(email) {
        const value = String(email || '').trim().toLowerCase();
        if (!value || value.length > 254) {
            return false;
        }
        return /^[^\s@]+@([a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/i.test(value);
    }

    function applySessionIdentity(identity) {
        if (!identity || typeof identity !== 'object') {
            return;
        }
        state.identity = {
            kind: String(identity.kind || 'guest'),
            display_name: String(identity.display_name || ''),
            email: String(identity.email || ''),
            avatar_url: String(identity.avatar_url || ''),
            can_change_email: identity.can_change_email !== false
        };
        renderIdentityChip();
    }

    function renderIdentityChip() {
        const chip = document.getElementById('cs-identity-chip');
        const label = document.getElementById('cs-identity-label');
        const avatar = document.getElementById('cs-identity-avatar');
        if (!chip || !label) {
            return;
        }
        const name = String(state.identity.display_name || '').trim();
        const email = String(state.identity.email || '').trim();
        const text = name || email;
        if (!text) {
            chip.hidden = true;
            return;
        }
        label.textContent = text;
        chip.hidden = false;
        chip.classList.toggle('is-editable', !!state.identity.can_change_email);
        chip.setAttribute(
            'title',
            state.identity.can_change_email ? __('点击修改邮箱身份') : __('当前登录身份')
        );
        if (avatar) {
            const url = String(state.identity.avatar_url || '').trim();
            if (url) {
                avatar.src = url;
                avatar.hidden = false;
            } else {
                avatar.removeAttribute('src');
                avatar.hidden = true;
            }
        }
    }

    const CS_EMAIL_BOUND_CHANNEL = 'weline-cs-email-bound';
    const CS_EMAIL_BOUND_STORAGE_KEY = 'cs_email_bound_v1';
    let lastEmailBoundEventTs = 0;
    let emailBoundHandling = null;

    function dismissNoticeAlert() {
        const overlay = document.getElementById('cs-notice-alert');
        if (overlay && overlay.parentNode) {
            overlay.parentNode.removeChild(overlay);
        }
    }

    async function ensureChatOpen() {
        if (state.isOpen) {
            return;
        }
        await toggleChat();
    }

    async function onEmailBindingConfirmed(payload) {
        const email = String((payload && payload.email) || '').trim().toLowerCase();
        const sessionToken = String((payload && payload.session_token) || '').trim();
        if (sessionToken && state.sessionToken && sessionToken !== state.sessionToken) {
            return;
        }

        dismissNoticeAlert();
        closeBindModal();
        await ensureChatOpen();
        await initSession();
        if (state.isOpen) {
            await loadMessages();
        }
        if (email && !String(state.identity.email || '').trim()) {
            applySessionIdentity({
                kind: state.identity.kind || 'guest',
                display_name: state.identity.display_name || email,
                email: email,
                avatar_url: state.identity.avatar_url || '',
                can_change_email: true
            });
        }
        renderIdentityChip();
        notify('success', email
            ? __('邮箱已绑定：%{1}，可以继续聊天', email)
            : __('邮箱已绑定，可以继续聊天'), {
            title: __('绑定成功')
        });
        const input = document.getElementById('cs-message-input');
        if (input && !input.disabled) {
            input.focus();
        }
    }

    function handleEmailBoundSyncPayload(raw) {
        const payload = raw && typeof raw === 'object' ? raw : null;
        if (!payload || payload.type !== 'email_bound') {
            return;
        }
        const ts = Number(payload.ts || 0);
        if (ts && ts <= lastEmailBoundEventTs) {
            return;
        }
        if (ts) {
            lastEmailBoundEventTs = ts;
        }
        if (emailBoundHandling) {
            return;
        }
        emailBoundHandling = onEmailBindingConfirmed(payload).catch(function (error) {
            console.error('Failed to sync email binding:', error);
        }).finally(function () {
            emailBoundHandling = null;
        });
    }

    function bindEmailBoundSync() {
        if (typeof window === 'undefined') {
            return;
        }
        if (window.__csEmailBoundSyncBound) {
            return;
        }
        window.__csEmailBoundSyncBound = true;

        try {
            if (typeof BroadcastChannel !== 'undefined') {
                const channel = new BroadcastChannel(CS_EMAIL_BOUND_CHANNEL);
                channel.addEventListener('message', function (event) {
                    handleEmailBoundSyncPayload(event.data);
                });
            }
        } catch (_e) {}

        window.addEventListener('storage', function (event) {
            if (event.key !== CS_EMAIL_BOUND_STORAGE_KEY || !event.newValue) {
                return;
            }
            try {
                handleEmailBoundSyncPayload(JSON.parse(event.newValue));
            } catch (_e2) {}
        });

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState !== 'visible') {
                return;
            }
            try {
                const raw = localStorage.getItem(CS_EMAIL_BOUND_STORAGE_KEY);
                if (!raw) {
                    return;
                }
                handleEmailBoundSyncPayload(JSON.parse(raw));
            } catch (_e3) {}
        });
    }

    function dayKeyFromTime(timeStr) {
        if (!timeStr) {
            return '';
        }
        const date = new Date(timeStr);
        if (Number.isNaN(date.getTime())) {
            return '';
        }
        return date.getFullYear() + '-' + (date.getMonth() + 1) + '-' + date.getDate();
    }

    function formatDaySeparatorLabel(timeStr) {
        const date = new Date(timeStr);
        if (Number.isNaN(date.getTime())) {
            return '';
        }
        const now = new Date();
        const startToday = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        const startMsg = new Date(date.getFullYear(), date.getMonth(), date.getDate());
        const diffDays = Math.round((startToday - startMsg) / 86400000);
        if (diffDays === 0) {
            return __('今天');
        }
        if (diffDays === 1) {
            return __('昨天');
        }
        return date.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' });
    }

    function appendDaySeparatorIfNeeded(messagesContainer, timeStr) {
        const key = dayKeyFromTime(timeStr);
        if (!key || key === state.lastDayKey) {
            return;
        }
        state.lastDayKey = key;
        const sep = document.createElement('div');
        sep.className = 'cs-day-separator';
        sep.setAttribute('role', 'separator');
        sep.textContent = formatDaySeparatorLabel(timeStr);
        messagesContainer.appendChild(sep);
    }

    function notifyIncomingAgentMessage(message) {
        state.unreadCount += 1;
        updateUnreadBadge();
        const preview = String(message.display_content || message.content || '').slice(0, 80);
        if (typeof window.Notification === 'undefined') {
            return;
        }
        const show = function () {
            try {
                new Notification(__('客服新消息'), {
                    body: preview || __('您有一条新的客服回复'),
                    tag: 'cs-agent-' + String(message.message_id || Date.now())
                });
            } catch (_e) {}
        };
        if (Notification.permission === 'granted') {
            show();
        } else if (Notification.permission === 'default') {
            Notification.requestPermission().then(function (permission) {
                if (permission === 'granted') {
                    show();
                }
            }).catch(function () {});
        }
    }
    
    /**
     * 初始化
     */
    function init(options) {
        config = Object.assign(config, options);
        const defaultLocale = String(config.defaultCustomerLocale || 'zh_Hans_CN');
        state.locale = defaultLocale;
        
        // 从 localStorage 恢复状态
        const savedState = localStorage.getItem('cs_widget_state');
        if (savedState) {
            try {
                const parsed = JSON.parse(savedState);
                state.sessionToken = parsed.sessionToken || null;
                state.locale = parsed.locale || defaultLocale;
                state.displayMode = parsed.displayMode || 'translated';
            } catch (e) {
                console.error('Failed to load saved state:', e);
            }
        }
        
        // 初始化 UI
        initUIState();
        bindWidgetControls();
        updateWidgetLocaleText();
        bindMiniCartLayerState();
        bindEmailBoundSync();
        // Warm screenshot engine so confirm is not blocked on first script fetch.
        ensureModernScreenshot().catch(function () { /* optional */ });
        ensureHtml2Canvas().catch(function () { /* optional */ });
    }
    
    /**
     * 閸掓繂顫愰崠鏈閻樿埖鈧?
     */
    function initUIState() {
        // 鐠佸墽鐤嗙拠顓♀枅闁瀚ㄩ崳?
        const localeSelect = document.getElementById('cs-locale-select');
        if (localeSelect) {
            localeSelect.value = state.locale;
        }
        
        // 鐠佸墽鐤嗛弰鍓с仛濡€崇础闁瀚ㄩ崳?
        const displayModeSelect = document.getElementById('cs-display-mode');
        if (displayModeSelect) {
            displayModeSelect.value = state.displayMode;
        }
    }

    function bindWidgetControls() {
        if (widgetControlsBound) {
            return;
        }

        widgetControlsBound = true;
        document.querySelectorAll('[data-cs-toggle-chat]').forEach(button => {
            button.addEventListener('click', event => {
                event.preventDefault();
                toggleChat();
            });
        });

        const settingsButton = document.querySelector('[data-cs-toggle-settings]');
        if (settingsButton) {
            settingsButton.addEventListener('click', event => {
                event.preventDefault();
                toggleSettings();
            });
        }

        const localeSelect = document.getElementById('cs-locale-select');
        if (localeSelect) {
            localeSelect.addEventListener('change', event => {
                changeLanguage(event.target.value);
            });
        }

        const displayModeSelect = document.getElementById('cs-display-mode');
        if (displayModeSelect) {
            displayModeSelect.addEventListener('change', event => {
                changeDisplayMode(event.target.value);
            });
        }

        const messageInput = document.getElementById('cs-message-input');
        if (messageInput) {
            messageInput.addEventListener('keydown', event => {
                if (event.key === 'Enter' && !event.shiftKey) {
                    event.preventDefault();
                    sendMessage();
                }
            });
        }

        const sendButton = document.querySelector('[data-cs-send-message]');
        if (sendButton) {
            sendButton.addEventListener('click', event => {
                event.preventDefault();
                sendMessage();
            });
        }

        bindComposerTools();

        document.querySelectorAll('[data-cs-open-bind-email]').forEach(button => {
            button.addEventListener('click', event => {
                event.preventDefault();
                showBindPrompt({force: true});
            });
        });

        const identityChip = document.getElementById('cs-identity-chip');
        if (identityChip && identityChip.dataset.bound !== '1') {
            identityChip.dataset.bound = '1';
            identityChip.addEventListener('click', function (event) {
                event.preventDefault();
                if (!state.identity.can_change_email) {
                    return;
                }
                showBindPrompt({ force: true, reason: 'edit_identity' });
            });
        }
    }

    function applyGuestSendGate(gate) {
        if (!gate || typeof gate !== 'object') {
            return;
        }
        state.guestSend = {
            gate_active: Boolean(gate.gate_active),
            email_bound: Boolean(gate.email_bound),
            awaiting_reply: Boolean(gate.awaiting_reply),
            can_send: gate.can_send !== false,
            message: String(gate.message || '')
        };
        const locked = state.guestSend.gate_active && state.guestSend.awaiting_reply;
        const banner = document.getElementById('cs-guest-send-gate');
        const text = document.getElementById('cs-guest-send-gate-text');
        const input = document.getElementById('cs-message-input');
        const sendButton = document.querySelector('.cs-send-button, [data-cs-send-message]');
        if (banner) {
            if (locked) {
                banner.hidden = false;
                if (text) {
                    text.textContent = state.guestSend.message
                        || __('请等待客服回复后再发送。验证邮箱后可跳过等待、连续发送消息。');
                }
            } else {
                banner.hidden = true;
            }
        }
        if (input && !state.isSending) {
            input.disabled = locked;
            input.setAttribute('aria-disabled', locked ? 'true' : 'false');
            if (locked) {
                input.placeholder = __('等待客服回复…');
            } else {
                input.placeholder = __('输入消息...');
            }
        }
        if (sendButton && !state.isSending) {
            sendButton.disabled = locked;
        }
        document.querySelectorAll('.cs-composer-tool').forEach(function (btn) {
            btn.disabled = locked || state.isSending;
        });
    }

    function updateWidgetLocaleDirection() {
        const widgetRoot = document.getElementById('customer-service-widget');
        if (!widgetRoot) {
            return;
        }

        const locale = String(state.locale || 'zh_Hans_CN');
        const language = locale.split(/[_-]/, 1)[0].toLowerCase();
        const isRtl = ['ar', 'fa', 'he', 'ur'].includes(language);

        widgetRoot.lang = locale.replace(/_/g, '-');
        widgetRoot.dir = isRtl ? 'rtl' : 'ltr';
    }

    function updateWidgetLocaleText() {
        updateWidgetLocaleDirection();

        const textMap = {
            'cs-title-text': __('客服服务'),
            'cs-locale-label-text': __('我的语言'),
            'cs-display-mode-label-text': __('显示模式'),
            'cs-welcome-title': __('欢迎使用客服服务！'),
            'cs-welcome-copy': __('请输入您的问题，我们的客服将尽快为您解答。')
        };

        Object.keys(textMap).forEach((id) => {
            const element = document.getElementById(id);
            if (element) {
                element.textContent = textMap[id];
            }
        });

        const messageInput = document.getElementById('cs-message-input');
        if (messageInput) {
            messageInput.placeholder = __('输入消息...');
        }

        const sendButton = document.querySelector('[data-cs-send-message]');
        if (sendButton) {
            sendButton.setAttribute('aria-label', __('发送'));
        }

        const settingsButton = document.getElementById('cs-settings-btn');
        if (settingsButton) {
            settingsButton.title = __('设置');
        }

        const minimizeButton = document.getElementById('cs-minimize-btn');
        if (minimizeButton) {
            minimizeButton.title = __('收起');
        }

        const displayModeSelect = document.getElementById('cs-display-mode');
        if (displayModeSelect) {
            Array.from(displayModeSelect.options).forEach((option) => {
                switch (option.value) {
                    case 'translated':
                        option.textContent = __('仅显示译文');
                        break;
                    case 'both':
                        option.textContent = __('原文+译文');
                        break;
                    case 'original':
                        option.textContent = __('仅显示原文');
                        break;
                }
            });
        }

        const composerLabels = {
            emoji: __('表情'),
            image: __('图片'),
            file: __('文件'),
            screenshot: __('截图')
        };
        document.querySelectorAll('#customer-service-widget [data-cs-tool]').forEach(function (btn) {
            const tool = btn.getAttribute('data-cs-tool');
            const label = composerLabels[tool];
            if (!label) {
                return;
            }
            btn.textContent = label;
            btn.setAttribute('title', label);
        });
        const composerToolbar = document.querySelector('#customer-service-widget .cs-composer-toolbar');
        if (composerToolbar) {
            composerToolbar.setAttribute('aria-label', __('聊天工具'));
        }

        updateStatusIndicator();
    }

    function syncMiniCartLayerState() {
        const widget = document.getElementById('customer-service-widget');
        if (!widget) {
            return;
        }

        const drawer = document.getElementById('mini-cart-drawer');
        const isMiniCartOpen = Boolean(drawer && drawer.classList.contains('is-open'));
        widget.classList.toggle('customer-service-widget--mini-cart-open', isMiniCartOpen);
    }

    function bindMiniCartLayerState() {
        if (miniCartStateObserver) {
            syncMiniCartLayerState();
            return;
        }

        document.addEventListener('weshop:mini-cart:open', syncMiniCartLayerState);
        document.addEventListener('weshop:mini-cart:close', syncMiniCartLayerState);

        const drawer = document.getElementById('mini-cart-drawer');
        if (drawer && typeof MutationObserver !== 'undefined') {
            miniCartStateObserver = new MutationObserver(syncMiniCartLayerState);
            miniCartStateObserver.observe(drawer, {
                attributes: true,
                attributeFilter: ['class']
            });
        } else {
            miniCartStateObserver = true;
        }

        syncMiniCartLayerState();
    }
    
    /**
     * 閸掑洦宕茬拋鍓х枂闂堛垺婢?
     */
    function toggleSettings() {
        const panel = document.getElementById('cs-settings-panel');
        if (panel) {
            state.settingsOpen = !state.settingsOpen;
            panel.style.display = state.settingsOpen ? 'block' : 'none';
        }
    }
    
    /**
     * 閺囧瓨鏁奸弰鍓с仛濡€崇础
     */
    function changeDisplayMode(mode) {
        state.displayMode = mode;
        saveState();
        
        // 闁插秵鏌婂〒鍙夌厠濞戝牊浼?
        rerenderMessageBubbles();
    }
    
    /**
     * 閸掓繂顫愰崠鏍︾窗鐠?
     */
    async function initSession() {
        try {
            const params = {
                session_token: state.sessionToken || '',
                locale: state.locale
            };

            const data = await (await getCustomerServiceApi()).session(params, {silent: true});
            
            if (data.success) {
                state.sessionId = data.data.session_id;
                state.sessionToken = data.data.session_token;
                state.locale = data.data.customer_locale;
                if (data.data.guest_send) {
                    applyGuestSendGate(data.data.guest_send);
                }
                if (data.data.identity) {
                    applySessionIdentity(data.data.identity);
                }
                
                saveState();
                initUIState();
                updateWidgetLocaleText();
                return true;
            }
            return false;
        } catch (error) {
            console.error('Failed to init session:', error);
            return false;
        }
    }

    /**
     * 閹稿娓堕崚婵嗩潗閸栨牔绱扮拠婵撶礉闁灝鍘ゆい鐢告桨閸旂姾娴囬弮鎯板殰閸斻劌鍨卞杞扮窗鐠?     */
    async function ensureSessionReady() {
        if (state.sessionId && state.sessionToken) {
            return true;
        }

        if (sessionInitializationPromise) {
            return sessionInitializationPromise;
        }

        sessionInitializationPromise = initSession().finally(() => {
            sessionInitializationPromise = null;
        });

        return sessionInitializationPromise;
    }

    /**
     * 閻劍鍩涙稉璇插З閹垫挸绱戦懕濠傘亯閸氬骸鍟€閸旂姾娴囨导姘崇樈閸滃瞼濮搁幀?     */
    async function activateChat() {
        const initialized = await ensureSessionReady();
        if (!state.isOpen) {
            return initialized;
        }

        await checkServiceStatus();
        if (!state.isOpen) {
            return initialized;
        }

        startStatusPolling();

        if (initialized) {
            await loadMessages();
            if (state.isOpen) {
                startPolling();
            }
        }

        return initialized;
    }
    
    /**
     * 閸掑洦宕查懕濠傘亯缁愭褰?
     */
    async function toggleChat() {
        const chatWindow = document.getElementById('cs-chat-window');
        const chatButton = document.getElementById('cs-chat-button');
        if (!chatWindow || !chatButton) {
            return;
        }
        
        const widget = document.getElementById('customer-service-widget');
        if (state.isOpen) {
            state.isOpen = false;
            chatWindow.style.display = 'none';
            chatButton.style.display = 'flex';
            widget?.classList.remove('is-open');
            stopPolling();
        } else {
            state.isOpen = true;
            state.unreadCount = 0;
            updateUnreadBadge();
            chatWindow.style.display = 'flex';
            chatButton.style.display = 'none';
            widget?.classList.add('is-open');
            await activateChat();
        }
    }
    
    /**
     * 发送消息
     */
    const CS_EMOJI_LIST = ['😀','😁','😂','😊','😍','🤔','😅','😢','😡','👍','👎','🙏','👏','🎉','❤️','🔥','✅','❌','⭐','💡','📎','📷','📄','🤝','😴','🤗'];

    function bindComposerTools() {
        const toolbar = document.querySelector('#customer-service-widget .cs-composer-toolbar');
        if (!toolbar || toolbar.dataset.bound === '1') {
            return;
        }
        toolbar.dataset.bound = '1';
        toolbar.addEventListener('click', function (e) {
            const btn = e.target.closest('[data-cs-tool]');
            if (!btn || btn.disabled) return;
            const tool = btn.getAttribute('data-cs-tool');
            if (tool === 'emoji') {
                toggleFrontendEmojiPanel();
            } else if (tool === 'image') {
                document.getElementById('cs-frontend-image-input')?.click();
            } else if (tool === 'file') {
                document.getElementById('cs-frontend-file-input')?.click();
            } else if (tool === 'screenshot') {
                captureAndSendScreenshot();
            }
        });
        const imageInput = document.getElementById('cs-frontend-image-input');
        const fileInput = document.getElementById('cs-frontend-file-input');
        if (imageInput) {
            imageInput.addEventListener('change', function () {
                const file = imageInput.files && imageInput.files[0];
                imageInput.value = '';
                if (file) {
                    uploadAndSendAttachment(file, 'image');
                }
            });
        }
        if (fileInput) {
            fileInput.addEventListener('change', function () {
                const file = fileInput.files && fileInput.files[0];
                fileInput.value = '';
                if (file) {
                    uploadAndSendAttachment(file, 'file');
                }
            });
        }
        const messageInput = document.getElementById('cs-message-input');
        if (messageInput && messageInput.dataset.pasteBound !== '1') {
            messageInput.dataset.pasteBound = '1';
            messageInput.addEventListener('paste', function (event) {
                const items = event.clipboardData && event.clipboardData.items;
                if (!items) return;
                for (let i = 0; i < items.length; i++) {
                    const item = items[i];
                    if (item && item.type && item.type.indexOf('image/') === 0) {
                        event.preventDefault();
                        const file = item.getAsFile();
                        if (file) {
                            const named = new File([file], 'paste-' + Date.now() + '.png', { type: file.type || 'image/png' });
                            uploadAndSendAttachment(named, 'image');
                        }
                        return;
                    }
                }
            });
        }
    }

    function toggleFrontendEmojiPanel() {
        const panel = document.getElementById('cs-frontend-emoji-panel');
        if (!panel) return;
        if (!panel.hidden) {
            panel.hidden = true;
            return;
        }
        panel.innerHTML = CS_EMOJI_LIST.map(function (e) {
            return '<button type="button" class="cs-emoji-item" data-emoji="' + e + '">' + e + '</button>';
        }).join('');
        panel.hidden = false;
        panel.onclick = function (ev) {
            const item = ev.target.closest('[data-emoji]');
            if (!item) return;
            insertIntoFrontendInput(item.getAttribute('data-emoji') || '');
            panel.hidden = true;
        };
    }

    function insertIntoFrontendInput(text) {
        const input = document.getElementById('cs-message-input');
        if (!input) return;
        const start = input.selectionStart || input.value.length;
        const end = input.selectionEnd || input.value.length;
        input.value = input.value.slice(0, start) + text + input.value.slice(end);
        const pos = start + text.length;
        input.focus();
        try {
            input.setSelectionRange(pos, pos);
        } catch (e) {}
    }

    function readFileAsDataUrl(file) {
        return new Promise(function (resolve, reject) {
            const reader = new FileReader();
            reader.onload = function () { resolve(String(reader.result || '')); };
            reader.onerror = function () { reject(reader.error || new Error('read failed')); };
            reader.readAsDataURL(file);
        });
    }

    async function uploadAndSendAttachment(file, kind) {
        if (state.isSending) {
            return;
        }
        if (state.guestSend && state.guestSend.gate_active && state.guestSend.awaiting_reply) {
            applyGuestSendGate(state.guestSend);
            notify('warning', state.guestSend.message
                || __('请等待客服回复后再发送。验证邮箱后可跳过等待、连续发送消息。'), {
                title: __('请注意')
            });
            return;
        }
        state.isSending = true;
        applyGuestSendGate(state.guestSend);
        try {
            const sessionReady = await ensureSessionReady();
            if (!sessionReady || !state.sessionId) {
                notify('error', __('客服会话初始化失败，请稍后重试'));
                return;
            }
            notify('info', __('正在上传…'));
            const dataUrl = await readFileAsDataUrl(file);
            const uploaded = await (await getCustomerServiceApi()).upload({
                session_id: state.sessionId,
                name: file.name || (kind === 'image' ? 'image.png' : 'file.bin'),
                mime: file.type || 'application/octet-stream',
                data: dataUrl
            }, {silent: true});
            if (!(uploaded && uploaded.success && uploaded.data && uploaded.data.url)) {
                notify('error', (uploaded && uploaded.message) || __('上传失败'));
                return;
            }
            const sent = await (await getCustomerServiceApi()).sendMessage({
                session_id: state.sessionId,
                content: '',
                attachment_type: uploaded.data.kind || kind,
                attachment_url: uploaded.data.url,
                attachment_name: uploaded.data.name || file.name || '',
                attachment_size: uploaded.data.size || file.size || 0,
                attachment_mime: uploaded.data.mime || file.type || '',
                locale: state.locale
            }, {silent: true});
            if (sent && sent.guest_send) {
                applyGuestSendGate(sent.guest_send);
            }
            if (sent && sent.success && sent.data) {
                addMessage({
                    message_id: sent.data.message_id,
                    content: sent.data.content,
                    translated_content: sent.data.translated_content,
                    display_content: sent.data.display_content,
                    attachment: sent.data.attachment || {
                        type: uploaded.data.kind || kind,
                        url: uploaded.data.url,
                        name: uploaded.data.name || '',
                        size: uploaded.data.size || 0,
                        mime: uploaded.data.mime || ''
                    },
                    source_locale: sent.data.source_locale,
                    target_locale: sent.data.target_locale,
                    sender_type: 'customer',
                    created_at: sent.data.created_at
                });
                scrollToBottom();
                maybeShowGuestBindPrompt();
                const emojiPanel = document.getElementById('cs-frontend-emoji-panel');
                if (emojiPanel) emojiPanel.hidden = true;
            } else {
                notify('error', (sent && sent.message) || __('发送失败'));
            }
        } catch (error) {
            console.error('Failed to upload attachment:', error);
            notify('error', extractErrorMessage(error, __('上传失败，请稍后重试')));
        } finally {
            state.isSending = false;
            applyGuestSendGate(state.guestSend);
        }
    }

    async function captureAndSendScreenshot() {
        try {
            // Keep the chat widget visible during region pick (overlay covers it).
            // Hiding for the whole session made「完成」look like the chat vanished,
            // especially when a display-media permission sheet opened.
            ensureModernScreenshot().catch(function () { /* optional */ });
            ensureHtml2Canvas().catch(function () { /* optional */ });
            const blob = await openLiveRegionPicker();
            if (!blob) {
                notify('info', __('已取消截图'));
                return;
            }
            const file = new File([blob], 'screenshot-' + Date.now() + '.jpg', {
                type: 'image/jpeg'
            });
            await uploadAndSendAttachment(file, 'image');
        } catch (error) {
            console.error('screenshot failed:', error);
            notify('error', __('截图失败，请改用图片上传或粘贴'));
        } finally {
            restoreChatWidgetAfterShot();
        }
    }

    function hideChatWidgetForShotCapture() {
        const widget = document.getElementById('customer-service-widget');
        if (!widget) {
            return null;
        }
        if (!widget.dataset.csShotPrevVisibility) {
            widget.dataset.csShotPrevVisibility = widget.style.visibility || '';
            widget.dataset.csShotPrevPointer = widget.style.pointerEvents || '';
        }
        widget.style.visibility = 'hidden';
        widget.style.pointerEvents = 'none';
        return widget;
    }

    function restoreChatWidgetAfterShot() {
        const widget = document.getElementById('customer-service-widget');
        if (!widget) {
            return;
        }
        if (Object.prototype.hasOwnProperty.call(widget.dataset, 'csShotPrevVisibility')) {
            widget.style.visibility = widget.dataset.csShotPrevVisibility || '';
            delete widget.dataset.csShotPrevVisibility;
        } else {
            widget.style.visibility = '';
        }
        if (Object.prototype.hasOwnProperty.call(widget.dataset, 'csShotPrevPointer')) {
            widget.style.pointerEvents = widget.dataset.csShotPrevPointer || '';
            delete widget.dataset.csShotPrevPointer;
        } else {
            widget.style.pointerEvents = '';
        }
    }

    let html2canvasLoader = null;
    let modernScreenshotLoader = null;

    function normalizeVendorScriptUrl(urlRaw) {
        return String(urlRaw || '').trim().replace(/\?([^?]*)\?/, '?$1&');
    }

    function loadVendorScript(url, datasetKey) {
        return new Promise(function (resolve, reject) {
            const existing = document.querySelector('script[data-' + datasetKey + '="1"]');
            if (existing) {
                existing.addEventListener('load', function () { resolve(); });
                existing.addEventListener('error', function () {
                    reject(new Error(datasetKey + ' load failed'));
                });
                return;
            }
            const script = document.createElement('script');
            script.src = url;
            script.async = true;
            script.setAttribute('data-' + datasetKey, '1');
            script.onload = function () { resolve(); };
            script.onerror = function () { reject(new Error(datasetKey + ' load failed')); };
            document.head.appendChild(script);
        });
    }

    function ensureHtml2Canvas() {
        if (typeof window.html2canvas === 'function') {
            return Promise.resolve(window.html2canvas);
        }
        if (html2canvasLoader) {
            return html2canvasLoader;
        }
        const url = normalizeVendorScriptUrl(config.html2canvasUrl);
        if (!url) {
            return Promise.reject(new Error('html2canvas url missing'));
        }
        html2canvasLoader = loadVendorScript(url, 'cs-html2canvas').then(function () {
            if (typeof window.html2canvas === 'function') {
                return window.html2canvas;
            }
            throw new Error('html2canvas unavailable');
        }).catch(function (error) {
            html2canvasLoader = null;
            throw error;
        });
        return html2canvasLoader;
    }

    function ensureModernScreenshot() {
        if (window.modernScreenshot && typeof window.modernScreenshot.domToCanvas === 'function') {
            return Promise.resolve(window.modernScreenshot);
        }
        if (modernScreenshotLoader) {
            return modernScreenshotLoader;
        }
        const url = normalizeVendorScriptUrl(config.modernScreenshotUrl);
        if (!url) {
            return Promise.reject(new Error('modern-screenshot url missing'));
        }
        modernScreenshotLoader = loadVendorScript(url, 'cs-modern-screenshot').then(function () {
            if (window.modernScreenshot && typeof window.modernScreenshot.domToCanvas === 'function') {
                return window.modernScreenshot;
            }
            throw new Error('modern-screenshot unavailable');
        }).catch(function (error) {
            modernScreenshotLoader = null;
            throw error;
        });
        return modernScreenshotLoader;
    }

    function html2CanvasIgnoreElement(element) {
        if (!element) {
            return false;
        }
        if (element.id === 'customer-service-widget'
            || element.id === 'cs-shot-crop'
            || element.id === 'cs-notice-alert') {
            return true;
        }
        const tag = element.tagName;
        return tag === 'VIDEO' || tag === 'IFRAME' || tag === 'SCRIPT' || tag === 'LINK';
    }

    function screenshotFilterNode(node) {
        return !html2CanvasIgnoreElement(node);
    }

    function html2CanvasScaleForRegion(width, height) {
        const maxEdge = Math.max(1, width, height);
        return Math.min(1, Math.max(0.45, 960 / maxEdge));
    }

    /**
     * True when canvas is effectively blank (solid white / near-empty).
     * @param {HTMLCanvasElement} canvas
     */
    function canvasLooksBlank(canvas) {
        try {
            const w = canvas.width;
            const h = canvas.height;
            if (!w || !h) {
                return true;
            }
            const ctx = canvas.getContext('2d');
            if (!ctx) {
                return false;
            }
            const stepX = Math.max(1, Math.floor(w / 24));
            const stepY = Math.max(1, Math.floor(h / 24));
            let samples = 0;
            let nonWhite = 0;
            for (let y = 0; y < h; y += stepY) {
                for (let x = 0; x < w; x += stepX) {
                    const p = ctx.getImageData(x, y, 1, 1).data;
                    samples += 1;
                    if (p[3] < 8) {
                        continue;
                    }
                    if (p[0] < 250 || p[1] < 250 || p[2] < 250) {
                        nonWhite += 1;
                    }
                }
            }
            return samples > 0 && nonWhite / samples < 0.02;
        } catch (e) {
            return false;
        }
    }

    function cropCanvasRegion(source, sx, sy, sw, sh) {
        const out = document.createElement('canvas');
        out.width = Math.max(1, Math.floor(sw));
        out.height = Math.max(1, Math.floor(sh));
        const ctx = out.getContext('2d');
        if (!ctx) {
            throw new Error('2d context unavailable');
        }
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, out.width, out.height);
        ctx.drawImage(
            source,
            Math.max(0, Math.floor(sx)),
            Math.max(0, Math.floor(sy)),
            out.width,
            out.height,
            0,
            0,
            out.width,
            out.height
        );
        return out;
    }

    /** Hard cap so a stuck FO render cannot leave the chat hidden for tens of seconds. */
    const CS_SHOT_CAPTURE_MS = 8000;

    function withCaptureTimeout(promise, ms, label) {
        return Promise.race([
            promise,
            new Promise(function (_resolve, reject) {
                setTimeout(function () {
                    reject(new Error(label + ' timeout ' + ms + 'ms'));
                }, ms);
            })
        ]);
    }

    /**
     * FO fallback via modern-screenshot (region-only). Prefer getDisplayMedia on this theme.
     * @param {{x:number,y:number,w:number,h:number}} region client coordinates
     */
    async function captureWithModernScreenshot(region) {
        const api = await ensureModernScreenshot();
        const rw = Math.max(1, Math.floor(region.w));
        const rh = Math.max(1, Math.floor(region.h));
        const scale = html2CanvasScaleForRegion(rw, rh);
        const left = region.x;
        const top = region.y;
        // Translate-crop the selected rectangle only (no viewport/full-document multi-pass).
        const canvas = await withCaptureTimeout(
            api.domToCanvas(document.documentElement, {
                scale: scale,
                backgroundColor: '#ffffff',
                timeout: CS_SHOT_CAPTURE_MS,
                filter: screenshotFilterNode,
                width: rw,
                height: rh,
                style: {
                    margin: '0',
                    transform: 'translate(' + (-left) + 'px,' + (-top) + 'px)',
                    'transform-origin': 'top left'
                }
            }),
            CS_SHOT_CAPTURE_MS,
            'modern-region'
        );
        if (!canvas || canvas.width < 2 || canvas.height < 2 || canvasLooksBlank(canvas)) {
            throw new Error('modern-screenshot region blank');
        }
        return canvas;
    }

    /**
     * Legacy html2canvas fallback. Note: storefront themes using CSS color()/color-mix()
     * often render blank with html2canvas — modern-screenshot is preferred.
     * @param {{x:number,y:number,w:number,h:number}} region
     */
    async function captureWithHtml2Canvas(region) {
        const html2canvas = await ensureHtml2Canvas();
        const vw = Math.max(1, Math.floor(window.innerWidth || document.documentElement.clientWidth || 1));
        const vh = Math.max(1, Math.floor(window.innerHeight || document.documentElement.clientHeight || 1));
        const scrollX = window.scrollX || window.pageXOffset || 0;
        const scrollY = window.scrollY || window.pageYOffset || 0;
        const rw = Math.max(1, Math.floor(region.w));
        const rh = Math.max(1, Math.floor(region.h));
        const scale = html2CanvasScaleForRegion(rw, rh);
        const left = region.x;
        const top = region.y;
        // Region crop only — full-viewport FO here had the same hang as modern path1.
        return withCaptureTimeout(html2canvas(document.body, {
            x: Math.max(0, Math.floor(scrollX + left)),
            y: Math.max(0, Math.floor(scrollY + top)),
            width: rw,
            height: rh,
            windowWidth: vw,
            windowHeight: vh,
            scrollX: -scrollX,
            scrollY: -scrollY,
            scale: scale,
            useCORS: true,
            allowTaint: false,
            backgroundColor: '#ffffff',
            logging: false,
            imageTimeout: 1500,
            removeContainer: true,
            foreignObjectRendering: false,
            ignoreElements: html2CanvasIgnoreElement
        }), CS_SHOT_CAPTURE_MS, 'html2canvas-region');
    }

    /**
     * Capture ONLY the selected client rectangle.
     * Primary: browser getDisplayMedia (real pixels). DOM FO libs hang/fail on color()/color-mix themes.
     * @param {{x:number,y:number,w:number,h:number}} region client coordinates
     * @returns {Promise<HTMLCanvasElement>}
     */
    async function captureClientRegionDirect(region) {
        let canvas = null;
        let primaryError = null;
        // Always show chat before permission sheet / capture so「完成」does not look like chat vanished.
        restoreChatWidgetAfterShot();
        try {
            canvas = await captureWithTabDisplayMedia(region);
        } catch (error) {
            primaryError = error;
            console.warn('getDisplayMedia capture failed, trying modern-screenshot', error);
            // User cancelled share picker — do not fall into FO engines that hang for long.
            if (error && (error.name === 'NotAllowedError' || error.name === 'AbortError')) {
                throw error;
            }
        }
        if (!canvas || !canvas.width || !canvas.height || canvasLooksBlank(canvas)) {
            try {
                // Short FO fallback only — do not block UI for long.
                canvas = await withCaptureTimeout(captureWithModernScreenshot(region), 2500, 'modern-fallback');
            } catch (error) {
                console.warn('modern-screenshot short fallback failed', error);
                if (!primaryError) {
                    primaryError = error;
                }
            }
        }
        if (!canvas || !canvas.width || !canvas.height || canvasLooksBlank(canvas)) {
            throw primaryError || new Error('blank region canvas');
        }
        return canvas;
    }

    /**
     * Browser-native tab pixel capture then crop (avoids FO hang / color() parse failures).
     * @param {{x:number,y:number,w:number,h:number}} region
     */
    async function captureWithTabDisplayMedia(region) {
        if (!navigator.mediaDevices || typeof navigator.mediaDevices.getDisplayMedia !== 'function') {
            throw new Error('getDisplayMedia unavailable');
        }
        restoreChatWidgetAfterShot();
        const stream = await navigator.mediaDevices.getDisplayMedia({
            audio: false,
            video: true,
            preferCurrentTab: true,
            selfBrowserSurface: 'include',
            surfaceSwitching: 'exclude'
        });
        try {
            const video = document.createElement('video');
            video.muted = true;
            video.playsInline = true;
            video.srcObject = stream;
            await video.play();
            await new Promise(function (resolve) {
                if (video.videoWidth > 0) {
                    resolve();
                    return;
                }
                video.onloadeddata = function () { resolve(); };
                setTimeout(resolve, 800);
            });
            // One paint after stream so chrome/permission UI is gone from the frame when possible.
            await new Promise(function (r) {
                requestAnimationFrame(function () { requestAnimationFrame(r); });
            });
            const full = document.createElement('canvas');
            full.width = Math.max(1, video.videoWidth || window.innerWidth || 1);
            full.height = Math.max(1, video.videoHeight || window.innerHeight || 1);
            const ctx = full.getContext('2d');
            if (!ctx) {
                throw new Error('2d context unavailable');
            }
            ctx.drawImage(video, 0, 0, full.width, full.height);
            const vw = Math.max(1, window.innerWidth || full.width);
            const vh = Math.max(1, window.innerHeight || full.height);
            const ratioX = full.width / vw;
            const ratioY = full.height / vh;
            return cropCanvasRegion(
                full,
                region.x * ratioX,
                region.y * ratioY,
                region.w * ratioX,
                region.h * ratioY
            );
        } finally {
            stream.getTracks().forEach(function (track) {
                try { track.stop(); } catch (_e) {}
            });
        }
    }

    /**
     * @param {{x:number,y:number,w:number,h:number}} region
     * @returns {Promise<HTMLCanvasElement>}
     */
    async function captureViewportRegion(region) {
        return captureClientRegionDirect(region);
    }

    /**
     * WeChat / Snipping Tool style: dim live page, drag-select, then capture on confirm.
     * Capture is region-direct (not full-page prefetch) so confirm stays responsive.
     * @returns {Promise<Blob|null>}
     */
    function openLiveRegionPicker() {
        return new Promise(function (resolve) {
            const existing = document.getElementById('cs-shot-crop');
            if (existing && existing.parentNode) {
                existing.parentNode.removeChild(existing);
            }

            const overlay = document.createElement('div');
            overlay.id = 'cs-shot-crop';
            overlay.className = 'cs-shot-crop cs-shot-crop--live';
            overlay.setAttribute('role', 'dialog');
            overlay.setAttribute('aria-modal', 'true');
            overlay.innerHTML = [
                '<div class="cs-shot-crop__stage" data-cs-shot-stage>',
                '<div class="cs-shot-crop__rect" data-cs-shot-rect hidden></div>',
                '</div>',
                '<p class="cs-shot-crop__hint" data-cs-shot-hint></p>',
                '<div class="cs-shot-crop__bar" data-cs-shot-bar>',
                '<button type="button" class="w-button cs-btn cs-shot-crop__btn" data-tone="neutral" data-cs-shot-cancel></button>',
                '<button type="button" class="w-button cs-btn cs-shot-crop__btn" data-tone="neutral" data-cs-shot-reset></button>',
                '<button type="button" class="w-button cs-btn cs-btn-primary cs-shot-crop__btn cs-shot-crop__confirm" data-tone="brand" data-cs-shot-confirm disabled></button>',
                '</div>'
            ].join('');

            const stage = overlay.querySelector('[data-cs-shot-stage]');
            const rectEl = overlay.querySelector('[data-cs-shot-rect]');
            const hintEl = overlay.querySelector('[data-cs-shot-hint]');
            const cancelBtn = overlay.querySelector('[data-cs-shot-cancel]');
            const resetBtn = overlay.querySelector('[data-cs-shot-reset]');
            const confirmBtn = overlay.querySelector('[data-cs-shot-confirm]');

            hintEl.textContent = __('在页面上拖拽选择要截取的区域');
            cancelBtn.textContent = __('取消');
            resetBtn.textContent = __('重选');
            confirmBtn.textContent = __('完成');

            document.body.appendChild(overlay);
            document.body.classList.add('cs-shot-crop-open');

            // No full-page prefetch: html2canvas(document.body) for the whole viewport is what made「完成」卡顿.
            // Confirm path captures only the selected rectangle.
            let selection = null;
            let dragging = false;
            let startX = 0;
            let startY = 0;
            let settled = false;
            let generating = false;

            function finish(result) {
                if (settled) {
                    return;
                }
                settled = true;
                restoreChatWidgetAfterShot();
                document.body.classList.remove('cs-shot-crop-open');
                if (overlay.parentNode) {
                    overlay.parentNode.removeChild(overlay);
                }
                resolve(result);
            }

            function syncRect() {
                const ready = !!(selection && selection.w >= 2 && selection.h >= 2);
                if (!ready) {
                    rectEl.hidden = true;
                    confirmBtn.disabled = true;
                    overlay.classList.remove('cs-shot-crop--ready');
                    hintEl.textContent = __('在页面上拖拽选择要截取的区域');
                    return;
                }
                rectEl.hidden = false;
                confirmBtn.disabled = false;
                overlay.classList.add('cs-shot-crop--ready');
                hintEl.textContent = __('已选中区域，可点击「完成」发送');
                rectEl.style.left = selection.x + 'px';
                rectEl.style.top = selection.y + 'px';
                rectEl.style.width = selection.w + 'px';
                rectEl.style.height = selection.h + 'px';
            }

            function pointInStage(clientX, clientY) {
                const box = stage.getBoundingClientRect();
                return {
                    x: Math.max(0, Math.min(box.width, clientX - box.left)),
                    y: Math.max(0, Math.min(box.height, clientY - box.top))
                };
            }

            function onPointerDown(event) {
                if (generating) {
                    return;
                }
                if (event.button !== undefined && event.button !== 0) {
                    return;
                }
                event.preventDefault();
                const p = pointInStage(event.clientX, event.clientY);
                dragging = true;
                startX = p.x;
                startY = p.y;
                selection = { x: p.x, y: p.y, w: 0, h: 0 };
                syncRect();
                if (stage.setPointerCapture && event.pointerId !== undefined) {
                    try { stage.setPointerCapture(event.pointerId); } catch (e) { /* ignore */ }
                }
            }

            function onPointerMove(event) {
                if (!dragging || generating) {
                    return;
                }
                event.preventDefault();
                const p = pointInStage(event.clientX, event.clientY);
                selection = {
                    x: Math.min(startX, p.x),
                    y: Math.min(startY, p.y),
                    w: Math.abs(p.x - startX),
                    h: Math.abs(p.y - startY)
                };
                syncRect();
            }

            function onPointerUp(event) {
                if (!dragging) {
                    return;
                }
                dragging = false;
                if (event && stage.releasePointerCapture && event.pointerId !== undefined) {
                    try { stage.releasePointerCapture(event.pointerId); } catch (e) { /* ignore */ }
                }
                syncRect();
                if (selection && selection.w >= 2 && selection.h >= 2) {
                    confirmBtn.focus();
                }
            }

            stage.addEventListener('pointerdown', onPointerDown);
            stage.addEventListener('pointermove', onPointerMove);
            stage.addEventListener('pointerup', onPointerUp);
            stage.addEventListener('pointercancel', onPointerUp);

            cancelBtn.addEventListener('click', function () {
                if (generating) {
                    return;
                }
                finish(null);
            });
            resetBtn.addEventListener('click', function () {
                if (generating) {
                    return;
                }
                selection = null;
                syncRect();
            });
            confirmBtn.addEventListener('click', async function () {
                if (generating) {
                    return;
                }
                if (!selection || selection.w < 2 || selection.h < 2) {
                    notify('warning', __('请先拖拽选择截图区域'), { title: __('截图') });
                    return;
                }
                generating = true;
                overlay.classList.add('cs-shot-crop--busy');
                overlay.classList.add('cs-shot-crop--ready');
                confirmBtn.disabled = true;
                cancelBtn.disabled = true;
                resetBtn.disabled = true;
                confirmBtn.textContent = __('生成中…');
                hintEl.textContent = __('正在生成截图…');
                const stageBox = stage.getBoundingClientRect();
                const region = {
                    x: selection.x + stageBox.left,
                    y: selection.y + stageBox.top,
                    w: selection.w,
                    h: selection.h
                };
                try {
                    // Keep chat visible: getDisplayMedia shows a share picker (DOM FO libs hang on this theme).
                    // Only hide the crop chrome so the selected page pixels are clean.
                    await new Promise(function (r) {
                        requestAnimationFrame(function () { requestAnimationFrame(r); });
                    });
                    restoreChatWidgetAfterShot();
                    overlay.style.opacity = '0';
                    overlay.style.pointerEvents = 'none';
                    hintEl.textContent = __('请在弹出窗口选择「这个标签页」以截取');
                    await new Promise(function (r) {
                        requestAnimationFrame(function () { requestAnimationFrame(r); });
                    });
                    const crop = await captureClientRegionDirect(region);
                    const blob = await compressCanvasToJpegUnderLimit(crop, 2 * 1024 * 1024);
                    restoreChatWidgetAfterShot();
                    finish(blob);
                } catch (err) {
                    console.error('screenshot region capture failed:', err);
                    restoreChatWidgetAfterShot();
                    overlay.style.opacity = '1';
                    overlay.style.pointerEvents = '';
                    generating = false;
                    overlay.classList.remove('cs-shot-crop--busy');
                    cancelBtn.disabled = false;
                    resetBtn.disabled = false;
                    confirmBtn.disabled = false;
                    confirmBtn.textContent = __('完成');
                    hintEl.textContent = __('生成失败，请重选区域或改用图片上传');
                    notify('error', __('截图失败，请改用图片上传或粘贴'));
                }
            });

            overlay.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && !generating) {
                    event.preventDefault();
                    finish(null);
                }
            });

            cancelBtn.focus();
        });
    }

    /**
     * @param {HTMLCanvasElement} canvas
     * @param {number} maxBytes
     * @returns {Promise<Blob>}
     */
    async function compressCanvasToJpegUnderLimit(canvas, maxBytes) {
        let work = canvas;
        let quality = 0.82;
        for (let attempt = 0; attempt < 10; attempt++) {
            const blob = await new Promise(function (resolve) {
                work.toBlob(function (b) { resolve(b); }, 'image/jpeg', quality);
            });
            if (blob && blob.size <= maxBytes) {
                return blob;
            }
            if (quality > 0.5) {
                quality -= 0.1;
                continue;
            }
            const next = document.createElement('canvas');
            next.width = Math.max(1, Math.floor(work.width * 0.75));
            next.height = Math.max(1, Math.floor(work.height * 0.75));
            next.getContext('2d').drawImage(work, 0, 0, next.width, next.height);
            work = next;
            quality = 0.85;
        }
        const fallback = await new Promise(function (resolve) {
            work.toBlob(function (b) { resolve(b); }, 'image/jpeg', 0.55);
        });
        if (!fallback) {
            throw new Error('empty cropped screenshot');
        }
        return fallback;
    }

    async function sendMessage() {
        const input = document.getElementById('cs-message-input');
        if (!input || state.isSending) {
            return;
        }
        const content = input.value.trim();
        
        if (!content) {
            return;
        }

        if (state.guestSend && state.guestSend.gate_active && state.guestSend.awaiting_reply) {
            applyGuestSendGate(state.guestSend);
            notify('warning', state.guestSend.message
                || __('请等待客服回复后再发送。验证邮箱后可跳过等待、连续发送消息。'), {
                title: __('请注意')
            });
            return;
        }

        state.isSending = true;

        const sessionReady = await ensureSessionReady();
        if (!sessionReady || !state.sessionId) {
            state.isSending = false;
            notify('error', __('客服会话初始化失败，请稍后重试'));
            return;
        }
        
        input.disabled = true;
        const sendButton = document.querySelector('.cs-send-button, [data-cs-send-message]');
        if (sendButton) {
            sendButton.disabled = true;
        }
        
        try {
            const data = await (await getCustomerServiceApi()).sendMessage({
                session_id: state.sessionId,
                content: content,
                locale: state.locale
            }, {silent: true});

            if (data.guest_send) {
                applyGuestSendGate(data.guest_send);
            }
            
            if (data.success) {
                input.value = '';
                
                addMessage({
                    message_id: data.data.message_id,
                    content: data.data.content,
                    translated_content: data.data.translated_content,
                    display_content: data.data.display_content,
                    source_locale: data.data.source_locale,
                    target_locale: data.data.target_locale,
                    sender_type: 'customer',
                    created_at: data.data.created_at
                });
                
                scrollToBottom();
                maybeShowGuestBindPrompt();
            } else {
                notify('error', data.message || __('发送失败'));
            }
        } catch (error) {
            console.error('Failed to send message:', error);
            notify('error', __('发送失败，请稍后重试'));
        } finally {
            state.isSending = false;
            applyGuestSendGate(state.guestSend);
        }
    }
    
    /**
     * 閸旂姾娴囧☉鍫熶紖
     */
    async function loadMessages() {
        if (!state.sessionId) {
            return;
        }
        
        try {
            const data = await (await getCustomerServiceApi()).messages({
                session_id: state.sessionId,
                locale: state.locale,
                limit: 50,
                offset: 0
            }, {silent: true});
            
            if (data.success) {
                if (data.guest_send) {
                    applyGuestSendGate(data.guest_send);
                }
                const messages = Array.isArray(data.data) ? data.data : null;
                const messagesContainer = document.getElementById('cs-chat-messages');
                if (!messagesContainer || messages === null) {
                    return;
                }
                messagesContainer.innerHTML = '';
                state.lastDayKey = '';
                
                if (messages.length === 0) {
                    messagesContainer.innerHTML = `
                        <div class="cs-welcome-message">
                            <p>${__('欢迎使用客服服务')}</p>
                            <p>${__('请输入您的问题，我们的客服将尽快为您解答。')}</p>
                        </div>
                    `;
                } else {
                    messages.forEach(msg => {
                        addMessage(msg, false);
                    });
                    
                    if (messages.length > 0) {
                        state.lastMessageId = messages[messages.length - 1].message_id;
                    }
                    
                    scrollToBottom();
                }
            }
        } catch (error) {
            console.error('Failed to load messages:', error);
        }
    }
    
    /**
     * 濞ｈ濮炲☉鍫熶紖閸掓壆鏅棃?
     */
    function addMessage(message, scroll = true) {
        const messagesContainer = document.getElementById('cs-chat-messages');
        if (!messagesContainer) {
            return;
        }
        
        const welcomeMsg = messagesContainer.querySelector('.cs-welcome-message');
        if (welcomeMsg) {
            welcomeMsg.remove();
        }

        appendDaySeparatorIfNeeded(messagesContainer, message.created_at);
        
        const messageDiv = document.createElement('div');
        const senderType = String(message.sender_type || 'agent');
        messageDiv.className = 'cs-message ' + senderType;
        messageDiv.dataset.messageId = message.message_id;
        messageDiv.dataset.content = message.content || '';
        messageDiv.dataset.translatedContent = message.translated_content || '';
        messageDiv.dataset.displayContent = message.display_content || '';
        messageDiv.dataset.sourceLocale = message.source_locale || '';
        messageDiv.dataset.targetLocale = message.target_locale || '';

        if (senderType === 'customer' || senderType === 'agent') {
            const meta = document.createElement('div');
            meta.className = 'cs-message-meta';
            if (senderType === 'customer' && state.identity.avatar_url) {
                const img = document.createElement('img');
                img.className = 'cs-message-avatar';
                img.src = state.identity.avatar_url;
                img.alt = '';
                meta.appendChild(img);
            }
            const nameEl = document.createElement(
                senderType === 'customer' && state.identity.can_change_email ? 'button' : 'span'
            );
            nameEl.className = 'cs-message-sender';
            if (senderType === 'customer') {
                nameEl.textContent = state.identity.display_name
                    || state.identity.email
                    || (config.isLoggedIn ? __('会员') : __('访客'));
                if (state.identity.can_change_email && nameEl.tagName === 'BUTTON') {
                    nameEl.type = 'button';
                    nameEl.addEventListener('click', function () {
                        showBindPrompt({ reason: 'edit_identity' });
                    });
                }
            } else {
                nameEl.textContent = __('客服');
            }
            meta.appendChild(nameEl);
            messageDiv.appendChild(meta);
        }
        
        const bubble = document.createElement('div');
        bubble.className = 'cs-message-bubble';
        renderMessageContent(bubble, message);
        
        const timeDiv = document.createElement('div');
        timeDiv.className = 'cs-message-time';
        timeDiv.textContent = formatTime(message.created_at);
        
        messageDiv.appendChild(bubble);
        messageDiv.appendChild(timeDiv);
        
        messagesContainer.appendChild(messageDiv);
        
        if (scroll) {
            scrollToBottom();
        }
    }
    
    /**
     * 閺嶈宓侀弰鍓с仛濡€崇础濞撳弶鐓嬪☉鍫熶紖閸愬懎顔?
     */
    function renderMessageContent(bubble, message) {
        const original = message.content || '';
        const attachment = message.attachment || parseCsAttachment(original);
        bubble.innerHTML = '';
        if (attachment && attachment.type === 'image' && attachment.url) {
            const link = document.createElement('a');
            link.href = attachment.url;
            link.target = '_blank';
            link.rel = 'noopener';
            const img = document.createElement('img');
            img.className = 'cs-message-image';
            img.src = attachment.url;
            img.alt = attachment.name || 'image';
            img.loading = 'lazy';
            img.addEventListener('error', function () {
                img.classList.add('is-broken');
                img.alt = __('图片加载失败');
            });
            link.appendChild(img);
            bubble.appendChild(link);
            return;
        }
        if (attachment && attachment.type === 'file' && attachment.url) {
            const link = document.createElement('a');
            link.href = attachment.url;
            link.target = '_blank';
            link.rel = 'noopener';
            link.textContent = attachment.name || (message.display_content || 'file');
            bubble.appendChild(link);
            return;
        }
        const translated = message.display_content || message.translated_content || original;
        const hasTranslation = translated && translated !== original;
        const sourceLocale = message.source_locale || '';
        const translatedLocale = message.display_content && translated !== original
            ? state.locale
            : (message.target_locale || state.locale);
        
        switch (state.displayMode) {
            case 'both':
                if (hasTranslation) {
                    // 閺勫墽銇氶崢鐔告瀮
                    const originalDiv = document.createElement('div');
                    originalDiv.className = 'cs-message-original';
                    const originalLabel = document.createElement('span');
                    originalLabel.className = 'cs-message-label';
                    originalLabel.textContent = getLocaleName(sourceLocale);
                    originalDiv.appendChild(originalLabel);
                    originalDiv.appendChild(document.createTextNode(original));
                    bubble.appendChild(originalDiv);
                    
                    // 閺勫墽銇氱拠鎴炴瀮
                    const translatedDiv = document.createElement('div');
                    translatedDiv.className = 'cs-message-translated';
                    const translatedLabel = document.createElement('span');
                    translatedLabel.className = 'cs-message-label';
                    translatedLabel.textContent = getLocaleName(translatedLocale);
                    translatedDiv.appendChild(translatedLabel);
                    translatedDiv.appendChild(document.createTextNode(translated));
                    bubble.appendChild(translatedDiv);
                } else {
                    bubble.textContent = original;
                }
                break;
                
            case 'original':
                bubble.textContent = original;
                break;
                
            case 'translated':
            default:
                bubble.textContent = translated;
                break;
        }
    }

    function parseCsAttachment(content) {
        const raw = String(content || '');
        if (raw.indexOf('__CSJSON__') !== 0) {
            return null;
        }
        try {
            const data = JSON.parse(raw.slice('__CSJSON__'.length));
            if (!data || (data.type !== 'image' && data.type !== 'file') || !data.url) {
                return null;
            }
            return data;
        } catch (e) {
            return null;
        }
    }

    function rerenderMessageBubbles() {
        const messagesContainer = document.getElementById('cs-chat-messages');
        if (!messagesContainer) {
            return;
        }

        messagesContainer.querySelectorAll('.cs-message').forEach((messageDiv) => {
            const bubble = messageDiv.querySelector('.cs-message-bubble');
            if (!bubble) {
                return;
            }

            renderMessageContent(bubble, {
                content: messageDiv.dataset.content || '',
                translated_content: messageDiv.dataset.translatedContent || '',
                display_content: messageDiv.dataset.displayContent || '',
                source_locale: messageDiv.dataset.sourceLocale || '',
                target_locale: messageDiv.dataset.targetLocale || '',
            });
        });
    }
    
    /**
     * 閼惧嘲褰囩拠顓♀枅閸氬秶袨
     */
    function getLocaleName(locale) {
        const names = {
            'zh_Hans_CN': '简中',
            'zh_Hant_TW': '繁中',
            'en_US': 'EN',
            'ja_JP': '日本語',
            'ko_KR': '한국어',
            'fr_FR': 'FR',
            'de_DE': 'DE',
            'es_ES': 'ES',
            'pt_BR': 'PT',
            'ru_RU': 'RU',
            'ar_SA': 'AR',
            'th_TH': 'TH',
            'vi_VN': 'VI'
        };
        return names[locale] || locale;
    }
    
    /**
     * 濠婃艾濮╅崚鏉跨俺闁?
     */
    function scrollToBottom() {
        const messagesContainer = document.getElementById('cs-chat-messages');
        if (!messagesContainer) {
            return;
        }
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }
    
    /**
     * 閺嶇厧绱￠崠鏍ㄦ闂?
     */
    function formatTime(timeStr) {
        if (!timeStr) return '';
        
        const date = new Date(timeStr);
        const now = new Date();
        const diff = now - date;
        
        if (diff < 60000) {
            return __('刚刚');
        } else if (diff < 3600000) {
            return Math.floor(diff / 60000) + __('分钟前');
        } else if (diff < 86400000) {
            return Math.floor(diff / 3600000) + __('小时前');
        } else {
            return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
        }
    }
    
    /**
     * 瀵偓婵鐤嗙拠銏℃煀濞戝牊浼?
     */
    function startPolling() {
        if (state.isPolling || !state.sessionId) {
            return;
        }
        
        state.isPolling = true;
        
        state.pollInterval = setInterval(async () => {
            try {
                const data = await (await getCustomerServiceApi()).messages({
                    session_id: state.sessionId,
                    locale: state.locale,
                    limit: 10,
                    offset: 0
                }, {silent: true});
                
                if (data.success && data.guest_send) {
                    applyGuestSendGate(data.guest_send);
                }

                if (data.success && Array.isArray(data.data) && data.data.length > 0) {
                    const messagesContainer = document.getElementById('cs-chat-messages');
                    if (!messagesContainer) {
                        return;
                    }
                    const existingIds = new Set(
                        Array.from(messagesContainer.querySelectorAll('.cs-message'))
                            .map(el => parseInt(el.dataset.messageId))
                    );
                    
                    let hasNew = false;
                    data.data.forEach(msg => {
                        const messageId = parseInt(msg.message_id, 10) || 0;
                        if (messageId > 0 && !existingIds.has(messageId)
                            && (msg.sender_type === 'agent' || msg.sender_type === 'system')) {
                            addMessage(msg);
                            existingIds.add(messageId);
                            hasNew = true;
                            
                            if (!state.isOpen && msg.sender_type === 'agent') {
                                notifyIncomingAgentMessage(msg);
                            }
                        }
                    });
                    
                    if (hasNew && data.data.length > 0) {
                        state.lastMessageId = data.data[data.data.length - 1].message_id;
                    }
                }
            } catch (error) {
                console.error('Polling error:', error);
            }
        }, 3000); // 濮?缁夋帟鐤嗙拠顫濞?
    }
    
    /**
     * 閸嬫粍顒涙潪顔款嚄
     */
    function stopPolling() {
        if (state.pollInterval) {
            clearInterval(state.pollInterval);
            state.pollInterval = null;
            state.isPolling = false;
        }
        if (state.statusPollInterval) {
            clearInterval(state.statusPollInterval);
            state.statusPollInterval = null;
        }
    }
    
    /**
     * 濡偓閺屻儱顓归張宥呮躬缁捐法濮搁幀?
     */
    async function checkServiceStatus() {
        try {
            const data = await (await getCustomerServiceApi()).serviceStatus({}, {silent: true});
            if (data.success) {
                state.serviceStatus = data.data.status;
                updateStatusIndicator();
            }
        } catch (e) {
            // 缂冩垹绮舵径杈Е娣囨繃瀵旇ぐ鎾冲閻樿埖鈧?
        }
    }

    /**
     * 瀵偓婵鐤嗙拠銏狀吂閺堝秴婀痪璺ㄥЦ閹緤绱欏В?5缁夋帪绱?
     */
    function startStatusPolling() {
        if (state.statusPollInterval) return;
        state.statusPollInterval = setInterval(checkServiceStatus, 15000);
    }

    /**
     * 更新在线状态指示器 UI（窗内文案；浮钮不再显示状态小圆点）
     * online=在线, ai=AI, offline=离线
     */
    function updateStatusIndicator() {
        const label = document.getElementById('cs-status-label');
        if (!label) {
            return;
        }

        const statusClasses = ['cs-status-online', 'cs-status-ai', 'cs-status-offline'];
        label.classList.remove(...statusClasses);
        label.style.backgroundColor = '';
        label.style.color = '';

        switch (state.serviceStatus) {
            case 'online':
                label.classList.add('cs-status-online');
                label.textContent = __('在线客服');
                break;
            case 'ai':
                label.classList.add('cs-status-ai');
                label.textContent = __('AI 智能客服');
                break;
            default:
                label.classList.add('cs-status-offline');
                label.textContent = __('离线');
                break;
        }
    }

    /**
     * 閺囧瓨鏌婇張顏囶嚢瀵扮晫鐝?
     */
    function updateUnreadBadge() {
        const badge = document.getElementById('cs-unread-badge');
        if (!badge) {
            return;
        }
        const count = Math.max(0, Number(state.unreadCount) || 0);
        
        if (count > 0 && !state.isOpen) {
            badge.textContent = String(count > 99 ? '99+' : count);
            badge.style.display = 'flex';
        } else {
            badge.style.display = 'none';
        }
    }
    
    /**
     * 閺囧瓨鏁肩拠顓♀枅
     */
    async function changeLanguage(locale) {
        state.locale = locale;
        saveState();
        updateWidgetLocaleText();

        const sessionReady = await ensureSessionReady();
        if (!sessionReady || !state.sessionToken) {
            return;
        }
        
        try {
            const data = await (await getCustomerServiceApi()).setLanguage({
                locale: locale,
                session_token: state.sessionToken
            }, {silent: true});
            
            if (data.success) {
                // 闁插秵鏌婇崝鐘烘祰濞戝牊浼呮禒銉ㄥ箯閸欐牜鐐曠拠?
                await loadMessages();
            }
        } catch (error) {
            console.error('Failed to change language:', error);
        }
    }
    
    function sanitizeStaticTemplateHtml(html) {
        const template = document.createElement('template');
        template.innerHTML = String(html || '');

        template.content.querySelectorAll('script, style, link, meta, iframe, frame, frameset, object, embed, base').forEach(node => node.remove());
        template.content.querySelectorAll('*').forEach(node => {
            Array.from(node.attributes).forEach(attribute => {
                const name = String(attribute.name || '').toLowerCase();
                const compactValue = String(attribute.value || '').trim().replace(/[\u0000-\u001F\u007F\s]+/g, '').toLowerCase();

                if (name.indexOf('on') === 0 || name === 'srcdoc') {
                    node.removeAttribute(attribute.name);
                    return;
                }

                if ((name === 'href' || name === 'src' || name === 'xlink:href' || name === 'action' || name === 'formaction' || name === 'poster') && (
                    compactValue.indexOf('javascript:') === 0 ||
                    compactValue.indexOf('vbscript:') === 0 ||
                    (compactValue.indexOf('data:') === 0 && compactValue.indexOf('data:image/') !== 0)
                )) {
                    node.removeAttribute(attribute.name);
                }
            });
        });

        return template.innerHTML;
    }

    function activateTrustedScripts(root) {
        if (!root || !root.querySelectorAll) {
            return;
        }

        root.querySelectorAll('script').forEach(function (oldScript) {
            const script = document.createElement('script');
            Array.from(oldScript.attributes).forEach(function (attribute) {
                script.setAttribute(attribute.name, attribute.value);
            });
            script.textContent = oldScript.textContent || '';
            oldScript.replaceWith(script);
        });
    }

    function resetBindFormRuntime(form) {
        if (!form) {
            return;
        }

        delete form.dataset.welineFormMounted;
        delete form.dataset.csBindFormBound;
        // Keep csBindSubmitBound: submit listeners stay; captcha scripts rebind via welineCaptchaBound.
        delete form.dataset.welineCaptchaVerified;
        delete form.dataset.welineCaptchaBound;
        delete form.dataset.welineCaptchaPending;
    }

    async function refreshBindCaptcha(modal, prefer, options) {
        if (!modal || !config.bindCaptchaChallengeUrl) {
            return false;
        }

        const slot = modal.querySelector('[data-weline-form-captcha-slot]');
        if (!slot) {
            return false;
        }

        try {
            let challengeUrl = String(config.bindCaptchaChallengeUrl);
            const preferValue = String(prefer || '').trim();
            if (preferValue === 'local_image') {
                const url = new URL(challengeUrl, window.location.origin);
                url.searchParams.set('prefer', 'local_image');
                const reason = String((options && options.reason) || '').trim().slice(0, 80);
                if (reason) {
                    url.searchParams.set('degrade_reason', reason);
                }
                challengeUrl = url.toString();
            }

            const fetchChallenge = async function () {
                return fetch(challengeUrl, {
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {
                        Accept: 'application/json',
                        'Cache-Control': 'no-cache'
                    }
                });
            };

            let response = await fetchChallenge();
            // Rolling reload / maintenance flaps briefly return 404/503; one retry avoids empty slot.
            if (response.status === 404 || response.status === 503) {
                await new Promise(function (resolve) { setTimeout(resolve, 400); });
                response = await fetchChallenge();
            }
            let data = null;
            try {
                data = await response.json();
            } catch (parseError) {
                data = null;
            }
            if (response.status === 503 && data && String(data.code || '') === 'maintenance') {
                notify('warning', String(data.message || __('网站正在维护，请稍后再试')), {title: __('请稍候')});
                return false;
            }
            if (!response.ok || !data || data.success !== true || !data.html) {
                return false;
            }

            slot.innerHTML = data.html;
            const form = modal.querySelector('#cs-bind-form');
            resetBindFormRuntime(form);
            mountBindForm(modal);
            if (preferValue === 'local_image') {
                const quiet = !!(options && options.quietHint);
                ensureLocalCaptchaHint(form, {quiet: quiet});
                const localInput = form && form.querySelector(
                    'input[name="captcha_response"], input[name="captcha_code"], .weline-captcha-local input'
                );
                if (localInput && typeof localInput.focus === 'function') {
                    localInput.focus();
                }
            }
            return true;
        } catch (error) {
            console.error('CustomerService: bind captcha refresh failed', error);
            return false;
        }
    }

    function ensureLocalCaptchaHint(form, options) {
        if (!form) {
            return;
        }
        let hint = form.querySelector('[data-cs-captcha-degrade-hint]');
        if (!hint) {
            hint = document.createElement('p');
            hint.className = 'cs-captcha-degrade-hint w-text';
            hint.setAttribute('data-cs-captcha-degrade-hint', '1');
            hint.setAttribute('data-size', 'sm');
            const slot = form.querySelector('[data-weline-form-captcha-slot]');
            if (slot && slot.parentNode) {
                slot.parentNode.insertBefore(hint, slot);
            } else {
                form.insertBefore(hint, form.firstChild);
            }
        }
        const quiet = !!(options && options.quiet);
        if (quiet) {
            // Auto fallback (e.g. Google JS unreachable in CN): neutral copy, no alarm toast tone.
            hint.setAttribute('data-tone', 'neutral');
            hint.textContent = __('请填写下方图码后发送验证邮件');
        } else {
            hint.setAttribute('data-tone', 'warning');
            hint.textContent = __('云端人机验证暂不可用，请填写下方本地图码后再次发送');
        }
        hint.hidden = false;
    }

    function notifyCaptchaDegrade(message) {
        notify('warning', message || __('云端人机验证暂不可用，已切换为本地图码，请填写后重试'), {
            title: __('请填写本地图码')
        });
    }

    function mountBindForm(modal) {
        const form = modal ? modal.querySelector('#cs-bind-form') : null;
        if (!form || form.dataset.csBindFormBound === '1') {
            return form;
        }

        activateTrustedScripts(modal);
        if (window.Weline && window.Weline.Form && typeof window.Weline.Form.mount === 'function') {
            window.Weline.Form.mount(form);
        } else if (window.WelineFormRuntime && typeof window.WelineFormRuntime.mount === 'function') {
            window.WelineFormRuntime.mount(form);
        }

        bindBindFormSubmit(form);
        form.dataset.csBindFormBound = '1';
        return form;
    }

    function readBindCaptchaProvider(form) {
        const root = form ? form.querySelector('[data-weline-captcha-provider]') : null;
        return root ? String(root.getAttribute('data-weline-captcha-provider') || '').trim() : '';
    }

    function readBindCaptchaResponse(form) {
        const root = form ? form.querySelector('[data-weline-captcha-provider]') : null;
        const scope = root instanceof HTMLElement ? root : form;
        const input = scope ? scope.querySelector('[name="captcha_response"]') : null;
        return input && 'value' in input ? String(input.value || '').trim() : '';
    }

    /**
     * Google/腾讯 token 由 prepare-submit 异步写入。绑邮箱走 fetch，不能空票直发。
     * Form 运行时未挂载时，这里自行派发 prepare-submit，等 verified 后再 requestSubmit。
     */
    function ensureRemoteBindCaptchaToken(form) {
        if (!(form instanceof HTMLFormElement) || !config.bindCaptchaEnabled) {
            return true;
        }
        if (form.dataset.welineCaptchaPending === '1') {
            return false;
        }

        const provider = readBindCaptchaProvider(form);
        const needsRemoteToken = provider === 'google_enterprise' || provider === 'tencent_captcha';
        if (!needsRemoteToken) {
            return true;
        }
        if (readBindCaptchaResponse(form) !== '') {
            return true;
        }

        const detail = {
            form: form,
            intent: form.dataset.welineFormIntent || 'customerservice_bind_email'
        };
        const prepare = new CustomEvent('weline:form:prepare-submit', {
            bubbles: true,
            cancelable: true,
            detail: detail
        });
        form.dispatchEvent(prepare);
        if (form.dataset.welineCaptchaPending === '1') {
            return false;
        }
        if (readBindCaptchaResponse(form) !== '') {
            return true;
        }

        notify('warning', __('人机验证尚未就绪，请稍后重试'), {title: __('请注意')});
        return false;
    }

    function bindBindFormSubmit(form) {
        if (!form || form.dataset.csBindSubmitBound === '1') {
            return;
        }

        form.dataset.csBindSubmitBound = '1';
        form.addEventListener('weline:form:verification-error', function (event) {
            const detail = event && event.detail ? event.detail : {};
            if (String(detail.degrade || '') === 'local_image') {
                if (typeof event.stopPropagation === 'function') {
                    event.stopPropagation();
                }
                autoDegradeBindCaptcha(form, {
                    silent: form.dataset.csCaptchaUserSubmit !== '1',
                    reason: String(detail.reason || (detail.error && detail.error.message) || 'verification_error')
                });
                return;
            }
            notify('error', __('人机验证加载失败，请稍后重试'));
        });
        form.addEventListener('weline:captcha:degrade', function (event) {
            const detail = event && event.detail ? event.detail : {};
            if (typeof event.stopPropagation === 'function') {
                event.stopPropagation();
            }
            if (String(detail.prefer || '') !== 'local_image') {
                return;
            }
            autoDegradeBindCaptcha(form, {
                silent: form.dataset.csCaptchaUserSubmit !== '1',
                reason: String(detail.reason || 'captcha_degrade')
            });
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            // Captcha providers cancel prepare-submit and set pending, then
            // requestSubmit() after the token lands. Stay out of that first pass.
            if (form.dataset.welineCaptchaPending === '1') {
                return;
            }
            form.dataset.csCaptchaUserSubmit = '1';
            if (!ensureRemoteBindCaptchaToken(form)) {
                return;
            }

            sendBindEmail(form);
        });
    }

    function autoDegradeBindCaptcha(form, options) {
        const modal = form instanceof HTMLFormElement
            ? form.closest('#cs-bind-modal')
            : document.getElementById('cs-bind-modal');
        if (!modal) {
            return;
        }
        if (modal.dataset.csCaptchaDegrading === '1') {
            return;
        }
        modal.dataset.csCaptchaDegrading = '1';
        const silent = !!(options && options.silent);
        if (!silent) {
            notifyCaptchaDegrade();
        }
        Promise.resolve(refreshBindCaptcha(modal, 'local_image', {
            quietHint: silent,
            reason: String((options && options.reason) || '')
        }))
            .catch(function () {})
            .finally(function () {
                delete modal.dataset.csCaptchaDegrading;
            });
    }

    function setBindModalOpen(isOpen) {
        document.body.classList.toggle('cs-bind-modal-open', isOpen);
    }

    function bindBindModalActions(modal) {
        if (!modal || modal.getAttribute('data-cs-bind-events') === '1') {
            return;
        }

        modal.setAttribute('data-cs-bind-events', '1');
        modal.querySelectorAll('[data-cs-bind-close]').forEach(button => {
            button.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                closeBindModal();
            });
        });

        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeBindModal();
            }
        });

        const dialog = modal.querySelector('.w-modal-dialog, .cs-modal-content');
        if (dialog) {
            dialog.addEventListener('click', function (event) {
                event.stopPropagation();
            });
        }

        mountBindForm(modal);
    }

    /**
     * Lazily create the bind-email modal only after the guest shows intent.
     */
    function ensureBindModal() {
        let modal = document.getElementById('cs-bind-modal');
        if (modal) {
            bindBindModalActions(modal);
            return modal;
        }

        const template = document.getElementById('cs-bind-modal-template');
        if (!template) {
            return null;
        }

        const wrapper = document.createElement('div');
        try {
            wrapper.innerHTML = sanitizeStaticTemplateHtml(JSON.parse(template.textContent || '""'));
        } catch (error) {
            console.error('CustomerService: bind modal template parse failed', error);
            return null;
        }

        modal = wrapper.querySelector('#cs-bind-modal');
        if (!modal) {
            return null;
        }

        document.body.appendChild(modal);
        bindBindModalActions(modal);
        mountBindForm(modal);
        return modal;
    }

    /**
     * Show the bind-email prompt only for eligible guest sessions.
     * Always fetch a fresh captcha challenge (never reuse SSR/cached HTML).
     */
    async function showBindPrompt(options) {
        const opts = options && typeof options === 'object' ? options : {};
        if (config.isLoggedIn) {
            return;
        }
        if (!opts.force && !config.showGuestBindPrompt) {
            return;
        }

        const modal = ensureBindModal();
        if (!modal) {
            return;
        }

        const emailInput = modal.querySelector('#cs-bind-email');
        if (emailInput && state.identity.email) {
            emailInput.value = state.identity.email;
        }

        const submitButton = modal.querySelector('[data-cs-bind-send]');
        if (submitButton) {
            submitButton.disabled = true;
        }

        modal.style.display = 'flex';
        modal.classList.add('w-open', 'show');
        setBindModalOpen(true);

        if (config.bindCaptchaEnabled) {
            const prefer = String(opts.prefer || '').trim() === 'local_image'
                ? 'local_image'
                : '';
            const loaded = await refreshBindCaptcha(modal, prefer);
            if (!loaded) {
                notify('error', __('人机验证加载失败，请稍后重试'), {title: __('发送失败')});
            }
        }

        if (submitButton) {
            submitButton.disabled = false;
        }
        if (emailInput) {
            emailInput.focus();
            emailInput.select();
        }
    }

    /**
     * Auto-prompt only after the guest actively sends a message.
     */
    function maybeShowGuestBindPrompt() {
        if (guestBindPromptShown || config.isLoggedIn || !config.showGuestBindPrompt) {
            return;
        }

        guestBindPromptShown = true;
        showBindPrompt();
    }
    
    /**
     * 閸忔娊妫寸紒鎴濈暰瀵湱鐛?
     */
    function closeBindModal() {
        const modal = document.getElementById('cs-bind-modal');
        if (modal) {
            modal.style.display = 'none';
            modal.classList.remove('w-open', 'show');
        }
        setBindModalOpen(false);
    }
    
    /**
     * 閸欐垿鈧胶绮︾€规岸鍋栨禒?
     */
    async function sendBindEmail(formOrModal) {
        const modal = formOrModal instanceof HTMLFormElement
            ? formOrModal.closest('#cs-bind-modal')
            : ensureBindModal();
        const form = formOrModal instanceof HTMLFormElement
            ? formOrModal
            : modal ? modal.querySelector('#cs-bind-form') : null;
        if (!modal || !form) {
            return;
        }

        const emailInput = form.querySelector('#cs-bind-email');
        if (!emailInput) {
            return;
        }

        const email = emailInput.value.trim();
        
        if (!email || !isValidBindEmail(email)) {
            notify('warning', __('请输入有效的邮箱地址'), {title: __('请注意')});
            return;
        }
        
        if (!state.sessionToken) {
            const sessionReady = await ensureSessionReady();
            if (!sessionReady || !state.sessionToken) {
                notify('error', __('会话未初始化，请刷新页面重试'), {title: __('发送失败')});
                return;
            }
        }

        const formData = new FormData(form);
        const captchaRoot = form.querySelector('[data-weline-captcha-provider]');
        const captchaScope = captchaRoot instanceof HTMLElement ? captchaRoot : form;
        const readCaptchaField = function (name) {
            const scoped = captchaScope.querySelector('[name="' + name + '"]');
            if (scoped && 'value' in scoped) {
                return String(scoped.value || '');
            }
            return String(formData.get(name) || '');
        };
        const payload = {
            email: email,
            session_token: state.sessionToken,
            captcha_provider: readCaptchaField('captcha_provider'),
            captcha_token: readCaptchaField('captcha_token'),
            captcha_response: readCaptchaField('captcha_response'),
            captcha_action: readCaptchaField('captcha_action')
        };

        if (config.bindCaptchaEnabled && payload.captcha_provider === '') {
            notify('warning', __('人机验证尚未就绪，正在刷新，请稍后重试'), {title: __('请注意')});
            await refreshBindCaptcha(modal);
            return;
        }

        const needsRemoteToken = payload.captcha_provider === 'google_enterprise'
            || payload.captcha_provider === 'tencent_captcha';
        if (config.bindCaptchaEnabled && needsRemoteToken && String(payload.captcha_response || '').trim() === '') {
            if (!ensureRemoteBindCaptchaToken(form)) {
                return;
            }
            payload.captcha_response = readBindCaptchaResponse(form);
            payload.captcha_provider = readBindCaptchaProvider(form) || payload.captcha_provider;
            payload.captcha_action = readCaptchaField('captcha_action') || payload.captcha_action;
            if (String(payload.captcha_response || '').trim() === '') {
                // Token still pending (grecaptcha.execute in flight); provider will requestSubmit.
                return;
            }
        }

        const submitButton = form.querySelector('[data-cs-bind-send]');
        if (submitButton) {
            submitButton.disabled = true;
        }
        
        try {
            const data = await (await getCustomerServiceApi()).sendVerification(payload, {
                silent: true,
                keepBusinessResult: true
            });
            
            if (data.success) {
                notify('success', __('验证邮件已发送，请查收您的邮箱'), {
                    title: __('发送成功')
                });
                closeBindModal();
            } else {
                delete form.dataset.welineCaptchaVerified;
                const degradeTo = String(data.captcha_degrade || '').trim();
                const captchaFailed = Boolean(data.captcha_error);
                if (degradeTo === 'local_image') {
                    notifyCaptchaDegrade(data.message);
                    await refreshBindCaptcha(modal, 'local_image', {
                        reason: 'server_captcha_degrade'
                    });
                } else {
                    const verificationUrl = String(data.verification_url || '').trim();
                    notify('error', data.message || __('发送失败，请稍后重试'), {
                        title: __('发送失败'),
                        linkUrl: verificationUrl,
                        linkText: __('打开验证链接')
                    });
                    if (captchaFailed) {
                        const prefer = payload.captcha_provider === 'local_image'
                            ? 'local_image'
                            : '';
                        await refreshBindCaptcha(modal, prefer, prefer === 'local_image' ? {
                            reason: 'server_captcha_error'
                        } : undefined);
                    }
                }
            }
        } catch (error) {
            console.error('Failed to send bind email:', error);
            delete form.dataset.welineCaptchaVerified;
            const errPayload = (error && error.response && error.response.data && error.response.data.data)
                || (error && error.data && error.data.data)
                || (error && error.data)
                || {};
            const degradeTo = String(errPayload.captcha_degrade || '').trim();
            if (config.bindCaptchaEnabled && degradeTo === 'local_image') {
                notifyCaptchaDegrade(extractErrorMessage(
                    error,
                    __('云端人机验证暂不可用，已切换为本地图码，请填写后重试')
                ));
                await refreshBindCaptcha(modal, 'local_image', {
                    reason: 'server_captcha_degrade_catch'
                });
            } else {
                const verificationUrl = String(errPayload.verification_url || '').trim();
                notify('error', extractErrorMessage(error, __('发送失败，请稍后重试')), {
                    title: __('发送失败'),
                    linkUrl: verificationUrl,
                    linkText: __('打开验证链接')
                });
                if (config.bindCaptchaEnabled) {
                    const prefer = payload.captcha_provider === 'local_image'
                        || payload.captcha_provider === ''
                        ? 'local_image'
                        : '';
                    await refreshBindCaptcha(modal, prefer, prefer === 'local_image' ? {
                        reason: 'send_error_fallback_local'
                    } : undefined);
                }
            }
        } finally {
            if (submitButton) {
                submitButton.disabled = false;
            }
        }
    }
    
    /**
     * 娣囨繂鐡ㄩ悩鑸碘偓?
     */
    function saveState() {
        localStorage.setItem('cs_widget_state', JSON.stringify({
            sessionToken: state.sessionToken,
            locale: state.locale,
            displayMode: state.displayMode
        }));
    }
    
    // 鐎电厧鍤崗顒€鍙PI
    return {
        init,
        toggleChat,
        toggleSettings,
        sendMessage,
        changeLanguage,
        changeDisplayMode,
        showBindPrompt,
        closeBindModal,
        sendBindEmail
    };
})();

window.CustomerServiceWidget = CustomerServiceWidget;
