/**
 * Source contract: shipping shell + local/yanwen providers.
 *
 * @weline-e2e-spec { module: Weline_Shipping, type: contract, layer: frontend }
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
const shippingRoot = path.resolve(__dirname, '../../../');

moduleDescribe(test, MODULE, 'Shipping shell provider source contract', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'SHIP-SHELL-PROVIDER-001' },
    '壳文档/Facade/内置 Local+Yanwen Provider 存在；quoteRates 委派且无供应商字面量',
    async () => {
      expect(fs.existsSync(path.join(shippingRoot, 'doc/shipping-shell.md'))).toBeTruthy();
      expect(fs.existsSync(path.join(shippingRoot, 'Interface/ShippingProviderInterface.php'))).toBeTruthy();
      expect(fs.existsSync(path.join(shippingRoot, 'Service/ShippingFacade.php'))).toBeTruthy();
      expect(fs.existsSync(path.join(
        shippingRoot,
        'extends/module/Weline_Shipping/ShippingProvider/LocalRateTemplateProvider.php',
      ))).toBeTruthy();
      expect(fs.existsSync(path.join(
        shippingRoot,
        'extends/module/Weline_Shipping/ShippingProvider/YanwenProvider.php',
      ))).toBeTruthy();

      const manager = fs.readFileSync(path.join(shippingRoot, 'Service/ShippingServiceManager.php'), 'utf8');
      expect(manager).toContain('ShippingProviderManager');
      expect(manager).toContain('->quote(');
      expect(manager).not.toMatch(/yw56|yanwen|Yanwen/i);

      const tracking = fs.readFileSync(path.join(shippingRoot, 'Service/TrackingService.php'), 'utf8');
      expect(tracking).toContain('ShippingFacade');
      expect(tracking).not.toContain('北京分拨中心');
    },
  );
});
