<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Phrase;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Weline\Framework\Context;
use Weline\Framework\Phrase\Parser;

final class ParserReentrancyGuardTest extends TestCase
{
    private const CONTEXT_KEY = 'phrase.translation_resolution_depth';

    protected function tearDown(): void
    {
        $this->setCliGuardDepth(0);
        if (Context::hasCurrent()) {
            Context::leave();
        }

        parent::tearDown();
    }

    public function testNestedContextTranslationReturnsSourcePhraseWithArgumentsApplied(): void
    {
        $context = new Context();
        Context::enter($context);
        $context->set(self::CONTEXT_KEY, 1);

        $phrase = 'Database driver %{1} is unavailable';

        self::assertSame(
            'Database driver pgsql is unavailable',
            Parser::parse($phrase, ['pgsql']),
        );
        self::assertSame(1, $context->get(self::CONTEXT_KEY));
    }

    public function testNestedCliTranslationUsesBootstrapFallbackGuard(): void
    {
        $this->setCliGuardDepth(1);
        $phrase = 'Database driver %{1} is unavailable';

        self::assertSame(
            'Database driver pgsql is unavailable',
            Parser::parse($phrase, ['pgsql']),
        );
        self::assertSame(1, $this->cliGuardDepth());
    }

    public function testWordsLoaderChecksTheReentrancyFenceBeforeResolvingLanguage(): void
    {
        $source = file_get_contents((new \ReflectionClass(Parser::class))->getFileName());
        self::assertIsString($source);
        $methodStart = strpos($source, 'public static function getWords()');
        $loaderStart = strpos($source, 'private static function loadWords()', $methodStart ?: 0);
        self::assertIsInt($methodStart);
        self::assertIsInt($loaderStart);

        $entry = substr($source, $methodStart, $loaderStart - $methodStart);
        self::assertStringContainsString('self::translationResolutionDepth() > 0', $entry);
        self::assertStringNotContainsString('State::getLangLocal()', $entry);
        self::assertStringNotContainsString('self::resolveRequestModules()', $entry);
    }

    public function testProcessWordsChecksTheReentrancyFenceBeforePersistentLookup(): void
    {
        $source = file_get_contents((new \ReflectionClass(Parser::class))->getFileName());
        self::assertIsString($source);
        $methodStart = strpos($source, 'protected static function processWords(string $words): string');
        $nextMethod = strpos($source, 'public static function getUsedWords(): array', $methodStart ?: 0);
        self::assertIsInt($methodStart);
        self::assertIsInt($nextMethod);

        $entry = substr($source, $methodStart, $nextMethod - $methodStart);
        self::assertStringContainsString('self::translationResolutionDepth() > 0', $entry);
        self::assertStringContainsString('self::enterTranslationResolution()', $entry);
        self::assertStringContainsString('self::leaveTranslationResolution()', $entry);
    }

    private function setCliGuardDepth(int $depth): void
    {
        $property = new ReflectionProperty(Parser::class, 'translationResolutionDepth');
        $property->setValue(null, $depth);
    }

    private function cliGuardDepth(): int
    {
        $property = new ReflectionProperty(Parser::class, 'translationResolutionDepth');
        return (int)$property->getValue();
    }
}
