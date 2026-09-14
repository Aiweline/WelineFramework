/**
 * 种子航线锚定当前仓发货地址
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

moduleDescribe(test, MODULE, '种子当前仓发货', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'SHIPPING-SEED-ORIGIN-WH-001' },
    '前后端通路：SEED_LANE_* 绑定默认发货地址 origin',
    async () => {
      const seed = fs.readFileSync(
        path.join(ROOT, 'Service/DefaultShippingLaneSeedService.php'),
        'utf8',
      );
      expect(seed).toContain('ORIGIN_SHIPPING_ADDRESS_ID');
      expect(seed).toContain('resolveCurrentWarehouseOriginId');
      expect(seed).toContain('bindDefaultWarehouseOrigin');
      expect(seed).toContain('ensureSeedShippingAddressFromWarehouse');
      expect(seed).toContain('resolveCurrentWarehouseContext');
      expect(seed).not.toMatch(/ORIGIN_SHIPPING_ADDRESS_ID\s*=>\s*0/);

      const modulePhp = fs.readFileSync(path.join(ROOT, 'etc/module.php'), 'utf8');
      expect(modulePhp).toContain('2.4.90');
    },
  );
});
