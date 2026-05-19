/**
 * 鐎广垺婀囬張宥呭濡€虫健JavaScript
 */

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

    let config = {
        chatUrl: '',
        bindUrl: '',
        customerId: null,
        isLoggedIn: false,
        showGuestBindPrompt: false,
        supportedLocales: [],
        widgetTranslations: {}
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
    
    let state = {
        sessionId: null,
        sessionToken: null,
        isOpen: false,
        isPolling: false,
        isSending: false,
        pollInterval: null,
        statusPollInterval: null,
        lastMessageId: 0,
        locale: 'zh_Hans_CN',
        displayMode: 'translated', // translated, both, original
        settingsOpen: false,
        serviceStatus: 'offline' // online(缂?, ai(閽?, offline(閻?
    };
    
    /**
     * 閸掓繂顫愰崠?
     */
    function init(options) {
        config = Object.assign(config, options);
        
        // 娴犲窅ocalStorage閹垹顦查悩鑸碘偓?
        const savedState = localStorage.getItem('cs_widget_state');
        if (savedState) {
            try {
                const parsed = JSON.parse(savedState);
                state.sessionToken = parsed.sessionToken || null;
                state.locale = parsed.locale || 'zh_Hans_CN';
                state.displayMode = parsed.displayMode || 'translated';
            } catch (e) {
                console.error('Failed to load saved state:', e);
            }
        }
        
        // 閸掓繂顫愰崠鏈閻樿埖鈧?
        initUIState();
        updateWidgetLocaleText();
        bindMiniCartLayerState();
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

    function updateWidgetLocaleText() {
        const textMap = {
            'cs-title-text': __('欢迎使用客服服务'),
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
        
        if (state.isOpen) {
            state.isOpen = false;
            chatWindow.style.display = 'none';
            chatButton.style.display = 'flex';
            stopPolling();
        } else {
            state.isOpen = true;
            chatWindow.style.display = 'flex';
            chatButton.style.display = 'none';
            await activateChat();
        }
    }
    
    /**
     * 閸欐垿鈧焦绉烽幁?
     */
    async function sendMessage() {
        const input = document.getElementById('cs-message-input');
        if (!input || state.isSending) {
            return;
        }
        const content = input.value.trim();
        
        if (!content) {
            return;
        }

        state.isSending = true;

        const sessionReady = await ensureSessionReady();
        if (!sessionReady || !state.sessionId) {
            state.isSending = false;
            alert(__('客服会话初始化失败，请稍后重试'));
            return;
        }
        
        // 缁備胶鏁ゆ潏鎾冲弳
        input.disabled = true;
        const sendButton = document.querySelector('.cs-send-button');
        sendButton.disabled = true;
        
        try {
            const data = await (await getCustomerServiceApi()).sendMessage({
                session_id: state.sessionId,
                content: content,
                locale: state.locale
            }, {silent: true});
            
            if (data.success) {
                input.value = '';
                
                // 濞ｈ濮炲☉鍫熶紖閸掓壆鏅棃?
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
                
                // Scroll to bottom after the customer sends a message.
                scrollToBottom();
                maybeShowGuestBindPrompt();
            } else {
                alert(data.message || __('发送失败'));
            }
        } catch (error) {
            console.error('Failed to send message:', error);
            alert(__('发送失败，请稍后重试'));
        } finally {
            input.disabled = false;
            sendButton.disabled = false;
            state.isSending = false;
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
                const messages = Array.isArray(data.data) ? data.data : null;
                const messagesContainer = document.getElementById('cs-chat-messages');
                if (!messagesContainer || messages === null) {
                    return;
                }
                messagesContainer.innerHTML = '';
                
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
                    
                    // 閺囧瓨鏌婇張鈧崥搴濈閺夆剝绉烽幁鐤楧
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
        
        // 缁夊娅庡▎銏ｇ箣濞戝牊浼?
        const welcomeMsg = messagesContainer.querySelector('.cs-welcome-message');
        if (welcomeMsg) {
            welcomeMsg.remove();
        }
        
        const messageDiv = document.createElement('div');
        messageDiv.className = 'cs-message ' + message.sender_type;
        messageDiv.dataset.messageId = message.message_id;
        // 鐎涙ê鍋嶉崢鐔奉潗濞戝牊浼呴弫鐗堝祦閻劋绨柌宥嗘煀濞撳弶鐓?
        messageDiv.dataset.content = message.content || '';
        messageDiv.dataset.translatedContent = message.translated_content || '';
        messageDiv.dataset.displayContent = message.display_content || '';
        messageDiv.dataset.sourceLocale = message.source_locale || '';
        messageDiv.dataset.targetLocale = message.target_locale || '';
        
        const bubble = document.createElement('div');
        bubble.className = 'cs-message-bubble';
        
        // 閺嶈宓侀弰鍓с仛濡€崇础濞撳弶鐓嬮崘鍛啇
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
        const translated = message.display_content || message.translated_content || original;
        const hasTranslation = translated && translated !== original;
        const sourceLocale = message.source_locale || '';
        const translatedLocale = message.display_content && translated !== original
            ? state.locale
            : (message.target_locale || state.locale);
        
        bubble.innerHTML = '';
        
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
                
                if (data.success && Array.isArray(data.data) && data.data.length > 0) {
                    const messagesContainer = document.getElementById('cs-chat-messages');
                    if (!messagesContainer) {
                        return;
                    }
                    const existingIds = new Set(
                        Array.from(messagesContainer.querySelectorAll('.cs-message'))
                            .map(el => parseInt(el.dataset.messageId))
                    );
                    
                    // 閸欘亝鍧婇崝鐘虫煀濞戝牊浼?
                    let hasNew = false;
                    data.data.forEach(msg => {
                        if (!existingIds.has(msg.message_id) && msg.sender_type === 'agent') {
                            addMessage(msg);
                            hasNew = true;
                            
                            // 閺囧瓨鏌婇張顏囶嚢閺佷即鍣?
                            if (!state.isOpen) {
                                updateUnreadBadge();
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
     * 閺囧瓨鏌婇悩鑸碘偓浣瑰瘹缁€鍝勬珤 UI
     * online=缂佽儻澹? ai=閽冩繆澹? offline=閻忔媽澹?
     */
    function updateStatusIndicator() {
        const dot = document.getElementById('cs-status-dot');
        const label = document.getElementById('cs-status-label');
        if (!dot) return;

        // 缁夊娅庨弮褏娈戦悩鑸碘偓浣鸿
        dot.classList.remove('cs-status-online', 'cs-status-ai', 'cs-status-offline');

        switch (state.serviceStatus) {
            case 'online':
                dot.classList.add('cs-status-online');
                if (label) {
                    label.textContent = __('鐎广垺婀囬崷銊у殠');
                    label.style.backgroundColor = 'rgba(34, 197, 94, 0.15)';
                    label.style.color = '#16a34a';
                }
                break;
            case 'ai':
                dot.classList.add('cs-status-ai');
                if (label) {
                    label.textContent = __('AI 智能客服');
                    label.style.backgroundColor = 'rgba(59, 130, 246, 0.15)';
                    label.style.color = '#2563eb';
                }
                break;
            default:
                dot.classList.add('cs-status-offline');
                if (label) {
                    label.textContent = __('鐎广垺婀囩粋鑽ゅ殠');
                    label.style.backgroundColor = 'rgba(156, 163, 175, 0.15)';
                    label.style.color = '#9ca3af';
                }
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
        const count = document.querySelectorAll('.cs-message.agent[data-message-id]').length;
        
        if (count > 0) {
            badge.textContent = count;
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
    
    /**
     * Lazily create the bind-email modal only after the guest shows intent.
     */
    function ensureBindModal() {
        let modal = document.getElementById('cs-bind-modal');
        if (modal) {
            return modal;
        }

        const template = document.getElementById('cs-bind-modal-template');
        if (!template) {
            return null;
        }

        const wrapper = document.createElement('div');
        try {
            wrapper.innerHTML = JSON.parse(template.textContent || '""');
        } catch (error) {
            console.error('CustomerService: bind modal template parse failed', error);
            return null;
        }

        modal = wrapper.querySelector('#cs-bind-modal');
        if (!modal) {
            return null;
        }

        document.body.appendChild(modal);
        return modal;
    }

    /**
     * Show the bind-email prompt only for eligible guest sessions.
     */
    function showBindPrompt() {
        if (config.isLoggedIn || !config.showGuestBindPrompt) {
            return;
        }

        const modal = ensureBindModal();
        if (modal) {
            modal.style.display = 'flex';
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
        }
    }
    
    /**
     * 閸欐垿鈧胶绮︾€规岸鍋栨禒?
     */
    async function sendBindEmail() {
        const modal = ensureBindModal();
        if (!modal) {
            return;
        }

        const emailInput = modal.querySelector('#cs-bind-email');
        if (!emailInput) {
            return;
        }

        const email = emailInput.value.trim();
        
        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            alert(__('请输入有效的邮箱地址'));
            return;
        }
        
        if (!state.sessionToken) {
            const sessionReady = await ensureSessionReady();
            if (!sessionReady || !state.sessionToken) {
                alert(__('会话未初始化，请刷新页面重试'));
                return;
            }
        }
        
        try {
            const data = await (await getCustomerServiceApi()).sendVerification({
                email: email,
                session_token: state.sessionToken
            }, {silent: true});
            
            if (data.success) {
                alert(__('验证邮件已发送，请查收您的邮箱'));
                closeBindModal();
            } else {
                alert(data.message || __('发送失败，请稍后重试'));
            }
        } catch (error) {
            console.error('Failed to send bind email:', error);
            alert(__('发送失败，请稍后重试'));
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

