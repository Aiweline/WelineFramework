<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Phrase\Parser;
use Weline\Theme\Service\StorefrontNotFoundStaticGenerator;

final class StorefrontNotFoundStaticGeneratorPhraseIsolationContractTest extends TestCase
{
    public function testBuildHtmlClearsUpgradePhraseBagBeforeRender(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontNotFoundStaticGenerator.php'
        );

        self::assertStringContainsString('isolatePhraseBagForStaticRender', $source);
        self::assertStringContainsString('Parser::clearUsedWords', $source);
        self::assertStringContainsString('JsWordsRegistry::reset', $source);
    }

    public function testParserClearUsedWordsDropsAccumulatedBag(): void
    {
        $ref = new \ReflectionClass(Parser::class);
        $prop = $ref->getProperty('usedWords');
        $prop->setAccessible(true);
        $prop->setValue(null, [
            'upgrade phrase a' => 'upgrade phrase a',
            'upgrade phrase b' => 'upgrade phrase b',
        ]);

        self::assertCount(2, Parser::getUsedWords());
        Parser::clearUsedWords();
        self::assertSame([], Parser::getUsedWords());
    }

    public function testGeneratorSourceDocumentsUpgradeHangRootCause(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontNotFoundStaticGenerator.php'
        );
        self::assertStringContainsString('setup:upgrade', $source);
        self::assertStringContainsString('usedWords', $source);
    }
}
