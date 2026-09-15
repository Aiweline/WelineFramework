<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Setup\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Setup\Service\SetupSourceFingerprint;

final class SetupSourceFingerprintTest extends TestCase
{
    protected function tearDown(): void
    {
        SetupSourceFingerprint::resetMemoryStore();
        parent::tearDown();
    }

    public function testFingerprintTreeIsStableForSameEntries(): void
    {
        $dir = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weline_fp_' . \bin2hex(\random_bytes(4));
        \mkdir($dir, 0700, true);
        $file = $dir . DIRECTORY_SEPARATOR . 'a.php';
        \file_put_contents($file, "<?php\n");
        try {
            $fp = new SetupSourceFingerprint();
            $a = $fp->fingerprintTree($dir);
            $b = $fp->fingerprintTree($dir);
            self::assertSame($a, $b);
            self::assertNotSame('', $a);
        } finally {
            @\unlink($file);
            @\rmdir($dir);
        }
    }

    public function testMatchesRequiresStoredFingerprint(): void
    {
        $fp = new SetupSourceFingerprint();
        $key = 'test:unit:' . \bin2hex(\random_bytes(3));
        $value = \hash('sha256', 'x');
        self::assertFalse($fp->matches($key, $value));
        $fp->set($key, $value);
        self::assertTrue($fp->matches($key, $value));
        self::assertFalse($fp->matches($key, \hash('sha256', 'y')));
    }

    public function testComputeOptimizeStampIsNonEmpty(): void
    {
        $fp = new SetupSourceFingerprint();
        $stamp = $fp->computeOptimizeStamp();
        self::assertSame(64, \strlen($stamp));
    }
}
