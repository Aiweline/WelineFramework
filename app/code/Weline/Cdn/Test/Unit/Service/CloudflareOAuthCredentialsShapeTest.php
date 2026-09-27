<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Service\CloudflareOAuthService;

/**
 * Cloudflare create-client dialog: Client ID = 32-hex, Secret often cfoc_…
 * Heuristic must not flag a correctly pasted pair as "misplaced".
 */
final class CloudflareOAuthCredentialsShapeTest extends TestCase
{
    public function testCorrectCreateDialogPairIsNotMisplaced(): void
    {
        self::assertFalse(CloudflareOAuthService::credentialsLookMisplaced(
            'a56c32e72a11a028124560c01a1e6872',
            'cfoc_GOnN9fid0w5Sl30VXwRiyqEujp7lIyzBLZuj02zC827d'
        ));
    }

    public function testSwappedCreateDialogPairIsMisplaced(): void
    {
        self::assertTrue(CloudflareOAuthService::credentialsLookMisplaced(
            'cfoc_GOnN9fid0w5Sl30VXwRiyqEujp7lIyzBLZuj02zC827d',
            'a56c32e72a11a028124560c01a1e6872'
        ));
    }

    public function testIdenticalValuesAreNotReportedAsMisplacedHere(): void
    {
        // Identical pair is handled by hasIdenticalClientCredentials(), not this heuristic.
        self::assertFalse(CloudflareOAuthService::credentialsLookMisplaced(
            'a56c32e72a11a028124560c01a1e6872',
            'a56c32e72a11a028124560c01a1e6872'
        ));
    }
}
