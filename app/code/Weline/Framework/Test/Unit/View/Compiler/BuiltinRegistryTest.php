<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View\Compiler;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\Compiler\Ir\Emitter;
use Weline\Framework\View\Compiler\Ir\Lower;
use Weline\Framework\View\Compiler\Lexer\Lexer;
use Weline\Framework\View\Compiler\Parser\Parser;
use Weline\Framework\View\Compiler\Tag\Builtin\Interpolation;
use Weline\Framework\View\Compiler\Tag\BuiltinRegistry;
use Weline\Framework\View\Compiler\Tag\StaticTagNameMatcher;
use Weline\Framework\View\Template;

final class BuiltinRegistryTest extends TestCase
{
    public function testContainsOnlyVerifiedGroups(): void
    {
        $names = BuiltinRegistry::names();

        foreach (['lang', 'js', 'css', 'csrf', 'template', 'form', 'var', 'static', 'url', 'backend-url', 'admin-url', 'frontend-url', 'api', 'if', 'elseif', 'else', 'foreach', 'hook', 'block'] as $expected) {
            self::assertContains($expected, $names);
        }
        foreach (['local', 'js:part'] as $moduleTag) {
            self::assertNotContains($moduleTag, $names, $moduleTag . ' 是模块标签，应由 ModuleTagBridge 接入而不是内置名单');
        }
    }

    public function testMixedTemplateMatchesLegacy(): void
    {
        $source = '<div><lang>你好</lang>{{title}}<if condition="$x">A<else/>B</if>'
            . '@url{/x}<a href="@backend-url{\'*/b/x\'}">b</a>@static(Weline_Promotion::css/promotion-hub.css)</div>';

        $template = Template::getInstance();
        $legacy = (string)$template->getTaglib()->compile($template, $source, 'builtin-registry-test.phtml');

        $registry = BuiltinRegistry::build();
        $parser = new Parser(new Lexer(), new StaticTagNameMatcher(BuiltinRegistry::names()));
        $v3 = (new Emitter())->emit(
            (new Lower($registry, null, new Interpolation(), $template))->lower($parser->parse($source))
        );

        self::assertSame($legacy, $v3);
    }
}
