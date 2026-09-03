<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Weline\I18n\Api\Translation\TranslationResolverInterface;
use Weline\I18n\Service\TranslationResolver;

final class TranslationResolverProcessCacheResetTest extends TestCase
{
    public function testResetDropsCachedModuleWords(): void
    {
        $resolver = new TranslationResolver();
        $moduleWords = new ReflectionProperty($resolver, 'moduleWords');
        $moduleWords->setValue($resolver, [
            'ar_SA|Weline_Theme' => ['Active' => 'stale'],
        ]);

        self::assertNotSame([], $moduleWords->getValue($resolver));
        $resolver->reset();
        self::assertSame([], $moduleWords->getValue($resolver));
    }

    public function testPublicContractAndWlsResetterWireTheTranslationCache(): void
    {
        self::assertTrue(method_exists(TranslationResolverInterface::class, 'reset'));

        $resetter = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Api/Runtime/ProcessCacheResetter.php',
        );
        self::assertStringContainsString('TranslationResolverInterface::class', $resetter);
        self::assertStringContainsString(')->reset();', $resetter);
    }
}
