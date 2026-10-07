<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Nginx\ManagedNginxLocalHostCatalog;

final class ManagedNginxLocalHostCatalogContractTest extends TestCase
{
    public function testExpandServerNamesAddsManagedLocalHostsAndWildcard(): void
    {
        $catalog = new ManagedNginxLocalHostCatalog();
        $names = $catalog->expandServerNames(
            ['p05113ef3.test.weline.com'],
            static fn (): array => [
                ['domain' => 'grocery.test.weline.com', 'website_id' => 544],
                ['domain' => 'example.com', 'website_id' => 1],
            ],
        );

        self::assertContains('p05113ef3.test.weline.com', $names);
        self::assertContains('grocery.test.weline.com', $names);
        self::assertContains('*.test.weline.com', $names);
        self::assertNotContains('example.com', $names);
    }

    public function testResolveEdgeCertificatePrefersActiveWildcardWhenLocalHostsPresent(): void
    {
        $catalog = new ManagedNginxLocalHostCatalog();
        $store = new class {
            public function active(string $domain): ?array
            {
                if ($domain !== '*.test.weline.com') {
                    return null;
                }

                return [
                    'domain' => '*.test.weline.com',
                    'generation' => 24,
                    'source_digest' => \str_repeat('a', 64),
                    'cert_path' => '/tmp/fullchain.pem',
                    'key_path' => '/tmp/privkey.pem',
                    'chain_path' => '',
                    'leaf_fingerprint_sha256' => \str_repeat('b', 64),
                    'cert_sha256' => \str_repeat('c', 64),
                    'key_sha256' => \str_repeat('d', 64),
                    'chain_sha256' => '',
                ];
            }
        };

        $preferred = [
            'domain' => 'p05113ef3.test.weline.com',
            'generation' => 30,
            'source_digest' => \str_repeat('e', 64),
        ];
        $resolved = $catalog->resolveEdgeCertificate(
            $preferred,
            ['p05113ef3.test.weline.com', 'grocery.test.weline.com', '*.test.weline.com'],
            $store,
        );

        self::assertIsArray($resolved);
        self::assertSame('*.test.weline.com', $resolved['domain'] ?? null);
        self::assertSame(24, (int)($resolved['generation'] ?? 0));
    }
}
