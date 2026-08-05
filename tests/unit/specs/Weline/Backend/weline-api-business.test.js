import { describe, expect, it, beforeEach } from 'vitest';
import { readFileSync } from 'fs';
import { dirname, resolve } from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);
const projectRoot = resolve(__dirname, '../../../../..');
const businessScript = readFileSync(
  resolve(projectRoot, 'app/code/Weline/Backend/view/statics/js/weline-api-business.js'),
  'utf-8'
);

describe('Weline.ApiBusiness.formatApiError', () => {
  beforeEach(() => {
    delete globalThis.Weline;
    delete globalThis.WelineApiBusiness;
    // eslint-disable-next-line no-new-func
    new Function(businessScript)();
  });

  it('joins unique errors from business payload', () => {
    const error = {
      message: 'fallback',
      response: {
        data: {
          success: false,
          message: 'outer',
          errors: ['first', 'second', 'first'],
        },
      },
    };
    expect(globalThis.Weline.ApiBusiness.formatApiError(error)).toBe('first\nsecond');
  });

  it('falls back to error.message then default text', () => {
    expect(globalThis.Weline.ApiBusiness.formatApiError({ message: 'network' })).toBe('network');
    expect(globalThis.Weline.ApiBusiness.formatApiError({})).toBe('Request failed.');
  });

  it('reads nested result error_messages', () => {
    const error = {
      response: {
        data: {
          data: {
            results: [
              { success: false, message: 'row failed', error_messages: ['detail-a'] },
            ],
          },
        },
      },
    };
    expect(globalThis.Weline.ApiBusiness.formatApiError(error)).toContain('detail-a');
  });
});

describe('Weline.ApiBusiness.wrapAdminBridgeResult', () => {
  beforeEach(() => {
    delete globalThis.Weline;
    delete globalThis.WelineApiBusiness;
    // eslint-disable-next-line no-new-func
    new Function(businessScript)();
  });

  it('exposes business success on the bridge object (identity-then consumers)', async () => {
    const body = { success: true, message: 'ok', data: { user: { user_id: 7 } } };
    const resp = globalThis.Weline.ApiBusiness.wrapAdminBridgeResult(body);
    expect(resp.ok).toBe(true);
    expect(resp.success).toBe(true);
    expect(resp.data.user.user_id).toBe(7);
    expect(await resp.json()).toEqual(body);
  });

  it('unwrapBusiness returns flattened body, not nested wrapper.data', () => {
    const body = { success: true, data: { user: { user_id: 3 } } };
    const resp = globalThis.Weline.ApiBusiness.wrapAdminBridgeResult(body);
    const unwrapped = globalThis.Weline.ApiBusiness.unwrapBusiness(resp);
    expect(unwrapped.success).toBe(true);
    expect(unwrapped.data.user.user_id).toBe(3);
  });

  it('unwrapBusiness keeps legacy {ok,data:body} working', () => {
    const legacy = { ok: true, data: { success: true, message: 'saved' } };
    expect(globalThis.Weline.ApiBusiness.unwrapBusiness(legacy)).toEqual(legacy.data);
  });

  it('unwrapBusiness keeps theme-style code bodies on the bridge object', () => {
    const resp = globalThis.Weline.ApiBusiness.wrapAdminBridgeResult({
      code: 200,
      msg: 'ok',
      data: { component: { name: 'x' } },
    });
    const data = globalThis.Weline.ApiBusiness.unwrapBusiness(resp);
    expect(resp.ok).toBe(true);
    expect(data.code).toBe(200);
    expect(data.data.component.name).toBe('x');
  });
});
