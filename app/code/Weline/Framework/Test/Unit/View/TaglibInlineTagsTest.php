<?php
/**
 * Taglib inline tag parsing tests.
 * 覆盖 @static、<css>、<js>、<theme:css>、<theme:js> 等标签的输出格式。
 */

namespace Weline\Framework\Test\Unit\View;

use Weline\Framework\Test\TestCore;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Taglib;
use Weline\Framework\View\Template;

class TaglibInlineTagsTest extends TestCore
{
    /**
     * @var Taglib
     */
    private Taglib $taglib;

    /**
     * @var Template
     */
    private Template $template;

    public function setUp(): void
    {
        parent::setUp();
        self::initRequest();
        $this->taglib = ObjectManager::getInstance(Taglib::class);
        $this->template = ObjectManager::getInstance(Template::class);
    }

    /**
     * Ensure @static tags in attributes are resolved.
     */
    public function testInlineStaticTagInAttribute()
    {
        $content = '<img src="@static(Weline_Frontend::img/logo.png)" alt="Logo">';
        $result = $this->taglib->compile($this->template, $content, 'inline-static-attr.phtml');

        $this->assertStringNotContainsString('@static(', $result, 'Inline @static should be resolved');
        $this->assertStringContainsString('logo.png', $result, 'Resolved URL should include logo filename');
    }

    /**
     * Ensure @static tags in inline text are resolved.
     */
    public function testInlineStaticTagInText()
    {
        $content = "<script>var logoUrl='@static(Weline_Frontend::img/logo.png)';</script>";
        $result = $this->taglib->compile($this->template, $content, 'inline-static-text.phtml');

        $this->assertStringNotContainsString('@static(', $result, 'Inline @static should be resolved in text');
        $this->assertStringContainsString('logo.png', $result, 'Resolved URL should include logo filename');
    }

    /**
     * <css> 标签必须输出正确格式的 href：开发 /Vendor/Module/view/statics/...，生产 /static/{theme}/Vendor/Module/view/statics/...
     */
    public function testCssTagOutputFormat(): void
    {
        $content = '<css>Weline_Theme::ui/weline-foundation.css</css>';
        $result = $this->taglib->compile($this->template, $content, 'css-tag.phtml');

        $this->assertStringNotContainsString('<css>', $result, '原始 <css> 标签应被替换');
        $this->assertStringContainsString('<link', $result, '应输出 link 标签');
        $this->assertMatchesRegularExpression(
            "#href='([^']+)'#",
            $result,
            '应包含 href 属性'
        );
        preg_match("#href='([^']+)'#", $result, $m);
        $href = $m[1];
        $pathOnly = preg_replace('#\?v=.*$#', '', $href);
        if (defined('DEV') && DEV) {
            $this->assertEquals(
                '/Weline/Theme/view/statics/ui/weline-foundation.css',
                $pathOnly,
                '开发环境 href 应为 /Weline/Admin/view/statics/...'
            );
        } else {
            $theme = (Env::getInstance()->getTheme()['path'] ?? 'Weline/Theme/view/theme');
            $theme = str_replace('\\', '/', $theme);
            $expected = '/static/' . $theme . '/Weline/Theme/view/statics/ui/weline-foundation.css';
            $this->assertEquals($expected, $pathOnly, '生产环境 href 应为 /static/{theme}/Weline/Admin/view/statics/...');
        }
    }

    /**
     * <js> 标签必须输出正确格式的 src：开发 /Vendor/Module/view/statics/...，生产 /static/{theme}/Vendor/Module/view/statics/...
     */
    public function testJsTagOutputFormat(): void
    {
        $content = '<js>Weline_Frontend::js/cookie.js</js>';
        $result = $this->taglib->compile($this->template, $content, 'js-tag.phtml');
        $this->assertStringNotContainsString('<js>', $result, '原始 <js> 标签应被替换');
        $this->assertStringContainsString('<script', $result, '应输出 script 标签');
        $this->assertMatchesRegularExpression(
            "#src='([^']+)'#",
            $result,
            '应包含 src 属性'
        );
        preg_match("#src='([^']+)'#", $result, $m);
        $src = $m[1];
        $pathOnly = preg_replace('#\?v=.*$#', '', $src);
        if (defined('DEV') && DEV) {
            $this->assertEquals(
                '/Weline/Frontend/view/statics/js/cookie.js',
                $pathOnly,
                '开发环境 src 应为 /Weline/Admin/view/statics/...'
            );
        } else {
            $theme = (Env::getInstance()->getTheme()['path'] ?? 'Weline/Theme/view/theme');
            $theme = str_replace('\\', '/', $theme);
            $expected = '/static/' . $theme . '/Weline/Frontend/view/statics/js/cookie.js';
            $this->assertEquals($expected, $pathOnly, '生产环境 src 应为 /static/{theme}/Weline/Admin/view/statics/...');
        }
    }

    /**
     * <js>Weline_Backend::js/... 格式必须正确解析，不得输出空路径或错模块。
     */
    public function testJsTagWelineBackendFormat(): void
    {
        $content = '<js>Weline_Backend::js/runtime.js</js>';
        $result = $this->taglib->compile($this->template, $content, 'head-js.phtml');
        $this->assertStringNotContainsString('<js>', $result, '原始 <js> 标签应被替换');
        $this->assertMatchesRegularExpression("#src='([^']+)'#", $result, '应包含 src 属性');
        preg_match("#src='([^']+)'#", $result, $m);
        $pathOnly = preg_replace('#\?v=.*$#', '', $m[1] ?? '');
        $this->assertStringContainsString('runtime.js', $pathOnly, '路径应包含文件名 runtime.js');
        $this->assertStringNotContainsString('/view/statics/\'</script>', $pathOnly, '路径不应以 statics/ 结尾无文件名');
        // 模块应为 Backend 而非 Admin
        $this->assertMatchesRegularExpression('#Weline/Backend/view/statics/#', $pathOnly, '应为 Weline_Backend 模块路径');
    }

    /**
     * <js type="module"> 必须保留 type 属性，否则含 import.meta 的脚本会在非 module 上下文语法错误。
     */
    public function testJsTagPreservesModuleTypeAttribute(): void
    {
        $content = '<js type="module">Weline_Theme::ui/weline-ui.js</js>';
        $result = $this->taglib->compile($this->template, $content, 'js-module-type.phtml');

        $this->assertStringNotContainsString('<js', $result, '原始 <js> 标签应被替换');
        $this->assertStringContainsString('<script type="module"', $result, '应输出 type="module"');
        $this->assertStringContainsString("src='/Weline/Theme/view/statics/ui/weline-ui.js", $result);
        if (defined('DEV') && DEV) {
            $this->assertMatchesRegularExpression(
                "#src='/Weline/Theme/view/statics/ui/weline-ui\\.js\\?v=dev_[a-f0-9]{12}'#",
                $result,
                '开发环境静态资源必须带内容指纹，普通刷新不能继续命中旧 UI'
            );
        }
        $this->assertStringContainsString('</script>', $result);
    }

    public function testDevelopmentJsAndCssTagsCarryStableContentFingerprints(): void
    {
        if (!defined('DEV') || !DEV) {
            $this->markTestSkipped('内容指纹只属于开发环境。');
        }

        $jsSource = '<js>Weline_Theme::ui/weline-ui.js</js>';
        $cssSource = '<css>Weline_Theme::ui/weline-foundation.css</css>';
        $js = $this->taglib->compile(
            $this->template,
            $jsSource,
            'js-development-fingerprint.phtml'
        );
        $css = $this->taglib->compile(
            $this->template,
            $cssSource,
            'css-development-fingerprint.phtml'
        );

        self::assertMatchesRegularExpression('/weline-ui\\.js\\?v=dev_[a-f0-9]{12}/', $js);
        self::assertMatchesRegularExpression('/weline-foundation\\.css\\?v=dev_[a-f0-9]{12}/', $css);

        $jsSourceAgain = '<js>Weline_Theme::ui/weline-ui.js</js>';
        $jsAgain = $this->taglib->compile(
            $this->template,
            $jsSourceAgain,
            'js-development-fingerprint-repeat.phtml'
        );
        preg_match('/weline-ui\\.js\\?v=([^\'\"]+)/', $js, $first);
        preg_match('/weline-ui\\.js\\?v=([^\'\"]+)/', $jsAgain, $second);
        self::assertSame($first[1] ?? null, $second[1] ?? null, '内容不变时版本指纹必须稳定');
    }

    /**
     * <theme:css> 字面量路径走编译期静态镜像（与 Taglib 文档 / CompileTimeStaticMirror 契约一致）。
     * 动态路径仍吐运行期 PHP；主题 URL 由 view/tpl 的 theme_id 分片承载，不是共享无主题缓存。
     */
    public function testThemeCssTagOutputFormat(): void
    {
        $content = '<theme:css>Weline_Theme::theme/frontend/assets/css/theme.css</theme:css>';
        $result = $this->taglib->compile($this->template, $content, 'theme-css-tag.phtml');

        $this->assertStringNotContainsString('<theme:css>', $result, '原始 <theme:css> 标签应被替换');
        $this->assertStringContainsString('<link', $result, '应输出 link 标签');
        $this->assertStringNotContainsString(
            '$__themeCssHref = $this->fetchTagSource(',
            $result,
            '字面量 theme:css 应编译期直出，不再吐运行期 fetchTagSource'
        );
        $this->assertStringNotContainsString('<?php', $result, '字面量 theme:css 不得残留 PHP');
        $this->assertStringContainsString('frontend/assets/css/theme.css', $result);
        $this->assertStringContainsString('rel="stylesheet"', $result);
        $this->assertStringNotContainsString('preview_', $result, '编译期镜像不得烘入 preview Token');
        if (defined('DEV') && DEV) {
            $this->assertMatchesRegularExpression(
                '/theme\\.css\\?v=dev_[a-f0-9]{12}/',
                $result,
                'DEV 下 theme:css 镜像须带内容指纹'
            );
        }
    }

    /**
     * <theme:js> 字面量路径走编译期静态镜像（与 Taglib 文档 / CompileTimeStaticMirror 契约一致）。
     */
    public function testThemeJsTagOutputFormat(): void
    {
        $content = '<theme:js>Weline_Theme::theme/frontend/assets/js/theme.js</theme:js>';
        $result = $this->taglib->compile($this->template, $content, 'theme-js-tag.phtml');

        $this->assertStringNotContainsString('<theme:js>', $result, '原始 <theme:js> 标签应被替换');
        $this->assertStringContainsString('<script', $result, '应输出 script 标签');
        $this->assertStringNotContainsString(
            '$__themeJsSrc = $this->fetchTagSource(',
            $result,
            '字面量 theme:js 应编译期直出，不再吐运行期 fetchTagSource'
        );
        $this->assertStringNotContainsString('<?php', $result, '字面量 theme:js 不得残留 PHP');
        $this->assertStringContainsString('frontend/assets/js/theme.js', $result);
        $this->assertStringContainsString('</script>', $result);
        $this->assertStringNotContainsString('preview_', $result, '编译期镜像不得烘入 preview Token');
        if (defined('DEV') && DEV) {
            $this->assertMatchesRegularExpression(
                '/theme\\.js\\?v=dev_[a-f0-9]{12}/',
                $result,
                'DEV 下 theme:js 镜像须带内容指纹'
            );
        }
    }
}
