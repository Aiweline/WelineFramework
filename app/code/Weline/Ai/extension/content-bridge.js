// 页面 ↔ SW 桥（ISOLATED world：有 chrome.runtime）
// 页面 MAIN world 与 ISOLATED world 的 JS 不互通，但 DOM 事件互通：
//   页面派发 CustomEvent('weline-ai-ext-request', {detail}) 
//   桥监听 → chrome.runtime.sendMessage(SW) → 派发 CustomEvent('weline-ai-ext-reply', {detail})
// Taglib bootstrap 与授权页均用 CustomEvent 协议（detail 含 type/action/nonce/payload）
(() => {
  'use strict';
  const REQ = 'weline-ai-ext-request';
  const RES = 'weline-ai-ext-reply';
  window.addEventListener(REQ, (event) => {
    const msg = event?.detail;
    if (!msg || msg.type !== 'weline-ai-ext') return;
    const { action, nonce, payload } = msg;
    if (!action || typeof nonce !== 'string' || nonce === '') return;
    const reply = (payload2) => {
      window.dispatchEvent(new CustomEvent(RES, { detail: { type: 'weline-ai-ext', action, nonce, payload: payload2 } }));
    };
    try {
      chrome.runtime.sendMessage({ type: msg.type, action, nonce, payload: payload ?? {} }, (res) => {
        if (chrome.runtime.lastError) {
          reply({ ok: false, error: 'extension unavailable' });
          return;
        }
        reply(res?.payload ?? { ok: false, error: 'no response' });
      });
    } catch (e) {
      reply({ ok: false, error: 'extension context invalidated' });
    }
  });
})();
