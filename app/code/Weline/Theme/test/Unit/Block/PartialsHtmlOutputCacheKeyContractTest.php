<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Block;

use PHPUnit\Framework\TestCase;

/**
 * Storefront chrome/head HTML bags must use KeyBuilder::buildEnvironmentScoped.
 */
final class PartialsHtmlOutputCacheKeyContractTest extends TestCase
{
    public function testPartialOutputKeysUseSystemEnvironmentScopedBuilder(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Block/Partials.php',
        );

        self::assertStringContainsString('function resolvePartialOutputCacheKey(', $src);
        self::assertStringContainsString('KeyBuilder::buildEnvironmentScoped(', $src);
        self::assertStringContainsString("'theme.head.partial'", $src);
        self::assertStringContainsString("'theme.chrome.partial'", $src);
        self::assertStringContainsString("'schema' => 'chrome-partial-v23-env-scoped-key'", $src);
        self::assertStringNotContainsString('\\sha1($path . \'|\' . $sourceFingerprint . \'|\' . $cacheContext)', $src);
        self::assertStringNotContainsString('\\sha1($fileName . \'|\' . $sourceFingerprint . \'|\' . $cacheContext)', $src);
        self::assertStringNotContainsString('return KeyBuilder::environmentHash($hashPayload, $hashDims);', $src);
    }
}
