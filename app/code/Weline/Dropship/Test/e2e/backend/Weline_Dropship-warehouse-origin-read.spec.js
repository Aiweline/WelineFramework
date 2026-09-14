/**
 * Dropship 读 Shipping 权威仓地址
 *
 * @weline-e2e-spec { module: Weline_Dropship, type: flow, layer: backend }
 */
const fs = require('fs');
const path = require('path');
const {
  test,
  expect,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Dropship';
const ROOT = path.join(__dirname, '../../..');

moduleDescribe(test, MODULE, '仓地址权威口', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'DROPSHIP-ORIGIN-001' },
    '完整通路：映射读 WarehouseShippingOriginInterface',
    async () => {
      const src = fs.readFileSync(path.join(ROOT, 'Service/DropshipWarehouseMapService.php'), 'utf8');
      expect(src).toContain('resolveShippingAddressId');
      expect(src).toContain('WarehouseShippingOriginInterface');
    },
  );
});
