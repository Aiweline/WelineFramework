/**
 * FAQ PDP widget/SEO pathway + plan suite closeout.
 *
 * @weline-e2e-spec { module: Weline_Faq, type: feature, layer: frontend }
 * @weline-e2e-runtime fallback
 */

const path = require('path');
const fs = require('fs');
const { execFileSync } = require('child_process');
const {
  test,
  expect,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Faq';
const ROOT = path.resolve(__dirname, '../../../../../../..');
const FAQ = path.join(ROOT, 'app/code/Weline/Faq');

function runPhpunit(filter, file) {
  return execFileSync(
    'php',
    [
      path.join(ROOT, 'vendor/bin/phpunit'),
      '--bootstrap',
      path.join(ROOT, 'vendor/autoload.php'),
      '--filter',
      filter,
      file,
    ],
    { cwd: ROOT, encoding: 'utf8' }
  );
}

moduleDescribe(test, MODULE, 'e2e-pdp-faq and e2e-plan-suite', () => {
  moduleCase(test, { module: MODULE, id: 'e2e-pdp-faq' }, 'widget SEO single resolve path', async () => {
    const widget = fs.readFileSync(
      path.join(FAQ, 'view/templates/frontend/widgets/product-faq.phtml'),
      'utf8'
    );
    expect(widget).toContain('FaqPdpResolveService');
    expect(widget).toContain('resolveForPdp');
    expect(widget).not.toContain("listForEntity('product'");
    expect(widget).toContain('weline-product-faq__source');
    expect(widget).toContain('--weline-theme-');

    const out = runPhpunit(
      'FaqPdpWidgetSeoContractTest',
      path.join(FAQ, 'Test/Unit/Service/FaqPdpWidgetSeoContractTest.php')
    );
    expect(out).toMatch(/OK/);
  });

  moduleCase(test, { module: MODULE, id: 'e2e-plan-suite' }, 'unit suite for resolve config widget', async () => {
    const out = runPhpunit('FaqPdp', path.join(FAQ, 'Test/Unit/Service'));
    expect(out).toMatch(/OK/);
  });
});
