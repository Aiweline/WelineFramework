/**
 * AI chat page app (Weline_Ai).
 * Moved verbatim from the inline <script> in templates/frontend/Chat/index.phtml.
 * Copy/i18n dictionary comes from the non-executable JSON block #ai-chat-i18n.
 * Registered as module `aiChat`; the chat root declares data-weline-load.
 */
var I18N = (function () { try { var el = document.getElementById('ai-chat-i18n'); return el ? JSON.parse(el.textContent || '{}') : {}; } catch (e) { return {}; } }());

let isProcessing = false;
let runtimeApiPromise = null;
let activeChatStream = null;
const CHAT_ACTIVE_TASK_STORAGE_KEY = 'weline.ai.chat.active_task';

function getRuntimeApi() {
    if (!runtimeApiPromise) {
        runtimeApiPromise = (async function() {
            let api = window.WelineApiModule || null;
            if (window.Weline && typeof window.Weline.load === 'function') {
                api = await window.Weline.load('api');
            }
            if (!api || typeof api.resource !== 'function'
                || typeof api.createStream !== 'function') {
                throw new Error(I18N["可恢复任务 API 不可用。"]);
            }
            return api;
        })();
    }
    return runtimeApiPromise;
}

function toggleSettings() {
    const panel = document.getElementById('settingsPanel');
    panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
}

function appendTextMessage(target, className, prefix, message) {
    target.textContent = '';
    const span = document.createElement('span');
    if (className) {
        span.className = className;
    }
    span.textContent = String(prefix || '') + String(message || '');
    target.appendChild(span);
}

function createChatRequestId() {
    if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
        const bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        return 'chat-' + Array.from(bytes, function(byte) {
            return byte.toString(16).padStart(2, '0');
        }).join('');
    }
    return 'chat-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 14);
}

function streamPayload(event) {
    if (!event) {
        return {};
    }
    if (typeof event.data !== 'string') {
        return event.data && typeof event.data === 'object' ? event.data : {};
    }
    try {
        const data = JSON.parse(event.data);
        return data && typeof data === 'object' ? data : {};
    } catch (error) {
        return {};
    }
}

function setChatProcessing(processing) {
    isProcessing = processing;
    const sendButton = document.getElementById('sendButton');
    const cancelButton = document.getElementById('cancelButton');
    if (sendButton) {
        sendButton.disabled = processing;
    }
    if (cancelButton) {
        cancelButton.disabled = !processing || !activeChatStream;
    }
}

function normalizeChatTaskHandle(value) {
    const handle = value && typeof value === 'object' ? value : null;
    if (!handle || typeof handle.task_id !== 'string' || typeof handle.lease_id !== 'string'
        || typeof handle.stream_channel !== 'string' || handle.task_id === '' || handle.lease_id === '') {
        throw new Error(I18N["后台任务启动响应无效。"]);
    }
    return {
        task_id: handle.task_id,
        lease_id: handle.lease_id,
        stream_channel: handle.stream_channel,
    };
}

function persistActiveChatTask(handle, message) {
    try {
        window.sessionStorage.setItem(CHAT_ACTIVE_TASK_STORAGE_KEY, JSON.stringify({
            task_id: handle.task_id,
            lease_id: handle.lease_id,
            stream_channel: handle.stream_channel,
            message: String(message || ''),
        }));
    } catch (error) {
        // The StreamHandle maintains its own cursor state when storage is available.
    }
}

function readActiveChatTask() {
    try {
        const raw = window.sessionStorage.getItem(CHAT_ACTIVE_TASK_STORAGE_KEY);
        const value = raw ? JSON.parse(raw) : null;
        if (!value || typeof value !== 'object') {
            return null;
        }
        return Object.assign(normalizeChatTaskHandle(value), {
            message: typeof value.message === 'string' ? value.message : '',
        });
    } catch (error) {
        return null;
    }
}

function clearActiveChatTask() {
    try {
        window.sessionStorage.removeItem(CHAT_ACTIVE_TASK_STORAGE_KEY);
    } catch (error) {
        // Storage is optional; no task state is changed by this cleanup.
    }
}

function appendChatChunk(target, chunk) {
    if (!target || !chunk) {
        return;
    }
    target.textContent += String(chunk);
    scrollToBottom();
}

function taskFailureMessage(payload, fallback) {
    if (!payload || typeof payload !== 'object') {
        return fallback;
    }
    return String(payload.reason || payload.message || payload.error_code || fallback);
}

function createWelcomeMessage() {
    const wrapper = document.createElement('div');
    wrapper.className = 'welcome-message';

    const icon = document.createElement('span');
    icon.className = 'w-text';
    icon.dataset.tone = 'primary';
    icon.setAttribute('aria-hidden', 'true');
    icon.textContent = 'AI';

    const title = document.createElement('h4');
    title.textContent = I18N["欢迎使用AI聊天助手！"];

    const copy = document.createElement('p');
    copy.textContent = I18N["请输入您的问题，我会尽力为您解答。"];

    wrapper.appendChild(icon);
    wrapper.appendChild(title);
    wrapper.appendChild(copy);
    return wrapper;
}

function confirmClearChat() {
    return new Promise(function(resolve) {
        const overlay = document.createElement('div');
        overlay.className = 'chat-confirm-overlay';

        const dialog = document.createElement('div');
        dialog.className = 'chat-confirm-dialog';
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');

        const message = document.createElement('p');
        message.textContent = I18N["确定要清空聊天记录吗？"];

        const actions = document.createElement('div');
        actions.className = 'chat-confirm-actions';

        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'btn btn-sm btn-outline-secondary';
        cancel.textContent = I18N["取消"];

        const clear = document.createElement('button');
        clear.type = 'button';
        clear.className = 'btn btn-sm btn-danger';
        clear.textContent = I18N["清空"];

        const close = function(value) {
            overlay.remove();
            resolve(value);
        };

        cancel.addEventListener('click', function() { close(false); });
        clear.addEventListener('click', function() { close(true); });
        overlay.addEventListener('click', function(event) {
            if (event.target === overlay) {
                close(false);
            }
        });

        actions.appendChild(cancel);
        actions.appendChild(clear);
        dialog.appendChild(message);
        dialog.appendChild(actions);
        overlay.appendChild(dialog);
        document.body.appendChild(overlay);
        cancel.focus();
    });
}

async function clearChat() {
    if (!(await confirmClearChat())) {
        return;
    }

    const messagesContainer = document.getElementById('chatMessages');
    messagesContainer.textContent = '';
    messagesContainer.appendChild(createWelcomeMessage());
}

function handleKeyDown(event) {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        sendMessage();
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const chatForm = document.getElementById('chatForm');
    const messageInput = document.getElementById('messageInput');
    const cancelButton = document.getElementById('cancelButton');
    if (chatForm) {
        chatForm.addEventListener('submit', function(event) {
            event.preventDefault();
            sendMessage();
        });
    }
    if (messageInput) {
        messageInput.addEventListener('keydown', handleKeyDown);
    }
    if (cancelButton) {
        cancelButton.addEventListener('click', function() {
            cancelActiveChatTask();
        });
    }
    document.addEventListener('click', function(event) {
        const trigger = event.target.closest('[data-ai-chat-action]');
        if (!trigger) {
            return;
        }
        event.preventDefault();
        const action = trigger.getAttribute('data-ai-chat-action');
        if (action === 'toggle-settings') {
            toggleSettings();
        } else if (action === 'clear-chat') {
            clearChat();
        }
    });

    restoreActiveChatTask();
});

window.addEventListener('pagehide', function() {
    if (activeChatStream) {
        // Detach only. A page leave must never silently cancel detached work.
        activeChatStream.close();
    }
});

async function sendMessage() {
    if (isProcessing) {
        return;
    }

    const input = document.getElementById('messageInput');
    const message = input ? input.value.trim() : '';
    if (!message) {
        return;
    }

    setChatProcessing(true);
    addMessage(message, 'user');
    input.value = '';
    const aiMessageDiv = addMessage('', 'ai');
    const aiContentDiv = aiMessageDiv.querySelector('.message-content');

    try {
        const api = await getRuntimeApi();
        const taskApi = api.resource('runtime_task');
        const modelSelect = document.getElementById('modelSelect');
        const scenarioSelect = document.getElementById('scenarioSelect');
        const task = normalizeChatTaskHandle(await taskApi.start({
            type_code: 'ai.chat_generation',
            input: {
                message: message,
                request_id: createChatRequestId(),
                model_code: modelSelect ? modelSelect.value : null,
                scenario_code: scenarioSelect ? scenarioSelect.value : null,
            },
        }, { silent: true }));

        persistActiveChatTask(task, message);
        await attachChatTask(api, task, aiContentDiv);
    } catch (error) {
        appendTextMessage(
            aiContentDiv,
            'text-danger',
            I18N["请求失败："],
            error && error.message ? error.message : I18N["无法启动后台任务。"]
        );
        activeChatStream = null;
        setChatProcessing(false);
    }

    scrollToBottom();
}

async function restoreActiveChatTask() {
    const task = readActiveChatTask();
    if (!task || isProcessing) {
        setChatProcessing(false);
        return;
    }

    if (task.message) {
        addMessage(task.message, 'user');
    }
    const aiMessageDiv = addMessage('', 'ai');
    const aiContentDiv = aiMessageDiv.querySelector('.message-content');
    setChatProcessing(true);

    try {
        const api = await getRuntimeApi();
        await attachChatTask(api, task, aiContentDiv);
    } catch (error) {
        appendTextMessage(
            aiContentDiv,
            'text-danger',
            I18N["恢复订阅失败："],
            error && error.message ? error.message : I18N["请稍后重试。"]
        );
        activeChatStream = null;
        setChatProcessing(false);
    }
}

async function attachChatTask(api, task, target) {
    const stream = api.createStream(task.stream_channel, {
        task_id: task.task_id,
        lease_id: task.lease_id,
    }, {
        eventTypes: [
            'attempt_reset',
            'chunk',
            'runtime_reset',
            'runtime_snapshot',
            'completed',
            'failed',
            'cancelled',
            'expired',
            'recovery_unsafe',
            'event_backlog_limit',
        ],
        storageKey: 'ai-chat:' + task.task_id,
    });

    let finished = false;
    let sawChunk = false;
    let displayedAttempt = 0;
    activeChatStream = stream;
    setChatProcessing(true);

    const finish = function() {
        if (finished) {
            return;
        }
        finished = true;
        clearActiveChatTask();
        if (activeChatStream === stream) {
            activeChatStream = null;
        }
        setChatProcessing(false);
        scrollToBottom();
    };

    stream.addEventListener('attempt_reset', function(event) {
        const payload = streamPayload(event);
        displayedAttempt = Number(payload.attempt || displayedAttempt || 0);
        sawChunk = false;
        target.textContent = '';
        scrollToBottom();
    });

    stream.addEventListener('runtime_reset', function() {
        sawChunk = false;
        target.textContent = '';
    });

    stream.addEventListener('runtime_snapshot', function(event) {
        const payload = streamPayload(event);
        const response = payload && payload.checkpoint && payload.checkpoint.state
            ? payload.checkpoint.state.response
            : '';
        if (typeof response === 'string' && response !== '') {
            target.textContent = response;
            sawChunk = true;
            scrollToBottom();
        }
    });

    stream.addEventListener('chunk', function(event) {
        const payload = streamPayload(event);
        const attempt = Number(payload.attempt || 0);
        if (attempt && displayedAttempt && attempt < displayedAttempt) {
            return;
        }
        if (attempt > displayedAttempt) {
            displayedAttempt = attempt;
            sawChunk = false;
            target.textContent = '';
        }
        sawChunk = true;
        appendChatChunk(target, payload.chunk || '');
    });

    stream.addEventListener('completed', function(event) {
        const payload = streamPayload(event);
        const response = payload && payload.result ? payload.result.message : '';
        if (!sawChunk && typeof response === 'string') {
            target.textContent = response;
        }
        finish();
    });

    const terminalFailure = function(event, fallback) {
        appendTextMessage(target, 'text-danger', I18N["生成未完成："], taskFailureMessage(streamPayload(event), fallback));
        finish();
    };
    stream.addEventListener('failed', function(event) {
        terminalFailure(event, I18N["生成失败。"]);
    });
    stream.addEventListener('cancelled', function(event) {
        terminalFailure(event, I18N["已取消生成。"]);
    });
    stream.addEventListener('expired', function(event) {
        terminalFailure(event, I18N["客户端失联超时，任务已停止。"]);
    });
    stream.addEventListener('recovery_unsafe', function(event) {
        terminalFailure(event, I18N["任务无法安全恢复。"]);
    });
    stream.addEventListener('event_backlog_limit', function(event) {
        terminalFailure(event, I18N["任务事件已达到资源上限。"]);
    });
    stream.addEventListener('error', function() {
        if (!sawChunk && target.textContent === '') {
            target.textContent = I18N["连接暂时中断，正在重连…"];
        }
    });
    stream.addEventListener('terminal', function() {
        finish();
    });

    await stream.start();
}

async function cancelActiveChatTask() {
    if (!activeChatStream) {
        return;
    }

    const cancelButton = document.getElementById('cancelButton');
    if (cancelButton) {
        cancelButton.disabled = true;
    }
    try {
        await activeChatStream.cancel('user_requested');
    } catch (error) {
        if (cancelButton && isProcessing) {
            cancelButton.disabled = false;
        }
    }
}

function addMessage(content, type) {
    const messagesContainer = document.getElementById('chatMessages');

    // 移除欢迎消息
    const welcome = messagesContainer.querySelector('.welcome-message');
    if (welcome) {
        welcome.remove();
    }

    const messageDiv = document.createElement('div');
    messageDiv.className = `message message-${type}`;

    const avatar = document.createElement('div');
    avatar.className = 'message-avatar';

    const icon = document.createElement('i');
    icon.className = type === 'user' ? 'user' : 'robot';
    avatar.appendChild(icon);

    const contentDiv = document.createElement('div');
    contentDiv.className = 'message-content';
    contentDiv.textContent = String(content || '');

    messageDiv.appendChild(avatar);
    messageDiv.appendChild(contentDiv);

    messagesContainer.appendChild(messageDiv);
    return messageDiv;
}

function scrollToBottom() {
    const messagesContainer = document.getElementById('chatMessages');
    messagesContainer.scrollTop = messagesContainer.scrollHeight;
}
