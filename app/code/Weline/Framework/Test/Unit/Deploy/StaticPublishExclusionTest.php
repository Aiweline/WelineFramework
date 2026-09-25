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
 *
 * 第二类反例是服务端可执行/模板源码：`pub/static` 下曾有 95 个 `.php`
 * （elFinder 连接器三份副本 + 设计主题 register.php / page-boot.php）与
 * 301 个 `.phtml`（设计主题源码模板），均可被 `/static/...` 直接取到。
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
            'real layout named test' => ['frontend/layouts/test/assets-test.css', false],
            'real layout dir named test' => ['frontend/layouts/test', false],
            'layout asset under layouts' => ['layouts/test/foo.js', false],
            'partials namespace' => ['frontend/partials/test/foo.js', false],
            'statics css partials' => ['css/partials/tokens.css', false],
            'file named docs.css' => ['css/docs.css', false],
            'empty path' => ['', false],
            'backslash separators' => ['libs\\x\\docs\\readme.md', true],

            // 服务端可执行 / 模板源码：pub/static 在 Web 根之下，绝不允许落地。
            'elfinder connector php' => ['php/connector.minimal.php', true],
            'elfinder class php' => ['php/elFinder.class.php', true],
            'elfinder php-dist template' => ['php/connector.minimal.php-dist', true],
            'theme source template' => ['frontend/layouts/homepage/default.phtml', true],
            'theme widget template' => ['frontend/widgets/header/default.phtml', true],
            'theme partial template' => ['frontend/partials/head/default.phtml', true],
            'uppercase php extension' => ['php/Connector.PHP', true],
            'phtml under a real layout named test' => ['frontend/layouts/test/assets-test.phtml', true],
            'design theme register script' => ['register.php', true],
            'page boot script' => ['frontend/includes/daocharms-page-boot.php', true],
            'python script' => ['tools/build.py', true],
            'ruby config' => ['libs/slick/config.rb', true],
            'shell script' => ['scripts/deploy.sh', true],
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

    /**
     * 安全契约：服务端可执行 / 模板源码扩展名一律不得进入发布树。
     *
     * `pub/static` 在 Web 根之下：`*.php` 会被 Web 服务器交给 PHP 执行，
     * `.phtml` / `.pht` / `.phar` / `.php-dist` 在 Apache / LiteSpeed 常见
     * `AddHandler` 配置下同样按 PHP 处理；即便服务器不执行，
     * `Router\Core::StaticFile()` 也会用 `file_get_contents()` 回吐源码原文。
     */
    public function testServerSideScriptExtensionsAreNeverPublished(): void
    {
        $extensions = [
            'php', 'phtml', 'pht', 'phps', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8',
            'php-dist',
            'cgi', 'fcgi', 'pl', 'py', 'rb', 'sh', 'bash', 'zsh',
            'asp', 'aspx', 'jsp', 'jspx', 'shtml',
        ];

        foreach ($extensions as $extension) {
            self::assertTrue(
                StaticPublishExclusion::isExcluded('assets/theme.' . $extension),
                '扩展名 .' . $extension . ' 不得进入 pub/static'
            );
        }
    }

    /**
     * 排除只针对**文件**：同名目录不得被剪枝，避免误伤合法静态目录。
     */
    public function testScriptExtensionRuleDoesNotPruneDirectories(): void
    {
        self::assertFalse(StaticPublishExclusion::isExcluded('php', true));
        self::assertFalse(StaticPublishExclusion::isExcluded('assets/theme.php', true));
    }

    /**
     * Web 服务器 / PHP 运行期配置文件同样不得进入 Web 根：它们能改变
     * 「谁能访问、什么会被执行」（`.htaccess` 的 `AddHandler` 等）。
     */
    public function testWebServerConfigFilesAreNeverPublished(): void
    {
        foreach (['.htaccess', '.htpasswd', '.user.ini', 'php.ini', 'web.config'] as $name) {
            self::assertTrue(
                StaticPublishExclusion::isExcluded('php/.tmp/' . $name),
                $name . ' 不得进入 pub/static'
            );
        }
    }
}
