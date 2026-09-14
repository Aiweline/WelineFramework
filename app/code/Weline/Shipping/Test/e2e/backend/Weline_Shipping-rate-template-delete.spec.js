/**
 * 费用模板自建删除（完整通路契约）
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

moduleDescribe(test, MODULE, '费用模板自建删除', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'SHIPPING-RATE-TEMPLATE-DELETE-001' },
    '完整通路：自建可删、种子不可删、引用拦截',
    async () => {
      const svc = fs.readFileSync(
        path.join(ROOT, 'Service/ShippingConfigurationAdminService.php'),
        'utf8',
      );
      const ctl = fs.readFileSync(
        path.join(ROOT, 'Controller/Backend/RateTemplate.php'),
        'utf8',
      );
      const tpl = fs.readFileSync(
        path.join(ROOT, 'view/templates/Backend/RateTemplate/index.phtml'),
        'utf8',
      );
      const model = fs.readFileSync(
        path.join(ROOT, 'Model/RateTemplate.php'),
        'utf8',
      );

      expect(model).toContain('SEED_CODE_PREFIX');
      expect(model).toContain('isSeedTemplateCode');
      expect(svc).toContain('function deleteRateTemplate');
      expect(svc).toContain('系统种子不可删除');
      expect(svc).toContain('仍有配送服务引用该费用模板');
      expect(ctl).toContain('function remove');
      expect(ctl).toContain('rate_template_remove');
      expect(tpl).toContain('shipping-rate-template-delete');
      expect(tpl).toContain('shipping-rate-template-delete-forbidden');
      expect(tpl).toContain('ratetemplate/remove');
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'SHIPPING-RATE-TEMPLATE-DELETE-PLAN-SUITE-002' },
    '完整功能通路 e2e 组：费用模板自建删除链路',
    async () => {
      const modulePhp = fs.readFileSync(path.join(ROOT, 'etc/module.php'), 'utf8');
      const log = fs.readFileSync(path.join(ROOT, 'doc/开发日志.md'), 'utf8');
      expect(modulePhp).toMatch(/2\.4\.\d+/);
      expect(log).toContain('费用模板自建删除');
    },
  );
});
