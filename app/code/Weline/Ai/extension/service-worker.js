import { createRenewLoop } from './renew-loop.js';
import { createTranslator } from './bridge.js';

const TYPE = 'weline-ai-ext';
const BASE_URL = globalThis.location?.origin || '';
const store = chrome.storage.local;
const { weline_ai_ext_access: initialAccess } = await store.get('weline_ai_ext_access');
const loop = createRenewLoop({ chrome, baseUrl: BASE_URL, initialAccess });

chrome.alarms.create('weline-ai-renew', { periodInMinutes: 10 });
chrome.alarms.onAlarm.addListener((alarm) => {
  if (alarm.name === 'weline-ai-renew') loop.tick();
});

// origin 白名单由授权页通过 pairing 时写入；未配置时仅允许扩展自身与 http/https 后台同源
async function allowedOrigins() {
  const { weline_ai_ext_origins: origins } = await store.get('weline_ai_ext_origins');
  const list = Array.isArray(origins) ? origins : [];
  return [...list, chrome.runtime.getURL('').replace(/\/$/, '')];
}

async function statusPayload() {
  const s = loop.getState();
  const online = true; // SW 在响应当前消息，扩展在线
  const authorized = s.status === 'active';
  return { online, authorized, status: s.status, failCount: s.failCount };
}

let translator = null;
async function getTranslator() {
  translator ??= createTranslator(globalThis);
  return translator;
}

chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  if (message?.type !== TYPE) return undefined;
  (async () => {
    const { action, nonce, payload } = message;
    if (action === 'status') {
      return sendResponse({ payload: { ok: true, ...(await statusPayload()) } });
    }
    if (action === 'pairing') {
      const origin = payload?.origin ?? _sender?.origin ?? '';
      const access = payload?.access ?? '';
      if (typeof access !== 'string' || access === '') {
        return sendResponse({ payload: { ok: false, error: 'payload.access is required' } });
      }
      loop.reset(access);
      await store.set({ weline_ai_ext_access: access });
      const current = await allowedOrigins();
      if (origin && !current.includes(origin)) {
        await store.set({ weline_ai_ext_origins: [...current, origin] });
      }
      return sendResponse({ payload: { ok: true, ...(await statusPayload()) } });
    }
    if (action === 'translate') {
      const s = loop.getState();
      if (s.status === 'halted') {
        return sendResponse({ payload: { ok: false, error: 'halted' } });
      }
      const text = payload?.text;
      if (typeof text !== 'string' || text.length === 0) {
        return sendResponse({ payload: { ok: false, error: 'payload.text is required' } });
      }
      try {
        const t = await getTranslator();
        const translated = await t.translate(text, {
          sourceLanguage: payload.sourceLanguage,
          targetLanguage: payload.targetLanguage,
        });
        return sendResponse({ payload: { ok: true, translated } });
      } catch (error) {
        return sendResponse({ payload: { ok: false, error: error?.message || 'translate failed' } });
      }
    }
    return sendResponse({ payload: { ok: false, error: 'unsupported action' } });
  })();
  return true; // async sendResponse
});
