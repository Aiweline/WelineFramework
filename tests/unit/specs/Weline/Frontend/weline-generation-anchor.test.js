import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'fs';
import { dirname, resolve } from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);
const projectRoot = resolve(__dirname, '../../../../..');
const anchorSource = readFileSync(
  resolve(projectRoot, 'app/code/Weline/Frontend/view/statics/js/weline-generation-anchor.js'),
  'utf-8'
);

function loadAnchorModule() {
  // IIFE assigns window.WelineGenerationAnchor; eval in this window context.
  window.eval(anchorSource);
  return window.WelineGenerationAnchor;
}

function stubBootstrap(payload) {
  vi.stubGlobal('fetch', vi.fn(async () => ({
    ok: true,
    json: async () => payload,
  })));
}

function setPageAnchor(uid) {
  window.Weline = { config: { generationUid: uid } };
  document.documentElement.setAttribute('data-weline-generation', uid);
}

describe('WelineGenerationAnchor.ensureScopeAnchorAligned', () => {
  let mod;

  beforeEach(() => {
    window.sessionStorage.clear();
    window.Weline = {};
    document.documentElement.removeAttribute('data-weline-generation');
    mod = loadAnchorModule();
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    window.sessionStorage.clear();
  });

  it('页面带主题段、服务端只回裸部署号时判定不可比、不导航', async () => {
    setPageAnchor('6cc0f13374bb~hanfu/v282');
    stubBootstrap({
      ok: true,
      generation_uid: '24f6bf5121ff',
      acceptable_generation_uids: [],
      page_generation_token: 'eb7c711cc46c4a8b',
    });

    const result = await mod.ensureScopeAnchorAligned();

    expect(result.aligned).toBe(true);
    expect(result.reason).toBe('incomparable_anchor');
    // 不写终态：服务端恢复链解析后仍能跟进
    expect(window.sessionStorage.getItem('weline.storefront_last_aligned_anchor')).toBeNull();
  });

  it('服务端不给 uid（空串）时同样禁止导航', async () => {
    setPageAnchor('6cc0f13374bb~hanfu/v282');
    stubBootstrap({
      ok: true,
      generation_uid: '',
      acceptable_generation_uids: [],
      page_generation_token: 'eb7c711cc46c4a8b',
    });

    const result = await mod.ensureScopeAnchorAligned();

    expect(result.aligned).toBe(true);
    expect(result.reason).toBe('incomparable_anchor');
  });

  it('双方都有主题段且不一致仍走正常跟进判定', async () => {
    setPageAnchor('6cc0f13374bb~hanfu/v282');
    stubBootstrap({
      ok: true,
      generation_uid: '24f6bf5121ff~hanfu/v283',
      acceptable_generation_uids: ['24f6bf5121ff~hanfu/v283'],
      page_generation_token: 'eb7c711cc46c4a8b',
    });

    const result = await mod.ensureScopeAnchorAligned();

    expect(result.aligned).toBe(false);
    expect(result.token).toBe('eb7c711cc46c4a8b');
  });

  it('页面裸锚点 vs 服务端主题锚点不拦截（允许升级旧页）', async () => {
    setPageAnchor('6cc0f13374bb');
    stubBootstrap({
      ok: true,
      generation_uid: '24f6bf5121ff~hanfu/v282',
      acceptable_generation_uids: [],
      page_generation_token: 'eb7c711cc46c4a8b',
    });

    const result = await mod.ensureScopeAnchorAligned();

    expect(result.aligned).toBe(false);
    expect(result.token).toBe('eb7c711cc46c4a8b');
  });

  it('锚点一致仍命中终态（回归）', async () => {
    setPageAnchor('24f6bf5121ff~hanfu/v282');
    stubBootstrap({
      ok: true,
      generation_uid: '24f6bf5121ff~hanfu/v282',
      acceptable_generation_uids: ['24f6bf5121ff~hanfu/v282'],
      page_generation_token: 'eb7c711cc46c4a8b',
    });

    const result = await mod.ensureScopeAnchorAligned();

    expect(result.aligned).toBe(true);
    expect(result.reason).toBe('match');
    expect(window.sessionStorage.getItem('weline.storefront_last_aligned_anchor'))
      .toBe('24f6bf5121ff~hanfu/v282');
  });
});
