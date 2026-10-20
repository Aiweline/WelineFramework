<?php

declare(strict_types=1);

/**
 * `@if{cond=>value|...}` 内联 S1：旧链用 `varParser()`，与元素 if 的表达式口径不同。
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
use Weline\Framework\View\Compiler\Tag\BuiltinRegistry;
use Weline\Framework\View\Compiler\Tag\RegistryNameMatcher;
use Weline\Framework\View\Template;

final class InlineIfTest extends TestCase
{
    /**
     * @return array<string, array{0:string}>
     */
    public static function snippets(): array
    {
        return [
            '点分条件' => ['<div>@if{sort.target=>"active"}</div>'],
            '带引号值' => ['<div>@if{log.fail_total=>\'class="w-text"\'}</div>'],
            'else 分支' => ['<div>@if{ddns.ddns_domain_id=>\'edit\'|\'add\'}</div>'],
            '比较表达式' => ['<div>@if{group.eav_entity_id== v.eav_entity_id=>\'selected\'}</div>'],
            '原生属性内' => ['<div><li class="@if{sort.target=>\'active\'}">x</li></div>'],
        ];
    }

    #[DataProvider('snippets')]
    public function testMatchesLegacyCompiler(string $source): void
    {
        $template = Template::getInstance();
        $legacySource = $source;
        $legacy = (string)$template->getTaglib()->compile($template, $legacySource, 'inline-if-test.phtml');

        self::assertSame($legacy, $this->compileV3($source));
    }

    private function compileV3(string $source): string
    {
        $registry = BuiltinRegistry::build();
        $parser = new Parser(new Lexer(), new RegistryNameMatcher($registry, BuiltinRegistry::names(), []));
        $statements = (new Lower($registry, null, null, Template::getInstance()))
            ->lower($parser->parse($source));

        return (new Emitter())->emit($statements);
    }
}
