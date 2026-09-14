/**
 * Plan-suite: dual-mode add uses PDP selling-mode only (no cart chooser dialog).
 *
 * @weline-e2e-spec { module: Weline_B2B, type: feature, layer: frontend }
 */

const path = require('path');
const fs = require('fs');
const {
  test,
  expect,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_B2B';
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');

moduleDescribe(test, MODULE, '双模式加购无选车计划链路', () => {
  test.setTimeout(60000);

  moduleCase(
    test,
    { module: MODULE, id: 'B2B-CART-TYPE-CHOOSER-SUITE' },
    '计划链路e2e组：加购不再接线选车弹窗',
    async () => {
      const switcher = fs.readFileSync(
        path.join(ROOT_DIR, 'app/code/Weline/B2B/view/templates/frontend/widgets/selling-mode-switcher.phtml'),
        'utf8',
      );
      const sellingJs = fs.readFileSync(
        path.join(ROOT_DIR, 'app/code/Weline/B2B/view/statics/js/selling-mode.js'),
        'utf8',
      );
      const cartJs = fs.readFileSync(
        path.join(ROOT_DIR, 'app/code/Weline/Cart/view/statics/js/widgets/product-purchase-actions.js'),
        'utf8',
      );
      expect(switcher.includes('data-testid="b2b-cart-type-chooser"')).toBe(false);
      expect(sellingJs.includes('confirmCartTypeForAdd')).toBe(false);
      expect(cartJs.includes('confirmCartTypeForAdd')).toBe(false);
      expect(switcher.includes('data-testid="selling-mode-segment"')).toBe(true);
    },
  );
});
