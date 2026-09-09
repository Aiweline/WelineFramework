
function csAdmin(url, options){
  options=options||{};
  var body=options.body;
  var headers=Object.assign({}, options.headers||{});
  if(body && typeof FormData!=='undefined' && body instanceof FormData){
    var p=new URLSearchParams(); body.forEach(function(v,k){p.append(k,String(v));}); body=p.toString();
  } else if(body && typeof URLSearchParams!=='undefined' && body instanceof URLSearchParams){
    body=body.toString();
  } else if(body && typeof body!=='string'){ try{body=JSON.stringify(body);}catch(e){body='';} }
  if(typeof body==='string' && body.indexOf('=')!==-1 && !headers['Content-Type'] && !headers['content-type']){
    headers['Content-Type']='application/x-www-form-urlencoded;charset=UTF-8';
  }
  var callOptions={
    keepBusinessResult: options.keepBusinessResult !== false,
    silent: !!options.silent
  };
  var run=function(api){ return api.resource('customerService').adminRequest({url:url, method:options.method||'GET', headers:headers, body:body||''}, callOptions); };
  if(window.Weline&&window.Weline.load) return window.Weline.load('api').then(run);
  return Promise.resolve(run(window.Weline.Api));
}

function csExtractErrorMessage(error, fallback){
  fallback = fallback || '';
  if (!error) {
    return fallback;
  }
  if (typeof error === 'string' && error.trim() !== '') {
    return error.trim();
  }
  var direct = String(error.message || error.error || '').trim();
  if (direct) {
    return direct;
  }
  var nested = error.data && (error.data.message || error.data.error);
  if (nested) {
    return String(nested).trim();
  }
  var business = error.response && error.response.data && error.response.data.data
    && (error.response.data.data.message || error.response.data.data.error);
  if (business) {
    return String(business).trim();
  }
  var responseMessage = error.response && error.response.message;
  if (responseMessage) {
    return String(responseMessage).trim();
  }
  return fallback;
}

/**
 * 客服工作台JavaScript
 */
const CustomerServiceConsole = (function() {
    let config = {
        consoleUrl: '',
        agentId: 0,
        defaultSessionId: 0
    };
    
    let state = {
        currentSessionId: null,
        isPolling: false,
        pollInterval: null,
        heartbeatInterval: null,
        lastMessageId: 0,
        oldestMessageId: 0,
        hasMoreHistory: false,
        historyLoading: false,
        pageSize: 50,
        autoSelectDone: false,
        unreadBySession: {},
        titleAlertTimer: null,
        baseDocumentTitle: typeof document !== 'undefined' ? document.title : ''
    };

    function notify(type, message) {
        if (window.Weline.UI.toast && typeof window.Weline.UI.toast[type] === 'function') {
            window.Weline.UI.toast[type](message);
            return;
        }

        if (window.Weline.UI.toast && typeof window.Weline.UI.toast.info === 'function') {
            window.Weline.UI.toast.info(message);
            return;
        }

        console[type === 'error' ? 'error' : 'log'](message);
    }

    function confirmAction(message, options) {
        options = options || {};

        if (window.BackendConfirm && typeof window.BackendConfirm.show === 'function') {
            return Promise.resolve(window.BackendConfirm.show(message, options)).then(Boolean);
        }

        if (window.AdminConfirm && typeof window.AdminConfirm.show === 'function') {
            return Promise.resolve(window.AdminConfirm.show(message, options)).then(Boolean);
        }

        const dialog = window.Weline && window.Weline.UI && window.Weline.UI.dialog;
        if (dialog && typeof dialog.confirm === 'function') {
            return Promise.resolve(dialog.confirm(String(message || ''), {
                title: options.title || __('确认操作'),
                tone: options.type || 'warning',
                confirmLabel: options.confirmText || options.confirmLabel || __('确定'),
                cancelLabel: options.cancelText || options.cancelLabel || __('取消'),
                dangerous: options.type === 'danger'
            })).then(Boolean);
        }

        return Promise.resolve(window.confirm(String(message || '')));
    }
    
    /**
     * 初始化
     */
    function init(options) {
        config = Object.assign(config, options);
        state.baseDocumentTitle = document.title || state.baseDocumentTitle;

        // 侧栏互斥须最先绑定：避免后续轮询/心跳异常时跳过
        initSessionGroupAccordion();
        
        // 绑定事件
        bindEvents();
        
        // 开始轮询会话列表
        startSessionPolling();
        
        // 开始心跳（标记客服在线）
        sendHeartbeat();
        startHeartbeat();

        // 默认选中最近/优先会话（SSR 首位或配置指定）
        autoSelectPreferredSession(true);
        refreshSessions().then(function () {
            autoSelectPreferredSession(false);
        });
    }

    /**
     * 侧栏三组：展开一组时关闭其他（互斥手风琴）。
     */
    function initSessionGroupAccordion() {
        const root = document.querySelector('.cs-console__session-groups');
        if (!root || root.dataset.csAccordionBound === '1') {
            return;
        }
        root.dataset.csAccordionBound = '1';
        root.setAttribute('data-cs-accordion', 'exclusive');

        const closeGroup = (group) => {
            if (!(group instanceof HTMLElement) || group.dataset.state !== 'open') {
                return;
            }
            const trigger = group.querySelector('[data-w-disclosure-trigger]');
            const panel = group.querySelector('[data-w-disclosure-panel]');
            if (!(trigger instanceof HTMLElement) || !(panel instanceof HTMLElement)) {
                return;
            }
            trigger.setAttribute('aria-expanded', 'false');
            panel.hidden = true;
            group.dataset.state = 'closed';
        };

        root.addEventListener('weline:ui:disclosure:open', function (event) {
            const opened = event.target;
            if (!(opened instanceof HTMLElement) || !opened.classList.contains('cs-session-group')) {
                return;
            }
            if (!root.contains(opened)) {
                return;
            }
            root.querySelectorAll('.cs-session-group.w-disclosure').forEach((group) => {
                if (group !== opened) {
                    closeGroup(group);
                }
            });
        });
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

            const assignButton = e.target.closest('[data-assign-session-id]');
            if (assignButton) {
                e.preventDefault();
                const sessionId = normalizePositiveInt(assignButton.getAttribute('data-assign-session-id'));
                if (sessionId > 0) {
                    assignSession(sessionId);
                }
                return;
            }

            const sessionItem = e.target.closest('[data-load-session-id]');
            if (sessionItem) {
                e.preventDefault();
                const sessionId = normalizePositiveInt(sessionItem.getAttribute('data-load-session-id'));
                if (sessionId > 0) {
                    loadSession(sessionId);
                }
                return;
            }

            const closeButton = e.target.closest('[data-close-session-id]');
            if (closeButton) {
                e.preventDefault();
                const sessionId = normalizePositiveInt(closeButton.getAttribute('data-close-session-id'));
                if (sessionId > 0) {
                    closeSession(sessionId);
                }
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
            const data = await csAdmin(config.consoleUrl + '/sessions');
            
            if (data.success) {
                updateSessionList(
                    data.data.sessions,
                    data.data.waiting_sessions,
                    data.data.transferred_sessions || []
                );
            }
            return data;
        } catch (error) {
            console.error('Failed to refresh sessions:', error);
            return null;
        }
    }
    
    /**
     * 更新会话列表
     */
    function updateSessionList(sessions, waitingSessions, transferredSessions) {
        const waitingItems = Array.isArray(waitingSessions) ? waitingSessions : [];
        const transferredItems = sortSessionsByPriority(Array.isArray(transferredSessions) ? transferredSessions : []);
        const myItems = sortSessionsByPriority(Array.isArray(sessions) ? sessions : []);

        detectIncomingSessionAlerts(myItems.concat(transferredItems));

        // 更新等待分配的会话
        const waitingList = document.getElementById('waiting-sessions-list');
        if (waitingList) {
            if (waitingItems.length > 0) {
                waitingList.innerHTML = waitingItems.map(session => `
                    <div class="session-item waiting" data-session-id="${sessionRowId(session)}">
                        <div class="session-header">
                            <span class="session-id">#${sessionRowId(session)}</span>
                            <span class="w-badge session-status badge-waiting" data-tone="warning" data-size="sm">${__('等待中')}</span>
                        </div>
                        <div class="session-preview">
                            <p class="last-message">${session.last_message ? escapeHtml(session.last_message.substring(0, 50)) : __('暂无消息')}</p>
                        </div>
                        <div class="session-actions">
                            <button type="button" class="w-button weline-btn-assign btn-assign" data-tone="primary" data-size="sm" data-assign-session-id="${sessionRowId(session)}">
                                ${__('接单')}
                            </button>
                        </div>
                    </div>
                `).join('');
            } else {
                waitingList.innerHTML = '<div class="empty-state w-empty" style="padding:var(--weline-space-5);"><p class="w-text" data-tone="muted" data-size="sm" style="--w-m:0;">' + __('当前没有等待中的会话') + '</p></div>';
            }
        }

        const transferredList = document.getElementById('transferred-sessions-list');
        if (transferredList) {
            if (transferredItems.length > 0) {
                transferredList.innerHTML = transferredItems.map(session => renderOwnedSessionItem(session, true)).join('');
            } else {
                transferredList.innerHTML = '<div class="empty-state w-empty" style="padding:var(--weline-space-5);"><p class="w-text" data-tone="muted" data-size="sm" style="--w-m:0;">' + __('当前没有转让进来的会话') + '</p><p class="w-text" data-tone="muted" data-size="sm" style="--w-m:0;margin-top:var(--weline-space-2);">' + __('你转出的会话只显示在接收方工作台的「转让分配」中。') + '</p></div>';
            }
        }
        
        // 更新我的会话
        const mySessionsList = document.getElementById('my-sessions-list');
        if (mySessionsList) {
            if (myItems.length > 0) {
                mySessionsList.innerHTML = myItems.map(session => renderOwnedSessionItem(session, false)).join('');
            } else {
                mySessionsList.innerHTML = '<div class="empty-state w-empty"><p>' + __('暂无进行中的会话') + '</p><p class="w-text" data-tone="muted" data-size="sm" style="--w-m:0;">' + __('有等待中的会话时，点击「接单」开始接待。') + '</p></div>';
            }
        }

        updateSectionCount('my-sessions-count', myItems.length);
        updateSectionCount('transferred-sessions-count', transferredItems.length);
        updateSectionCount('waiting-sessions-count', waitingItems.length);

        // 列表重绘后恢复当前选中态
        if (state.currentSessionId) {
            document.querySelectorAll('.session-item').forEach(item => {
                const sid = normalizePositiveInt(item.dataset.sessionId || item.dataset.loadSessionId);
                item.classList.toggle('active', sid === state.currentSessionId);
            });
        }

        autoSelectPreferredSession(false);
    }

    function sessionSortTime(value) {
        if (!value) return 0;
        const t = Date.parse(String(value));
        return Number.isFinite(t) ? t : 0;
    }

    function sortSessionsByPriority(sessions) {
        return sessions.slice().sort(function (a, b) {
            const aUnread = normalizePositiveInt(a && a.unread_count) > 0;
            const bUnread = normalizePositiveInt(b && b.unread_count) > 0;
            if (aUnread !== bUnread) {
                return aUnread ? -1 : 1;
            }
            if (aUnread && bUnread) {
                const aWait = sessionSortTime(a.waiting_since_at) || Number.MAX_SAFE_INTEGER;
                const bWait = sessionSortTime(b.waiting_since_at) || Number.MAX_SAFE_INTEGER;
                if (aWait !== bWait) {
                    return aWait - bWait;
                }
            }
            const aLast = sessionSortTime(a && (a.last_message_at || a.last_message_time));
            const bLast = sessionSortTime(b && (b.last_message_at || b.last_message_time));
            if (aLast !== bLast) {
                return bLast - aLast;
            }
            return sessionRowId(b) - sessionRowId(a);
        });
    }

    function autoSelectPreferredSession(fromSsr) {
        if (state.currentSessionId || state.autoSelectDone) {
            return;
        }
        let sessionId = normalizePositiveInt(config.defaultSessionId);
        if (!sessionId) {
            const first = document.querySelector('#my-sessions-list .session-item[data-load-session-id], #my-sessions-list .session-item[data-session-id]');
            if (first) {
                sessionId = normalizePositiveInt(first.getAttribute('data-load-session-id') || first.getAttribute('data-session-id'));
            }
        }
        if (!sessionId && !fromSsr) {
            const transferred = document.querySelector('#transferred-sessions-list .session-item[data-load-session-id], #transferred-sessions-list .session-item[data-session-id]');
            if (transferred) {
                sessionId = normalizePositiveInt(transferred.getAttribute('data-load-session-id') || transferred.getAttribute('data-session-id'));
            }
        }
        if (sessionId <= 0) {
            return;
        }
        state.autoSelectDone = true;
        if (typeof window.loadSession === 'function') {
            window.loadSession(sessionId);
        }
    }

    function detectIncomingSessionAlerts(sessions) {
        const nextMap = {};
        const risen = [];
        sessions.forEach(function (session) {
            const sid = sessionRowId(session);
            if (sid <= 0) return;
            const unread = normalizePositiveInt(session.unread_count);
            nextMap[sid] = unread;
            const prev = normalizePositiveInt(state.unreadBySession[sid]);
            if (unread > prev && sid !== state.currentSessionId) {
                risen.push({ sessionId: sid, unread: unread, delta: unread - prev });
            }
        });
        const hadBaseline = Object.keys(state.unreadBySession).length > 0;
        state.unreadBySession = nextMap;
        if (!hadBaseline || risen.length === 0) {
            return;
        }
        risen.forEach(function (item) {
            pulseSessionItem(item.sessionId);
        });
        if (risen.length === 1) {
            notifyIncomingMessage(risen[0].sessionId, risen[0].delta);
        } else {
            notify('info', __('收到 %{1} 个会话的新消息').replace('%{1}', String(risen.length)));
            flashDocumentTitle(__('新消息'));
        }
    }

    function notifyIncomingMessage(sessionId, count) {
        const n = Math.max(1, normalizePositiveInt(count));
        const text = n > 1
            ? __('会话 #%{1} 有 %{2} 条新消息').replace('%{1}', String(sessionId)).replace('%{2}', String(n))
            : __('会话 #%{1} 有新消息').replace('%{1}', String(sessionId));
        notify('info', text);
        flashDocumentTitle(__('新消息'));
        playIncomingCue();
    }

    function pulseSessionItem(sessionId) {
        const el = document.querySelector('.session-item[data-session-id="' + sessionId + '"]');
        if (!el) return;
        el.classList.remove('cs-session-alert');
        // restart animation
        void el.offsetWidth;
        el.classList.add('cs-session-alert');
        window.setTimeout(function () {
            el.classList.remove('cs-session-alert');
        }, 2600);
    }

    function flashDocumentTitle(prefix) {
        if (state.titleAlertTimer) {
            clearInterval(state.titleAlertTimer);
            state.titleAlertTimer = null;
        }
        const base = state.baseDocumentTitle || document.title;
        let on = true;
        let ticks = 0;
        document.title = '(' + prefix + ') ' + base;
        state.titleAlertTimer = window.setInterval(function () {
            ticks += 1;
            document.title = on ? base : ('(' + prefix + ') ' + base);
            on = !on;
            if (ticks >= 8 || document.visibilityState === 'visible' && ticks >= 4) {
                clearInterval(state.titleAlertTimer);
                state.titleAlertTimer = null;
                document.title = base;
            }
        }, 900);
        const resume = function () {
            if (state.titleAlertTimer) {
                clearInterval(state.titleAlertTimer);
                state.titleAlertTimer = null;
            }
            document.title = base;
            document.removeEventListener('visibilitychange', resume);
            window.removeEventListener('focus', resume);
        };
        document.addEventListener('visibilitychange', resume);
        window.addEventListener('focus', resume);
    }

    function playIncomingCue() {
        try {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;
            const ctx = new Ctx();
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.value = 880;
            gain.gain.value = 0.0001;
            osc.connect(gain);
            gain.connect(ctx.destination);
            const now = ctx.currentTime;
            gain.gain.exponentialRampToValueAtTime(0.05, now + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.18);
            osc.start(now);
            osc.stop(now + 0.2);
            window.setTimeout(function () { ctx.close(); }, 300);
        } catch (e) {}
    }

    function updateSectionCount(elementId, count) {
        const badge = document.getElementById(elementId);
        if (!badge) {
            return;
        }
        const n = Math.max(0, parseInt(String(count), 10) || 0);
        if (n > 0) {
            badge.hidden = false;
            badge.textContent = String(n);
        } else {
            badge.hidden = true;
            badge.textContent = '';
        }
    }

    function renderOwnedSessionItem(session, transferred) {
        const sessionId = sessionRowId(session);
        const transferBadge = transferred
            ? `<span class="w-badge session-transfer-badge" data-tone="info" data-size="sm" title="${escapeHtml(session.transfer_badge_title || __('转让会话'))}">${__('转让')}</span>`
            : '';
        const fromLabel = transferred
            ? String(session.transfer_from_label || (
                session.transferred_from_agent_name
                    ? String(__('来自客服「__AGENT__」')).split('__AGENT__').join(String(session.transferred_from_agent_name))
                    : __('来自其他客服')
              ))
            : '';
        const fromLine = fromLabel
            ? `<p class="session-transfer-from">${escapeHtml(fromLabel)}</p>`
            : '';
        return `
            <div class="session-item ${transferred ? 'transferred' : ''} ${session.unread_count > 0 ? 'unread' : ''} ${sessionId === state.currentSessionId ? 'active' : ''}"
                 data-session-id="${sessionId}"
                 data-load-session-id="${sessionId}">
                <div class="session-header">
                    <span class="session-id">#${sessionId}</span>
                    <span class="session-header-meta">
                        ${transferBadge}
                        ${session.unread_count > 0 ? `<span class="unread-badge">${session.unread_count}</span>` : ''}
                    </span>
                </div>
                ${fromLine}
                <div class="session-preview">
                    <p class="last-message">${session.last_message ? escapeHtml(session.last_message.substring(0, 50)) : __('暂无消息')}</p>
                    ${session.last_message_time ? `<span class="last-time">${formatTime(session.last_message_time)}</span>` : ''}
                </div>
            </div>
        `;
    }
    
    /**
     * 加载会话
     */
    window.loadSession = async function(sessionId) {
        sessionId = normalizePositiveInt(sessionId);
        if (sessionId <= 0) {
            return;
        }

        // 已打开同一会话且聊天壳仍在：允许强制刷新；仅在仍是空态占位时跳过重复点击
        if (state.currentSessionId === sessionId && document.getElementById('chat-messages')) {
            return;
        }

        const previousSessionId = state.currentSessionId;
        state.currentSessionId = sessionId;
        state.lastMessageId = 0;
        state.oldestMessageId = 0;
        state.hasMoreHistory = false;
        state.historyLoading = false;
        
        // 更新会话列表的active状态
        document.querySelectorAll('.session-item').forEach(item => {
            const sid = normalizePositiveInt(item.dataset.sessionId || item.dataset.loadSessionId);
            item.classList.toggle('active', sid === sessionId);
        });

        // 立即进入聊天壳，避免长时间停在「请选择会话」空态
        renderChatArea(sessionId, [], { loading: true });
        
        // 加载最新一页消息
        const loaded = await loadMessages(sessionId);
        if (!loaded) {
            if (previousSessionId > 0) {
                state.currentSessionId = null;
                await window.loadSession(previousSessionId);
            } else {
                state.currentSessionId = null;
                document.querySelectorAll('.session-item').forEach(item => item.classList.remove('active'));
                resetChatEmptyState();
            }
            return;
        }
        
        // 开始轮询新消息
        startMessagePolling();
    };
    
    /**
     * 加载最新消息（首屏）
     * @returns {Promise<boolean>}
     */
    async function loadMessages(sessionId) {
        try {
            const data = await csAdmin(
                config.consoleUrl + '/messages?session_id=' + sessionId
                + '&limit=' + state.pageSize
                + '&offset=0&mark_read=1'
            );
            
            if (data && data.success) {
                const messages = Array.isArray(data.data) ? data.data : [];
                const hasMore = data.has_more === true || messages.length >= state.pageSize;
                renderChatArea(sessionId, messages, { loading: false, hasMore: hasMore });
                refreshSessions();
                return true;
            }

            notify('error', (data && data.message) ? data.message : __('加载会话失败'));
            return false;
        } catch (error) {
            console.error('Failed to load messages:', error);
            notify('error', csExtractErrorMessage(error, __('加载会话失败，请稍后重试')));
            return false;
        }
    }

    /**
     * 上滑加载更旧消息
     */
    async function loadOlderMessages() {
        if (state.historyLoading || !state.hasMoreHistory || !state.currentSessionId) {
            return;
        }
        const beforeId = normalizePositiveInt(state.oldestMessageId);
        if (beforeId <= 0) {
            state.hasMoreHistory = false;
            updateHistoryHint();
            return;
        }

        const messagesContainer = document.getElementById('chat-messages');
        if (!messagesContainer) {
            return;
        }

        state.historyLoading = true;
        updateHistoryHint(true);
        const prevHeight = messagesContainer.scrollHeight;
        const prevTop = messagesContainer.scrollTop;

        try {
            const data = await csAdmin(
                config.consoleUrl + '/messages?session_id=' + state.currentSessionId
                + '&limit=' + state.pageSize
                + '&before_id=' + beforeId
                + '&mark_read=0'
            );
            if (!(data && data.success)) {
                notify('error', (data && data.message) ? data.message : __('加载更早消息失败'));
                return;
            }
            const rows = Array.isArray(data.data) ? data.data : [];
            if (rows.length === 0) {
                state.hasMoreHistory = false;
                updateHistoryHint();
                return;
            }

            const existingIds = new Set(
                Array.from(messagesContainer.querySelectorAll('.message-item'))
                    .map(el => normalizePositiveInt(el.dataset.messageId))
                    .filter(id => id > 0)
            );
            const fragment = document.createDocumentFragment();
            rows.forEach(msg => {
                const messageId = normalizePositiveInt(msg.message_id);
                if (messageId <= 0 || existingIds.has(messageId)) {
                    return;
                }
                const wrap = document.createElement('div');
                wrap.innerHTML = renderMessage(msg);
                if (wrap.firstElementChild) {
                    fragment.appendChild(wrap.firstElementChild);
                    existingIds.add(messageId);
                }
            });
            const historyHint = messagesContainer.querySelector('.cs-chat-history-hint');
            if (historyHint && historyHint.nextSibling) {
                messagesContainer.insertBefore(fragment, historyHint.nextSibling);
            } else if (historyHint) {
                messagesContainer.appendChild(fragment);
            } else {
                messagesContainer.insertBefore(fragment, messagesContainer.firstChild);
            }

            const oldest = rows.reduce((min, msg) => {
                const id = normalizePositiveInt(msg.message_id);
                return id > 0 && (min === 0 || id < min) ? id : min;
            }, 0);
            if (oldest > 0) {
                state.oldestMessageId = oldest;
            }
            state.hasMoreHistory = data.has_more === true || rows.length >= state.pageSize;
            updateHistoryHint();

            // 保持视口相对位置，避免跳动
            messagesContainer.scrollTop = messagesContainer.scrollHeight - prevHeight + prevTop;
        } catch (error) {
            console.error('Failed to load older messages:', error);
            notify('error', __('加载更早消息失败，请稍后重试'));
        } finally {
            state.historyLoading = false;
            updateHistoryHint();
        }
    }
    
    /**
     * 渲染聊天区域
     */
    function renderChatArea(sessionId, messages, options) {
        sessionId = normalizePositiveInt(sessionId);
        options = options || {};
        const container = document.getElementById('chat-container');
        const loading = !!options.loading;
        const hasMore = options.hasMore === true || (Array.isArray(messages) && messages.length >= state.pageSize);
        
        let bodyHtml;
        if (loading) {
            bodyHtml = '<div class="cs-chat-loading w-text" data-tone="muted" data-size="sm">' + __('正在加载最新消息…') + '</div>';
        } else if (messages.length > 0) {
            bodyHtml = messages.map(msg => renderMessage(msg)).join('');
        } else {
            bodyHtml = '<div class="empty-state w-empty"><p>' + __('暂无消息') + '</p><p class="w-text" data-tone="muted" data-size="sm" style="--w-m:0;">' + __('在下方输入框发送第一条回复。') + '</p></div>';
        }

        container.innerHTML = `
            <div class="chat-header">
                <div class="chat-header-info">
                    <h3>${__('会话')} #${sessionId}</h3>
                </div>
                <div class="chat-header-actions">
                    <button type="button" class="w-button btn-close-session" data-tone="danger" data-variant="outline" data-size="sm" data-close-session-id="${sessionId}">
                        ${__('关闭会话')}
                    </button>
                </div>
            </div>
            <div class="chat-messages" id="chat-messages">
                <div class="cs-chat-history-hint w-text" data-tone="muted" data-size="sm" hidden></div>
                ${bodyHtml}
            </div>
            <div class="chat-input-area">
                ${composerToolbarHtml(loading)}
                <div class="chat-input-wrapper">
                    <textarea class="w-textarea chat-input" id="message-input" placeholder="${__('输入消息...')}" ${loading ? 'disabled' : ''}></textarea>
                    <button type="button" class="w-button btn-send" data-tone="primary" id="send-button" ${loading ? 'disabled' : ''}>${__('发送')}</button>
                </div>
            </div>
        `;

        const messagesContainer = document.getElementById('chat-messages');
        if (messagesContainer && !loading) {
            messagesContainer.addEventListener('scroll', onChatMessagesScroll, { passive: true });
        }
        if (!loading) {
            bindComposerTools();
        }
        
        if (!loading) {
            if (messages.length > 0) {
                state.lastMessageId = normalizePositiveInt(messages[messages.length - 1].message_id);
                state.oldestMessageId = normalizePositiveInt(messages[0].message_id);
                state.hasMoreHistory = hasMore;
            } else {
                state.lastMessageId = 0;
                state.oldestMessageId = 0;
                state.hasMoreHistory = false;
            }
            updateHistoryHint();
            scrollToBottom();
        }
    }

    function onChatMessagesScroll(event) {
        const el = event.currentTarget;
        if (!(el instanceof HTMLElement)) {
            return;
        }
        if (el.scrollTop <= 48) {
            loadOlderMessages();
        }
    }

    function updateHistoryHint(loading) {
        const hint = document.querySelector('#chat-messages .cs-chat-history-hint');
        if (!hint) {
            return;
        }
        if (loading || state.historyLoading) {
            hint.hidden = false;
            hint.textContent = __('正在加载更早消息…');
            return;
        }
        if (state.hasMoreHistory) {
            hint.hidden = false;
            hint.textContent = __('上滑加载更早消息');
            return;
        }
        if (state.oldestMessageId > 0) {
            hint.hidden = false;
            hint.textContent = __('没有更早的消息了');
            return;
        }
        hint.hidden = true;
        hint.textContent = '';
    }

    function resetChatEmptyState() {
        const container = document.getElementById('chat-container');
        if (!container) {
            return;
        }
        container.innerHTML = `
            <div class="chat-empty-state w-empty">
                <div class="empty-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="28" height="28" fill="currentColor">
                        <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/>
                    </svg>
                </div>
                <p>${__('请选择一个会话开始聊天')}</p>
                <p class="w-text" data-tone="muted" data-size="sm" style="--w-m:0;">${__('从右侧「我的会话」点选，或先接单等待中的会话。')}</p>
            </div>
        `;
        stopMessagePolling();
    }
    
    /**
     * 渲染单条消息
     */
    function renderMessage(message) {
        const isAgent = message.sender_type === 'agent';
        const attachment = message.attachment || parseAttachmentContent(message.content);
        const time = formatTime(message.created_at);
        let body;
        if (attachment && attachment.type === 'image') {
            body = `<a class="cs-msg-image" href="${escapeHtml(attachment.url)}" target="_blank" rel="noopener">`
                + `<img src="${escapeHtml(attachment.url)}" alt="${escapeHtml(attachment.name || __('图片'))}" loading="lazy" />`
                + `</a>`;
        } else if (attachment && attachment.type === 'file') {
            const label = attachment.name || __('文件');
            body = `<a class="cs-msg-file" href="${escapeHtml(attachment.url)}" target="_blank" rel="noopener">`
                + `<span class="cs-msg-file__name">${escapeHtml(label)}</span>`
                + (attachment.size ? `<span class="cs-msg-file__size">${formatFileSize(attachment.size)}</span>` : '')
                + `</a>`;
        } else {
            const content = message.translated_content || message.display_content || message.content || '';
            body = `<p class="message-content">${escapeHtml(content)}</p>`;
        }
        
        return `
            <div class="message-item ${isAgent ? 'agent' : 'customer'}" data-message-id="${normalizePositiveInt(message.message_id)}">
                <div class="message-bubble">
                    ${body}
                </div>
                <div class="message-time">${time}</div>
            </div>
        `;
    }

    function parseAttachmentContent(content) {
        const raw = String(content || '');
        if (raw.indexOf('__CSJSON__') !== 0) {
            return null;
        }
        try {
            const data = JSON.parse(raw.slice('__CSJSON__'.length));
            if (!data || (data.type !== 'image' && data.type !== 'file') || !data.url) {
                return null;
            }
            return {
                type: data.type,
                url: String(data.url),
                name: String(data.name || ''),
                size: normalizePositiveInt(data.size),
                mime: String(data.mime || '')
            };
        } catch (e) {
            return null;
        }
    }

    function formatFileSize(bytes) {
        const n = normalizePositiveInt(bytes);
        if (n <= 0) return '';
        if (n < 1024) return n + ' B';
        if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
        return (n / 1048576).toFixed(1) + ' MB';
    }

    const CS_EMOJI_LIST = ['😀','😁','😂','😊','😍','🤔','😅','😢','😡','👍','👎','🙏','👏','🎉','❤️','🔥','✅','❌','⭐','💡','📎','📷','📄','🤝','😴','🤗'];

    function composerToolbarHtml(disabled) {
        const dis = disabled ? 'disabled' : '';
        return `
            <div class="cs-composer-toolbar" role="toolbar" aria-label="${__('聊天工具')}">
                <button type="button" class="w-button cs-composer-tool" data-tone="neutral" data-variant="ghost" data-size="sm" data-cs-tool="emoji" ${dis} title="${__('表情')}">${__('表情')}</button>
                <button type="button" class="w-button cs-composer-tool" data-tone="neutral" data-variant="ghost" data-size="sm" data-cs-tool="image" ${dis} title="${__('图片')}">${__('图片')}</button>
                <button type="button" class="w-button cs-composer-tool" data-tone="neutral" data-variant="ghost" data-size="sm" data-cs-tool="file" ${dis} title="${__('文件')}">${__('文件')}</button>
                <button type="button" class="w-button cs-composer-tool" data-tone="neutral" data-variant="ghost" data-size="sm" data-cs-tool="phrase" ${dis} title="${__('话术库')}">${__('话术')}</button>
                <input type="file" id="cs-image-input" accept="image/jpeg,image/png,image/gif,image/webp" hidden />
                <input type="file" id="cs-file-input" accept=".pdf,.txt,.zip,.doc,.docx,.xls,.xlsx,application/pdf,text/plain,application/zip" hidden />
            </div>
            <div class="cs-composer-panel" id="cs-emoji-panel" hidden></div>
            <div class="cs-composer-panel" id="cs-phrase-panel" hidden></div>
        `;
    }

    function bindComposerTools() {
        const toolbar = document.querySelector('.cs-composer-toolbar');
        if (!toolbar || toolbar.dataset.bound === '1') {
            return;
        }
        toolbar.dataset.bound = '1';
        toolbar.addEventListener('click', function (e) {
            const btn = e.target.closest('[data-cs-tool]');
            if (!btn) return;
            const tool = btn.getAttribute('data-cs-tool');
            if (tool === 'emoji') {
                toggleEmojiPanel();
            } else if (tool === 'phrase') {
                togglePhrasePanel();
            } else if (tool === 'image') {
                document.getElementById('cs-image-input')?.click();
            } else if (tool === 'file') {
                document.getElementById('cs-file-input')?.click();
            }
        });
        const imageInput = document.getElementById('cs-image-input');
        const fileInput = document.getElementById('cs-file-input');
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
    }

    function closeComposerPanels() {
        const emoji = document.getElementById('cs-emoji-panel');
        const phrase = document.getElementById('cs-phrase-panel');
        if (emoji) emoji.hidden = true;
        if (phrase) phrase.hidden = true;
    }

    function toggleEmojiPanel() {
        const panel = document.getElementById('cs-emoji-panel');
        if (!panel) return;
        const phrase = document.getElementById('cs-phrase-panel');
        if (phrase) phrase.hidden = true;
        if (!panel.hidden) {
            panel.hidden = true;
            return;
        }
        panel.innerHTML = CS_EMOJI_LIST.map(e => `<button type="button" class="cs-emoji-item" data-emoji="${e}">${e}</button>`).join('');
        panel.hidden = false;
        panel.onclick = function (ev) {
            const item = ev.target.closest('[data-emoji]');
            if (!item) return;
            insertIntoInput(item.getAttribute('data-emoji') || '');
            panel.hidden = true;
        };
    }

    async function togglePhrasePanel() {
        const panel = document.getElementById('cs-phrase-panel');
        if (!panel) return;
        const emoji = document.getElementById('cs-emoji-panel');
        if (emoji) emoji.hidden = true;
        if (!panel.hidden) {
            panel.hidden = true;
            return;
        }
        panel.hidden = false;
        panel.innerHTML = `<p class="w-text" data-tone="muted" data-size="sm">${__('加载话术…')}</p>`;
        try {
            const data = await csAdmin(config.consoleUrl + '/phrases');
            const rows = data && data.success && Array.isArray(data.data) ? data.data : [];
            panel.innerHTML = `
                <div class="cs-phrase-list" id="cs-phrase-list"></div>
                <div class="cs-phrase-editor w-stack" style="--w-gap:var(--weline-space-2);">
                    <input class="w-input" id="cs-phrase-title" placeholder="${__('话术标题')}" />
                    <textarea class="w-textarea" id="cs-phrase-content" rows="2" placeholder="${__('话术内容')}"></textarea>
                    <button type="button" class="w-button" data-tone="primary" data-size="sm" id="cs-phrase-save">${__('保存话术')}</button>
                </div>
            `;
            const list = document.getElementById('cs-phrase-list');
            if (!rows.length) {
                list.innerHTML = `<p class="w-text" data-tone="muted" data-size="sm">${__('暂无话术，可在下方添加')}</p>`;
            } else {
                list.innerHTML = rows.map(row => `
                    <div class="cs-phrase-item" data-phrase-id="${normalizePositiveInt(row.phrase_id)}">
                        <button type="button" class="cs-phrase-use" data-content="${encodeURIComponent(row.content || '')}">
                            <strong>${escapeHtml(row.title || '')}</strong>
                            <span>${escapeHtml((row.content || '').slice(0, 48))}</span>
                        </button>
                        <button type="button" class="w-button cs-phrase-del" data-tone="danger" data-variant="ghost" data-size="sm" data-del="${normalizePositiveInt(row.phrase_id)}">${__('删除')}</button>
                    </div>
                `).join('');
            }
            list.onclick = async function (ev) {
                const useBtn = ev.target.closest('.cs-phrase-use');
                if (useBtn) {
                    const input = document.getElementById('message-input');
                    if (input) {
                        try {
                            input.value = decodeURIComponent(useBtn.getAttribute('data-content') || '');
                        } catch (err) {
                            input.value = useBtn.getAttribute('data-content') || '';
                        }
                        input.focus();
                    }
                    panel.hidden = true;
                    return;
                }
                const delBtn = ev.target.closest('[data-del]');
                if (delBtn) {
                    const id = normalizePositiveInt(delBtn.getAttribute('data-del'));
                    if (id <= 0) return;
                    const formData = new URLSearchParams();
                    formData.append('phrase_id', String(id));
                    const res = await csAdmin(config.consoleUrl + '/phrase-delete', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: formData
                    });
                    if (res && res.success) {
                        panel.hidden = true;
                        togglePhrasePanel();
                    } else {
                        notify('error', (res && res.message) || __('删除失败'));
                    }
                }
            };
            document.getElementById('cs-phrase-save')?.addEventListener('click', async function () {
                const title = (document.getElementById('cs-phrase-title')?.value || '').trim();
                const content = (document.getElementById('cs-phrase-content')?.value || '').trim();
                if (!title || !content) {
                    notify('error', __('标题和内容不能为空'));
                    return;
                }
                const formData = new URLSearchParams();
                formData.append('title', title);
                formData.append('content', content);
                const res = await csAdmin(config.consoleUrl + '/phrase-save', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: formData
                });
                if (res && res.success) {
                    panel.hidden = true;
                    togglePhrasePanel();
                } else {
                    notify('error', (res && res.message) || __('保存失败'));
                }
            });
        } catch (error) {
            panel.innerHTML = `<p class="w-text" data-tone="danger" data-size="sm">${escapeHtml(csExtractErrorMessage(error, __('加载话术失败')))}</p>`;
        }
    }

    function insertIntoInput(text) {
        const input = document.getElementById('message-input');
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
        if (!state.currentSessionId) {
            notify('error', __('请先选择会话'));
            return;
        }
        try {
            notify('info', __('正在上传…'));
            const dataUrl = await readFileAsDataUrl(file);
            const formData = new URLSearchParams();
            formData.append('name', file.name || (kind === 'image' ? 'image.png' : 'file.bin'));
            formData.append('mime', file.type || 'application/octet-stream');
            formData.append('data', dataUrl);
            const uploaded = await csAdmin(config.consoleUrl + '/upload', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData
            });
            if (!(uploaded && uploaded.success && uploaded.data && uploaded.data.url)) {
                notify('error', (uploaded && uploaded.message) || __('上传失败'));
                return;
            }
            const sendBody = new URLSearchParams();
            sendBody.append('session_id', String(state.currentSessionId));
            sendBody.append('attachment_type', uploaded.data.kind || kind);
            sendBody.append('attachment_url', uploaded.data.url);
            sendBody.append('attachment_name', uploaded.data.name || file.name || '');
            sendBody.append('attachment_size', String(uploaded.data.size || file.size || 0));
            sendBody.append('attachment_mime', uploaded.data.mime || file.type || '');
            const sent = await csAdmin(config.consoleUrl + '/send-message', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: sendBody
            });
            if (sent && sent.success) {
                addMessageToChat({
                    message_id: sent.data && sent.data.message_id,
                    content: sent.data && sent.data.content,
                    translated_content: sent.data && sent.data.translated_content,
                    sender_type: 'agent',
                    created_at: sent.data && sent.data.created_at,
                    attachment: {
                        type: uploaded.data.kind || kind,
                        url: uploaded.data.url,
                        name: uploaded.data.name || '',
                        size: uploaded.data.size || 0,
                        mime: uploaded.data.mime || ''
                    }
                });
                refreshSessions();
                closeComposerPanels();
            } else {
                notify('error', (sent && sent.message) || __('发送失败'));
            }
        } catch (error) {
            notify('error', csExtractErrorMessage(error, __('上传失败，请稍后重试')));
        }
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
            
            const data = await csAdmin(config.consoleUrl + '/send-message', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: formData
            });
            
            if (data && data.success) {
                input.value = '';
                autoResizeTextarea(input);
                
                // 添加消息到界面
                addMessageToChat({
                    message_id: data.data && data.data.message_id,
                    content: (data.data && data.data.content) || content,
                    translated_content: data.data && data.data.translated_content,
                    sender_type: 'agent',
                    created_at: data.data && data.data.created_at
                });
                
                // 刷新会话列表
                refreshSessions();
            } else {
                notify('error', (data && data.message) ? data.message : __('发送失败'));
            }
        } catch (error) {
            console.error('Failed to send message:', error);
            notify('error', csExtractErrorMessage(error, __('发送失败，请稍后重试')));
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
        state.lastMessageId = normalizePositiveInt(message.message_id);
    }
    
    /**
     * 分配会话
     */
    window.assignSession = async function(sessionId) {
        sessionId = normalizePositiveInt(sessionId);
        if (sessionId <= 0) {
            return;
        }

        if (!(await confirmAction(__('确定要接单此会话吗？'), {
            title: __('确认接单'),
            type: 'warning',
            confirmText: __('接单'),
            cancelText: __('取消')
        }))) {
            return;
        }
        
        try {
            const formData = new URLSearchParams();
            formData.append('session_id', sessionId);
            
            const data = await csAdmin(config.consoleUrl + '/assign-session', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: formData
            });
            
            if (data.success) {
                // 刷新会话列表
                refreshSessions();
                // 加载会话
                loadSession(sessionId);
            } else {
                notify('error', data.message || __('分配失败'));
            }
        } catch (error) {
            console.error('Failed to assign session:', error);
            notify('error', __('分配失败，请稍后重试'));
        }
    };
    
    /**
     * 关闭会话
     */
    window.closeSession = async function(sessionId) {
        sessionId = normalizePositiveInt(sessionId);
        if (sessionId <= 0) {
            return;
        }

        if (!(await confirmAction(__('确定要关闭此会话吗？'), {
            title: __('确认关闭'),
            type: 'warning',
            confirmText: __('关闭'),
            cancelText: __('取消')
        }))) {
            return;
        }
        
        try {
            const formData = new URLSearchParams();
            formData.append('session_id', sessionId);
            
            const data = await csAdmin(config.consoleUrl + '/close-session', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: formData
            });
            
            if (data.success) {
                // 清空当前后再刷新，便于自动切到下一条优先会话
                state.currentSessionId = null;
                state.lastMessageId = 0;
                state.oldestMessageId = 0;
                state.hasMoreHistory = false;
                state.autoSelectDone = false;
                stopMessagePolling();
                resetChatEmptyState();
                await refreshSessions();
            } else {
                notify('error', data.message || __('关闭失败'));
            }
        } catch (error) {
            console.error('Failed to close session:', error);
            notify('error', __('关闭失败，请稍后重试'));
        }
    };
    
    /**
     * 发送心跳（标记客服在线状态）
     */
    async function sendHeartbeat() {
        if (!config.consoleUrl) return;
        try {
            await csAdmin(config.consoleUrl + '/heartbeat', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: ''
            });
        } catch (e) {
            // 心跳失败不影响正常工作
        }
    }

    /**
     * 开始心跳定时器（每30秒）
     */
    function startHeartbeat() {
        if (state.heartbeatInterval) return;
        state.heartbeatInterval = setInterval(sendHeartbeat, 30000);
        
        // 页面关闭时发送最后一次心跳（尝试）
        window.addEventListener('beforeunload', function() {
            if (navigator.sendBeacon && config.consoleUrl) {
                navigator.sendBeacon(config.consoleUrl + '/heartbeat', '');
            }
        });
    }

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
                const sinceId = normalizePositiveInt(state.lastMessageId);
                const url = config.consoleUrl + '/messages?session_id=' + state.currentSessionId
                    + '&limit=50&offset=0'
                    + (sinceId > 0 ? ('&since_id=' + sinceId) : '')
                    + '&mark_read=1';
                const data = await csAdmin(url);
                const rows = data && data.success && Array.isArray(data.data) ? data.data : [];
                if (rows.length === 0) {
                    return;
                }

                const messagesContainer = document.getElementById('chat-messages');
                if (!messagesContainer) {
                    return;
                }

                const existingIds = new Set(
                    Array.from(messagesContainer.querySelectorAll('.message-item'))
                        .map(el => normalizePositiveInt(el.dataset.messageId))
                        .filter(id => id > 0)
                );

                let hasNew = false;
                let hasNewCustomer = false;
                rows.forEach(msg => {
                    const messageId = normalizePositiveInt(msg.message_id);
                    if (messageId <= 0 || existingIds.has(messageId)) {
                        return;
                    }
                    // 增量同步客户与客服消息，保证前后端对话对齐
                    addMessageToChat(msg);
                    existingIds.add(messageId);
                    hasNew = true;
                    if (String(msg.sender_type || '') === 'customer') {
                        hasNewCustomer = true;
                    }
                });

                if (hasNew) {
                    refreshSessions();
                }
                if (hasNewCustomer) {
                    notifyIncomingMessage(state.currentSessionId, 1);
                    pulseSessionItem(state.currentSessionId);
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

    function normalizePositiveInt(value) {
        const parsed = parseInt(value, 10);
        return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
    }

    function sessionRowId(session) {
        if (!session || typeof session !== 'object') {
            return 0;
        }
        return normalizePositiveInt(session.session_id || session.id);
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
