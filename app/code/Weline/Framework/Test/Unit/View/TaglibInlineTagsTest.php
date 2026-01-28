<?php
/**
 * Taglib inline tag parsing tests.
 */

namespace Weline\Framework\Test\Unit\View;

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

    protected function setUp(): void
    {
        parent::setUp();
        $this->taglib = ObjectManager::getInstance(Taglib::class);
        $this->template = ObjectManager::getInstance(Template::class);
    }

    /**
     * Ensure @static tags in attributes are resolved.
     */
    public function testInlineStaticTagInAttribute()
    {
        $content = '<img src="@static(Weline_Frontend::img/logo.png)" alt="Logo">';
        $result = $this->taglib->parse($this->template, 'inline-static-attr.phtml', $content);

        $this->assertStringNotContainsString('@static(', $result, 'Inline @static should be resolved');
        $this->assertStringContainsString('logo.png', $result, 'Resolved URL should include logo filename');
    }

    /**
     * Ensure @static tags in inline text are resolved.
     */
    public function testInlineStaticTagInText()
    {
        $content = "<script>var logoUrl='@static(Weline_Frontend::img/logo.png)';</script>";
        $result = $this->taglib->parse($this->template, 'inline-static-text.phtml', $content);

        $this->assertStringNotContainsString('@static(', $result, 'Inline @static should be resolved in text');
        $this->assertStringContainsString('logo.png', $result, 'Resolved URL should include logo filename');
    }
}
