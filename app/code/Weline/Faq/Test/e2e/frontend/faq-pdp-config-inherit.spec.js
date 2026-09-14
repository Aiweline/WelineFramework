/**
 * FAQ PDP config inherit / packs chapter e2e.
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

moduleDescribe(test, MODULE, 'e2e-config-inherit', () => {
  moduleCase(test, { module: MODULE, id: 'e2e-config-inherit' }, 'packs SystemConfig scope', async () => {
    const cfg = fs.readFileSync(
      path.join(FAQ, 'extends/module/Weline_SystemConfig/Config/frontend/faq-pdp.phtml'),
      'utf8'
    );
    expect(cfg).toContain('faq/pdp/merge_enabled');
    expect(cfg).toContain('faq/pdp/active_pack');
    expect(cfg).toContain('scope="website,store,channel"');
    expect(cfg).toContain('retail:标准零售');

    const packs = fs.readFileSync(path.join(FAQ, 'Service/FaqTemplatePacks.php'), 'utf8');
    expect(packs).toContain("RETAIL = 'retail'");
    expect(packs).toContain('cross_border');
    expect(packs).toContain('virtual');
    expect(packs).toContain('b2b');

    const stdout = execFileSync(
      'php',
      [
        path.join(ROOT, 'vendor/bin/phpunit'),
        '--bootstrap',
        path.join(ROOT, 'vendor/autoload.php'),
        '--filter',
        'FaqPdpConfigSeedContractTest',
        path.join(FAQ, 'Test/Unit/Service/FaqPdpConfigSeedContractTest.php'),
      ],
      { cwd: ROOT, encoding: 'utf8' }
    );
    expect(stdout).toMatch(/OK/);
  });
});
