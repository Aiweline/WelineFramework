<?php

declare(strict_types=1);

/**
 * P5 runtime tag S1：`w:form` 的静态/动态属性都必须与旧链的
 * `FiberOutputBuffer` + `renderRuntimeTag(...)` 产物逐字节相同；
 * 原生 `<form>` 必须保持原文（wOnly）。
 *
 * @author Aiweline
 */

namespace Weline\Framework\Test\Unit\View\Compiler;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Weline\Framework\View\Compiler\Ir\Emitter;
use Weline\Framework\View\Compiler\Ir\Lower;
use Weline\Framework\View\Compiler\Lexer\Lexer;
use Weline\Framework\View\Compiler\Parser\Parser;
use Weline\Framework\View\Compiler\Tag\Builtin\Interpolation;
use Weline\Framework\View\Compiler\Tag\BuiltinRegistry;
use Weline\Framework\View\Compiler\Tag\RegistryNameMatcher;
use Weline\Framework\View\Template;

final class FormTagTest extends TestCase
{
    /**
     * @return array<string, array{0:string}>
     */
    public static function snippets(): array
    {
        return [
            '静态属性' => ['<div><w:form id="test-form" method="post" action="/x" class="c">child</w:form></div>'],
            '动态属性' => ['<div><w:form id="test-form" method="post" action="<?= $x ?>" class="c">child</w:form></div>'],
        ];
    }

    #[DataProvider('snippets')]
    public function testFormRuntimeMatchesLegacy(string $source): void
    {
        $template = Template::getInstance();
        $legacySource = $source;
        $legacy = (string)$template->getTaglib()->compile($template, $legacySource, 'form-runtime-test.phtml');

        self::assertSame($legacy, $this->compileV3($source));
    }

    public function testNativeFormStaysVerbatim(): void
    {
        $source = '<div><form method="post">native</form></div>';

        self::assertSame($source, $this->compileV3($source));
    }

    private function compileV3(string $source): string
    {
        $registry = BuiltinRegistry::build();
        $parser = new Parser(new Lexer(), new RegistryNameMatcher($registry, BuiltinRegistry::names(), []));
        $statements = (new Lower($registry, null, new Interpolation(), Template::getInstance()))
            ->lower($parser->parse($source));

        return (new Emitter())->emit($statements);
    }
}
