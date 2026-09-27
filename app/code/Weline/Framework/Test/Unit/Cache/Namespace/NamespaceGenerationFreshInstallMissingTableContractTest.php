<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Cache\Namespace;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Namespace\NamespaceGenerationRepository;
use Weline\Framework\Model\Cache\NamespaceVersion;

/**
 * Fresh one-click install: Phrase/CLI may query namespace generations before
 * setup:upgrade creates weline_cache_namespace_version. Missing-table errors
 * must soft-return empty rows instead of aborting install.
 */
final class NamespaceGenerationFreshInstallMissingTableContractTest extends TestCase
{
    public function testReadStoredRowsSoftFailsWhenAuthorityTableMissing(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Cache/Namespace/NamespaceGenerationRepository.php',
        );
        self::assertStringContainsString('isGenerationAuthorityTableMissing', $src);
        self::assertStringContainsString('Fresh install / setup:upgrade', $src);
        self::assertStringContainsString('42P01', $src);
        self::assertStringContainsString('Base table or view not found', $src);
        self::assertStringContainsString(NamespaceVersion::schema_table, $src);
        self::assertMatchesRegularExpression(
            '/catch\s*\(\s*\\\\Throwable\s+\$e\s*\)\s*\{[^}]*isGenerationAuthorityTableMissing/s',
            $src,
        );
        self::assertTrue(method_exists(NamespaceGenerationRepository::class, 'canonicalize'));
    }
}
