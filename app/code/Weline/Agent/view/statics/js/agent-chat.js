/**
 * 通用智能体聊天页（Weline_Agent）
 *
 * 会话切换（服务端持久化）、角色/模型/项目切换、agent.chat_turn
 * 可恢复任务流式输出（复用 runtime_task SSE）。
 * 渲染层：安全 DOM markdown（不经 innerHTML 注入模型内容）。
 */
(function () {
    'use strict';

    const ACTIVE_TASK_KEY = 'weline.agent.chat.active_task';
    const CTX_KEY = 'weline.agent.chat.context_id';

    function getConfig() {
        const el = document.getElementById('agent-chat-config');
        if (!el) return null;
        try { return JSON.parse(el.textContent); } catch (e) { return null; }
    }

    function getContextId() {
        let id = localStorage.getItem(CTX_KEY);
        if (!id) {
            id = 'ctx_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 10);
            localStorage.setItem(CTX_KEY, id);
        }
        return id;
    }

    function apiPost(path, body) {
        return fetch(path, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(body || {}),
            credentials: 'same-origin',
        }).then(function (r) { return r.json(); });
    }

    function apiGet(path) {
        return fetch(path + (path.indexOf('?') >= 0 ? '&' : '?') + '_=' + Date.now(), {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
        }).then(function (r) { return r.json(); });
    }

    let runtimeApiPromise = null;
    function getRuntimeApi() {
        if (!runtimeApiPromise) {
            runtimeApiPromise = (async function () {
                let api = window.WelineApiModule || null;
                if (window.Weline && typeof window.Weline.load === 'function') {
                    api = await window.Weline.load('api');
                }
                if (!api || typeof api.resource !== 'function' || typeof api.createStream !== 'function') {
                    const diag = api
                        ? 'type=' + typeof api + ' resource=' + typeof api.resource + ' createStream=' + typeof api.createStream
                        : 'api=null';
                    throw new Error('runtime task api unavailable (' + diag + ')');
                }
                return api;
            })();
        }
        return runtimeApiPromise;
    }

    function streamPayload(event) {
        if (!event) return {};
        if (typeof event.data !== 'string') {
            return event.data && typeof event.data === 'object' ? event.data : {};
        }
        try {
            const data = JSON.parse(event.data);
            return data && typeof data === 'object' ? data : {};
        } catch (e) {
            return {};
        }
    }

    function normalizeTaskHandle(value) {
        const handle = value && typeof value === 'object' ? value : null;
        if (!handle || typeof handle.task_id !== 'string' || typeof handle.lease_id !== 'string'
            || typeof handle.stream_channel !== 'string'
            || handle.task_id === '' || handle.lease_id === '' || handle.stream_channel === '') {
            throw new Error('invalid task handle');
        }
        return {
            task_id: handle.task_id,
            lease_id: handle.lease_id,
            stream_channel: handle.stream_channel,
        };
    }

    /* ================= markdown → DOM（安全：模型内容不走 innerHTML） ================= */

    function el(tag, cls, text) {
        const node = document.createElement(tag);
        if (cls) node.className = cls;
        if (text != null) node.textContent = text;
        return node;
    }

    function safeUrl(url) {
        const u = String(url || '').trim();
        if (/^(https?:|mailto:|\/|#)/i.test(u) && !/^javascript:/i.test(u)) return u;
        return '';
    }

    // 行内：`code` **bold** *italic* ~~del~~ [text](url)
    function appendInline(parent, text) {
        const re = /(`[^`\n]+`)|(\*\*[^*\n]+\*\*)|(\*[^*\n]+\*)|(~~[^~\n]+~~)|(\[[^\]\n]+\]\([^)\n]+\))/g;
        let last = 0, m;
        while ((m = re.exec(text))) {
            if (m.index > last) parent.appendChild(document.createTextNode(text.slice(last, m.index)));
            const tok = m[0];
            if (tok[0] === '`') {
                parent.appendChild(el('code', null, tok.slice(1, -1)));
            } else if (tok.startsWith('**')) {
                const b = el('strong'); appendInline(b, tok.slice(2, -2)); parent.appendChild(b);
            } else if (tok[0] === '*') {
                const i = el('em'); appendInline(i, tok.slice(1, -1)); parent.appendChild(i);
            } else if (tok.startsWith('~~')) {
                const d = el('del'); appendInline(d, tok.slice(2, -2)); parent.appendChild(d);
            } else {
                const mm = /^\[([^\]]+)\]\(([^)]+)\)$/.exec(tok);
                if (mm) {
                    const a = el('a', null, mm[1]);
                    const href = safeUrl(mm[2]);
                    if (href) { a.href = href; a.target = '_blank'; a.rel = 'noopener noreferrer'; }
                    else a.removeAttribute('href');
                    parent.appendChild(a);
                } else {
                    parent.appendChild(document.createTextNode(tok));
                }
            }
            last = m.index + tok.length;
        }
        if (last < text.length) parent.appendChild(document.createTextNode(text.slice(last)));
    }

    function appendCodeBlock(parent, code, lang) {
        const pre = el('pre');
        const head = el('div', 'ac-code-head');
        head.appendChild(el('span', null, lang || 'code'));
        const copy = el('button', 'ac-code-copy', 'copy');
        copy.type = 'button';
        copy.addEventListener('click', function () {
            navigator.clipboard && navigator.clipboard.writeText(code).then(function () {
                copy.textContent = 'copied';
                setTimeout(function () { copy.textContent = 'copy'; }, 1200);
            }).catch(function () {});
        });
        head.appendChild(copy);
        pre.appendChild(head);
        const codeEl = el('code');
        codeEl.textContent = code;
        pre.appendChild(codeEl);
        parent.appendChild(pre);
    }

    function isTableRow(line) { return /^\s*\|.*\|\s*$/.test(line); }
    function isTableSep(line) { return /^\s*\|?[\s:|-]+\|[\s:|-]*$/.test(line) && line.indexOf('-') >= 0; }

    function appendTable(parent, rows) {
        const table = el('table');
        const thead = el('thead'), tbody = el('tbody');
        rows.forEach(function (cells, i) {
            const tr = el('tr');
            cells.forEach(function (cell) {
                const td = el(i === 0 ? 'th' : 'td');
                appendInline(td, cell.trim());
                tr.appendChild(td);
            });
            (i === 0 ? thead : tbody).appendChild(tr);
        });
        table.appendChild(thead);
        if (tbody.childNodes.length) table.appendChild(tbody);
        parent.appendChild(table);
    }

    function splitTableRow(line) {
        return line.trim().replace(/^\||\|$/g, '').split('|').map(function (c) { return c.trim(); });
    }

    // 主入口：markdown 文本 → 追加到 container（返回是否有内容）
    function renderMarkdown(container, text) {
        const src = String(text == null ? '' : text);
        const lines = src.replace(/\r\n?/g, '\n').split('\n');
        let i = 0, listStack = null;

        const closeLists = function () {
            if (listStack) { while (listStack.firstChild) container.appendChild(listStack.firstChild); listStack = null; }
        };

        while (i < lines.length) {
            const line = lines[i];

            // fenced code
            const fence = /^```(\w*)\s*$/.exec(line);
            if (fence) {
                closeLists();
                const buf = [];
                i++;
                while (i < lines.length && !/^```\s*$/.test(lines[i])) { buf.push(lines[i]); i++; }
                i++; // skip closing fence (or EOF)
                appendCodeBlock(container, buf.join('\n'), fence[1]);
                continue;
            }

            // heading
            const h = /^(#{1,4})\s+(.*)$/.exec(line);
            if (h) {
                closeLists();
                const tag = 'h' + Math.min(h[1].length + 1, 4);
                const node = el(tag);
                appendInline(node, h[2]);
                container.appendChild(node);
                i++; continue;
            }

            // hr
            if (/^\s*(-{3,}|\*{3,}|_{3,})\s*$/.test(line)) {
                closeLists();
                container.appendChild(el('hr'));
                i++; continue;
            }

            // blockquote（连续 > 行合并为一个 blockquote）
            if (/^\s*>/.test(line)) {
                closeLists();
                const bq = el('blockquote');
                const buf = [];
                while (i < lines.length && /^\s*>/.test(lines[i])) {
                    buf.push(lines[i].replace(/^\s*>\s?/, ''));
                    i++;
                }
                appendInline(bq, buf.join('\n'));
                container.appendChild(bq);
                continue;
            }

            // table：当前行 + 下一行分隔行
            if (isTableRow(line) && i + 1 < lines.length && isTableSep(lines[i + 1])) {
                closeLists();
                const rows = [splitTableRow(line)];
                i += 2;
                while (i < lines.length && isTableRow(lines[i])) { rows.push(splitTableRow(lines[i])); i++; }
                appendTable(container, rows);
                continue;
            }

            // list
            const li = /^\s*([-*+]|\d+[.)])\s+(.*)$/.exec(line);
            if (li) {
                const ordered = /\d/.test(li[1]);
                if (!listStack || listStack.tagName !== (ordered ? 'OL' : 'UL')) {
                    closeLists();
                    listStack = el(ordered ? 'ol' : 'ul');
                }
                const item = el('li');
                appendInline(item, li[2]);
                listStack.appendChild(item);
                i++; continue;
            }

            // blank
            if (/^\s*$/.test(line)) {
                closeLists();
                i++; continue;
            }

            // paragraph：连续非空行合并（遇到块级起始停止）
            closeLists();
            const buf = [];
            while (i < lines.length) {
                const l = lines[i];
                if (/^\s*$/.test(l)) break;
                if (/^```/.test(l) || /^(#{1,4})\s/.test(l) || /^\s*>/.test(l)
                    || /^\s*(-{3,}|\*{3,}|_{3,})\s*$/.test(l)
                    || /^\s*([-*+]|\d+[.)])\s+/.test(l)
                    || (isTableRow(l) && i + 1 < lines.length && isTableSep(lines[i + 1]))) break;
                buf.push(l);
                i++;
            }
            const p = el('p');
            appendInline(p, buf.join('\n'));
            container.appendChild(p);
        }
        closeLists();
        return src.trim() !== '';
    }

    /* ================= 应用 ================= */

    const cfg = getConfig() || {};
    const I18N = cfg.i18n || {};
    const t = function (key, fallback) { return I18N[key] || fallback || key; };

    function AgentChatApp(root) {
        this.root = root;
        this.apiBase = cfg.apiBase || '/agent/api/v1/chat';
        this.roles = cfg.roles || [];
        this.models = cfg.models || [];
        this.projects = cfg.projects || [];

        this.$col = root.querySelector('#agentMessages .ac-col');
        this.$msgs = root.querySelector('#agentMessages');
        this.$welcome = root.querySelector('#agentWelcome');
        this.$input = root.querySelector('#agentInput');
        this.$send = root.querySelector('#agentSend');
        this.$stop = root.querySelector('#agentStop');
        this.$project = root.querySelector('#agentProjectSelect');
        this.$role = root.querySelector('#agentRoleSelect');
        this.$model = root.querySelector('#agentModelSelect');
        this.$badge = root.querySelector('#agentSessionBadge');
        this.$sessionList = root.querySelector('#agentSessionList');
        this.$newChat = root.querySelector('#agentNewChat');
        this.$chips = root.querySelector('#agentChips');
        this.$welcomeRole = root.querySelector('#agentWelcomeRole');

        this.sessionId = 0;
        this.sessionTitle = '';
        this.roleId = this.roles.length ? this.roles[0].id : 0;
        this.roleCode = this.roles.length ? this.roles[0].code : '';
        this.projectId = 0;
        this.modelCode = this.models.length ? this.models[0].code : '';
        this.sending = false;
        this.taskHandle = null;
        this.activeStream = null;
        this.activeMd = null;
        this.activeText = '';
        this._mdFrame = 0;
        this._resumeSessionId = 0;
        this._resumePlaceholder = null;

        this.init();
    }

    AgentChatApp.prototype.init = function () {
        if (this.$project) {
            this.fillSelect(this.$project, this.projects, 'name', 'id', 'projectId');
            this.projectId = parseInt(this.projectId, 10) || 0;
            this.$project.addEventListener('change', this.onProjectChange.bind(this));
        }
        this.fillSelect(this.$role, this.roles, 'name', 'role_id', 'roleId');
        this.fillSelect(this.$model, this.models, 'name', 'code', 'modelCode');
        this.onRoleChange();
        this.$role.addEventListener('change', this.onRoleChange.bind(this));
        this.$model.addEventListener('change', this.onModelChange.bind(this));
        this.$send.addEventListener('click', this.send.bind(this));
        this.$stop.addEventListener('click', this.stop.bind(this));
        this.$input.addEventListener('keydown', this.onKeydown.bind(this));
        this.$input.addEventListener('input', this.autosize.bind(this));
        this.$newChat.addEventListener('click', this.newChat.bind(this));
        this.$sessionList.addEventListener('click', this.onSessionClick.bind(this));
        this.$tasksBtn = root.querySelector('#agentTasksBtn');
        this.$tasksPanel = root.querySelector('#agentTasksPanel');
        this.$tasksList = root.querySelector('#agentTasksList');
        this.$tasksCount = root.querySelector('#agentTasksCount');
        this.$tasksRefresh = root.querySelector('#agentTasksRefresh');
        this.taskApiBase = '/agent/api/v1/task';
        this._tasksTimer = null;
        if (this.$tasksBtn) {
            this.$tasksBtn.addEventListener('click', this.toggleTasksPanel.bind(this));
        }
        if (this.$tasksRefresh) {
            this.$tasksRefresh.addEventListener('click', this.loadTasks.bind(this));
        }
        if (this.$tasksList) {
            this.$tasksList.addEventListener('click', this.onTaskAction.bind(this));
        }
        if (this.$chips) {
            this.$chips.addEventListener('click', this.onChipClick.bind(this));
        }
        this.renderWelcomeRole();
        this.renderChips();
        this.loadSessions();
        this.loadTasksBadge();
        this.tryResumeActiveTask();
    };

    AgentChatApp.prototype.fillSelect = function (sel, items, labelKey, ...valueKeys) {
        sel.innerHTML = '';
        items.forEach(function (item) {
            const opt = document.createElement('option');
            opt.value = item[valueKeys[0]];
            opt.textContent = item[labelKey] || item[valueKeys[0]];
            if (item.is_default) opt.selected = true;
            sel.appendChild(opt);
        });
        if (valueKeys.length > 1) {
            this[valueKeys[1]] = sel.value;
        }
    };

    AgentChatApp.prototype.renderWelcomeRole = function () {
        if (!this.$welcomeRole) return;
        this.$welcomeRole.innerHTML = '';
        const r = this.roles.find(function (x) { return String(x.id) === String(this.roleId); }.bind(this))
            || this.roles[0];
        this.$welcomeRole.appendChild(document.createTextNode('当前角色：'));
        const b = el('b', null, r ? r.name : '—');
        this.$welcomeRole.appendChild(b);
    };

    AgentChatApp.prototype.renderChips = function () {
        if (!this.$chips) return;
        const prompts = [
            '介绍一下你能做什么',
            '帮我分析一下这个项目的架构',
            '查看最近的运行状态',
        ];
        prompts.forEach(function (p) {
            const chip = el('button', 'ac-chip', p);
            chip.type = 'button';
            chip.dataset.prompt = p;
            this.$chips.appendChild(chip);
        }.bind(this));
    };

    AgentChatApp.prototype.onChipClick = function (e) {
        const chip = e.target.closest('.ac-chip');
        if (!chip) return;
        const p = chip.dataset.prompt || '';
        if (!p) return;
        this.$input.value = p;
        this.autosize();
        this.send();
    };

    AgentChatApp.prototype.autosize = function () {
        const ta = this.$input;
        ta.style.height = 'auto';
        ta.style.height = Math.min(ta.scrollHeight, 200) + 'px';
    };

    AgentChatApp.prototype.onProjectChange = function () {
        this.projectId = parseInt(this.$project.value, 10) || 0;
        // 项目在会话创建时绑定：切换项目即开启新会话，避免跨项目污染
        if (this.sessionId) {
            this.newChat();
        }
        this.loadTasksBadge();
        if (this.$tasksPanel && !this.$tasksPanel.hidden) {
            this.loadTasks();
        }
    };

    /* ---------- 团队任务 / 审批面板 ---------- */

    AgentChatApp.prototype.taskStatusLabel = function (s) {
        const map = {
            queued: '排队中', running: '执行中', awaiting_approval: '待审批',
            done: '已完成', failed: '失败', cancelled: '已取消',
        };
        return map[s] || s || '—';
    };

    AgentChatApp.prototype.taskStatusClass = function (s) {
        if (s === 'awaiting_approval') return 'is-awaiting';
        if (s === 'done') return 'is-done';
        if (s === 'failed') return 'is-failed';
        return 'is-' + (s || 'queued');
    };

    AgentChatApp.prototype.loadTasksBadge = function () {
        const self = this;
        const qs = this.projectId > 0 ? ('?project_id=' + this.projectId + '&status=awaiting_approval') : '?status=awaiting_approval';
        apiGet(this.taskApiBase + '/tasks' + qs).then(function (res) {
            const n = res && res.success && Array.isArray(res.tasks) ? res.tasks.length : 0;
            if (self.$tasksCount) {
                self.$tasksCount.textContent = String(n);
                self.$tasksCount.hidden = n <= 0;
            }
        }).catch(function () {});
    };

    AgentChatApp.prototype.toggleTasksPanel = function () {
        if (!this.$tasksPanel) return;
        const show = this.$tasksPanel.hidden;
        this.$tasksPanel.hidden = !show;
        if (show) {
            this.loadTasks();
            const self = this;
            if (this._tasksTimer) clearInterval(this._tasksTimer);
            this._tasksTimer = setInterval(function () { self.loadTasks(); self.loadTasksBadge(); }, 15000);
        } else if (this._tasksTimer) {
            clearInterval(this._tasksTimer);
            this._tasksTimer = null;
        }
    };

    AgentChatApp.prototype.loadTasks = function () {
        const self = this;
        const qs = this.projectId > 0 ? ('?project_id=' + this.projectId) : '';
        apiGet(this.taskApiBase + '/tasks' + qs).then(function (res) {
            if (!self.$tasksList) return;
            const tasks = res && res.success && Array.isArray(res.tasks) ? res.tasks : [];
            if (!tasks.length) {
                self.$tasksList.innerHTML = '<div class="ac-tasks-empty">暂无团队任务</div>';
                return;
            }
            self.$tasksList.innerHTML = '';
            tasks.forEach(function (t) {
                const box = el('div', 'ac-task');
                box.appendChild(el('div', 'ac-task-prompt', '#' + t.task_id + ' ' + (t.prompt || '')));
                const meta = el('div', 'ac-task-meta');
                meta.appendChild(el('span', 'ac-task-status ' + self.taskStatusClass(t.status), self.taskStatusLabel(t.status)));
                meta.appendChild(el('span', null, 'by ' + (t.created_by_type || 'system')));
                if (t.status === 'awaiting_approval' || t.status === 'queued') {
                    const actions = el('div', 'ac-task-actions');
                    const ok = el('button', 'ac-approve', '批准');
                    ok.type = 'button'; ok.dataset.taskId = t.task_id; ok.dataset.action = 'approve';
                    const no = el('button', null, '拒绝');
                    no.type = 'button'; no.dataset.taskId = t.task_id; no.dataset.action = 'reject';
                    actions.appendChild(ok); actions.appendChild(no);
                    meta.appendChild(actions);
                }
                box.appendChild(meta);
                self.$tasksList.appendChild(box);
            });
        }).catch(function () {});
    };

    AgentChatApp.prototype.onTaskAction = function (e) {
        const btn = e.target.closest('button[data-action]');
        if (!btn) return;
        const taskId = parseInt(btn.dataset.taskId, 10) || 0;
        const action = btn.dataset.action;
        if (taskId <= 0) return;
        btn.disabled = true;
        const path = action === 'approve' ? '/approve' : '/reject';
        const self = this;
        apiPost(this.taskApiBase + path, { task_id: taskId }).then(function (res) {
            if (res && res.success) {
                self.loadTasks();
                self.loadTasksBadge();
            } else {
                btn.disabled = false;
                alert((res && res.msg) || '操作失败');
            }
        }).catch(function () {
            btn.disabled = false;
            alert('网络错误');
        });
    };

    AgentChatApp.prototype.onRoleChange = function () {
        const r = this.roles.find(function (x) { return String(x.id) === this.$role.value; }.bind(this));
        this.roleId = r ? r.id : 0;
        this.roleCode = r ? r.code : '';
        this.renderWelcomeRole();
    };

    AgentChatApp.prototype.onModelChange = function () {
        this.modelCode = this.$model.value;
    };

    AgentChatApp.prototype.onKeydown = function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            this.send();
        }
    };

    AgentChatApp.prototype.newChat = function () {
        this.sessionId = 0;
        this.sessionTitle = '';
        this.clearMessages();
        this.$badge.textContent = '';
        this.renderSessionListActive();
        this.$input.focus();
    };

    AgentChatApp.prototype.clearMessages = function () {
        if (this.$col) this.$col.innerHTML = '';
        if (this.$welcome) this.$col.appendChild(this.$welcome);
    };

    AgentChatApp.prototype.loadSessions = function () {
        var self = this;
        apiGet(this.apiBase + '/sessions?channel=web&context_id=' + encodeURIComponent(getContextId()) + '&limit=30')
            .then(function (res) {
                if (!res || !res.success) return;
                const list = (res.data && res.data.sessions) || [];
                self.$sessionList.innerHTML = '';
                if (!list.length) {
                    self.$sessionList.appendChild(el('div', 'ac-session', '—'));
                    return;
                }
                list.forEach(function (s) {
                    const item = el('div', 'ac-session');
                    item.dataset.sessionId = s.session_id || s.id;
                    const sProjectId = parseInt(s.project_id, 10) || 0;
                    const projectName = sProjectId > 0
                        ? ((self.projects || []).find(function (p) { return p.id === sProjectId; }) || {}).name
                        : '';
                    item.appendChild(el('div', null, (s.title || ('#' + (s.session_id || s.id)))));
                    if (projectName) item.appendChild(el('div', 'ac-session-proj', projectName));
                    self.$sessionList.appendChild(item);
                });
                self.renderSessionListActive();
            }).catch(function () { /* 会话列表加载失败不阻塞聊天 */ });
    };

    AgentChatApp.prototype.renderSessionListActive = function () {
        const items = this.$sessionList.querySelectorAll('.ac-session[data-session-id]');
        items.forEach(function (node) {
            node.classList.toggle('active', String(node.dataset.sessionId) === String(this.sessionId));
        }.bind(this));
    };

    AgentChatApp.prototype.onSessionClick = function (e) {
        const el = e.target.closest('.ac-session[data-session-id]');
        if (!el) return;
        const sid = parseInt(el.dataset.sessionId, 10) || 0;
        if (!sid || sid === this.sessionId) return;
        this.openSession(sid);
    };

    AgentChatApp.prototype.openSession = function (sessionId) {
        var self = this;
        apiGet(this.apiBase + '/history?session_id=' + sessionId + '&limit=100')
            .then(function (res) {
                if (!res || !res.success) return;
                self.sessionId = sessionId;
                self.clearMessages();
                const session = res.data && res.data.session;
                self.$badge.textContent = session ? ('#' + (session.session_id || session.id)) : '';
                // 同步会话绑定的项目，保证 @派单与项目边界校验一致
                const sidProjectId = session ? parseInt(session.project_id, 10) || 0 : 0;
                if (self.$project && self.projectId !== sidProjectId) {
                    self.projectId = sidProjectId;
                    self.$project.value = String(sidProjectId);
                }
                const messages = (res.data && res.data.messages) || [];
                messages.forEach(function (m) {
                    const role = m.role === 'user' ? 'user' : 'assistant';
                    self.appendMessage(role, m.content || '', {
                        meta: m.agent_code || m.model_code ? [m.agent_code, m.model_code].filter(Boolean).join(' / ') : '',
                    });
                });
                self.renderSessionListActive();
                self.scrollBottom();
            }).catch(function () {});
    };

    AgentChatApp.prototype.scrollBottom = function () {
        // 目标是内部滚动容器 #agentMessages
        this.$msgs.scrollTop = this.$msgs.scrollHeight;
    };

    AgentChatApp.prototype.appendMessage = function (role, content, opts) {
        opts = opts || {};
        const wrap = el('div', 'ac-msg ac-msg-' + role);
        if (role === 'user') {
            wrap.appendChild(el('div', 'ac-bubble-user', content));
        } else {
            const head = el('div', 'ac-msg-head');
            head.appendChild(el('div', 'ac-avatar', 'W'));
            const meta = el('span', 'ac-msg-meta', opts.meta || '');
            head.appendChild(meta);
            wrap.appendChild(head);
            const md = el('div', 'ac-md');
            renderMarkdown(md, content);
            wrap.appendChild(md);
        }
        this.$col.appendChild(wrap);
        this.scrollBottom();
        return wrap;
    };

    AgentChatApp.prototype.appendEvent = function (kind, labelHtml) {
        // labelHtml: {text, code} —— code 渲染为等宽标签
        const row = el('div', 'ac-event ac-event--' + (kind || 'tool'));
        row.appendChild(el('span', 'ac-event-dot'));
        const body = el('span');
        body.appendChild(document.createTextNode(labelHtml.text || ''));
        if (labelHtml.code) body.appendChild(el('code', null, labelHtml.code));
        row.appendChild(body);
        this.$col.appendChild(row);
        this.scrollBottom();
        return row;
    };

    AgentChatApp.prototype.beginAssistantStream = function () {
        const wrap = el('div', 'ac-msg ac-msg-assistant');
        const head = el('div', 'ac-msg-head');
        head.appendChild(el('div', 'ac-avatar', 'W'));
        head.appendChild(el('span', 'ac-msg-meta', t('agentTag', 'Agent')));
        wrap.appendChild(head);
        const md = el('div', 'ac-md');
        wrap.appendChild(md);
        const caret = el('span', 'ac-caret');
        md.appendChild(caret);
        this.$col.appendChild(wrap);
        this.scrollBottom();
        this.activeMd = md;
        this.activeCaret = caret;
        this.activeText = '';
        return md;
    };

    AgentChatApp.prototype.updateAssistantStream = function () {
        if (!this.activeMd) return;
        // 重建 markdown；caret 保留在末尾
        const caret = this.activeCaret;
        this.activeMd.innerHTML = '';
        renderMarkdown(this.activeMd, this.activeText);
        if (caret) this.activeMd.appendChild(caret);
        this.scrollBottom();
    };

    AgentChatApp.prototype.flushAssistantStream = function () {
        if (this._mdFrame) { cancelAnimationFrame(this._mdFrame); this._mdFrame = 0; }
        this.updateAssistantStream();
        if (this.activeCaret && this.activeCaret.parentNode) {
            this.activeCaret.parentNode.removeChild(this.activeCaret);
        }
        this.activeMd = null;
        this.activeCaret = null;
    };

    AgentChatApp.prototype.queueStreamRender = function () {
        if (this._mdFrame) return;
        this._mdFrame = requestAnimationFrame(() => {
            this._mdFrame = 0;
            this.updateAssistantStream();
        });
    };

    AgentChatApp.prototype.send = function () {
        const text = (this.$input.value || '').trim();
        if (!text || this.sending) return;

        this.appendMessage('user', text);
        this.$input.value = '';
        this.autosize();
        this.sending = true;
        this.$send.disabled = true;
        this.$stop.classList.add('show');

        var self = this;
        this.startChatTurn(text)
            .catch(function (err) {
                self.sending = false;
                self.$send.disabled = false;
                self.$stop.classList.remove('show');
                self.appendMessage('assistant', t('sendFailed', 'Send failed: ') + (err && err.message ? err.message : String(err)));
            });
        // sending 保持到 finishTurn（流真正结束）
    };

    AgentChatApp.prototype.stop = function () {
        try {
            if (this.activeStream && typeof this.activeStream.cancel === 'function') {
                this.activeStream.cancel('user_requested');
            }
        } catch (e) {}
    };

    AgentChatApp.prototype.startChatTurn = function (message) {
        var self = this;
        return getRuntimeApi().then(function (api) {
            const input = {
                message: message,
                request_id: 'agent_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 10),
                channel: 'web',
                context_id: getContextId(),
            };
            if (self.sessionId) input.session_id = self.sessionId;
            if (self.projectId) input.project_id = self.projectId;
            if (self.roleId) input.role_id = self.roleId;
            if (self.roleCode) input.role_code = self.roleCode;
            if (self.modelCode) input.model_code = self.modelCode;

            return api.resource('runtime_task').start({
                type_code: 'agent.chat_turn',
                input: input,
            }, { silent: true }).then(function (raw) {
                const handle = normalizeTaskHandle(raw);
                self.taskHandle = handle;
                try {
                    sessionStorage.setItem(ACTIVE_TASK_KEY, JSON.stringify({
                        task_id: handle.task_id,
                        lease_id: handle.lease_id,
                        stream_channel: handle.stream_channel,
                        message: message,
                    }));
                } catch (e) {}
                self.bindStream(api, handle);
                return handle;
            });
        });
    };

    AgentChatApp.prototype.bindStream = function (api, handle) {
        var self = this;
        const stream = api.createStream(handle.stream_channel, {
            task_id: handle.task_id,
            lease_id: handle.lease_id,
        }, {
            eventTypes: [
                'attempt_reset', 'chunk', 'agent_info', 'tool_call', 'tool_result',
                'team_dispatch', 'done', 'completed', 'failed', 'cancelled',
                'expired', 'recovery_unsafe', 'event_backlog_limit', 'terminal',
            ],
            storageKey: 'agent-chat:' + handle.task_id,
        });
        this.activeStream = stream;

        let finished = false;

        const onPayload = function (fn) {
            return function (event) { fn(streamPayload(event)); };
        };

        stream.addEventListener('attempt_reset', function () {
            self.activeMd = null;
            self.activeText = '';
        });
        stream.addEventListener('chunk', onPayload(function (p) {
            const chunk = p.chunk || '';
            if (!chunk) return;
            if (!self.activeMd) self.beginAssistantStream();
            self.activeText += chunk;
            self.queueStreamRender();
        }));
        stream.addEventListener('agent_info', onPayload(function (p) {
            if (p.session_id) {
                self.sessionId = p.session_id;
                self.$badge.textContent = '#' + self.sessionId;
                self.rememberActiveTaskSession(p.session_id);
            }
            self.appendEvent('agent', { text: (p.role_name || p.agent_code || 'agent') + ' ' });
        }));
        stream.addEventListener('tool_call', onPayload(function (p) {
            self.appendEvent('tool', { text: t('toolCall', 'tool') + ' ', code: p.tool_name || '' });
        }));
        stream.addEventListener('tool_result', onPayload(function () {
            self.appendEvent('tool', { text: t('toolResult', 'tool result') });
        }));
        stream.addEventListener('team_dispatch', onPayload(function (p) {
            self.appendEvent('dispatch', { text: t('teamDispatch', 'dispatch') + ' ', code: (p.line || '') });
        }));
        stream.addEventListener('done', function () {});
        stream.addEventListener('completed', onPayload(function (p) {
            const result = p.result || {};
            if (result.session_id) {
                self.sessionId = result.session_id;
                self.$badge.textContent = '#' + self.sessionId;
            }
            self.flushAssistantStream();
            if (!self.activeMd && typeof result.message === 'string' && result.message !== ''
                && !(self.activeText || '').trim()) {
                self.appendMessage('assistant', result.message);
            }
            finished = true;
            self.finishTurn({});
        }));
        stream.addEventListener('failed', onPayload(function (p) {
            self.flushAssistantStream();
            self.appendMessage('assistant', t('errorPrefix', 'Error: ') + ((p && (p.error || p.message)) || 'generation failed'));
            finished = true;
            self.finishTurn({});
        }));
        stream.addEventListener('cancelled', function () {
            self.flushAssistantStream();
            self.appendMessage('assistant', t('cancelled', '(cancelled)'));
            finished = true;
            self.finishTurn({});
        });
        stream.addEventListener('expired', function () {
            self.flushAssistantStream();
            self.appendMessage('assistant', t('expired', '(task expired, please retry)'));
            finished = true;
            self.finishTurn({});
        });
        stream.addEventListener('terminal', function () {
            if (!finished) {
                self.flushAssistantStream();
                self.finishTurn({});
            }
        });

        Promise.resolve(stream.start && stream.start()).catch(function (err) {
            if (!finished) {
                self.flushAssistantStream();
                self.appendMessage('assistant', t('streamFailed', 'Stream failed: ') + (err && err.message ? err.message : err));
                self.finishTurn({});
            }
        });
    };

    AgentChatApp.prototype.rememberActiveTaskSession = function (sessionId) {
        try {
            const raw = sessionStorage.getItem(ACTIVE_TASK_KEY);
            if (!raw) return;
            const info = JSON.parse(raw);
            if (info && info.task_id) {
                info.session_id = sessionId;
                sessionStorage.setItem(ACTIVE_TASK_KEY, JSON.stringify(info));
            }
        } catch (e) {}
    };

    AgentChatApp.prototype.finishTurn = function () {
        this.taskHandle = null;
        this.activeStream = null;
        this.sending = false;
        this.$send.disabled = false;
        this.$stop.classList.remove('show');
        const resumeSessionId = this._resumeSessionId || 0;
        this._resumeSessionId = 0;
        try { sessionStorage.removeItem(ACTIVE_TASK_KEY); } catch (e) {}
        if (resumeSessionId > 0) {
            // 恢复的任务已落库：用服务端历史为准，避免占位气泡残留
            this.openSession(resumeSessionId);
            return;
        }
        this.loadSessions();
        this.scrollBottom();
        this.$input.focus();
    };

    AgentChatApp.prototype.tryResumeActiveTask = function () {
        var self = this;
        let raw = null;
        try { raw = sessionStorage.getItem(ACTIVE_TASK_KEY); } catch (e) {}
        if (!raw) return;
        let info = null;
        try { info = JSON.parse(raw); } catch (e) { return; }
        if (!info || !info.task_id || !info.lease_id || !info.stream_channel) {
            try { sessionStorage.removeItem(ACTIVE_TASK_KEY); } catch (e) {}
            return;
        }
        if (info.message) {
            this.appendMessage('user', info.message);
        }
        this.appendMessage('assistant', t('resuming', 'Resuming task…'));
        this._resumeSessionId = parseInt(info.session_id, 10) || 0;
        getRuntimeApi().then(function (api) {
            self.bindStream(api, {
                task_id: info.task_id,
                lease_id: info.lease_id,
                stream_channel: info.stream_channel,
            });
        }).catch(function () {
            try { sessionStorage.removeItem(ACTIVE_TASK_KEY); } catch (e) {}
        });
    };

    window.AgentChatModule = {
        mount: function (root) {
            if (!root || root.__agentChatMounted) return;
            root.__agentChatMounted = true;
            new AgentChatApp(root);
        },
    };

    function boot() {
        document.querySelectorAll('[data-weline-load~="agentChat"]').forEach(function (el) {
            window.AgentChatModule.mount(el);
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
