const MAX_TRANSLATED_CHARS = 20000;

export function createTranslator({ Translator } = {}) {
  return {
    async translate(text, { sourceLanguage, targetLanguage } = {}) {
      if (!Translator || typeof Translator.availability !== 'function') {
        throw new Error('Prompt API Translator is unavailable');
      }
      const availability = await Translator.availability({ sourceLanguage, targetLanguage });
      if (availability !== 'available' || typeof Translator.create !== 'function') {
        throw new Error('Prompt API Translator is ' + (availability || 'unavailable'));
      }
      const translator = await Translator.create({ sourceLanguage, targetLanguage });
      const out = await translator.translate(text);
      if (typeof out !== 'string') throw new Error('Translator returned a non-string result');
      return out.slice(0, MAX_TRANSLATED_CHARS);
    },
  };
}

export function createBridge({ chrome, tokenState = () => ({ status: 'active', failCount: 0 }), translatorFactory, allowedOrigins = [] } = {}) {
  const status = () => {
    const value = tokenState();
    return {
      status: value && value.status === 'halted' ? 'halted' : 'active',
      failCount: Number(value && value.failCount) || 0,
    };
  };
  const reply = (action, nonce, extra) => ({ type: 'weline-ai-ext', action, nonce, ...extra });

  return {
    status,
    async setTokenState(state) {
      if (chrome?.storage?.local?.set) await chrome.storage.local.set(state);
    },
    async handleMessage(event) {
      const msg = event?.data;
      if (!msg || msg.type !== 'weline-ai-ext') return null;
      const { action, nonce } = msg;
      if (!allowedOrigins.includes(event.origin)) {
        return reply(action, nonce, { ok: false, error: 'origin not allowed' });
      }
      if (action === 'status') return reply(action, nonce, { ok: true, payload: status() });
      if (action !== 'translate') return reply(action, nonce, { ok: false, error: 'unsupported action' });
      if (status().status === 'halted') return reply(action, nonce, { ok: false, error: 'halted' });
      const text = msg.payload?.text;
      if (typeof text !== 'string' || text.length === 0) {
        return reply(action, nonce, { ok: false, error: 'payload.text is required' });
      }
      try {
        const translator = await translatorFactory();
        const translated = await translator.translate(text, {
          sourceLanguage: msg.payload.sourceLanguage,
          targetLanguage: msg.payload.targetLanguage,
        });
        return reply(action, nonce, { ok: true, payload: { translated } });
      } catch (error) {
        return reply(action, nonce, { ok: false, error: error?.message || 'translate failed' });
      }
    },
  };
}
