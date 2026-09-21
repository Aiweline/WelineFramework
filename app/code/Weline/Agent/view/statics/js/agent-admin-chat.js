/**
 * Agent backend chat console — BinQuery sendMessage / getChatHistory only.
 */
(function (global) {
    'use strict';

    function $(sel, root) {
        return (root || document).querySelector(sel);
    }

    function $all(sel, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(sel));
    }

    function toast(type, message) {
        var admin = global.WelineAgentAdmin;
        if (admin && typeof admin.toast === 'function') {
            admin.toast(type, message);
            return;
        }
        console.log('[agent-chat][' + type + ']', message);
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function roleLabel(role) {
        if (role === 'user') return '用户';
        if (role === 'assistant') return '智能体';
        if (role === 'system') return '系统';
        if (role === 'tool') return '工具';
        return role || '未知';
    }

    function roleTone(role) {
        if (role === 'user') return 'info';
        if (role === 'assistant') return 'success';
        if (role === 'system') return 'warning';
        return '';
    }

    function boot() {
        var root = $('#agentChatConsole');
        if (!root) return;
        var admin = global.WelineAgentAdmin;
        if (!admin || typeof admin.call !== 'function') {
            toast('error', 'Weline.Api 不可用，无法聊天');
            return;
        }

        var state = {
            roleCode: '',
            roleName: '',
            roleId: 0,
            sessionId: 0,
            contextId: 'backend-console-' + String(Date.now()),
            sending: false
        };

        var timeline = $('#agentChatTimeline', root);
        var empty = $('#agentChatEmpty', root);
        var chip = $('#agentChatRoleChip', root);
        var input = $('#agentChatInput', root);
        var sendBtn = $('#agentChatSend', root);
        var newBtn = $('#agentChatNewSession', root);
        var statusEl = $('#agentChatStatus', root);
        var openSession = $('#agentChatOpenSession', root);
        var form = $('#agentChatForm', root);
        var sessionViewBase = root.getAttribute('data-session-view-base') || '';

        function setStatus(text) {
            if (statusEl) statusEl.textContent = text || '';
        }

        function setComposerEnabled(enabled) {
            if (input) input.disabled = !enabled || state.sending;
            if (sendBtn) sendBtn.disabled = !enabled || state.sending;
            if (newBtn) newBtn.disabled = !enabled;
        }

        function updateSessionLink() {
            if (!openSession) return;
            if (state.sessionId > 0 && sessionViewBase) {
                var sep = sessionViewBase.indexOf('?') >= 0 ? '&' : '?';
                openSession.href = sessionViewBase + sep + 'id=' + encodeURIComponent(String(state.sessionId));
                openSession.hidden = false;
            } else {
                openSession.hidden = true;
            }
        }

        function renderMessages(messages) {
            if (!timeline) return;
            var list = Array.isArray(messages) ? messages : [];
            timeline.innerHTML = '';
            if (list.length === 0) {
                if (empty) {
                    timeline.appendChild(empty);
                    empty.hidden = false;
                }
                return;
            }
            if (empty) empty.hidden = true;
            list.forEach(function (msg) {
                var role = String((msg && msg.role) || 'assistant');
                var content = String((msg && msg.content) || '');
                var created = String((msg && msg.created_at) || '');
                var article = document.createElement('article');
                article.className = 'w-agent-msg';
                article.setAttribute('data-role', role);
                var tone = roleTone(role);
                article.innerHTML =
                    '<div class="w-agent-msg__meta">' +
                    '<span class="w-agent-chip"' + (tone ? ' data-tone="' + tone + '"' : '') + '>' +
                    escapeHtml(roleLabel(role)) +
                    '</span>' +
                    '<span class="w-text" data-tone="muted">' + escapeHtml(created) + '</span>' +
                    '</div>' +
                    '<div class="w-agent-msg__body"></div>';
                article.querySelector('.w-agent-msg__body').textContent = content;
                timeline.appendChild(article);
            });
            timeline.scrollTop = timeline.scrollHeight;
        }

        function appendLocal(role, content) {
            if (empty) empty.hidden = true;
            var article = document.createElement('article');
            article.className = 'w-agent-msg';
            article.setAttribute('data-role', role);
            var tone = roleTone(role);
            article.innerHTML =
                '<div class="w-agent-msg__meta">' +
                '<span class="w-agent-chip"' + (tone ? ' data-tone="' + tone + '"' : '') + '>' +
                escapeHtml(roleLabel(role)) +
                '</span>' +
                '<span class="w-text" data-tone="muted"></span>' +
                '</div>' +
                '<div class="w-agent-msg__body"></div>';
            article.querySelector('.w-agent-msg__body').textContent = content;
            timeline.appendChild(article);
            timeline.scrollTop = timeline.scrollHeight;
        }

        function selectRole(btn) {
            $all('[data-agent-role-code]', root).forEach(function (el) {
                el.classList.remove('is-selected');
                el.setAttribute('aria-pressed', 'false');
            });
            btn.classList.add('is-selected');
            btn.setAttribute('aria-pressed', 'true');
            state.roleCode = btn.getAttribute('data-agent-role-code') || '';
            state.roleName = btn.getAttribute('data-agent-role-name') || state.roleCode;
            state.roleId = parseInt(btn.getAttribute('data-agent-role-id') || '0', 10) || 0;
            state.sessionId = 0;
            state.contextId = 'backend-console-' + String(state.roleId || state.roleCode) + '-' + String(Date.now());
            if (chip) chip.textContent = state.roleName || state.roleCode;
            setComposerEnabled(!!state.roleCode);
            updateSessionLink();
            renderMessages([]);
            setStatus('');
            if (input) input.focus();
        }

        function loadHistory() {
            if (state.sessionId <= 0) return Promise.resolve();
            return admin.call('getChatHistory', { session_id: state.sessionId, limit: 100 }).then(function (raw) {
                var data = admin.unwrap ? admin.unwrap(raw) : raw;
                if (!data || !data.success) {
                    throw new Error((data && data.msg) || '加载历史失败');
                }
                var payload = data.data || {};
                renderMessages(payload.messages || []);
            });
        }

        function sendMessage(event) {
            if (event) event.preventDefault();
            if (state.sending) return false;
            if (!state.roleCode) {
                toast('error', '请先选择角色');
                return false;
            }
            var text = (input && input.value ? input.value : '').trim();
            if (!text) {
                toast('error', '消息不能为空');
                return false;
            }

            state.sending = true;
            setComposerEnabled(true);
            setStatus('正在思考…');
            appendLocal('user', text);
            if (input) input.value = '';

            admin.call('sendMessage', {
                role_code: state.roleCode,
                message: text,
                session_id: state.sessionId,
                context_id: state.contextId,
                channel: 'web'
            }).then(function (raw) {
                var data = admin.unwrap ? admin.unwrap(raw) : raw;
                if (!data) throw new Error('空响应');
                var payload = data.data || {};
                if (payload.session_id) {
                    state.sessionId = parseInt(payload.session_id, 10) || state.sessionId;
                }
                updateSessionLink();
                if (!data.success) {
                    appendLocal('system', data.msg || '对话失败');
                    toast('error', data.msg || '对话失败');
                    return loadHistory();
                }
                toast('success', data.msg || '回复已生成');
                return loadHistory();
            }).catch(function (error) {
                toast('error', error && error.message ? error.message : '发送失败');
                appendLocal('system', error && error.message ? error.message : '发送失败');
            }).finally(function () {
                state.sending = false;
                setComposerEnabled(!!state.roleCode);
                setStatus('');
            });
            return false;
        }

        $all('[data-agent-role-code]', root).forEach(function (btn) {
            btn.addEventListener('click', function () { selectRole(btn); });
        });

        if (form) form.addEventListener('submit', sendMessage);
        if (newBtn) {
            newBtn.addEventListener('click', function () {
                state.sessionId = 0;
                state.contextId = 'backend-console-' + String(state.roleId || state.roleCode) + '-' + String(Date.now());
                updateSessionLink();
                renderMessages([]);
                setStatus('已开始新会话');
                if (input) input.focus();
            });
        }

        if (input) {
            input.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' && !event.shiftKey) {
                    event.preventDefault();
                    sendMessage(event);
                }
            });
        }

        var defaultCode = root.getAttribute('data-default-role') || '';
        var defaultBtn = defaultCode
            ? $('[data-agent-role-code="' + defaultCode.replace(/"/g, '\\"') + '"]', root)
            : $('[data-agent-role-code]', root);
        if (defaultBtn) selectRole(defaultBtn);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})(window);
