(function (global) {
    'use strict';

    var ROLE_LABEL = {
        customer: '客户',
        merchant: '商家',
        system: '系统'
    };

    function $(root, sel) {
        return root.querySelector(sel);
    }

    async function b2bApi() {
        if (!global.Weline || !global.Weline.Api || typeof global.Weline.Api.resource !== 'function') {
            throw new Error('Weline.Api unavailable');
        }
        return global.Weline.Api.resource('b2b');
    }

    function setStatus(root, text, isError) {
        var el = $(root, '[data-b2b-order-chat-status]');
        if (!el) {
            return;
        }
        el.hidden = !text;
        el.textContent = text || '';
        el.classList.toggle('is-error', !!isError);
    }

    function renderMessages(root, messages) {
        var list = $(root, '[data-b2b-order-chat-messages]');
        if (!list) {
            return;
        }
        list.innerHTML = '';
        (messages || []).forEach(function (msg) {
            var li = document.createElement('li');
            var role = String((msg && msg.sender_role) || 'system');
            li.className = 'b2b-order-chat__msg b2b-order-chat__msg--' + role;
            li.setAttribute('data-sender-role', role);
            var who = document.createElement('span');
            who.className = 'b2b-order-chat__msg-role';
            who.textContent = ROLE_LABEL[role] || role;
            var body = document.createElement('p');
            body.className = 'b2b-order-chat__msg-body';
            body.textContent = String((msg && (msg.body_text || msg.body)) || '');
            li.appendChild(who);
            li.appendChild(body);
            list.appendChild(li);
        });
        list.scrollTop = list.scrollHeight;
    }

    async function openThread(root) {
        var api = await b2bApi();
        var params = {
            order_uuid: String(root.getAttribute('data-order-ref') || ''),
        };
        var role = String(root.getAttribute('data-chat-role') || 'customer');
        var customerId = String(root.getAttribute('data-customer-id') || '').trim();
        if (role === 'merchant' && customerId !== '') {
            params.customer_id = customerId;
        }
        var res = await api['orderChat.open'](params);
        if (!res || !(res.success || res.ok)) {
            throw new Error((res && (res.message || res.error)) || 'open_failed');
        }
        var thread = res.thread || res;
        var threadId = String(thread.thread_id || res.thread_id || '');
        if (threadId === '') {
            throw new Error('missing_thread_id');
        }
        root.setAttribute('data-thread-id', threadId);
        return threadId;
    }

    async function loadMessages(root) {
        var threadId = String(root.getAttribute('data-thread-id') || '');
        if (threadId === '') {
            threadId = await openThread(root);
        }
        var api = await b2bApi();
        var res = await api['orderChat.messages']({ thread_id: threadId, since_id: 0 });
        if (!res || !(res.success || res.ok)) {
            throw new Error((res && (res.message || res.error)) || 'messages_failed');
        }
        renderMessages(root, res.messages || []);
        try {
            await api['orderChat.markSeen']({
                thread_id: threadId,
                role: String(root.getAttribute('data-chat-role') || 'customer')
            });
        } catch (e) {
            // Non-fatal.
        }
    }

    async function sendMessage(root, body) {
        var threadId = String(root.getAttribute('data-thread-id') || '');
        if (threadId === '') {
            threadId = await openThread(root);
        }
        var api = await b2bApi();
        var res = await api['orderChat.send']({
            thread_id: threadId,
            body: body,
            role: String(root.getAttribute('data-chat-role') || 'customer')
        });
        if (!res || !(res.success || res.ok)) {
            throw new Error((res && (res.message || res.error)) || 'send_failed');
        }
        await loadMessages(root);
    }

    async function toggle(root, forceOpen) {
        var panel = $(root, '[data-b2b-order-chat-panel]');
        var toggleBtn = $(root, '[data-b2b-order-chat-toggle]');
        if (!panel || !toggleBtn) {
            return;
        }
        var open = forceOpen === true ? true : (forceOpen === false ? false : panel.hidden);
        panel.hidden = !open;
        toggleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        root.classList.toggle('is-open', open);
        var closedLbl = $(root, '.b2b-order-chat__toggle-closed');
        var openLbl = $(root, '.b2b-order-chat__toggle-open');
        if (closedLbl) {
            closedLbl.hidden = !!open;
        }
        if (openLbl) {
            openLbl.hidden = !open;
        }
        if (!open) {
            return;
        }
        setStatus(root, '加载中…', false);
        try {
            await loadMessages(root);
            setStatus(root, '', false);
        } catch (err) {
            setStatus(root, String((err && err.message) || err || '加载失败'), true);
        }
    }

    function bindRoot(root) {
        if (!root || root.getAttribute('data-b2b-order-chat-bound') === '1') {
            return;
        }
        root.setAttribute('data-b2b-order-chat-bound', '1');
        var toggleBtn = $(root, '[data-b2b-order-chat-toggle]');
        var form = $(root, '[data-b2b-order-chat-form]');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', function (ev) {
                ev.preventDefault();
                toggle(root);
            });
        }
        if (form) {
            form.addEventListener('submit', function (ev) {
                ev.preventDefault();
                var input = $(root, '[data-b2b-order-chat-input]');
                var body = input ? String(input.value || '').trim() : '';
                if (body === '') {
                    return;
                }
                var sendBtn = $(root, '[data-b2b-order-chat-send]');
                if (sendBtn) {
                    sendBtn.disabled = true;
                }
                setStatus(root, '发送中…', false);
                sendMessage(root, body).then(function () {
                    if (input) {
                        input.value = '';
                    }
                    setStatus(root, '', false);
                }).catch(function (err) {
                    setStatus(root, String((err && err.message) || err || '发送失败'), true);
                }).finally(function () {
                    if (sendBtn) {
                        sendBtn.disabled = false;
                    }
                });
            });
        }
        var openAttr = String(root.getAttribute('data-open') || '');
        if (openAttr === '1' || openAttr === 'true') {
            toggle(root, true);
        }
    }

    function boot(scope) {
        var root = scope && scope.querySelectorAll ? scope : document;
        root.querySelectorAll('[data-b2b-order-chat-accordion]').forEach(bindRoot);
    }

    document.addEventListener('click', function (ev) {
        var opener = ev.target && ev.target.closest
            ? ev.target.closest('[data-b2b-open-order-chat]')
            : null;
        if (!opener) {
            return;
        }
        var orderRef = String(opener.getAttribute('data-b2b-open-order-chat') || '').trim();
        if (orderRef === '') {
            return;
        }
        var accordion = document.querySelector(
            '[data-b2b-order-chat-accordion][data-order-ref="' + orderRef.replace(/"/g, '') + '"]'
        );
        if (!accordion) {
            return;
        }
        ev.preventDefault();
        accordion.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        toggle(accordion, true);
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { boot(document); });
    } else {
        boot(document);
    }
    document.addEventListener('weline:account-sidebar-content-loaded', function (ev) {
        boot((ev && ev.detail && ev.detail.root) || document);
    });

    global.WelineB2BOrderChat = {
        boot: boot,
        toggle: toggle
    };
})(typeof window !== 'undefined' ? window : this);
