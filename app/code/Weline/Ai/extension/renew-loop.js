export function createRenewLoop({ fetchImpl, chrome, baseUrl = '', initialAccess = null, intervalMin = 10 }) {
  let access = initialAccess;
  const state = { status: 'active', failCount: 0 };

  async function tick() {
    if (state.status === 'halted' || !access) return { ...state };
    try {
      const resp = await fetchImpl(`${baseUrl}/ai/extension/renew`, {
        method: 'POST',
        headers: { 'X-Ai-Ext-Token': access },
      });
      if (resp.ok || (resp.status >= 200 && resp.status < 300)) {
        const data = await resp.json();
        if (data && data.access) {
          access = data.access;
          await chrome.storage.local.set({ weline_ai_ext_access: access });
        }
        state.failCount = 0;
      } else {
        state.failCount++;
        if (state.failCount >= 3) state.status = 'halted';
      }
    } catch {
      state.failCount++;
      if (state.failCount >= 3) state.status = 'halted';
    }
    return { ...state };
  }

  function reset(newAccess) {
    access = newAccess;
    state.failCount = 0;
    state.status = 'active';
  }

  return { tick, getState: () => ({ ...state }), reset, intervalMin };
}
