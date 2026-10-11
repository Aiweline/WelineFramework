<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Nginx\ManagedNginxConfigWriter;
use Weline\Server\Service\WorkerStaticResponseL1;

/**
 * 静态资源浏览器/边缘 TTL：一年（31536000）+ immutable。
 */
final class StaticBrowserTtlContractTest extends TestCase
{
    public function testWorkerStaticResponseL1DefaultMaxAgeIsOneYear(): void
    {
        $reflection = new \ReflectionClass(WorkerStaticResponseL1::class);
        $const = $reflection->getReflectionConstant('DEFAULT_MAX_AGE_SECONDS');
        $this->assertNotFalse($const);
        $this->assertSame(31_536_000, $const->getValue());
    }

    public function testManagedNginxStaticLocationUsesOneYearCacheControl(): void
    {
        $path = (new \ReflectionClass(ManagedNginxConfigWriter::class))->getFileName();
        $this->assertIsString($path);
        $src = (string)\file_get_contents($path);
        $this->assertStringContainsString('expires 365d;', $src);
        $this->assertStringContainsString('max-age=31536000, immutable', $src);
        $this->assertStringNotContainsString('expires 30d;', $src);
        $this->assertStringNotContainsString('max-age=2592000', $src);
    }

    public function testWorkerSslStaticHandleUsesOneYearMaxAge(): void
    {
        $workerSsl = \dirname(__DIR__, 3) . '/bin/worker_ssl.php';
        $this->assertFileExists($workerSsl);
        $src = (string)\file_get_contents($workerSsl);
        $this->assertMatchesRegularExpression('/\$maxAge\s*=\s*31536000\s*;/', $src);
        $this->assertMatchesRegularExpression('/\$staticFileCacheMaxAge\s*=\s*31536000\s*;/', $src);
        $this->assertStringContainsString('max-age={$maxAge}, immutable', $src);
        $this->assertStringNotContainsString('$maxAge = 86400 * 7', $src);
    }
}
