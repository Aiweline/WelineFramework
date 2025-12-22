/**
 * 客服工作台JavaScript
 */
const CustomerServiceConsole = (function() {
    let config = {
        consoleUrl: '',
        agentId: 0
    };
    
    let state = {
        currentSessionId: null,
        isPolling: false,
        pollInterval: null,
        lastMessageId: 0
    };
    
    /**
     * 初始化
     */
    function init(options) {
        config = Object.assign(config, options);
        
        // 绑定事件
        bindEvents();
        
        // 开始轮询会话列表
        startSessionPolling();
    }
    
    /**
     * 绑定事件
     */
    function bindEvents() {
        // 刷新按钮
        const refreshBtn = document.getElementById('refresh-sessions');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', refreshSessions);
        }
        
        // 发送消息按钮
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('btn-send') || e.target.closest('.btn-send')) {
                e.preventDefault();
                sendMessage();
            }
        });
        
        // 输入框回车发送
        document.addEventListener('keydown', function(e) {
            if (e.target.classList.contains('chat-input') && e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });
        
        // 输入框自动调整高度
        document.addEventListener('input', function(e) {
            if (e.target.classList.contains('chat-input')) {
                autoResizeTextarea(e.target);
            }
        });
    }
    
    /**
     * 刷新会话列表
     */
    async function refreshSessions() {
        try {
            const response = await fetch(config.consoleUrl + '/getSessions');
            const data = await response.json();
            
            if (data.success) {
                updateSessionList(data.data.sessions, data.data.waiting_sessions);
            }
        } catch (error) {
            console.error('Failed to refresh sessions:', error);
        }
    }
    
    /**
     * 更新会话列表
     */
    function updateSessionList(sessions, waitingSessions) {
        // 更新等待分配的会话
        const waitingList = document.getElementById('waiting-sessions-list');
        if (waitingList) {
            if (waitingSessions && waitingSessions.length > 0) {
                waitingList.innerHTML = waitingSessions.map(session => `
                    <div class="session-item waiting" data-session-id="${session.session_id}">
                        <div class="session-header">
                            <span class="session-id">#${session.session_id}</span>
                            <span class="session-status badge-waiting">${__('等待中')}</span>
                        </div>
                        <div class="session-preview">
                            <p class="last-message">${session.last_message ? escapeHtml(session.last_message.substring(0, 50)) : __('暂无消息')}</p>
                        </div>
                        <div class="session-actions">
                            <button class="btn-assign" onclick="assignSession(${session.session_id})">
                                ${__('接单')}
                            </button>
                        </div>
                    </div>
                `).join('');
            } else {
                waitingList.innerHTML = '';
            }
        }
        
        // 更新我的会话
        const mySessionsList = document.getElementById('my-sessions-list');
        if (mySessionsList) {
            if (sessions && sessions.length > 0) {
                mySessionsList.innerHTML = sessions.map(session => `
                    <div class="session-item ${session.unread_count > 0 ? 'unread' : ''} ${session.session_id === state.currentSessionId ? 'active' : ''}" 
                         data-session-id="${session.session_id}"
                         onclick="loadSession(${session.session_id})">
                        <div class="session-header">
                            <span class="session-id">#${session.session_id}</span>
                            ${session.unread_count > 0 ? `<span class="unread-badge">${session.unread_count}</span>` : ''}
                        </div>
                        <div class="session-preview">
                            <p class="last-message">${session.last_message ? escapeHtml(session.last_message.substring(0, 50)) : __('暂无消息')}</p>
                            ${session.last_message_time ? `<span class="last-time">${formatTime(session.last_message_time)}</span>` : ''}
                        </div>
                    </div>
                `).join('');
            } else {
                mySessionsList.innerHTML = '<div class="empty-state"><p>' + __('暂无会话') + '</p></div>';
            }
        }
    }
    
    /**
     * 加载会话
     */
    window.loadSession = async function(sessionId) {
        if (state.currentSessionId === sessionId) {
            return;
        }
        
        state.currentSessionId = sessionId;
        
        // 更新会话列表的active状态
        document.querySelectorAll('.session-item').forEach(item => {
            item.classList.remove('active');
            if (parseInt(item.dataset.sessionId) === sessionId) {
                item.classList.add('active');
            }
        });
        
        // 加载消息
        await loadMessages(sessionId);
        
        // 开始轮询新消息
        startMessagePolling();
    };
    
    /**
     * 加载消息
     */
    async function loadMessages(sessionId) {
        try {
            const response = await fetch(config.consoleUrl + '/getMessages?session_id=' + sessionId + '&limit=50&offset=0');
            const data = await response.json();
            
            if (data.success) {
                renderChatArea(sessionId, data.data);
            }
        } catch (error) {
            console.error('Failed to load messages:', error);
        }
    }
    
    /**
     * 渲染聊天区域
     */
    function renderChatArea(sessionId, messages) {
        const container = document.getElementById('chat-container');
        
        container.innerHTML = `
            <div class="chat-header">
                <div class="chat-header-info">
                    <h3>${__('会话')} #${sessionId}</h3>
                </div>
                <div class="chat-header-actions">
                    <button class="btn-close-session" onclick="closeSession(${sessionId})">
                        ${__('关闭会话')}
                    </button>
                </div>
            </div>
            <div class="chat-messages" id="chat-messages">
                ${messages.length > 0 ? messages.map(msg => renderMessage(msg)).join('') : '<div class="empty-state"><p>' + __('暂无消息') + '</p></div>'}
            </div>
            <div class="chat-input-area">
                <div class="chat-input-wrapper">
                    <textarea class="chat-input" id="message-input" placeholder="${__('输入消息...')}"></textarea>
                    <button class="btn-send" id="send-button">${__('发送')}</button>
                </div>
            </div>
        `;
        
        // 滚动到底部
        scrollToBottom();
        
        // 更新最后一条消息ID
        if (messages.length > 0) {
            state.lastMessageId = messages[messages.length - 1].message_id;
        }
    }
    
    /**
     * 渲染单条消息
     */
    function renderMessage(message) {
        const isAgent = message.sender_type === 'agent';
        const content = message.translated_content || message.content;
        const time = formatTime(message.created_at);
        
        return `
            <div class="message-item ${isAgent ? 'agent' : 'customer'}" data-message-id="${message.message_id}">
                <div class="message-bubble">
                    <p class="message-content">${escapeHtml(content)}</p>
                </div>
                <div class="message-time">${time}</div>
            </div>
        `;
    }
    
    /**
     * 发送消息
     */
    async function sendMessage() {
        const input = document.getElementById('message-input');
        const sendButton = document.getElementById('send-button');
        
        if (!input || !state.currentSessionId) {
            return;
        }
        
        const content = input.value.trim();
        if (!content) {
            return;
        }
        
        // 禁用输入
        input.disabled = true;
        if (sendButton) {
            sendButton.disabled = true;
        }
        
        try {
            const formData = new URLSearchParams();
            formData.append('session_id', state.currentSessionId);
            formData.append('content', content);
            
            const response = await fetch(config.consoleUrl + '/sendMessage', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                input.value = '';
                autoResizeTextarea(input);
                
                // 添加消息到界面
                addMessageToChat({
                    message_id: data.data.message_id,
                    content: data.data.content,
                    translated_content: data.data.translated_content,
                    sender_type: 'agent',
                    created_at: data.data.created_at
                });
                
                // 刷新会话列表
                refreshSessions();
            } else {
                alert(data.message || __('发送失败'));
            }
        } catch (error) {
            console.error('Failed to send message:', error);
            alert(__('发送失败，请稍后重试'));
        } finally {
            input.disabled = false;
            if (sendButton) {
                sendButton.disabled = false;
            }
        }
    }
    
    /**
     * 添加消息到聊天界面
     */
    function addMessageToChat(message) {
        const messagesContainer = document.getElementById('chat-messages');
        if (!messagesContainer) {
            return;
        }
        
        // 移除空状态
        const emptyState = messagesContainer.querySelector('.empty-state');
        if (emptyState) {
            emptyState.remove();
        }
        
        const messageDiv = document.createElement('div');
        messageDiv.innerHTML = renderMessage(message);
        messagesContainer.appendChild(messageDiv.firstElementChild);
        
        scrollToBottom();
        
        // 更新最后一条消息ID
        state.lastMessageId = message.message_id;
    }
    
    /**
     * 分配会话
     */
    window.assignSession = async function(sessionId) {
        if (!confirm(__('确定要接单此会话吗？'))) {
            return;
        }
        
        try {
            const formData = new URLSearchParams();
            formData.append('session_id', sessionId);
            
            const response = await fetch(config.consoleUrl + '/assignSession', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                // 刷新会话列表
                refreshSessions();
                // 加载会话
                loadSession(sessionId);
            } else {
                alert(data.message || __('分配失败'));
            }
        } catch (error) {
            console.error('Failed to assign session:', error);
            alert(__('分配失败，请稍后重试'));
        }
    };
    
    /**
     * 关闭会话
     */
    window.closeSession = async function(sessionId) {
        if (!confirm(__('确定要关闭此会话吗？'))) {
            return;
        }
        
        try {
            const formData = new URLSearchParams();
            formData.append('session_id', sessionId);
            
            const response = await fetch(config.consoleUrl + '/closeSession', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                // 刷新会话列表
                refreshSessions();
                // 清空聊天区域
                const container = document.getElementById('chat-container');
                container.innerHTML = `
                    <div class="chat-empty-state">
                        <div class="empty-icon">
                            <i class="mdi mdi-message-text"></i>
                        </div>
                        <p>${__('请选择一个会话开始聊天')}</p>
                    </div>
                `;
                state.currentSessionId = null;
                stopMessagePolling();
            } else {
                alert(data.message || __('关闭失败'));
            }
        } catch (error) {
            console.error('Failed to close session:', error);
            alert(__('关闭失败，请稍后重试'));
        }
    };
    
    /**
     * 开始轮询会话列表
     */
    function startSessionPolling() {
        setInterval(refreshSessions, 10000); // 每10秒刷新一次
    }
    
    /**
     * 开始轮询新消息
     */
    function startMessagePolling() {
        stopMessagePolling();
        
        if (!state.currentSessionId) {
            return;
        }
        
        state.isPolling = true;
        state.pollInterval = setInterval(async () => {
            try {
                const response = await fetch(config.consoleUrl + '/getMessages?session_id=' + state.currentSessionId + '&limit=10&offset=0');
                const data = await response.json();
                
                if (data.success && data.data.length > 0) {
                    const messagesContainer = document.getElementById('chat-messages');
                    if (messagesContainer) {
                    const existingIds = new Set(
                        Array.from(messagesContainer.querySelectorAll('.message-item'))
                            .map(el => {
                                const messageId = el.dataset.messageId;
                                return messageId ? parseInt(messageId) : 0;
                            })
                    );
                        
                        // 只添加新消息
                        let hasNew = false;
                        data.data.forEach(msg => {
                            if (!existingIds.has(msg.message_id) && msg.sender_type === 'customer') {
                                addMessageToChat(msg);
                                hasNew = true;
                            }
                        });
                        
                        if (hasNew) {
                            // 刷新会话列表以更新未读数
                            refreshSessions();
                        }
                    }
                }
            } catch (error) {
                console.error('Polling error:', error);
            }
        }, 3000); // 每3秒轮询一次
    }
    
    /**
     * 停止轮询消息
     */
    function stopMessagePolling() {
        if (state.pollInterval) {
            clearInterval(state.pollInterval);
            state.pollInterval = null;
            state.isPolling = false;
        }
    }
    
    /**
     * 滚动到底部
     */
    function scrollToBottom() {
        const messagesContainer = document.getElementById('chat-messages');
        if (messagesContainer) {
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }
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
     * 转义HTML
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    /**
     * 自动调整文本区域高度
     */
    function autoResizeTextarea(textarea) {
        textarea.style.height = 'auto';
        textarea.style.height = Math.min(textarea.scrollHeight, 120) + 'px';
    }
    
    // 导出公共API
    return {
        init
    };
})();

