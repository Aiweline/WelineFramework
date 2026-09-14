/**
 * 多仓拆单运费契约（完整通路源码门禁）
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

moduleDescribe(test, MODULE, '多仓拆单运费', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'SHIPPING-SPLIT-001' },
    '完整通路：仓绑定 + quoteSplit + 一键复制 + 非按距离文案',
    async () => {
      const modulePhp = fs.readFileSync(path.join(ROOT, 'etc/module.php'), 'utf8');
      expect(modulePhp).toContain('SplitShippingQuoteServiceInterface');
      expect(modulePhp).toContain('WarehouseShippingOriginInterface');
      expect(modulePhp).toContain('2.4.89');

      const split = fs.readFileSync(path.join(ROOT, 'Service/SplitShippingQuoteService.php'), 'utf8');
      expect(split).toContain('quoteSplit');
      expect(split).toContain('listSplitOptions');
      expect(split).toContain('shipping_profile_conflict');
      expect(split).toContain('buildRequestHash');

      const view = fs.readFileSync(
        path.join(ROOT, 'view/templates/Backend/ShippingService/index.phtml'),
        'utf8',
      );
      expect(view).toContain('copy-default-lanes-btn');
      expect(view).toContain('仓 ↔ 发货地址绑定');

      const rate = fs.readFileSync(
        path.join(ROOT, 'view/templates/Backend/RateTemplate/index.phtml'),
        'utf8',
      );
      expect(rate).toContain('相加');
      expect(rate).toContain('按距离');
    },
  );
});
