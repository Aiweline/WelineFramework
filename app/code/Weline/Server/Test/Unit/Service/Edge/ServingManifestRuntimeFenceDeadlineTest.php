<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\ServingManifestRuntimeFence;

final class ServingManifestRuntimeFenceDeadlineTest extends TestCase
{
    public function testPublicationUsesOneDeadlineForBuildAndEndpointCas(): void
    {
        $sourcePath = (new \ReflectionClass(
            ServingManifestRuntimeFence::class,
        ))->getFileName();
        self::assertIsString($sourcePath);
        $source = \file_get_contents($sourcePath);
        self::assertIsString($source);

        self::assertStringContainsString(
            '->buildServingManifest(' . "\n"
                . '            $context->instanceName,' . "\n"
                . '            $deadlineMonotonic,',
            $source,
        );
        self::assertStringContainsString(
            'self::remainingPublicationSeconds($deadlineMonotonic)',
            $source,
        );
        self::assertStringContainsString('$endpointTimeout,', $source);
    }

    public function testExpiredPublicationDeadlineFailsBeforeProjectStateAccess(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'WLS serving manifest publication deadline was exhausted.',
        );

        (new \ReflectionMethod(
            ServingManifestRuntimeFence::class,
            'remainingPublicationSeconds',
        ))->invoke(null, (\hrtime(true) / 1_000_000_000) - 1.0);
    }

    public function testNonFinitePublicationDeadlineFailsClosed(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'WLS serving manifest publication deadline is invalid.',
        );

        (new \ReflectionMethod(
            ServingManifestRuntimeFence::class,
            'remainingPublicationSeconds',
        ))->invoke(null, INF);
    }
}
