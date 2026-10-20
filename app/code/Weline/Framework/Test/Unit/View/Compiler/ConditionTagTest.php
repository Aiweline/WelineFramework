<?php

declare(strict_types=1);

/**
 * empty/notempty/has 的三种旧形态：无 w: 丢弃、w: runtime、内联编译期 PHP。
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

final class ConditionTagTest extends TestCase
{
    /**
     * @return array<string, array{0:string}>
     */
    public static function snippets(): array
    {
        return [
            'empty-无前缀丢弃' => ['<div><empty name="local_descriptions">E</empty></div>'],
            'notempty-无前缀丢弃' => ['<div><notempty name="params">N</notempty></div>'],
            'has-无前缀丢弃' => ['<div><has name="close_button_show">H</has></div>'],
            'w:empty-runtime' => ['<div><w:empty name="currencies">E</w:empty></div>'],
            'w:notempty-runtime' => ['<div><w:notempty name="params">N</w:notempty></div>'],
            'w:has-runtime' => ['<div><w:has name="close_button_show">H</w:has></div>'],
            'inline-empty' => ['<div>@empty{currencies|E}</div>'],
            'inline-notempty' => ['<div>@notempty{params|N}</div>'],
        ];
    }

    #[DataProvider('snippets')]
    public function testMatchesLegacyCompiler(string $source): void
    {
        $template = Template::getInstance();
        $legacySource = $source;
        $legacy = (string)$template->getTaglib()->compile($template, $legacySource, 'condition-tag-test.phtml');

        self::assertSame($legacy, $this->compileV3($source));
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
