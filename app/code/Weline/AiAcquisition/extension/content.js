'use strict';

/**
 * 当前页抽取脚本（由 background.js 通过 chrome.scripting 按需注入）。
 *
 * 只读取当前页可见文本、标题、描述与公开邮箱，供 inspect_page 工具兜底；
 * 不修改页面、不发送任何网络请求。
 */
(() => {
  if (typeof globalThis.__welineExtractPage === 'function') {
    return;
  }

  const EMAIL_PATTERN = /[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/gi;

  function cleanText(text) {
    return String(text || '').replace(/\s+/g, ' ').trim();
  }

  function readMeta(name) {
    const node =
      document.querySelector(`meta[name="${name}"]`) ||
      document.querySelector(`meta[property="${name}"]`);
    return node ? node.getAttribute('content') || '' : '';
  }

  function extractEmails(text) {
    const found = String(text || '').match(EMAIL_PATTERN) || [];
    const emails = [];
    for (const email of found) {
      const lower = email.toLowerCase();
      if (/\.(png|jpe?g|gif|webp|svg|css|js|ico)$/i.test(lower)) {
        continue;
      }
      if (!emails.includes(lower)) {
        emails.push(lower);
      }
      if (emails.length >= 20) {
        break;
      }
    }
    return emails;
  }

  globalThis.__welineExtractPage = function __welineExtractPage() {
    const bodyText = cleanText(document.body ? document.body.innerText : '');
    return {
      url: location.href,
      title: cleanText(document.title),
      description: cleanText(readMeta('description') || readMeta('og:description')),
      site_name: cleanText(readMeta('og:site_name')),
      text: bodyText.slice(0, 4000),
      emails: extractEmails(`${bodyText}\n${document.documentElement?.outerHTML || ''}`),
    };
  };

  chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
    if (message && message.type === 'WELINE_EXTRACT_PAGE') {
      sendResponse(globalThis.__welineExtractPage());
    }
    return false;
  });
})();
