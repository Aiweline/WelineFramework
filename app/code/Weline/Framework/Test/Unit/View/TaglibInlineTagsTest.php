<?php
/**
 * Taglib inline tag parsing tests.
 * 覆盖 @static、<css>、<js>、<theme:css>、<theme:js> 等标签的输出格式。
 */

namespace Weline\Framework\Test\Unit\View;

use Weline\Framework\App\Env;
use Weline\Framework\UnitTest\TestCore;
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
        $content = '<css>Weline_Admin::backend/lib/bootstrap-5.1.3-dist/css/bootstrap.min.css</css>';
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
                '/Weline/Admin/view/statics/backend/lib/bootstrap-5.1.3-dist/css/bootstrap.min.css',
                $pathOnly,
                '开发环境 href 应为 /Weline/Admin/view/statics/...'
            );
        } else {
            $theme = Env::get('theme')['path'] ?? Env::default_theme_DATA['path'];
            $theme = str_replace('\\', '/', $theme);
            $expected = '/static/' . $theme . '/Weline/Admin/view/statics/backend/lib/bootstrap-5.1.3-dist/css/bootstrap.min.css';
            $this->assertEquals($expected, $pathOnly, '生产环境 href 应为 /static/{theme}/Weline/Admin/view/statics/...');
        }
    }

    /**
     * <js> 标签必须输出正确格式的 src：开发 /Vendor/Module/view/statics/...，生产 /static/{theme}/Vendor/Module/view/statics/...
     */
    public function testJsTagOutputFormat(): void
    {
        $content = '<js>Weline_Admin::backend/lib/jquery/3.6.0/jquery.js</js>';
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
                '/Weline/Admin/view/statics/backend/lib/jquery/3.6.0/jquery.js',
                $pathOnly,
                '开发环境 src 应为 /Weline/Admin/view/statics/...'
            );
        } else {
            $theme = Env::get('theme')['path'] ?? Env::default_theme_DATA['path'];
            $theme = str_replace('\\', '/', $theme);
            $expected = '/static/' . $theme . '/Weline/Admin/view/statics/backend/lib/jquery/3.6.0/jquery.js';
            $this->assertEquals($expected, $pathOnly, '生产环境 src 应为 /static/{theme}/Weline/Admin/view/statics/...');
        }
    }

    /**
     * <js>Weline_Backend::/backend/... 格式（与 head.phtml 一致）必须正确解析，不得输出空路径或错模块
     */
    public function testJsTagWelineBackendFormat(): void
    {
        $content = '<js>Weline_Backend::/backend/lib/jquery/3.6.0/jquery.js</js>';
        $result = $this->taglib->compile($this->template, $content, 'head-js.phtml');
        $this->assertStringNotContainsString('<js>', $result, '原始 <js> 标签应被替换');
        $this->assertMatchesRegularExpression("#src='([^']+)'#", $result, '应包含 src 属性');
        preg_match("#src='([^']+)'#", $result, $m);
        $pathOnly = preg_replace('#\?v=.*$#', '', $m[1] ?? '');
        $this->assertStringContainsString('jquery.js', $pathOnly, '路径应包含文件名 jquery.js');
        $this->assertStringNotContainsString('/view/statics/\'</script>', $pathOnly, '路径不应以 statics/ 结尾无文件名');
        // 模块应为 Backend 而非 Admin
        $this->assertMatchesRegularExpression('#Weline/Backend/view/statics/#', $pathOnly, '应为 Weline_Backend 模块路径');
    }

    /**
     * <theme:css> 标签必须输出正确格式的 href：开发 /Vendor/Module/view/theme/...，生产 /static/{theme}/Vendor/Module/view/theme/...
     */
    public function testThemeCssTagOutputFormat(): void
    {
        $content = '<theme:css>Weline_Theme::theme/frontend/assets/css/theme.css</theme:css>';
        $result = $this->taglib->compile($this->template, $content, 'theme-css-tag.phtml');

        $this->assertStringNotContainsString('<theme:css>', $result, '原始 <theme:css> 标签应被替换');
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
            $this->assertStringContainsString(
                '/Weline/Theme/view/theme/',
                $pathOnly,
                '开发环境 theme:css href 应包含 /Weline/Theme/view/theme/'
            );
            $this->assertStringContainsString('frontend/assets/css/theme.css', $pathOnly, '路径应包含主题相对路径');
        } else {
            $theme = Env::get('theme')['path'] ?? Env::default_theme_DATA['path'];
            $theme = str_replace('\\', '/', $theme);
            $this->assertStringStartsWith('/static/' . $theme . '/', $pathOnly, '生产环境 theme:css href 应以 /static/{theme}/ 开头');
            $this->assertStringContainsString('Weline/Theme/view/theme/', $pathOnly, '应包含模块 theme 路径');
        }
    }

    /**
     * <theme:js> 标签必须输出正确格式的 src：开发 /Vendor/Module/view/theme/...，生产 /static/{theme}/Vendor/Module/view/theme/...
     */
    public function testThemeJsTagOutputFormat(): void
    {
        $content = '<theme:js>Weline_Theme::theme/frontend/assets/js/theme.js</theme:js>';
        $result = $this->taglib->compile($this->template, $content, 'theme-js-tag.phtml');

        $this->assertStringNotContainsString('<theme:js>', $result, '原始 <theme:js> 标签应被替换');
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
            $this->assertStringContainsString(
                '/Weline/Theme/view/theme/',
                $pathOnly,
                '开发环境 theme:js src 应包含 /Weline/Theme/view/theme/'
            );
            $this->assertStringContainsString('frontend/assets/js/theme.js', $pathOnly, '路径应包含主题相对路径');
        } else {
            $theme = Env::get('theme')['path'] ?? Env::default_theme_DATA['path'];
            $theme = str_replace('\\', '/', $theme);
            $this->assertStringStartsWith('/static/' . $theme . '/', $pathOnly, '生产环境 theme:js src 应以 /static/{theme}/ 开头');
            $this->assertStringContainsString('Weline/Theme/view/theme/', $pathOnly, '应包含模块 theme 路径');
        }
    }
}
