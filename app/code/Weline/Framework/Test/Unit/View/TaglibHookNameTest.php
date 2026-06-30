<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;
use Weline\Framework\View\Taglib;
use Weline\Framework\View\Template;

final class TaglibHookNameTest extends TestCore
{
    private Taglib $taglib;
    private Template $template;

    public function setUp(): void
    {
        parent::setUp();
        self::initRequest();
        $this->taglib = ObjectManager::getInstance(Taglib::class);
        $this->template = ObjectManager::getInstance(Template::class);
    }

    public function testHookTagPreservesDottedHookNameAttribute(): void
    {
        $content = '<w:hook name="account.sidebar"/>';
        $result = $this->taglib->compile($this->template, $content, 'hook-dotted-name.phtml');

        $this->assertStringContainsString("getHook('account.sidebar')", $result);
        $this->assertStringNotContainsString("getHook('account')", $result);
    }

    public function testHookTagPreservesNestedDottedHookNameAttribute(): void
    {
        $content = '<w:hook name="account.sidebar.content"/>';
        $result = $this->taglib->compile($this->template, $content, 'hook-nested-dotted-name.phtml');

        $this->assertStringContainsString("getHook('account.sidebar.content')", $result);
        $this->assertStringNotContainsString("getHook('account.sidebar')", $result);
        $this->assertStringNotContainsString("getHook('account')", $result);
    }
}
