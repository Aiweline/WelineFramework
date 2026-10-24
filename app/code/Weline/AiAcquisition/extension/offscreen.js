'use strict';

import { CreateMLCEngine, prebuiltAppConfig } from './vendor/webllm/index.js';

let engine = null;
let engineModel = '';

function progress(text) {
  try {
    chrome.runtime.sendMessage({ type: 'WEBLLM_PROGRESS', text: String(text || '').slice(0, 200) }).catch(() => {});
  } catch (error) {
    // 后台暂时休眠时忽略
  }
}

async function getEngine(model) {
  if (engine && engineModel === model) {
    return engine;
  }
  if (engine) {
    try {
      await engine.unload();
    } catch (error) {
      // 忽略卸载失败
    }
    engine = null;
    engineModel = '';
  }
  progress(`加载 ${model}（首次需下载模型权重）`);
  // 端侧小模型的预置上下文只有 4096，长提示会直接报超窗；这里统一抬到 8192。
  const appConfig = {
    ...prebuiltAppConfig,
    model_list: (prebuiltAppConfig.model_list || []).map((item) => (
      item.model_id === model
        ? { ...item, overrides: { ...(item.overrides || {}), context_window_size: 8192 } }
        : item
    )),
  };
  engine = await CreateMLCEngine(model, {
    appConfig,
    initProgressCallback(report) {
      progress(report?.text || '模型加载中…');
    },
  });
  engineModel = model;
  return engine;
}

async function chat(payload) {
  const model = String(payload.model || '').trim();
  if (!model) {
    throw new Error('浏览器模型为空');
  }
  const messages = Array.isArray(payload.messages) ? payload.messages : [];
  const tools = Array.isArray(payload.tools) ? payload.tools : [];
  const params = payload.params && typeof payload.params === 'object' ? payload.params : {};
  const client = await getEngine(model);
  const request = {
    messages,
    temperature: Number(params.temperature) || 0.3,
    max_tokens: Number(params.max_tokens) || 1200,
    stream: false,
  };
  if (tools.length > 0) {
    request.tools = tools;
    request.tool_choice = 'auto';
  }
  let completion;
  try {
    completion = await client.chat.completions.create(request);
  } catch (error) {
    const messageText = String(error?.message || error);
    if (/abort/i.test(messageText)) {
      try { await client.unload(); } catch (e) { /* 忽略 */ }
      engine = null;
      engineModel = '';
      throw new Error('浏览器模型推理被中断，已重置引擎，请重试一次');
    }
    throw error;
  }
  const message = completion?.choices?.[0]?.message || {};
  return {
    ok: true,
    message: {
      role: 'assistant',
      content: String(message.content || ''),
      tool_calls: Array.isArray(message.tool_calls) ? message.tool_calls : [],
    },
    usage: completion?.usage || {},
  };
}

chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  if (!message || message.target !== 'weline-offscreen') {
    return false;
  }
  if (message.type === 'WEBLLM_STATUS') {
    sendResponse({
      ok: true,
      gpu: typeof navigator.gpu,
      secure: Boolean(globalThis.isSecureContext),
      engine: Boolean(engine),
      model: engineModel,
    });
    return false;
  }
  if (message.type === 'WEBLLM_CACHE_TEST') {
    (async () => {
      const url = String(message.url || '');
      const result = { url: url.slice(0, 80) };
      try {
        const t0 = Date.now();
        const response = await fetch(url, { cache: 'no-store' });
        const buffer = await response.arrayBuffer();
        result.fetch = { status: response.status, bytes: buffer.byteLength, ms: Date.now() - t0 };
      } catch (error) {
        result.fetch = { error: String(error) };
      }
      try {
        const cache = await caches.open('weline-cache-test');
        await cache.add(new Request(url));
        result.cacheAdd = 'ok';
      } catch (error) {
        result.cacheAdd = String(error);
      }
      try {
        const cache = await caches.open('weline-cache-test-put');
        const response = await fetch(url, { cache: 'no-store' });
        await cache.put(url, response);
        result.cachePut = 'ok';
      } catch (error) {
        result.cachePut = String(error);
      }
      sendResponse({ ok: true, result });
    })();
    return true;
  }

  if (message.type === 'WEBLLM_DIAG') {
    (async () => {
      let adapter = false;
      let info = '';
      try {
        const a = navigator.gpu ? await navigator.gpu.requestAdapter() : null;
        adapter = Boolean(a);
        if (a) {
          info = String(a.info?.vendor || '') + '/' + String(a.info?.architecture || '');
        }
      } catch (error) {
        info = 'adapter_error: ' + (error?.message || error);
      }
      let cacheOk = false;
      try {
        await caches.open('weline-diag');
        cacheOk = true;
      } catch (error) {
        cacheOk = false;
      }
      sendResponse({
        ok: true,
        gpu: typeof navigator.gpu,
        adapter,
        info,
        caches: typeof caches,
        cacheOk,
        engine: Boolean(engine),
        model: engineModel,
      });
    })();
    return true;
  }

  if (message.type === 'WEBLLM_PREPARE') {
    const model = String(message.model || '').trim();
    if (!model) {
      sendResponse({ ok: false, message: '浏览器模型为空' });
      return false;
    }
    getEngine(model)
      .then(() => sendResponse({ ok: true, model }))
      .catch((error) => sendResponse({ ok: false, message: error?.message || '浏览器模型初始化失败' }));
    return true;
  }

  if (message.type !== 'WEBLLM_CHAT') {
    return false;
  }
  chat(message)
    .then((result) => sendResponse(result))
    .catch((error) => sendResponse({ ok: false, message: error?.message || '浏览器模型运行失败' }));
  return true;
});
