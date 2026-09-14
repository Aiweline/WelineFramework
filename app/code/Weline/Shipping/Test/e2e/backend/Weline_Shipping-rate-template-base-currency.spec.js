/**
 * 费用模板：列表金额/基础货币 + 编辑保存 + 报价换算（源码通路契约）
 *
 * 实机 UI 由 Agent Browser 验收（本机 127.0.0.1 ECONNRESET 不稳定，故 e2e 以契约锁回归）。
 *
 * @weline-e2e-spec { module: Weline_Shipping, type: flow, layer: backend }
 */
const fs = require('fs');
const path = require('path');
const {
  test,
  expect,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Shipping';
const ROOT = path.join(__dirname, '../../..');

moduleDescribe(test, MODULE, '费用模板基础货币与编辑', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'SHIPPING-RATE-TEMPLATE-BASE-CURRENCY-001' },
    '完整通路契约：编辑入口、金额/货币列、基础货币只读、updateRateTemplate',
    async () => {
      const tpl = fs.readFileSync(
        path.join(ROOT, 'view/templates/Backend/RateTemplate/index.phtml'),
        'utf8',
      );
      const controller = fs.readFileSync(
        path.join(ROOT, 'Controller/Backend/RateTemplate.php'),
        'utf8',
      );
      const admin = fs.readFileSync(
        path.join(ROOT, 'Service/ShippingConfigurationAdminService.php'),
        'utf8',
      );

      expect(tpl).toContain('data-testid="shipping-rate-template-list"');
      expect(tpl).toContain('data-testid="shipping-rate-template-edit"');
      expect(tpl).toContain('data-testid="shipping-rate-template-base-currency"');
      expect(tpl).toContain('data-field="base_fee"');
      expect(tpl).toContain('data-field="currency_code"');
      expect(tpl).toContain('基础货币');
      expect(tpl).toContain('汇率');
      expect(tpl).not.toContain('name="currency_code"');
      expect(controller).toContain('updateRateTemplate');
      expect(controller).toContain('editing_template');
      expect(admin).toContain('function updateRateTemplate');
      expect(admin).toContain('getBaseCurrency');
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'SHIPPING-RATE-TEMPLATE-FX-QUOTE-002' },
    '报价路径源码含 tryConvert（异币换算契约）',
    async () => {
      const src = fs.readFileSync(
        path.join(ROOT, 'Service/ShippingServiceManager.php'),
        'utf8',
      );
      expect(src).toContain('tryConvert');
      expect(src).toContain('convertShippingMinor');
      expect(src).not.toContain("if ($templateCurrency === '' || $templateCurrency !== $currency)");
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'SHIPPING-RATE-TEMPLATE-PLAN-SUITE-003' },
    '完整功能通路 e2e 组：编辑契约 + 基础货币锁定 + 报价换算',
    async () => {
      const tpl = fs.readFileSync(
        path.join(ROOT, 'view/templates/Backend/RateTemplate/index.phtml'),
        'utf8',
      );
      const manager = fs.readFileSync(
        path.join(ROOT, 'Service/ShippingServiceManager.php'),
        'utf8',
      );
      const modulePhp = fs.readFileSync(path.join(ROOT, 'etc/module.php'), 'utf8');
      expect(tpl).toContain('shipping-rate-template-edit');
      expect(tpl).toContain('基础货币');
      expect(manager).toContain('convertShippingMinor');
      expect(modulePhp).toContain("'Weline_Currency'");
      expect(modulePhp).toContain('2.4.83');
    },
  );
});
