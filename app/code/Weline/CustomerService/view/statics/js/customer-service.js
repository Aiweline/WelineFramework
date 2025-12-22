/**
 * 客服服务模块JavaScript
 */

const CustomerServiceWidget = (function() {
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
        lastMessageId: 0,
        locale: 'zh_Hans_CN'
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
            } catch (e) {
                console.error('Failed to load saved state:', e);
            }
        }
        
        // 初始化会话
        initSession();
    }
    
    /**
     * 初始化会话
     */
    async function initSession() {
        try {
            const response = await fetch(config.chatUrl + '/getSession', {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    session_token: state.sessionToken || '',
                    locale: state.locale
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                state.sessionId = data.data.session_id;
                state.sessionToken = data.data.session_token;
                state.locale = data.data.customer_locale;
                
                // 保存状态
                saveState();
                
                // 加载消息
                loadMessages();
                
                // 开始轮询
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
            const response = await fetch(config.chatUrl + '/sendMessage', {
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
            const response = await fetch(config.chatUrl + '/getMessages?' + new URLSearchParams({
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
        
        const bubble = document.createElement('div');
        bubble.className = 'cs-message-bubble';
        
        // 显示翻译后的内容（如果有）
        const displayContent = message.translated_content || message.content;
        bubble.textContent = displayContent;
        
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
                const response = await fetch(config.chatUrl + '/getMessages?' + new URLSearchParams({
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
            const response = await fetch(config.chatUrl + '/setLanguage', {
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
            const response = await fetch(config.bindUrl + '/sendVerification', {
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
            locale: state.locale
        }));
    }
    
    // 导出公共API
    return {
        init,
        toggleChat,
        sendMessage,
        changeLanguage,
        showBindPrompt,
        closeBindModal,
        sendBindEmail
    };
})();

