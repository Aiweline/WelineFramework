<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Deploy;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Deploy\StaticPublishExclusion;

/**
 * UT：静态发布排除规则（pub/static 只许含运行时资源）。
 *
 * 反例来自真实事故：vendored 库的 docs/tests/.github/*.md 与设计目录的
 * doc/test 被铺进 Web 根，文档可被直接读取。
 */
final class StaticPublishExclusionTest extends TestCase
{
    /**
     * @return list<array{0:string,1:bool}>
     */
    public static function excludedPathProvider(): array
    {
        return [
            'vendored docs' => ['libs/bootstrap-select-1.14.0-beta3/docs/usage.md', true],
            'vendored tests' => ['libs/bootstrap-treeview-master/tests/README.md', true],
            'vendored github meta' => ['libs/x/.github/workflows/cypress.yml', true],
            'vendored cypress specs' => ['libs/x/cypress/integration/basic.spec.js', true],
            'vendored jasmine spec' => ['libs/x/spec/helper.js', true],
            'vendored lockfile' => ['libs/x/package-lock.json', true],
            'vendored gruntfile' => ['libs/x/Gruntfile.js', true],
            'vendored package manifest' => ['libs/x/package.json', true],
            'vendored nuget' => ['libs/x/nuget/lib.dll', true],
            'vendored node_modules' => ['libs/x/node_modules/a/index.js', true],
            'lint config' => ['libs/x/.eslintrc.json', true],
            'lint ignore' => ['libs/x/.eslintignore', true],
            'top level readme' => ['README.md', true],
            'nested changelog' => ['libs/x/CHANGELOG.md', true],
            'extensionless license' => ['libs/x/LICENSE', true],
            'doc segment' => ['doc/README.md', true],
            'docs segment' => ['libs/x/docs/index.html', true],
            'design doc subtree' => ['doc/修复/深入复查/before/doc/资料/wireframes/build-effects.py', true],
            'design test scripts' => ['test/deep-remediation.py', true],
            'design candidate php' => ['test/content-slot-preservation.php', true],
            'doc directory itself' => ['libs/x/docs', true],

            'runtime css' => ['css/theme.css', false],
            'runtime js module' => ['js/app.mjs', false],
            'runtime json manifest' => ['manifest.webmanifest', false],
            'runtime sourcemap' => ['js/bundle.js.map', false],
            'runtime image' => ['images/hero.webp', false],
            'runtime font' => ['fonts/icon.woff2', false],
            'runtime robots' => ['robots.txt', false],
            'runtime sitemap' => ['sitemap.xml', false],
            'real layout named test' => ['frontend/layouts/test/assets-test.phtml', false],
            'real layout dir named test' => ['frontend/layouts/test', false],
            'layout asset under layouts' => ['layouts/test/foo.js', false],
            'partials namespace' => ['frontend/partials/test/foo.js', false],
            'statics css partials' => ['css/partials/tokens.css', false],
            'file named docs.css' => ['css/docs.css', false],
            'empty path' => ['', false],
            'backslash separators' => ['libs\\x\\docs\\readme.md', true],
        ];
    }

    /**
     * @dataProvider excludedPathProvider
     */
    public function testExclusionVerdictMatchesRuntimeContract(string $relativePath, bool $expected): void
    {
        self::assertSame($expected, StaticPublishExclusion::isExcluded($relativePath));
    }

    public function testDirectoryVerdictPrunesWholeSubtrees(): void
    {
        self::assertTrue(StaticPublishExclusion::isExcluded('test', true));
        self::assertTrue(StaticPublishExclusion::isExcluded('libs/x/tests', true));
        self::assertTrue(StaticPublishExclusion::isExcluded('libs/x/docs', true));
        self::assertTrue(StaticPublishExclusion::isExcluded('libs/x/.github', true));
        self::assertFalse(StaticPublishExclusion::isExcluded('frontend/layouts/test', true));
        self::assertFalse(StaticPublishExclusion::isExcluded('js/partials', true));
    }

    public function testDirectoryVerdictIgnoresFileNameRules(): void
    {
        // 目录名恰好形似文档文件名时，不应仅凭文件名规则剪枝（目录按段规则判定）。
        self::assertFalse(StaticPublishExclusion::isExcluded('assets/readme', true));
        self::assertTrue(StaticPublishExclusion::isExcluded('assets/readme', false));
    }

    public function testLeadingAndTrailingSeparatorsAreTolerated(): void
    {
        self::assertTrue(StaticPublishExclusion::isExcluded('/libs/x/docs/'));
        self::assertFalse(StaticPublishExclusion::isExcluded('/js/app.js/'));
    }
}
