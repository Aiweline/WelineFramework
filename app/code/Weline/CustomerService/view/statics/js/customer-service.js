/**
 * 客服服务模块JavaScript
 */

const CustomerServiceWidget = (function() {
    /**
     * 国际化：优先使用页面注入的 __，否则降级为占位符替换
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
        serviceStatus: 'offline' // online(绿), ai(蓝), offline(灰)
    };
    
    /**
     * 初始化
     */
    function init(options) {
        config = Object.assign(config, options);
        
        // 从localStorage恢复状态
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
        
        // 初始化UI状态
        initUIState();
        
        // 初始化会话
        initSession();
        
        // 开始轮询客服在线状态
        checkServiceStatus();
        startStatusPolling();
    }
    
    /**
     * 初始化UI状态
     */
    function initUIState() {
        // 设置语言选择器
        const localeSelect = document.getElementById('cs-locale-select');
        if (localeSelect) {
            localeSelect.value = state.locale;
        }
        
        // 设置显示模式选择器
        const displayModeSelect = document.getElementById('cs-display-mode');
        if (displayModeSelect) {
            displayModeSelect.value = state.displayMode;
        }
    }
    
    /**
     * 切换设置面板
     */
    function toggleSettings() {
        const panel = document.getElementById('cs-settings-panel');
        if (panel) {
            state.settingsOpen = !state.settingsOpen;
            panel.style.display = state.settingsOpen ? 'block' : 'none';
        }
    }
    
    /**
     * 更改显示模式
     */
    function changeDisplayMode(mode) {
        state.displayMode = mode;
        saveState();
        
        // 重新渲染消息
        loadMessages();
    }
    
    /**
     * 初始化会话
     */
    async function initSession() {
        try {
            if (!config.chatUrl) {
                console.warn('CustomerService: chatUrl not configured, skip init session');
                return;
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
                return;
            }
            
            if (!isJson) {
                console.warn('CustomerService: getSession returned non-JSON (e.g. HTML page). Check chatUrl and backend route.');
                return;
            }
            
            const data = await response.json();
            
            if (data.success) {
                state.sessionId = data.data.session_id;
                state.sessionToken = data.data.session_token;
                state.locale = data.data.customer_locale;
                
                saveState();
                loadMessages();
                startPolling();
            }
        } catch (error) {
            console.error('Failed to init session:', error);
        }
    }
    
    /**
     * 切换聊天窗口
     */
    function toggleChat() {
        const chatWindow = document.getElementById('cs-chat-window');
        const chatButton = document.getElementById('cs-chat-button');
        
        state.isOpen = !state.isOpen;
        
        if (state.isOpen) {
            chatWindow.style.display = 'flex';
            chatButton.style.display = 'none';
            loadMessages();
            startPolling();
        } else {
            chatWindow.style.display = 'none';
            chatButton.style.display = 'flex';
            stopPolling();
        }
    }
    
    /**
     * 发送消息
     */
    async function sendMessage() {
        const input = document.getElementById('cs-message-input');
        const content = input.value.trim();
        
        if (!content || !state.sessionId) {
            return;
        }
        
        // 禁用输入
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
                
                // 添加消息到界面
                addMessage({
                    message_id: data.data.message_id,
                    content: data.data.content,
                    translated_content: data.data.translated_content,
                    sender_type: 'customer',
                    created_at: data.data.created_at
                });
                
                // 滚动到底部
                scrollToBottom();
            } else {
                alert(data.message || __('发送失败'));
            }
        } catch (error) {
            console.error('Failed to send message:', error);
            alert(__('发送失败，请稍后重试'));
        } finally {
            input.disabled = false;
            sendButton.disabled = false;
        }
    }
    
    /**
     * 加载消息
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
                messagesContainer.innerHTML = '';
                
                if (data.data.length === 0) {
                    messagesContainer.innerHTML = `
                        <div class="cs-welcome-message">
                            <p>${__('欢迎使用客服服务！')}</p>
                            <p>${__('请输入您的问题，我们的客服将尽快为您解答。')}</p>
                        </div>
                    `;
                } else {
                    data.data.forEach(msg => {
                        addMessage(msg, false);
                    });
                    
                    // 更新最后一条消息ID
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
     * 添加消息到界面
     */
    function addMessage(message, scroll = true) {
        const messagesContainer = document.getElementById('cs-chat-messages');
        
        // 移除欢迎消息
        const welcomeMsg = messagesContainer.querySelector('.cs-welcome-message');
        if (welcomeMsg) {
            welcomeMsg.remove();
        }
        
        const messageDiv = document.createElement('div');
        messageDiv.className = 'cs-message ' + message.sender_type;
        messageDiv.dataset.messageId = message.message_id;
        // 存储原始消息数据用于重新渲染
        messageDiv.dataset.content = message.content || '';
        messageDiv.dataset.translatedContent = message.translated_content || '';
        messageDiv.dataset.sourceLocale = message.source_locale || '';
        messageDiv.dataset.targetLocale = message.target_locale || '';
        
        const bubble = document.createElement('div');
        bubble.className = 'cs-message-bubble';
        
        // 根据显示模式渲染内容
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
     * 根据显示模式渲染消息内容
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
                    // 显示原文
                    const originalDiv = document.createElement('div');
                    originalDiv.className = 'cs-message-original';
                    const originalLabel = document.createElement('span');
                    originalLabel.className = 'cs-message-label';
                    originalLabel.textContent = getLocaleName(sourceLocale);
                    originalDiv.appendChild(originalLabel);
                    originalDiv.appendChild(document.createTextNode(original));
                    bubble.appendChild(originalDiv);
                    
                    // 显示译文
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
     * 获取语言名称
     */
    function getLocaleName(locale) {
        const names = {
            'zh_Hans_CN': '中文',
            'zh_Hant_TW': '繁體',
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
     * 滚动到底部
     */
    function scrollToBottom() {
        const messagesContainer = document.getElementById('cs-chat-messages');
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }
    
    /**
     * 格式化时间
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
     * 开始轮询新消息
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
                    
                    // 只添加新消息
                    let hasNew = false;
                    data.data.forEach(msg => {
                        if (!existingIds.has(msg.message_id) && msg.sender_type === 'agent') {
                            addMessage(msg);
                            hasNew = true;
                            
                            // 更新未读数量
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
        }, 3000); // 每3秒轮询一次
    }
    
    /**
     * 停止轮询
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
     * 检查客服在线状态
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
            // 网络失败保持当前状态
        }
    }

    /**
     * 开始轮询客服在线状态（每15秒）
     */
    function startStatusPolling() {
        if (state.statusPollInterval) return;
        state.statusPollInterval = setInterval(checkServiceStatus, 15000);
    }

    /**
     * 更新状态指示器 UI
     * online=绿色, ai=蓝色, offline=灰色
     */
    function updateStatusIndicator() {
        const dot = document.getElementById('cs-status-dot');
        const label = document.getElementById('cs-status-label');
        if (!dot) return;

        // 移除旧的状态类
        dot.classList.remove('cs-status-online', 'cs-status-ai', 'cs-status-offline');

        switch (state.serviceStatus) {
            case 'online':
                dot.classList.add('cs-status-online');
                if (label) {
                    label.textContent = __('客服在线');
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
                    label.textContent = __('客服离线');
                    label.style.backgroundColor = 'rgba(156, 163, 175, 0.15)';
                    label.style.color = '#9ca3af';
                }
                break;
        }
    }

    /**
     * 更新未读徽章
     */
    function updateUnreadBadge() {
        const badge = document.getElementById('cs-unread-badge');
        const count = document.querySelectorAll('.cs-message.agent[data-message-id]').length;
        
        if (count > 0) {
            badge.textContent = count;
            badge.style.display = 'flex';
        } else {
            badge.style.display = 'none';
        }
    }
    
    /**
     * 更改语言
     */
    async function changeLanguage(locale) {
        state.locale = locale;
        saveState();
        
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
                // 重新加载消息以获取翻译
                loadMessages();
            }
        } catch (error) {
            console.error('Failed to change language:', error);
        }
    }
    
    /**
     * 显示绑定提示
     */
    function showBindPrompt() {
        if (config.isLoggedIn) {
            return; // 已登录不需要绑定
        }
        
        const modal = document.getElementById('cs-bind-modal');
        if (modal) {
            modal.style.display = 'flex';
        }
    }
    
    /**
     * 关闭绑定弹窗
     */
    function closeBindModal() {
        const modal = document.getElementById('cs-bind-modal');
        if (modal) {
            modal.style.display = 'none';
        }
    }
    
    /**
     * 发送绑定邮件
     */
    async function sendBindEmail() {
        const emailInput = document.getElementById('cs-bind-email');
        const email = emailInput.value.trim();
        
        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            alert(__('请输入有效的邮箱地址'));
            return;
        }
        
        if (!state.sessionToken) {
            alert(__('会话未初始化，请刷新页面重试'));
            return;
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
     * 保存状态
     */
    function saveState() {
        localStorage.setItem('cs_widget_state', JSON.stringify({
            sessionToken: state.sessionToken,
            locale: state.locale,
            displayMode: state.displayMode
        }));
    }
    
    // 导出公共API
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

