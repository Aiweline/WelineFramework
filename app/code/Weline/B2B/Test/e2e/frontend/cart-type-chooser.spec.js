/**
 * Dual-mode add-to-cart: PDP selling-mode already chosen — no cart-type chooser dialog.
 *
 * @weline-e2e-spec { module: Weline_B2B, type: feature, layer: frontend }
 */

const path = require('path');
const {
  test,
  expect,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_B2B';
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');

function assertWiring() {
  const switcher = require('fs').readFileSync(
    path.join(ROOT_DIR, 'app/code/Weline/B2B/view/templates/frontend/widgets/selling-mode-switcher.phtml'),
    'utf8',
  );
  const sellingJs = require('fs').readFileSync(
    path.join(ROOT_DIR, 'app/code/Weline/B2B/view/statics/js/selling-mode.js'),
    'utf8',
  );
  const cartJs = require('fs').readFileSync(
    path.join(ROOT_DIR, 'app/code/Weline/Cart/view/statics/js/widgets/product-purchase-actions.js'),
    'utf8',
  );
  return {
    dialogGone: !switcher.includes('data-testid="b2b-cart-type-chooser"'),
    confirmGone: !sellingJs.includes('confirmCartTypeForAdd'),
    cartHookGone: !cartJs.includes('confirmCartTypeForAdd'),
    sellingModeKept: switcher.includes('data-testid="selling-mode-segment"'),
  };
}

moduleDescribe(test, MODULE, '双模式加购无选车弹窗', () => {
  test.setTimeout(60000);

  moduleCase(
    test,
    { module: MODULE, id: 'B2B-CART-TYPE-CHOOSER' },
    '通路：详情页购买方式已选，加购不再弹选车',
    async () => {
      const result = assertWiring();
      expect(result.dialogGone, JSON.stringify(result)).toBe(true);
      expect(result.confirmGone).toBe(true);
      expect(result.cartHookGone).toBe(true);
      expect(result.sellingModeKept).toBe(true);
    },
  );
});
