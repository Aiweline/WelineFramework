/**
 * 可视化角色流程编辑/运行页（Weline_Agent）— Coze 式节点画布
 *
 * 节点收起 = 小卡片（序号 + 角色 + 状态），点击展开 = 行内配置卡
 * （角色 / 标签 / 提示词覆盖 / 排序 / 删除）。节点可拖拽布局，
 * x/y 随流程持久化；顺序语义仍由 nodes 数组决定（执行链），
 * 「自动排列」一键重置为水平链式布局。
 * v1 串行拓扑：保存时按节点顺序自动生成链式 edges。
 * 运行走 agent.flow_run 可恢复任务，SSE 事件驱动节点状态。
 */
(function () {
    'use strict';

    const API = '/agent/api/v1/flow';
    const NODE_W = 216;          // 收起卡片宽
    const NODE_GAP_X = 90;       // 自动布局水平间距
    const NODE_GAP_Y = 60;       // 自动布局垂直间距
    const WORLD_PAD = 48;

    function apiGet(path) {
        return fetch(path + (path.indexOf('?') >= 0 ? '&' : '?') + '_=' + Date.now(), {
            headers: { 'Accept': 'application/json' }, credentials: 'same-origin',
        }).then(function (r) { return r.json(); });
    }
    function apiPost(path, body) {
        return fetch(path, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(body || {}), credentials: 'same-origin',
        }).then(function (r) { return r.json(); });
    }
    function el(tag, cls, text) {
        const n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text != null) n.textContent = text;
        return n;
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function svgEl(tag, attrs) {
        const n = document.createElementNS('http://www.w3.org/2000/svg', tag);
        if (attrs) Object.keys(attrs).forEach(function (k) { n.setAttribute(k, attrs[k]); });
        return n;
    }

    function FlowApp(root) {
        this.root = root;
        this.roles = [];
        this.flows = [];
        this.flowId = 0;
        this.flowVersion = 0;
        this.nodes = []; // {node_key, role_id, prompt_override, label, x, y, expanded}
        this.running = false;
        this.nodeStatusEls = {};
        this._edgeRaf = 0;

        this.$list = root.querySelector('#flowList');
        this.$stage = root.querySelector('#flowNodes');
        this.$world = root.querySelector('#flowWorld');
        this.$edges = root.querySelector('#flowEdges');
        this.$empty = root.querySelector('#flowEmpty');
        this.$name = root.querySelector('#flowName');
        this.$desc = root.querySelector('#flowDesc');
        this.$status = root.querySelector('#flowStatus');
        this.$statusChip = root.querySelector('#flowStatusChip');
        this.$meta = root.querySelector('#flowMeta');
        this.$input = root.querySelector('#flowInput');
        this.$run = root.querySelector('#flowRunBtn');
        this.$log = root.querySelector('#flowRunLog');
        this.$runs = root.querySelector('#flowRuns');

        root.querySelector('#flowNewBtn').addEventListener('click', this.newFlow.bind(this));
        root.querySelector('#flowSaveBtn').addEventListener('click', this.save.bind(this));
        root.querySelector('#flowArrangeBtn').addEventListener('click', this.autoLayout.bind(this));
        this.$run.addEventListener('click', this.runFlow.bind(this));
        this.$list.addEventListener('click', this.onFlowClick.bind(this));
        this.$runs.addEventListener('click', this.onRunClick.bind(this));
        this.$status.addEventListener('change', this.renderStatusChip.bind(this));

        this.init();
        try {
            var fid = parseInt(new URLSearchParams(window.location.search).get('flow_id') || '0', 10);
            if (fid > 0) this.openFlow(fid);
        } catch (e) {}
    }

    /* ---------- 数据 ---------- */

    FlowApp.prototype.init = function () {
        var self = this;
        Promise.all([
            apiGet(API + '/roles'),
            apiGet(API + '/flows'),
        ]).then(function (res) {
            var rolesRes = res[0], flowsRes = res[1];
            if (rolesRes && rolesRes.success) self.roles = (rolesRes.data && rolesRes.data.roles) || [];
            if (flowsRes && flowsRes.success) {
                self.flows = (flowsRes.data && flowsRes.data.flows) || [];
                self.renderFlowList();
            }
        }).catch(function () {});
    };

    FlowApp.prototype.roleName = function (roleId) {
        var r = this.roles.find(function (x) { return x.role_id === roleId; });
        return r ? (r.name + ' (' + r.code + ')') : ('#' + roleId);
    };

    FlowApp.prototype.rolePrompt = function (roleId) {
        var r = this.roles.find(function (x) { return x.role_id === roleId; });
        return r ? (r.system_prompt || '') : '';
    };

    FlowApp.prototype.renderStatusChip = function () {
        if (!this.$statusChip) return;
        var map = { draft: '草稿', active: '启用', disabled: '停用' };
        var s = this.$status.value || 'draft';
        this.$statusChip.textContent = map[s] || s;
        this.$statusChip.dataset.s = s === 'active' ? 'active' : (s === 'disabled' ? 'disabled' : '');
    };

    FlowApp.prototype.renderFlowList = function () {
        var self = this;
        this.$list.innerHTML = '';
        this.flows.forEach(function (f) {
            var item = el('div', 'af-flow-item' + (f.flow_id === self.flowId ? ' active' : ''));
            item.dataset.flowId = f.flow_id;
            item.appendChild(el('div', null, f.name || ('#' + f.flow_id)));
            item.appendChild(el('div', 'af-flow-meta',
                (f.status || '') + ' · v' + (f.version || 0) + ' · ' + (f.node_count || 0) + ' 节点'));
            self.$list.appendChild(item);
        });
    };

    FlowApp.prototype.onFlowClick = function (e) {
        var item = e.target.closest('.af-flow-item');
        if (!item) return;
        this.openFlow(parseInt(item.dataset.flowId, 10) || 0);
    };

    FlowApp.prototype.openFlow = function (flowId) {
        var self = this;
        apiGet(API + '/flow?id=' + flowId).then(function (res) {
            if (!res || !res.success || !res.data) return;
            var f = res.data.flow;
            self.flowId = f.flow_id;
            self.flowVersion = f.version;
            self.$name.value = f.name || '';
            self.$desc.value = f.description || '';
            self.$status.value = f.status || 'draft';
            self.renderStatusChip();
            self.$meta.textContent = 'v' + f.version;
            self.nodes = ((f.graph && f.graph.nodes) || []).map(function (n) {
                return {
                    node_key: n.node_key || ('node_' + Math.random().toString(36).slice(2, 8)),
                    role_id: parseInt(n.role_id, 10) || 0,
                    prompt_override: n.prompt_override || '',
                    label: n.label || '',
                    inspect_interval: parseInt(n.inspect_interval, 10) || 0,
                    x: (n.x === null || n.x === undefined) ? null : parseFloat(n.x),
                    y: (n.y === null || n.y === undefined) ? null : parseFloat(n.y),
                    expanded: false,
                };
            });
            self.ensurePositions();
            self.renderCanvas();
            self.renderFlowList();
            self.loadRuns();
        });
    };

    FlowApp.prototype.newFlow = function () {
        this.flowId = 0;
        this.flowVersion = 0;
        this.$name.value = '';
        this.$desc.value = '';
        this.$status.value = 'draft';
        this.renderStatusChip();
        this.$meta.textContent = '';
        this.nodes = [];
        this.hideLog();
        this.renderCanvas();
        this.renderFlowList();
        this.$runs.innerHTML = '';
        this.$runs.style.display = 'none';
    };

    /* ---------- 布局 ---------- */

    FlowApp.prototype.ensurePositions = function () {
        var need = this.nodes.some(function (n) { return n.x === null || n.x === undefined || n.y === null || n.y === undefined; });
        if (need) this.autoLayout(true);
    };

    FlowApp.prototype.autoLayout = function (silent) {
        // 水平链式：每行最多 4 个，蛇形换行
        var perRow = 4;
        this.nodes.forEach(function (n, i) {
            var row = Math.floor(i / perRow);
            var col = i % perRow;
            var dir = row % 2 === 1 ? (perRow - 1 - col) : col; // 蛇形
            n.x = WORLD_PAD + dir * (NODE_W + NODE_GAP_X);
            n.y = WORLD_PAD + row * 240;
            n.expanded = false;
        });
        if (!silent) this.renderCanvas();
    };

    FlowApp.prototype.nodeBox = function (n) {
        var card = document.querySelector('.af-nodebox[data-key="' + n.node_key + '"]');
        if (!card) return null;
        return {
            x: parseFloat(card.style.left) || 0,
            y: parseFloat(card.style.top) || 0,
            w: card.offsetWidth || NODE_W,
            h: card.offsetHeight || 60,
        };
    };

    /* ---------- 画布渲染 ---------- */

    FlowApp.prototype.renderCanvas = function () {
        var self = this;
        // 只清节点卡片与「+」按钮，保留 SVG 连线层
        this.$world.querySelectorAll('.af-nodebox,.af-add-fab').forEach(function (n) { n.remove(); });
        this.nodeStatusEls = {};
        if (this.$edges) {
            while (this.$edges.firstChild) this.$edges.removeChild(this.$edges.firstChild);
        }
        if (this.$empty) this.$empty.style.display = this.nodes.length ? 'none' : '';
        if (!this.nodes.length) {
            this.sizeWorld(600, 260);
            return;
        }

        // 世界尺寸
        var maxX = 0, maxY = 0;
        this.nodes.forEach(function (n) {
            maxX = Math.max(maxX, (n.x || 0) + NODE_W + 160);
            maxY = Math.max(maxY, (n.y || 0) + 320);
        });
        this.sizeWorld(maxX + WORLD_PAD, maxY + WORLD_PAD);

        // 节点
        this.nodes.forEach(function (n, idx) {
            var card = self.buildNodeCard(n, idx);
            card.style.left = (n.x || 0) + 'px';
            card.style.top = (n.y || 0) + 'px';
            self.$world.appendChild(card);
            self.bindNodeDrag(card, n);
        });

        // 末尾「+」按钮
        var last = this.nodes[this.nodes.length - 1];
        var addBtn = el('button', 'af-add-fab', '+');
        addBtn.type = 'button';
        addBtn.title = '添加节点';
        addBtn.style.left = ((last.x || 0) + NODE_W + 40) + 'px';
        addBtn.style.top = ((last.y || 0) + 8) + 'px';
        addBtn.addEventListener('click', function () { self.addNode(); });
        this.$world.appendChild(addBtn);

        this.drawEdges();
    };

    FlowApp.prototype.sizeWorld = function (w, h) {
        this.$world.style.width = Math.max(w, 600) + 'px';
        this.$world.style.height = Math.max(h, 240) + 'px';
        if (this.$edges) {
            this.$edges.setAttribute('width', Math.max(w, 600));
            this.$edges.setAttribute('height', Math.max(h, 240));
            this.$edges.style.width = Math.max(w, 600) + 'px';
            this.$edges.style.height = Math.max(h, 240) + 'px';
        }
    };

    FlowApp.prototype.drawEdges = function () {
        var self = this;
        if (this._edgeRaf) return;
        this._edgeRaf = requestAnimationFrame(function () {
            self._edgeRaf = 0;
            self._drawEdgesNow();
        });
    };

    FlowApp.prototype._drawEdgesNow = function () {
        if (!this.$edges) return;
        while (this.$edges.firstChild) this.$edges.removeChild(this.$edges.firstChild);
        if (!this.nodes.length) return;

        // marker 定义
        var defs = svgEl('defs');
        var marker = svgEl('marker', {
            id: 'afArrow', viewBox: '0 0 10 10', refX: '9', refY: '5',
            markerWidth: '7', markerHeight: '7', orient: 'auto-start-reverse',
        });
        marker.appendChild(svgEl('path', { d: 'M0,0 L10,5 L0,10 z', fill: '#8a8177' }));
        defs.appendChild(marker);
        // 反向箭头（汇报线）：marker-start 用，朝左
        var markerBack = svgEl('marker', {
            id: 'afArrowBack', viewBox: '0 0 10 10', refX: '1', refY: '5',
            markerWidth: '7', markerHeight: '7', orient: 'auto-start-reverse',
        });
        markerBack.appendChild(svgEl('path', { d: 'M10,0 L0,5 L10,10 z', fill: '#8a8177' }));
        defs.appendChild(markerBack);
        this.$edges.appendChild(defs);

        for (var i = 0; i < this.nodes.length - 1; i++) {
            var a = this.nodeBox(this.nodes[i]);
            var b = this.nodeBox(this.nodes[i + 1]);
            if (!a || !b) continue;
            var x1 = a.x + a.w, y1 = a.y + a.h / 2;
            var x2 = b.x, y2 = b.y + b.h / 2;
            var dx = Math.max(40, (x2 - x1) / 2);
            var d = 'M ' + x1 + ' ' + y1 + ' C ' + (x1 + dx) + ' ' + y1 + ', ' + (x2 - dx) + ' ' + y2 + ', ' + x2 + ' ' + y2;
            this.$edges.appendChild(svgEl('path', {
                d: d, fill: 'none', stroke: '#6b655c', 'stroke-width': '1.5',
                'marker-start': 'url(#afArrowBack)', 'marker-end': 'url(#afArrow)',
            }));
        }
    };

    /* ---------- 节点卡片 ---------- */

    FlowApp.prototype.buildNodeCard = function (node, idx) {
        var self = this;
        var card = el('div', 'af-nodebox' + (node.expanded ? ' is-open' : ''));
        card.dataset.key = node.node_key;
        card.dataset.idx = idx;

        // 头部（收起/展开共用）：序号 + 名称 + 状态 + 折叠箭头
        var head = el('div', 'af-nodebox-head');
        head.appendChild(el('span', 'af-nodebox-idx', String(idx + 1)));
        var title = el('span', 'af-nodebox-title',
            this.nodeTitle(node, idx));
        head.appendChild(title);
        var status = el('span', 'af-nodebox-status');
        this.nodeStatusEls[node.node_key] = status;
        head.appendChild(status);
        head.appendChild(el('span', 'af-nodebox-caret', node.expanded ? '▾' : '▸'));
        card.appendChild(head);

        // 收起态：提示词预览
        if (!node.expanded) {
            var preview = (node.prompt_override || '').trim()
                || (node.role_id ? '使用角色默认提示词' : '点击展开配置');
            card.appendChild(el('div', 'af-nodebox-preview', preview.slice(0, 60)));
        } else {
            this.buildNodeForm(card, node, idx);
        }
        return card;
    };

    FlowApp.prototype.buildNodeForm = function (card, node, idx) {
        var self = this;

        var nameField = el('div', 'af-field');
        nameField.appendChild(el('label', null, '节点名称'));
        var nameInput = document.createElement('input');
        nameInput.type = 'text';
        nameInput.placeholder = '留空 = 角色名（' + (node.role_id ? this.roleName(node.role_id).split(' (')[0] : '选择角色后显示') + '）';
        nameInput.value = node.label || '';
        nameInput.addEventListener('input', function () {
            node.label = nameInput.value;
            self.refreshNodeTitle(node);
        });
        nameInput.addEventListener('click', function (e) { e.stopPropagation(); });
        nameInput.addEventListener('pointerdown', function (e) { e.stopPropagation(); });
        nameField.appendChild(nameInput);
        card.appendChild(nameField);

        var roleField = el('div', 'af-field');
        roleField.appendChild(el('label', null, '角色'));
        var sel = document.createElement('select');
        var placeholder = document.createElement('option');
        placeholder.value = ''; placeholder.textContent = '— 选择角色 —';
        sel.appendChild(placeholder);
        this.roles.forEach(function (r) {
            var opt = document.createElement('option');
            opt.value = r.role_id;
            opt.textContent = r.name + ' (' + r.code + ')';
            sel.appendChild(opt);
        });
        sel.value = node.role_id || '';
        sel.addEventListener('change', function () {
            node.role_id = parseInt(sel.value, 10) || 0;
            self.refreshNodeTitle(node);
        });
        sel.addEventListener('click', function (e) { e.stopPropagation(); });
        roleField.appendChild(sel);
        card.appendChild(roleField);

        var promptField = el('div', 'af-field');
        promptField.appendChild(el('label', null, '系统提示词覆盖'));
        var ta = document.createElement('textarea');
        ta.placeholder = '留空则使用角色默认提示词';
        ta.value = node.prompt_override || '';
        ta.addEventListener('input', function () { node.prompt_override = ta.value; });
        ta.addEventListener('click', function (e) { e.stopPropagation(); });
        ta.addEventListener('pointerdown', function (e) { e.stopPropagation(); });
        promptField.appendChild(ta);

        var sub = el('div', 'af-node-sub');
        var imp = el('button', 'af-import-btn', '从角色导入');
        imp.type = 'button';
        imp.addEventListener('click', function (e) {
            e.stopPropagation();
            var p = self.rolePrompt(node.role_id);
            ta.value = p;
            node.prompt_override = p;
        });
        sub.appendChild(imp);
        sub.appendChild(el('span', 'af-node-hint', '留空=角色默认'));
        promptField.appendChild(sub);
        card.appendChild(promptField);

        var intervalField = el('div', 'af-field');
        intervalField.appendChild(el('label', null, '巡检间隔（分钟，0=不巡检）'));
        var intervalInput = document.createElement('input');
        intervalInput.type = 'number';
        intervalInput.min = '0';
        intervalInput.step = '1';
        intervalInput.placeholder = '0';
        intervalInput.value = node.inspect_interval ? Math.round(node.inspect_interval / 60) : '';
        intervalInput.addEventListener('input', function () {
            var m = parseInt(intervalInput.value, 10);
            node.inspect_interval = isNaN(m) || m < 0 ? 0 : Math.min(43200, m) * 60;
        });
        intervalInput.addEventListener('click', function (e) { e.stopPropagation(); });
        intervalInput.addEventListener('pointerdown', function (e) { e.stopPropagation(); });
        intervalField.appendChild(intervalInput);
        card.appendChild(intervalField);

        var ops = el('div', 'af-node-ops');
        var up = el('button', null, '↑ 前移'); up.type = 'button';
        up.disabled = idx === 0;
        up.addEventListener('click', function (e) { e.stopPropagation(); self.moveNode(idx, -1); });
        var down = el('button', null, '↓ 后移'); down.type = 'button';
        down.disabled = idx === self.nodes.length - 1;
        down.addEventListener('click', function (e) { e.stopPropagation(); self.moveNode(idx, 1); });
        var del = el('button', 'af-node-del', '删除'); del.type = 'button';
        del.addEventListener('click', function (e) { e.stopPropagation(); self.nodes.splice(idx, 1); self.renderCanvas(); });
        ops.appendChild(up); ops.appendChild(down); ops.appendChild(del);
        card.appendChild(ops);
    };

    FlowApp.prototype.nodeTitle = function (node, idx) {
        var label = (node.label || '').trim();
        // 兼容旧数据：自动生成的「节点N」不算命名，回退角色名
        if (label && !/^节点\s*\d+$/.test(label)) return label;
        if (node.role_id) return this.roleName(node.role_id).split(' (')[0];
        return '未配置节点 ' + (idx + 1);
    };

    FlowApp.prototype.refreshNodeTitle = function (node) {
        var idx = this.nodes.indexOf(node);
        var card = document.querySelector('.af-nodebox[data-key="' + node.node_key + '"]');
        if (card && idx >= 0) {
            var title = card.querySelector('.af-nodebox-title');
            if (title) title.textContent = this.nodeTitle(node, idx);
        }
    };

    /* ---------- 拖拽 ---------- */

    FlowApp.prototype.bindNodeDrag = function (card, node) {
        var self = this;
        var startX = 0, startY = 0, origX = 0, origY = 0, dragging = false, moved = false;

        var onMove = function (e) {
            if (!dragging) return;
            var dx = e.clientX - startX, dy = e.clientY - startY;
            if (!moved && Math.abs(dx) + Math.abs(dy) < 5) return;
            moved = true;
            node.x = Math.max(0, origX + dx);
            node.y = Math.max(0, origY + dy);
            card.style.left = node.x + 'px';
            card.style.top = node.y + 'px';
            self.drawEdges();
            self.growWorld(node);
        };
        var onEnd = function (e) {
            if (!dragging) return;
            dragging = false;
            card.classList.remove('is-dragging');
            window.removeEventListener('pointermove', onMove);
            window.removeEventListener('pointerup', onEnd);
            window.removeEventListener('pointercancel', onEnd);
            try { card.releasePointerCapture(e.pointerId); } catch (err) {}
            if (!moved) {
                // 视为点击：折叠/展开
                node.expanded = !node.expanded;
                self.renderCanvas();
            }
        };
        card.addEventListener('pointerdown', function (e) {
            if (e.button !== 0) return;
            // 表单控件不触发拖拽/折叠
            if (e.target.closest('select,textarea,input,button,.af-field,.af-node-ops')) return;
            startX = e.clientX; startY = e.clientY;
            origX = node.x || 0; origY = node.y || 0;
            dragging = true; moved = false;
            card.classList.add('is-dragging');
            try { card.setPointerCapture(e.pointerId); } catch (err) {}
            // window 级监听兜底（合成事件/pointer capture 失效时仍可拖）
            window.addEventListener('pointermove', onMove);
            window.addEventListener('pointerup', onEnd);
            window.addEventListener('pointercancel', onEnd);
            e.preventDefault();
        });
        card.addEventListener('pointermove', onMove);
        card.addEventListener('pointerup', onEnd);
        card.addEventListener('pointercancel', onEnd);
    };

    FlowApp.prototype.growWorld = function (node) {
        // 拖出边界时扩世界
        var needW = (node.x || 0) + NODE_W + 160 + WORLD_PAD;
        var needH = (node.y || 0) + 320 + WORLD_PAD;
        var curW = parseFloat(this.$world.style.width) || 0;
        var curH = parseFloat(this.$world.style.height) || 0;
        if (needW > curW || needH > curH) {
            this.sizeWorld(Math.max(curW, needW), Math.max(curH, needH));
        }
    };

    /* ---------- 操作 ---------- */

    FlowApp.prototype.addNode = function () {
        var n = {
            node_key: 'node_' + Date.now().toString(36),
            role_id: 0,
            prompt_override: '',
            label: '',
            inspect_interval: 0,
            expanded: true, // 新节点直接展开便于配置
        };
        if (this.nodes.length) {
            var last = this.nodes[this.nodes.length - 1];
            n.x = (last.x || 0) + NODE_W + NODE_GAP_X;
            n.y = last.y || WORLD_PAD;
        } else {
            n.x = WORLD_PAD; n.y = WORLD_PAD;
        }
        this.nodes.push(n);
        this.renderCanvas();
    };

    FlowApp.prototype.moveNode = function (idx, dir) {
        var j = idx + dir;
        if (j < 0 || j >= this.nodes.length) return;
        var t = this.nodes[idx];
        this.nodes[idx] = this.nodes[j];
        this.nodes[j] = t;
        this.renderCanvas();
    };

    FlowApp.prototype.buildGraph = function () {
        var nodes = this.nodes.map(function (n, i) {
            return {
                node_key: n.node_key,
                role_id: n.role_id,
                agent_code: '',
                prompt_override: n.prompt_override || '',
                label: n.label || '',
                inspect_interval: n.inspect_interval || 0,
                x: Math.round(n.x || 0),
                y: Math.round(n.y || 0),
            };
        });
        var edges = [];
        for (var i = 0; i < nodes.length - 1; i++) {
            edges.push({ from_node_key: nodes[i].node_key, to_node_key: nodes[i + 1].node_key });
        }
        return { nodes: nodes, edges: edges };
    };

    FlowApp.prototype.save = function () {
        var self = this;
        var name = (this.$name.value || '').trim();
        if (!name) { alert('请填写流程名称'); return; }
        if (!this.nodes.length) { alert('请至少添加一个节点'); return; }
        for (var i = 0; i < this.nodes.length; i++) {
            if (!this.nodes[i].role_id) { alert('节点 ' + (i + 1) + ' 未选择角色'); return; }
        }
        apiPost(API + '/save', {
            flow_id: this.flowId || undefined,
            name: name,
            description: this.$desc.value || '',
            status: this.$status.value || 'draft',
            graph: this.buildGraph(),
        }).then(function (res) {
            if (res && res.success && res.data) {
                self.flowId = res.data.flow_id;
                self.flowVersion = res.data.version;
                self.$meta.textContent = 'v' + res.data.version;
                self.loadFlows();
            } else {
                alert((res && res.msg) || '保存失败');
            }
        });
    };

    FlowApp.prototype.loadFlows = function () {
        var self = this;
        apiGet(API + '/flows').then(function (res) {
            if (res && res.success) {
                self.flows = (res.data && res.data.flows) || [];
                self.renderFlowList();
            }
        });
    };

    FlowApp.prototype.loadRuns = function () {
        var self = this;
        if (!this.flowId) { this.$runs.innerHTML = ''; this.$runs.style.display = 'none'; return; }
        apiGet(API + '/runs?flow_id=' + this.flowId).then(function (res) {
            if (!res || !res.success) return;
            var runs = (res.data && res.data.runs) || [];
            self.$runs.innerHTML = '';
            self.$runs.style.display = runs.length ? '' : 'none';
            runs.slice(0, 12).forEach(function (r) {
                var chip = el('span', 'af-run-chip', '');
                chip.dataset.runId = r.run_id;
                var dot = el('span', 'st-' + esc(r.status), '●');
                chip.appendChild(dot);
                chip.appendChild(document.createTextNode(
                    ' #' + r.run_id + ' ' + esc(r.status) + ' · ' +
                    new Date((r.created_time || 0) * 1000).toLocaleString() +
                    ' · ' + esc((r.input_text || '').slice(0, 30))));
                self.$runs.appendChild(chip);
            });
        });
    };

    FlowApp.prototype.onRunClick = function (e) {
        var chip = e.target.closest('.af-run-chip');
        if (!chip) return;
        var self = this;
        apiGet(API + '/run?id=' + chip.dataset.runId).then(function (res) {
            if (!res || !res.success || !res.data) return;
            var run = res.data.run;
            self.showLog('');
            self.logLine('运行 #' + run.run_id + ' · ' + run.status, 'info');
            (run.node_runs || []).forEach(function (nr) {
                var cls = nr.status === 'done' ? 'ok' : (nr.status === 'failed' ? 'err' : 'warn');
                self.logLine('— ' + nr.node_key + ' [' + nr.status + '] ' + (nr.duration_ms || 0) + 'ms', cls);
                if (nr.output) self.logLine(nr.output.slice(0, 800), '');
            });
            if (run.result && run.result.output) {
                self.logLine('【最终输出】', 'info');
                self.logLine(run.result.output, '');
            }
            self.$log.scrollTop = self.$log.scrollHeight;
        });
    };

    FlowApp.prototype.showLog = function () {
        this.$log.classList.add('show');
        this.$log.innerHTML = '';
    };
    FlowApp.prototype.hideLog = function () {
        this.$log.classList.remove('show');
        this.$log.innerHTML = '';
    };
    FlowApp.prototype.logLine = function (text, cls) {
        var line = el('div', cls ? ('lg-' + cls) : '', text);
        this.$log.appendChild(line);
    };

    FlowApp.prototype.setNodeStatus = function (nodeKey, state) {
        var badge = this.nodeStatusEls[nodeKey];
        if (!badge) return;
        var map = { running: '运行中', done: '完成', error: '失败' };
        badge.textContent = map[state] || '';
        badge.className = 'af-nodebox-status' + (state === 'running' ? ' run' : state === 'done' ? ' done' : state === 'error' ? ' err' : '');
    };

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

    FlowApp.prototype.runFlow = function () {
        var self = this;
        var inputText = (this.$input.value || '').trim();
        if (!this.flowId) { alert('请先保存流程'); return; }
        if (!inputText) { alert('请输入需求'); return; }
        if (this.running) return;

        var apiPromise;
        try {
            apiPromise = (window.WelineApiModule && window.WelineApiModule.resource)
                ? Promise.resolve(window.WelineApiModule)
                : (window.Weline && typeof window.Weline.load === 'function'
                    ? window.Weline.load('api') : Promise.reject(new Error('API client unavailable')));
        } catch (e) {
            alert('API client unavailable');
            return;
        }

        this.running = true;
        this.$run.disabled = true;
        this.$run.textContent = '运行中…';
        this.showLog();
        this.logLine('启动流程运行…', 'info');
        this.nodes.forEach(function (n) { self.setNodeStatus(n.node_key, ''); });

        apiPromise.then(function (api) {
            return api.resource('runtime_task').start({
                type_code: 'agent.flow_run',
                input: {
                    flow_id: self.flowId,
                    input_text: inputText,
                    request_id: 'flow_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 10),
                },
            }, { silent: true });
        }).then(function (task) {
            var handle = task && typeof task === 'object' ? task : null;
            if (!handle || typeof handle.task_id !== 'string' || typeof handle.lease_id !== 'string'
                || typeof handle.stream_channel !== 'string') {
                self.logLine('(任务启动响应无效，运行记录见下方列表)', 'warn');
                self.finishRun();
                return;
            }
            return apiPromise.then(function (api) {
                var stream = api.createStream(handle.stream_channel, {
                    task_id: handle.task_id,
                    lease_id: handle.lease_id,
                }, {
                    eventTypes: [
                        'flow_start', 'node_start', 'node_chunk', 'node_done',
                        'node_error', 'node_agent_info', 'node_tool_call',
                        'flow_done', 'completed', 'failed', 'cancelled',
                        'expired', 'terminal',
                    ],
                    storageKey: 'agent-flow:' + handle.task_id,
                });
                var on = function (name, fn) {
                    stream.addEventListener(name, function (event) { fn(streamPayload(event)); });
                };
                var finish = function () {
                    self.finishRun();
                };
                on('node_start', function (p) {
                    var key = p.node_key || '';
                    self.setNodeStatus(key, 'running');
                    self.logLine('▶ 节点 ' + key + ' (' + (p.role_name || '') + ') 开始', 'info');
                    self.$log.scrollTop = self.$log.scrollHeight;
                });
                on('node_done', function (p) {
                    var key = p.node_key || '';
                    self.setNodeStatus(key, 'done');
                    self.logLine('✔ 节点 ' + key + ' 完成', 'ok');
                    self.$log.scrollTop = self.$log.scrollHeight;
                });
                on('node_error', function (p) {
                    var key = p.node_key || '';
                    self.setNodeStatus(key, 'error');
                    self.logLine('✘ 节点 ' + key + ' 失败: ' + (p.error || ''), 'err');
                    self.$log.scrollTop = self.$log.scrollHeight;
                });
                on('flow_done', function () {
                    self.logLine('■ 流程完成', 'ok');
                });
                on('completed', function () { finish(); });
                on('failed', function (p) {
                    self.logLine('✘ 运行失败: ' + ((p && (p.error || p.message)) || ''), 'err');
                    finish();
                });
                on('cancelled', function () { finish(); });
                on('expired', function () { finish(); });
                on('terminal', function () { finish(); });
                return Promise.resolve(stream.start && stream.start());
            });
        }).catch(function (err) {
            self.logLine('启动失败: ' + (err && err.message ? err.message : err), 'err');
            self.finishRun();
        });
    };

    FlowApp.prototype.finishRun = function () {
        this.running = false;
        this.$run.disabled = false;
        this.$run.textContent = '▶ 运行流程';
        this.loadRuns();
    };

    window.AgentFlowModule = {
        mount: function (root) {
            if (!root || root.__agentFlowMounted) return;
            root.__agentFlowMounted = true;
            new FlowApp(root);
        },
    };

    function boot() {
        document.querySelectorAll('[data-weline-load~="agentFlow"]').forEach(function (el) {
            window.AgentFlowModule.mount(el);
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
