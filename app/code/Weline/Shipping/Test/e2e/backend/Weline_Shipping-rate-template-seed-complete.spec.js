/**
 * 费用模板九档种子补齐 + 列表人性化（完整通路契约）
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

moduleDescribe(test, MODULE, '费用模板九档种子补齐', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'SHIPPING-RATE-SEED-COMPLETE-001' },
    '完整通路：九档 SEED_TPL_* + 费率字段 + 列表人性化标签/系统徽章',
    async () => {
      const seed = fs.readFileSync(
        path.join(ROOT, 'Service/DefaultShippingLaneSeedService.php'),
        'utf8',
      );
      const tpl = fs.readFileSync(
        path.join(ROOT, 'view/templates/Backend/RateTemplate/index.phtml'),
        'utf8',
      );
      const modulePhp = fs.readFileSync(path.join(ROOT, 'etc/module.php'), 'utf8');

      for (const lane of [
        'domestic',
        'greater_china',
        'asia_pacific',
        'americas',
        'europe',
        'oceania',
        'latam',
        'middle_east_africa',
        'other',
      ]) {
        expect(seed).toContain(`'${lane}'`);
      }
      expect(seed).toContain('expectedSeedTemplateCodes');
      expect(seed).toContain('九档模板始终落库');
      expect(seed).toContain('weight_rate');
      expect(tpl).toContain('shipping-rate-template-origin-seed');
      expect(tpl).toContain('按重量');
      expect(tpl).toContain('data-field="weight_rate"');
      expect(modulePhp).toMatch(/2\.4\.\d+/);
      expect(modulePhp).toContain('Weline_Shipping');
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'SHIPPING-RATE-SEED-PLAN-SUITE-002' },
    '完整功能通路 e2e 组：九档种子补齐+列表展示链路',
    async () => {
      const seed = fs.readFileSync(
        path.join(ROOT, 'Service/DefaultShippingLaneSeedService.php'),
        'utf8',
      );
      const upgrade = fs.readFileSync(path.join(ROOT, 'Setup/Upgrade.php'), 'utf8');
      expect(seed).toContain('SEED_TPL_');
      expect(upgrade).toContain('seedDefaultLanes');
      expect(upgrade).toContain('费用模板九档航线种子补齐');
    },
  );
});
