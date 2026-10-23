'use strict';

/**
 * 设置页：只保存后端地址与默认检索词。
 * 不保存票据、密钥或任何账号信息。
 */

const baseUrlInput = document.getElementById('baseUrl');
const querySlotsInput = document.getElementById('querySlots');
const saveButton = document.getElementById('save');
const statusText = document.getElementById('status');

function normalizeBaseUrl(raw) {
  const text = String(raw || '').trim();
  if (!text) {
    return '';
  }
  const url = new URL(text);
  if (url.protocol !== 'http:' && url.protocol !== 'https:') {
    throw new Error('后端地址必须是 http(s)');
  }
  if (url.username || url.password) {
    throw new Error('后端地址不能包含账号密码');
  }
  return url.href.replace(/\/+$/, '');
}

function parseSlots(raw) {
  return String(raw || '')
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line !== '')
    .slice(0, 20);
}

async function load() {
  const stored = await chrome.storage.local.get({ baseUrl: '', querySlots: [] });
  baseUrlInput.value = stored.baseUrl || '';
  querySlotsInput.value = Array.isArray(stored.querySlots) ? stored.querySlots.join('\n') : '';
}

saveButton.addEventListener('click', async () => {
  try {
    const baseUrl = normalizeBaseUrl(baseUrlInput.value);
    const querySlots = parseSlots(querySlotsInput.value);
    await chrome.storage.local.set({ baseUrl, querySlots });
    baseUrlInput.value = baseUrl;
    statusText.textContent = '已保存';
  } catch (error) {
    statusText.textContent = `保存失败：${error.message}`;
  }
});

load();
