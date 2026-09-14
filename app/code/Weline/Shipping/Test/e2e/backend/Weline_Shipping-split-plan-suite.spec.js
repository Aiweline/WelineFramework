/**
 * 多仓拆单完整功能通路组（plan suite）
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
const REPO = path.join(__dirname, '../../../../../../..');

moduleDescribe(test, MODULE, '多仓拆单计划链路', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'SHIPPING-SPLIT-PLAN-SUITE-001' },
    '完整功能通路：绑定+规划+报价+结账分仓明细',
    async () => {
      const checks = [
        'app/code/Weline/Shipping/Service/SplitShippingQuoteService.php',
        'app/code/Weline/Inventory/Service/FulfillmentSplitPlanService.php',
        'app/code/Weline/Checkout/Service/CheckoutGroupSubmitService.php',
        'app/code/Weline/Dropship/Service/DropshipWarehouseMapService.php',
      ];
      for (const rel of checks) {
        expect(fs.existsSync(path.join(REPO, rel))).toBeTruthy();
      }
      const checkout = fs.readFileSync(
        path.join(REPO, 'app/code/Weline/Checkout/Service/CheckoutGroupSubmitService.php'),
        'utf8',
      );
      expect(checkout).toContain('quoteSplit');
      expect(checkout).toContain('shipping_packages');
      const inv = fs.readFileSync(
        path.join(REPO, 'app/code/Weline/Inventory/etc/module.php'),
        'utf8',
      );
      expect(inv).toContain('FulfillmentSplitPlanInterface');
    },
  );
});
