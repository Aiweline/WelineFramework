'use strict';

/**
 * 弹窗：填写后端地址与票据，连接/停止运行，查看状态、成本与日志。
 * 票据只发送给 background，不写入 storage。
 */

const elements = {
  baseUrl: document.getElementById('baseUrl'),
  ticket: document.getElementById('ticket'),
  start: document.getElementById('start'),
  stop: document.getElementById('stop'),
  status: document.getElementById('status'),
  mode: document.getElementById('mode'),
  saved: document.getElementById('saved'),
  searches: document.getElementById('searches'),
  cost: document.getElementById('cost'),
  logs: document.getElementById('logs'),
  options: document.getElementById('options'),
};

let running = false;

function formatCost(value) {
  const number = Number(value) || 0;
  return number > 0 ? `¥${number.toFixed(6)}` : '0';
}

function renderLogs(logs) {
  elements.logs.textContent = '';
  for (const line of Array.isArray(logs) ? logs : []) {
    const item = document.createElement('li');
    item.textContent = String(line);
    elements.logs.appendChild(item);
  }
  elements.logs.scrollTop = elements.logs.scrollHeight;
}

function render(state) {
  running = Boolean(state?.running);
  elements.start.disabled = running;
  elements.stop.disabled = !running;
  elements.baseUrl.disabled = running;
  elements.ticket.disabled = running;

  if (!running) {
    elements.status.textContent = state?.logs?.length ? '已结束' : '未连接';
  } else {
    elements.status.textContent = state.stopRequested
      ? '停止中…'
      : `运行中 #${state.run?.id ?? '?'}`;
  }
  const modeLabels = {
    ai_module: 'AI 模块（云端）',
    local_computer: '本地电脑模型',
    browser_local: '浏览器端侧模型',
  };
  elements.mode.textContent = state?.modelLabel
    || modeLabels[state?.modelMode]
    || modeLabels.ai_module;
  elements.saved.textContent = String(Number(state?.saved) || 0);
  elements.searches.textContent = String(Number(state?.searches) || 0);
  elements.cost.textContent = formatCost(state?.cost);
  renderLogs(state?.logs);
}

async function load() {
  const stored = await chrome.storage.local.get({ baseUrl: '' });
  if (!elements.baseUrl.value) {
    elements.baseUrl.value = stored.baseUrl || '';
  }
  try {
    const response = await chrome.runtime.sendMessage({ type: 'WELINE_STATE' });
    if (response?.state) {
      render(response.state);
    }
  } catch (error) {
    elements.status.textContent = '后台未就绪';
  }
}

async function start() {
  const baseUrl = elements.baseUrl.value.trim();
  const ticket = elements.ticket.value.trim();
  if (!baseUrl) {
    elements.status.textContent = '请填写后端地址';
    return;
  }
  if (!ticket) {
    elements.status.textContent = '请填写运行票据';
    return;
  }

  elements.status.textContent = '连接中…';
  elements.start.disabled = true;
  try {
    const stored = await chrome.storage.local.get({ querySlots: [] });
    const response = await chrome.runtime.sendMessage({
      type: 'WELINE_START',
      payload: { baseUrl, ticket, querySlots: stored.querySlots },
    });
    if (!response?.ok) {
      throw new Error(response?.message || '启动失败');
    }
    await chrome.storage.local.set({ baseUrl });
    elements.ticket.value = '';
  } catch (error) {
    elements.status.textContent = `启动失败：${error.message}`;
    elements.start.disabled = false;
  }
}

elements.start.addEventListener('click', () => {
  start();
});

elements.stop.addEventListener('click', async () => {
  elements.stop.disabled = true;
  elements.status.textContent = '停止中…';
  try {
    await chrome.runtime.sendMessage({ type: 'WELINE_STOP' });
  } catch (error) {
    elements.status.textContent = '停止失败';
  }
});

elements.options.addEventListener('click', () => {
  chrome.runtime.openOptionsPage();
});

chrome.runtime.onMessage.addListener((message) => {
  if (message?.type === 'WELINE_STATE' && message.state) {
    render(message.state);
  }
});

load();
