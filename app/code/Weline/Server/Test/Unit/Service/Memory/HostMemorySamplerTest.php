<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Memory;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Memory\HostMemorySampler;

final class HostMemorySamplerTest extends TestCase
{
    public function testStandardDarwinVmStatAcceptsQuotedDiagnosticLabels(): void
    {
        $sample = <<<'VMSTAT'
Mach Virtual Memory Statistics: (page size of 16384 bytes)
Pages free: 100.
Pages active: 200.
Pages inactive: 300.
Pages speculative: 40.
Pages purgeable: 50.
"Translation faults": 123456.
Pages copy-on-write: 20.
VMSTAT;
        $method = new \ReflectionMethod(HostMemorySampler::class, 'parseDarwinVmStat');
        $parsed = $method->invoke(new HostMemorySampler(), $sample);

        self::assertSame([
            'page_size' => 16384,
            'pages_free' => 100,
            'pages_inactive' => 300,
            'pages_speculative' => 40,
            'pages_purgeable' => 50,
        ], $parsed);
    }

    public function testDarwinVmStatRejectsDuplicateLabelsAcrossQuoteForms(): void
    {
        $sample = <<<'VMSTAT'
Mach Virtual Memory Statistics: (page size of 4096 bytes)
Pages free: 100.
"Pages free": 101.
Pages inactive: 300.
Pages speculative: 40.
Pages purgeable: 50.
VMSTAT;
        $method = new \ReflectionMethod(HostMemorySampler::class, 'parseDarwinVmStat');

        self::assertNull($method->invoke(new HostMemorySampler(), $sample));
    }
}
