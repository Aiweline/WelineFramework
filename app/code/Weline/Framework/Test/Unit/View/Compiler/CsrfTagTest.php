<?php

declare(strict_types=1);

/**
 * `csrf` S1：token 是编译期随机值，按 hidden input 的 value 归一化后逐字节比。
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
use Weline\Framework\View\Compiler\Tag\Builtin\CsrfTag;
use Weline\Framework\View\Compiler\Tag\Builtin\Interpolation;
use Weline\Framework\View\Compiler\Tag\Registry;
use Weline\Framework\View\Compiler\Tag\RegistryNameMatcher;
use Weline\Framework\View\Template;

final class CsrfTagTest extends TestCase
{
    /**
     * @return array<string, array{0:string}>
     */
    public static function snippets(): array
    {
        return [
            '自闭合' => ['<div><csrf/></div>'],
            'name 属性' => ['<div><csrf name="demo"/></div>'],
            '成对内容' => ['<div><csrf>demo</csrf></div>'],
            '内联' => ['<div>@csrf(demo)</div>'],
        ];
    }

    #[DataProvider('snippets')]
    public function testMatchesLegacyCompilerAfterTokenNormalization(string $source): void
    {
        $template = Template::getInstance();
        $legacySource = $source;
        $legacy = (string)$template->getTaglib()->compile($template, $legacySource, 'csrf-tag-test.phtml');

        self::assertSame(self::normalize($legacy), self::normalize($this->compileV3($source)));
    }

    private static function normalize(string $html): string
    {
        return (string)\preg_replace(
            "/(<input type='hidden' name='[^']+' value=')[^']*(')/",
            '$1<token>$2',
            $html,
        );
    }

    private function compileV3(string $source): string
    {
        $registry = new Registry([CsrfTag::descriptor()]);
        $parser = new Parser(new Lexer(), new RegistryNameMatcher($registry, ['csrf'], []));
        $statements = (new Lower($registry, null, new Interpolation(), Template::getInstance()))
            ->lower($parser->parse($source));

        return (new Emitter())->emit($statements);
    }
}
