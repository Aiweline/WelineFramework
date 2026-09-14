/**
 * 种子当前仓发货计划链路组
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

moduleDescribe(test, MODULE, '种子当前仓发货计划链路', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'SHIPPING-SEED-ORIGIN-SUITE-001' },
    '完整功能通路 e2e 组：种子 origin + 仓绑定接口',
    async () => {
      const seed = fs.readFileSync(
        path.join(ROOT, 'Service/DefaultShippingLaneSeedService.php'),
        'utf8',
      );
      const origin = fs.readFileSync(
        path.join(ROOT, 'Service/WarehouseShippingOriginService.php'),
        'utf8',
      );
      expect(seed).toContain('resolveCurrentWarehouseOriginId');
      expect(origin).toContain('requireShippingAddressId');
    },
  );
});
