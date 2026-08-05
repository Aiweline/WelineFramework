/**
 * 万能商城内核计划：退款前端资源只发布顾客本人只读投影（TEST-REFUND-02）
 *
 * - 前端资源只允许 customerView
 * - 匿名访问必须由 Framework Gateway 拒绝
 * - 退款申请、渠道回写、测试造数不得成为浏览器操作
 *
 * 状态推进、占额与持久化并发由隔离数据库验证脚本覆盖；浏览器测试只验
 * HTTP 暴露面和认证边界。
 *
 * @weline-e2e-spec { module: Weline_Order, type: plan, layer: frontend }
 */

const {
  test,
  expect,
  gotoFrontend,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Order';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const DIRECT = { useProxy: false };

async function ensureApi(page) {
  await page.waitForFunction(() => {
    const w = window.Weline;
    return !!(w && ((w.Api && typeof w.Api.resource === 'function') || typeof w.load === 'function'));
  }, { timeout: 30000 });
  await page.evaluate(async () => {
    const w = window.Weline;
    if (w && w.Api && typeof w.Api.resource === 'function') {
      return;
    }
    if (w && typeof w.load === 'function') {
      await w.load('api');
    }
  });
  await page.waitForFunction(
    () => !!(window.Weline && window.Weline.Api && typeof window.Weline.Api.resource === 'function'),
    { timeout: 15000 },
  );
}

async function inspectRefundResource(page) {
  return page.evaluate(async () => {
    let api = window.Weline && window.Weline.Api;
    if ((!api || typeof api.resource !== 'function') && window.Weline && typeof window.Weline.load === 'function') {
      api = await window.Weline.load('api');
    }
    if (!api || typeof api.resource !== 'function') {
      return { __no_api: true };
    }
    const refund = await api.resource('refund');
    const shape = {
      has_customer_view: !!(refund && typeof refund.customerView === 'function'),
      anonymous_allowed: null,
      forbidden_write_allowed: null,
    };
    if (!shape.has_customer_view) {
      return { ...shape, __no_op: 'customerView' };
    }
    try {
      const data = await refund.customerView({ refund_case_uuid: '00000000-0000-4000-8000-000000000000' });
      shape.anonymous_allowed = true;
      shape.customer_view_data = data;
    } catch (err) {
      shape.anonymous_allowed = false;
      shape.customer_view_error = {
        message: String(err && (err.message || err)),
        response: err && err.response && err.response.data ? err.response.data : null,
      };
    }
    try {
      const data = await refund.requestRefund({
        order_uuid: '00000000-0000-4000-8000-000000000000',
        idempotency_key: '',
      });
      shape.forbidden_write_allowed = true;
      shape.forbidden_write_data = data;
    } catch (err) {
      shape.forbidden_write_allowed = false;
      shape.forbidden_write_error = {
        message: String(err && (err.message || err)),
        response: err && err.response && err.response.data ? err.response.data : null,
      };
    }
    return shape;
  });
}

moduleDescribe(test, MODULE, '计划 REFUND-02 顾客退款只读资源', () => {
  test.setTimeout(180000);

  moduleCase(
    test,
    { module: MODULE, id: 'TEST-REFUND-02' },
    '仅发布 customerView，匿名访问被拒绝，浏览器无退款写操作',
    async ({ page }) => {
      await gotoFrontend(page, '/customer/account/login', DIRECT);
      await expect(page.locator('body')).toBeVisible();
      await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);
      await ensureApi(page);

      const probe = await inspectRefundResource(page);
      expect(probe.__no_api, JSON.stringify(probe)).toBeUndefined();
      expect(probe.__no_op, JSON.stringify(probe)).toBeUndefined();
      expect(probe.has_customer_view, JSON.stringify(probe)).toBeTruthy();
      expect(probe.anonymous_allowed, JSON.stringify(probe)).toBeFalsy();
      expect(
        probe.customer_view_error?.response?.error?.code,
        JSON.stringify(probe),
      ).toBe('auth_error');
      expect(probe.forbidden_write_allowed, JSON.stringify(probe)).toBeFalsy();
      expect(
        probe.forbidden_write_error?.response?.error?.code,
        JSON.stringify(probe),
      ).toBe('capability_denied');
    },
  );
});
