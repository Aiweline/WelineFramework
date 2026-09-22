<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;

/**
 * Deferred adopt must mirror proof into WorkerReadinessState so status_report
 * can refresh Master homepage_fpc (server:status must not stay on fail-open).
 * B5: adopt before L1 eviction; prefer sample full_uri; / then /products order.
 */
final class WlsRuntimeAdoptHomepageFpcMetaContractTest extends TestCase
{
    public function testAdoptCallsWorkerReadinessMarkBusinessHomepageHot(): void
    {
        $source = \file_get_contents(BP . 'app/code/Weline/Framework/Runtime/WlsRuntime.php');
        self::assertIsString($source);
        self::assertStringContainsString(
            'publishAdoptedHomepageFpcProofToWorkerReadiness',
            $source,
        );
        self::assertStringContainsString(
            'WorkerReadinessState::markBusinessHomepageHot',
            $source,
        );
        self::assertStringContainsString(
            'homepage-fpc:deferred-warmup:adopted',
            $source,
        );
    }

    public function testDeferredWarmupAdoptsHomepageImmediatelyAndPrefersCatalogSlot(): void
    {
        $source = (string)\file_get_contents(BP . 'app/code/Weline/Framework/Runtime/WlsRuntime.php');
        self::assertStringContainsString(
            "if (\$path === '/' && !(bool)(\$this->readyGateHomepageFpcProof['hit'] ?? false))",
            $source,
        );
        self::assertStringContainsString('candidate_uris', $source);
        self::assertStringContainsString("\$sample['full_uri']", $source);

        $orderPos = \strpos($source, 'Prefer `/` then `/products` before locale homes');
        self::assertNotFalse($orderPos);
        $slicePos = \strpos($source, 'array_slice(\\array_values($paths)', $orderPos);
        self::assertNotFalse($slicePos);
        self::assertGreaterThan($orderPos, $slicePos);
    }
}
