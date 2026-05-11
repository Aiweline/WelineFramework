/**
 * 瀹㈡湇鏈嶅姟妯″潡JavaScript
 */

const CustomerServiceWidget = (function() {
    /**
     * 鍥介檯鍖栵細浼樺厛浣跨敤椤甸潰娉ㄥ叆鐨?__锛屽惁鍒欓檷绾т负鍗犱綅绗︽浛鎹?
     * @param {string} text
     * @param {Object|Array} params
     * @returns {string}
     */
    function __(text, params) {
        if (typeof window !== 'undefined' && typeof window.__ === 'function') {
            return window.__(text, params);
        }
        if (typeof window !== 'undefined' && window.Weline && window.Weline.i18n && typeof window.Weline.i18n.__ === 'function') {
            return window.Weline.i18n.__(text, params);
        }
        if (params) {
            let result = text;
            if (typeof params === 'object' && !Array.isArray(params)) {
                for (const key in params) {
                    result = result.replace(new RegExp('%\\{' + key + '\\}', 'g'), String(params[key]));
                }
            } else if (Array.isArray(params)) {
                params.forEach((param, index) => {
                    result = result.replace(new RegExp('%\\{' + (index + 1) + '\\}', 'g'), String(param));
                });
            } else {
                result = result.replace(/%\{1\}/g, String(params));
            }
            return result;
        }
        return text;
    }

    let config = {
        chatUrl: '',
        bindUrl: '',
        customerId: null,
        isLoggedIn: false
    };

    let sessionInitializationPromise = null;
    let guestBindPromptShown = false;
    
    let state = {
        sessionId: null,
        sessionToken: null,
        isOpen: false,
        isPolling: false,
        pollInterval: null,
        statusPollInterval: null,
        lastMessageId: 0,
        locale: 'zh_Hans_CN',
        displayMode: 'translated', // translated, both, original
        settingsOpen: false,
        serviceStatus: 'offline' // online(缁?, ai(钃?, offline(鐏?
    };
    
    /**
     * 鍒濆鍖?
     */
    function init(options) {
        config = Object.assign(config, options);
        
        // 浠巐ocalStorage鎭㈠鐘舵€?
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
        
        // 鍒濆鍖朥I鐘舵€?
        initUIState();
    }
    
    /**
     * 鍒濆鍖朥I鐘舵€?
     */
    function initUIState() {
        // 璁剧疆璇█閫夋嫨鍣?
        const localeSelect = document.getElementById('cs-locale-select');
        if (localeSelect) {
            localeSelect.value = state.locale;
        }
        
        // 璁剧疆鏄剧ず妯″紡閫夋嫨鍣?
        const displayModeSelect = document.getElementById('cs-display-mode');
        if (displayModeSelect) {
            displayModeSelect.value = state.displayMode;
        }
    }
    
    /**
     * 鍒囨崲璁剧疆闈㈡澘
     */
    function toggleSettings() {
        const panel = document.getElementById('cs-settings-panel');
        if (panel) {
            state.settingsOpen = !state.settingsOpen;
            panel.style.display = state.settingsOpen ? 'block' : 'none';
        }
    }
    
    /**
     * 鏇存敼鏄剧ず妯″紡
     */
    function changeDisplayMode(mode) {
        state.displayMode = mode;
        saveState();
        
        // 閲嶆柊娓叉煋娑堟伅
        loadMessages();
    }
    
    /**
     * 鍒濆鍖栦細璇?
     */
    async function initSession() {
        try {
            if (!config.chatUrl) {
                console.warn('CustomerService: chatUrl not configured, skip init session');
                return false;
            }
            const params = new URLSearchParams({
                session_token: state.sessionToken || '',
                locale: state.locale
            });
            const response = await fetch(config.chatUrl + '/session?' + params.toString(), {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                }
            });
            
            const contentType = response.headers.get('Content-Type') || '';
            const isJson = contentType.indexOf('application/json') !== -1;
            
            if (!response.ok) {
                const text = await response.text();
                console.warn('CustomerService: getSession failed', response.status, isJson ? (function() { try { return JSON.parse(text); } catch (e) { return text.slice(0, 200); } })() : text.slice(0, 200));
                return false;
            }
            
            if (!isJson) {
                console.warn('CustomerService: getSession returned non-JSON (e.g. HTML page). Check chatUrl and backend route.');
                return false;
            }
            
            const data = await response.json();
            
            if (data.success) {
                state.sessionId = data.data.session_id;
                state.sessionToken = data.data.session_token;
                state.locale = data.data.customer_locale;
                
                saveState();
                initUIState();
                return true;
            }
            return false;
        } catch (error) {
            console.error('Failed to init session:', error);
            return false;
        }
    }

    /**
     * 鎸夐渶鍒濆鍖栦細璇濓紝閬垮厤椤甸潰鍔犺浇鏃惰嚜鍔ㄥ垱寤轰細璇?     */
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
     * 鐢ㄦ埛涓诲姩鎵撳紑鑱婂ぉ鍚庡啀鍔犺浇浼氳瘽鍜岀姸鎬?     */
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
     * 鍒囨崲鑱婂ぉ绐楀彛
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
     * 鍙戦€佹秷鎭?
     */
    async function sendMessage() {
        const input = document.getElementById('cs-message-input');
        const content = input.value.trim();
        
        if (!content) {
            return;
        }

        const sessionReady = await ensureSessionReady();
        if (!sessionReady || !state.sessionId) {
            alert(__('瀹㈡湇浼氳瘽鍒濆鍖栧け璐ワ紝璇风◢鍚庨噸璇?));
            return;
        }
        
        // 绂佺敤杈撳叆
        input.disabled = true;
        const sendButton = document.querySelector('.cs-send-button');
        sendButton.disabled = true;
        
        try {
            const response = await fetch(config.chatUrl + '/send-message', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    session_id: state.sessionId,
                    content: content
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                input.value = '';
                
                // 娣诲姞娑堟伅鍒扮晫闈?
                addMessage({
                    message_id: data.data.message_id,
                    content: data.data.content,
                    translated_content: data.data.translated_content,
                    sender_type: 'customer',
                    created_at: data.data.created_at
                });
                
                // 婊氬姩鍒板簳閮?                scrollToBottom();

                maybeShowGuestBindPrompt();
            } else {
                alert(data.message || __('鍙戦€佸け璐?));
            }
        } catch (error) {
            console.error('Failed to send message:', error);
            alert(__('鍙戦€佸け璐ワ紝璇风◢鍚庨噸璇?));
        } finally {
            input.disabled = false;
            sendButton.disabled = false;
        }
    }
    
    /**
     * 鍔犺浇娑堟伅
     */
    async function loadMessages() {
        if (!state.sessionId) {
            return;
        }
        
        try {
            const response = await fetch(config.chatUrl + '/messages?' + new URLSearchParams({
                    session_id: state.sessionId,
                    limit: 50,
                    offset: 0
                }));
            
            const data = await response.json();
            
            if (data.success) {
                const messagesContainer = document.getElementById('cs-chat-messages');
                if (!messagesContainer) {
                    return;
                }
                messagesContainer.innerHTML = '';
                
                if (data.data.length === 0) {
                    messagesContainer.innerHTML = `
                        <div class="cs-welcome-message">
                            <p>${__('娆㈣繋浣跨敤瀹㈡湇鏈嶅姟锛?)}</p>
                            <p>${__('璇疯緭鍏ユ偍鐨勯棶棰橈紝鎴戜滑鐨勫鏈嶅皢灏藉揩涓烘偍瑙ｇ瓟銆?)}</p>
                        </div>
                    `;
                } else {
                    data.data.forEach(msg => {
                        addMessage(msg, false);
                    });
                    
                    // 鏇存柊鏈€鍚庝竴鏉℃秷鎭疘D
                    if (data.data.length > 0) {
                        state.lastMessageId = data.data[data.data.length - 1].message_id;
                    }
                    
                    scrollToBottom();
                }
            }
        } catch (error) {
            console.error('Failed to load messages:', error);
        }
    }
    
    /**
     * 娣诲姞娑堟伅鍒扮晫闈?
     */
    function addMessage(message, scroll = true) {
        const messagesContainer = document.getElementById('cs-chat-messages');
        
        // 绉婚櫎娆㈣繋娑堟伅
        const welcomeMsg = messagesContainer.querySelector('.cs-welcome-message');
        if (welcomeMsg) {
            welcomeMsg.remove();
        }
        
        const messageDiv = document.createElement('div');
        messageDiv.className = 'cs-message ' + message.sender_type;
        messageDiv.dataset.messageId = message.message_id;
        // 瀛樺偍鍘熷娑堟伅鏁版嵁鐢ㄤ簬閲嶆柊娓叉煋
        messageDiv.dataset.content = message.content || '';
        messageDiv.dataset.translatedContent = message.translated_content || '';
        messageDiv.dataset.sourceLocale = message.source_locale || '';
        messageDiv.dataset.targetLocale = message.target_locale || '';
        
        const bubble = document.createElement('div');
        bubble.className = 'cs-message-bubble';
        
        // 鏍规嵁鏄剧ず妯″紡娓叉煋鍐呭
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
     * 鏍规嵁鏄剧ず妯″紡娓叉煋娑堟伅鍐呭
     */
    function renderMessageContent(bubble, message) {
        const original = message.content || '';
        const translated = message.translated_content || original;
        const hasTranslation = translated && translated !== original;
        const sourceLocale = message.source_locale || '';
        
        bubble.innerHTML = '';
        
        switch (state.displayMode) {
            case 'both':
                if (hasTranslation) {
                    // 鏄剧ず鍘熸枃
                    const originalDiv = document.createElement('div');
                    originalDiv.className = 'cs-message-original';
                    const originalLabel = document.createElement('span');
                    originalLabel.className = 'cs-message-label';
                    originalLabel.textContent = getLocaleName(sourceLocale);
                    originalDiv.appendChild(originalLabel);
                    originalDiv.appendChild(document.createTextNode(original));
                    bubble.appendChild(originalDiv);
                    
                    // 鏄剧ず璇戞枃
                    const translatedDiv = document.createElement('div');
                    translatedDiv.className = 'cs-message-translated';
                    const translatedLabel = document.createElement('span');
                    translatedLabel.className = 'cs-message-label';
                    translatedLabel.textContent = getLocaleName(state.locale);
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
    
    /**
     * 鑾峰彇璇█鍚嶇О
     */
    function getLocaleName(locale) {
        const names = {
            'zh_Hans_CN': '涓枃',
            'zh_Hant_TW': '绻侀珨',
            'en_US': 'EN',
            'ja_JP': '鏃ユ湰瑾?,
            'ko_KR': '頃滉淡鞏?,
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
     * 婊氬姩鍒板簳閮?
     */
    function scrollToBottom() {
        const messagesContainer = document.getElementById('cs-chat-messages');
        if (!messagesContainer) {
            return;
        }
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }
    
    /**
     * 鏍煎紡鍖栨椂闂?
     */
    function formatTime(timeStr) {
        if (!timeStr) return '';
        
        const date = new Date(timeStr);
        const now = new Date();
        const diff = now - date;
        
        if (diff < 60000) {
            return __('鍒氬垰');
        } else if (diff < 3600000) {
            return Math.floor(diff / 60000) + __('鍒嗛挓鍓?);
        } else if (diff < 86400000) {
            return Math.floor(diff / 3600000) + __('灏忔椂鍓?);
        } else {
            return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
        }
    }
    
    /**
     * 寮€濮嬭疆璇㈡柊娑堟伅
     */
    function startPolling() {
        if (state.isPolling || !state.sessionId) {
            return;
        }
        
        state.isPolling = true;
        
        state.pollInterval = setInterval(async () => {
            try {
                const response = await fetch(config.chatUrl + '/messages?' + new URLSearchParams({
                    session_id: state.sessionId,
                    limit: 10,
                    offset: 0
                }));
                
                const data = await response.json();
                
                if (data.success && data.data.length > 0) {
                    const messagesContainer = document.getElementById('cs-chat-messages');
                    const existingIds = new Set(
                        Array.from(messagesContainer.querySelectorAll('.cs-message'))
                            .map(el => parseInt(el.dataset.messageId))
                    );
                    
                    // 鍙坊鍔犳柊娑堟伅
                    let hasNew = false;
                    data.data.forEach(msg => {
                        if (!existingIds.has(msg.message_id) && msg.sender_type === 'agent') {
                            addMessage(msg);
                            hasNew = true;
                            
                            // 鏇存柊鏈鏁伴噺
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
        }, 3000); // 姣?绉掕疆璇竴娆?
    }
    
    /**
     * 鍋滄杞
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
     * 妫€鏌ュ鏈嶅湪绾跨姸鎬?
     */
    async function checkServiceStatus() {
        if (!config.chatUrl) return;
        try {
            const resp = await fetch(config.chatUrl + '/service-status', {
                method: 'GET',
                headers: { 'Accept': 'application/json' }
            });
            if (!resp.ok) return;
            const data = await resp.json();
            if (data.success) {
                state.serviceStatus = data.data.status;
                updateStatusIndicator();
            }
        } catch (e) {
            // 缃戠粶澶辫触淇濇寔褰撳墠鐘舵€?
        }
    }

    /**
     * 寮€濮嬭疆璇㈠鏈嶅湪绾跨姸鎬侊紙姣?5绉掞級
     */
    function startStatusPolling() {
        if (state.statusPollInterval) return;
        state.statusPollInterval = setInterval(checkServiceStatus, 15000);
    }

    /**
     * 鏇存柊鐘舵€佹寚绀哄櫒 UI
     * online=缁胯壊, ai=钃濊壊, offline=鐏拌壊
     */
    function updateStatusIndicator() {
        const dot = document.getElementById('cs-status-dot');
        const label = document.getElementById('cs-status-label');
        if (!dot) return;

        // 绉婚櫎鏃х殑鐘舵€佺被
        dot.classList.remove('cs-status-online', 'cs-status-ai', 'cs-status-offline');

        switch (state.serviceStatus) {
            case 'online':
                dot.classList.add('cs-status-online');
                if (label) {
                    label.textContent = __('瀹㈡湇鍦ㄧ嚎');
                    label.style.backgroundColor = 'rgba(34, 197, 94, 0.15)';
                    label.style.color = '#16a34a';
                }
                break;
            case 'ai':
                dot.classList.add('cs-status-ai');
                if (label) {
                    label.textContent = __('AI 鏅鸿兘瀹㈡湇');
                    label.style.backgroundColor = 'rgba(59, 130, 246, 0.15)';
                    label.style.color = '#2563eb';
                }
                break;
            default:
                dot.classList.add('cs-status-offline');
                if (label) {
                    label.textContent = __('瀹㈡湇绂荤嚎');
                    label.style.backgroundColor = 'rgba(156, 163, 175, 0.15)';
                    label.style.color = '#9ca3af';
                }
                break;
        }
    }

    /**
     * 鏇存柊鏈寰界珷
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
     * 鏇存敼璇█
     */
    async function changeLanguage(locale) {
        state.locale = locale;
        saveState();

        const sessionReady = await ensureSessionReady();
        if (!sessionReady || !state.sessionToken) {
            return;
        }
        
        try {
            const response = await fetch(config.chatUrl + '/set-language', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    locale: locale,
                    session_token: state.sessionToken
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                // 閲嶆柊鍔犺浇娑堟伅浠ヨ幏鍙栫炕璇?
                loadMessages();
            }
        } catch (error) {
            console.error('Failed to change language:', error);
        }
    }
    
    /**
     * Show the bind-email prompt only for eligible guest sessions.
     */
    function showBindPrompt() {
        if (config.isLoggedIn || !config.showGuestBindPrompt) {
            return;
        }

        const modal = document.getElementById('cs-bind-modal');
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
        if (guestBindPromptShown || config.isLoggedIn || !config.showGuestBindPrompt) {
            return;
        }

        guestBindPromptShown = true;
        showBindPrompt();
    }
    
    /**
     * 鍏抽棴缁戝畾寮圭獥
     */
    function closeBindModal() {
        const modal = document.getElementById('cs-bind-modal');
        if (modal) {
            modal.style.display = 'none';
        }
    }
    
    /**
     * 鍙戦€佺粦瀹氶偖浠?
     */
    async function sendBindEmail() {
        const emailInput = document.getElementById('cs-bind-email');
        const email = emailInput.value.trim();
        
        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            alert(__('璇疯緭鍏ユ湁鏁堢殑閭鍦板潃'));
            return;
        }
        
        if (!state.sessionToken) {
            const sessionReady = await ensureSessionReady();
            if (!sessionReady || !state.sessionToken) {
                alert(__('浼氳瘽鏈垵濮嬪寲锛岃鍒锋柊椤甸潰閲嶈瘯'));
                return;
            }
        }
        
        try {
            const response = await fetch(config.bindUrl + '/send-verification', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    email: email,
                    session_token: state.sessionToken
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                alert(__('楠岃瘉閭欢宸插彂閫侊紝璇锋煡鏀舵偍鐨勯偖绠?));
                closeBindModal();
            } else {
                alert(data.message || __('鍙戦€佸け璐ワ紝璇风◢鍚庨噸璇?));
            }
        } catch (error) {
            console.error('Failed to send bind email:', error);
            alert(__('鍙戦€佸け璐ワ紝璇风◢鍚庨噸璇?));
        }
    }
    
    /**
     * 淇濆瓨鐘舵€?
     */
    function saveState() {
        localStorage.setItem('cs_widget_state', JSON.stringify({
            sessionToken: state.sessionToken,
            locale: state.locale,
            displayMode: state.displayMode
        }));
    }
    
    // 瀵煎嚭鍏叡API
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

