/**
 * you-may-like / recently-viewed default_injections 通路。
 *
 * @weline-e2e-spec { module: Weline_Theme, type: feature, layer: frontend }
 * @weline-e2e-runtime fallback
 */
const fs = require('fs');
const path = require('path');
const {
  test,
  expect,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Theme';
const ROOT = path.resolve(__dirname, '../../../../../../..');

moduleDescribe(test, MODULE, 'e2e-injection you-may-like default_injections pathway', () => {
  moduleCase(test, { module: MODULE, id: 'A-e2e-injection' }, '完整通路 Playwright：you-may-like default_injections 前后端', async () => {
    const widget = fs.readFileSync(
      path.join(ROOT, 'app/code/Weline/Product/view/templates/frontend/widgets/you-may-like.phtml'),
      'utf8'
    );
    expect(widget).toContain('@widget.default_injections');
    expect(widget).toContain('"slot":"product-you-may-like"');
    expect(widget).toContain('"required":true');
    expect(widget).toContain('youMayLikeCards');
    expect(widget).toContain('wpc-listing-card');

    const recently = fs.readFileSync(
      path.join(ROOT, 'app/code/Weline/RecentlyViewed/extends/module/Weline_Widget/Weline_RecentlyViewed/widget.php'),
      'utf8'
    );
    expect(recently).toContain("'slot' => 'product-recently-viewed'");
    expect(recently).toContain("'required' => true");

    const generated = fs.readFileSync(path.join(ROOT, 'generated/widgets.php'), 'utf8');
    expect(generated).toContain("'you-may-like'");
    expect(generated).toContain('product-you-may-like');
    expect(generated).toContain("Weline_Product::templates/frontend/widgets/you-may-like.phtml");
  });
});
