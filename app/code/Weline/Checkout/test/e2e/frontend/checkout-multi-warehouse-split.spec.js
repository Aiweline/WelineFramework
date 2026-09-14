/**
 * Checkout 多仓拆单运费展示/冻结契约
 *
 * @weline-e2e-spec { module: Weline_Checkout, type: flow, layer: frontend }
 */
const fs = require('fs');
const path = require('path');
const {
  test,
  expect,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Checkout';
const ROOT = path.join(__dirname, '../../..');

moduleDescribe(test, MODULE, '多仓拆单运费结账', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'CHECKOUT-SPLIT-001' },
    '完整通路：freeze quoteSplit + Express 分仓明细',
    async () => {
      const svc = fs.readFileSync(path.join(ROOT, 'Service/CheckoutGroupSubmitService.php'), 'utf8');
      expect(svc).toContain('quoteSplit');
      expect(svc).toContain('shipping_packages');
      expect(svc).toContain('applyFulfillmentSplitKeys');

      const js = fs.readFileSync(path.join(ROOT, 'view/statics/js/express-review.js'), 'utf8');
      expect(js).toContain('data-express-shipping-packages');
      expect(js).toContain('分仓运费明细');

      const phtml = fs.readFileSync(
        path.join(ROOT, 'view/frontend/checkout/express-review.phtml'),
        'utf8',
      );
      expect(phtml).toContain('checkout-split-shipping-packages');
    },
  );
});
