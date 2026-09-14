/**
 * 默认发货锚点回退：结账能命中种子航线
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

moduleDescribe(test, MODULE, '默认发货锚点回退', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'SHIPPING-DEFAULT-ORIGIN-FALLBACK-001' },
    '完整通路：resolveDefaultOriginId 回退 + 种子提升默认',
    async () => {
      const lane = fs.readFileSync(
        path.join(ROOT, 'Service/ServiceLaneMatchService.php'),
        'utf8',
      );
      const seed = fs.readFileSync(
        path.join(ROOT, 'Service/DefaultShippingLaneSeedService.php'),
        'utf8',
      );
      expect(lane).toContain('WarehouseShippingOriginInterface');
      expect(lane).toContain('findShippingAddressId');
      expect(seed).toContain('setDefault($pickedId)');
    },
  );
});
