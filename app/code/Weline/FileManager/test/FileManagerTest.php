<?php

namespace Weline\FileManager\Test;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\TestCore;
use Weline\Framework\View\Template;

class FileManagerTest extends TestCore
{
    public function testFileManager()
    {

        $str = '<file-manager code="weline_media" title="从图库选择" vars="store" target="#image" path="bbs/site/logo"
                                          value="{{site.logo.filename}}" w="50" h="50" multi="0"
                                          ext="png,jpeg,jpg,webp,svg,ico"/>';
        /**@var \Weline\Framework\View\Template $tmp */
        $tmp = ObjectManager::getInstance(Template::class);
        $res = $tmp->tmp_replace($str);
        $this->assertStringContainsString('framework_view_process_block(', $res);
        $this->assertStringContainsString('Weline\\\\MediaManager\\\\Block\\\\WelineMedia', $res);
        $this->assertStringContainsString("'target' => 'image'", $res);
        $this->assertStringContainsString("'path' => 'bbs/site/logo'", $res);
        $this->assertStringContainsString("'ext' => 'png,jpeg,jpg,webp,svg,ico'", $res);
    }

    /**
     * @lang 让 file-manager 走 renderRuntimeTag。回调仍返回编译期 PHP 时，
     * 运行期必须执行 framework_view_process_block，不能把属性源码 echo 进 HTML。
     */
    public function testLangAttributeRuntimeRendersPickerInsteadOfPhpSource(): void
    {
        $str = '<file-manager code="weline_media" target="#logo_dark" title="@lang(选择深色Logo)" vars="configs" preview="1" path="backend/logo/" value="configs.logo_dark" multi="0" ext="jpg,jpeg,png,gif,webp,svg" size="2048000" w="70" h="70" identity_root="config" identity_code="logo_dark" identity_scope="default.default.default" identity_kind="media" identity_field="logo_dark" identity_component="backend" ref_mode="single" strong_ref="1" />';
        /**@var Template $tmp */
        $tmp = ObjectManager::getInstance(Template::class);
        $compiled = $tmp->tmp_replace($str);
        $this->assertStringContainsString('renderRuntimeTag', $compiled);
        $this->assertStringNotContainsString('framework_view_process_block', $compiled);

        $tmp->assign('configs', ['logo_dark' => '']);
        $html = $tmp->getTaglib()->renderRuntimeTag($tmp, 'file-manager', 'tag-self-close', [
            'code' => 'weline_media',
            'target' => '#logo_dark',
            'title' => '选择深色Logo',
            'vars' => 'configs',
            'preview' => '1',
            'path' => 'backend/logo/',
            'value' => 'configs.logo_dark',
            'multi' => '0',
            'ext' => 'jpg,jpeg,png,gif,webp,svg',
            'size' => '2048000',
            'w' => '70',
            'h' => '70',
            'identity_root' => 'config',
            'identity_code' => 'logo_dark',
            'identity_scope' => 'default.default.default',
            'identity_kind' => 'media',
            'identity_field' => 'logo_dark',
            'identity_component' => 'backend',
            'ref_mode' => 'single',
            'strong_ref' => '1',
        ]);

        $this->assertStringNotContainsString('framework_view_process_block', $html);
        $this->assertStringNotContainsString('<?php', $html);
        $this->assertStringContainsString('w-file-picker', $html);
        $this->assertStringContainsString('logo_dark', $html);
    }
}
