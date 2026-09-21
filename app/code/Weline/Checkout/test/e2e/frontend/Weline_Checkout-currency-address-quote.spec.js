/**
 * Checkout currency reload must keep Americas lane for selected US address.
 *
 * @weline-e2e-spec { module: Weline_Checkout, type: feature, layer: frontend }
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
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');

moduleDescribe(test, MODULE, 'Checkout currency address quote', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'CK-CHECKOUT-CURRENCY-ADDRESS-001' },
    '统一结账窗：地址解析器与 Express 共用；部件 resolveQuoteAddress；跨国家 selectAddress',
    async () => {
      const provider = fs.readFileSync(
        path.join(
          ROOT_DIR,
          'app/code/Weline/Checkout/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php',
        ),
        'utf8',
      );
      const resolver = fs.readFileSync(
        path.join(ROOT_DIR, 'app/code/Weline/Checkout/Service/CheckoutShippingAddressResolver.php'),
        'utf8',
      );
      const express = fs.readFileSync(
        path.join(ROOT_DIR, 'app/code/Weline/Checkout/Service/ExpressCheckoutFlowService.php'),
        'utf8',
      );
      const delivery = fs.readFileSync(
        path.join(ROOT_DIR, 'app/code/Weline/Checkout/Service/CheckoutDeliveryContextService.php'),
        'utf8',
      );
      const checkoutTpl = fs.readFileSync(
        path.join(ROOT_DIR, 'app/code/Weline/Checkout/view/frontend/checkout/index.phtml'),
        'utf8',
      );
      const widgetJs = fs.readFileSync(
        path.join(
          ROOT_DIR,
          'app/code/Weline/Shipping/view/statics/js/widgets/checkout-shipping-address.js',
        ),
        'utf8',
      );
      const shippingMgr = fs.readFileSync(
        path.join(ROOT_DIR, 'app/code/Weline/Shipping/Service/ShippingServiceManager.php'),
        'utf8',
      );

      expect(resolver).toContain('class CheckoutShippingAddressResolver');
      expect(provider).toContain('shippingAddressResolver->resolve');
      expect(express).toContain('CheckoutShippingAddressResolver');
      expect(delivery).toContain("listAddresses('')");
      expect(checkoutTpl).toContain('resolveQuoteAddress');
      expect(checkoutTpl).toContain('text(shipping.country_code).trim().toUpperCase()');
      expect(widgetJs).toContain('syncFormFromSelectedCard');
      expect(widgetJs).toContain('function resolveQuoteAddress');
      expect(shippingMgr).toContain('fx_skipped');
      expect(shippingMgr).toContain('getLastQuoteDiagnostics');
    },
  );
});
