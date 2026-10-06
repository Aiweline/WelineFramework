<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Architecture\Finding;
use Weline\Framework\Architecture\Report;

final class ArchitectureRatchetBaselineTest extends TestCase
{
    private function report(array $findings): Report
    {
        return new Report($findings, ['modules' => 3, 'php_files' => 10, 'references' => 5]);
    }

    public function testFingerprintIgnoresLineDrift(): void
    {
        $a = new Finding('dependency.undeclared', 'X references Y without declaration.', 'Mod/A.php', 10);
        $b = new Finding('dependency.undeclared', 'X references Y without declaration.', 'Mod/A.php', 99);
        self::assertSame($a->fingerprint(), $b->fingerprint());
    }

    public function testRegressionAgainstBaselineIsBlockedAndResolvedCountsSurface(): void
    {
        $existing = new Finding('dependency.undeclared', 'A references B.', 'A/x.php', 5);
        $baseline = $this->report([$existing])->toBaseline();

        $newViolation = new Finding('dependency.internal_api', 'Cross-module reference must target B\Api\\*: B\\C.', 'A/y.php', 7);
        $after = $this->report([$existing, $newViolation]);
        $diff = $after->diffAgainstBaseline($baseline);
        self::assertCount(1, $diff['regressions']);
        self::assertSame('dependency.internal_api', $diff['regressions'][0]['rule']);
        self::assertSame([], $diff['resolved_counts']);
    }

    public function testResolvedViolationsAreReportedWithoutRegressions(): void
    {
        $one = new Finding('dependency.undeclared', 'A references B.', 'A/x.php', 5);
        $two = new Finding('dependency.undeclared', 'A references C.', 'A/z.php', 9);
        $baseline = $this->report([$one, $two])->toBaseline();

        $diff = $this->report([$one])->diffAgainstBaseline($baseline);
        self::assertSame([], $diff['regressions']);
        self::assertSame(['dependency.undeclared' => 1], $diff['resolved_counts']);
    }

    public function testUnknownSchemaFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->report([])->diffAgainstBaseline(['schema' => 'other.v1']);
    }
}
