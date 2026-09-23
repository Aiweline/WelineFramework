<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View\Taglib;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Taglib;
use Weline\Framework\View\Template;

/**
 * QA-06：`<if>/<elseif condition="ident">` 须与 `{{ident}}` 同语义
 *（`$ident ?? $this->getData('ident')`），禁止只加 `$` 触发 Undefined variable。
 */
final class ConditionGetDataContractTest extends TestCase
{
    public function testBareConditionIdentifierUsesGetDataFallback(): void
    {
        $taglib = ObjectManager::getInstance(Taglib::class);
        $template = ObjectManager::getInstance(Template::class);
        $content = '<if condition="content">YES<elseif condition="other"/>NO</if>';
        $compiled = $taglib->compile($template, $content, 'qa06-condition-getdata.phtml');

        self::assertStringContainsString(
            "(\$content ?? \$this->getData('content'))",
            $compiled,
            'if condition="content" must match {{content}} semantics'
        );
        self::assertStringContainsString(
            "(\$other ?? \$this->getData('other'))",
            $compiled,
            'elseif condition="other" must match {{other}} semantics'
        );
        self::assertStringNotContainsString('if($content):', $compiled);
        self::assertStringNotContainsString('elseif($other):', $compiled);
    }

    public function testDottedConditionPathUsesGetDataFallback(): void
    {
        $taglib = ObjectManager::getInstance(Taglib::class);
        $template = ObjectManager::getInstance(Template::class);
        $content = '<if condition="meta.showHeader">H</if>';
        $compiled = $taglib->compile($template, $content, 'qa06-condition-dotted.phtml');

        self::assertStringContainsString("getData('meta')", $compiled);
        self::assertStringContainsString("'showHeader'", $compiled);
    }

    public function testExplicitDollarConditionRemainsLocalVariable(): void
    {
        $taglib = ObjectManager::getInstance(Taglib::class);
        $template = ObjectManager::getInstance(Template::class);
        $content = '<if condition="$show">Shown</if>';
        $compiled = $taglib->compile($template, $content, 'qa06-condition-dollar.phtml');

        self::assertStringContainsString('if($show):', $compiled);
        self::assertStringNotContainsString("getData('show')", $compiled);
    }
}
