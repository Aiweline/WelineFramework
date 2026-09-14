/**
 * Inventory 分仓规划契约
 *
 * @weline-e2e-spec { module: Weline_Inventory, type: flow, layer: backend }
 */
const fs = require('fs');
const path = require('path');
const {
  test,
  expect,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Inventory';
const ROOT = path.join(__dirname, '../../..');

moduleDescribe(test, MODULE, '履约分仓规划', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'INV-SPLIT-PLAN-001' },
    '完整通路：FulfillmentSplitPlan Interface + 确定性选仓',
    async () => {
      const modulePhp = fs.readFileSync(path.join(ROOT, 'etc/module.php'), 'utf8');
      expect(modulePhp).toContain('FulfillmentSplitPlanInterface');
      expect(modulePhp).toContain('2.5.23');
      const svc = fs.readFileSync(path.join(ROOT, 'Service/FulfillmentSplitPlanService.php'), 'utf8');
      expect(svc).toContain('wh:');
      expect(svc).toContain('resolveDefault');
      expect(svc).not.toMatch(/mt_rand|array_rand|shuffle\(/);
    },
  );
});
