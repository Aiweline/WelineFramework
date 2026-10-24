<?php
declare(strict_types=1);

namespace {
    if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
        require __DIR__ . '/../bootstrap.php';
    }
}

namespace Weline\App\Test\Unit {
    use PHPUnit\Framework\TestCase;
    use Weline\App\Service\Build\VersionDrift;
    use Weline\Framework\Database\Exception\LinkException;

    /**
     * 版本漂移服务单元测试
     */
    class VersionDriftTest extends TestCase
    {
        public function testCurrentVersionReadsJson(): void
        {
            try {
                $result = (new VersionDrift())->drifted();
            } catch (\PDOException|LinkException) {
                $this->markTestSkipped('DB unavailable');
            }

            $this->assertSame('1.0.1', $result['current_version']);
        }

        public function testDriftedReturnsBool(): void
        {
            try {
                $result = (new VersionDrift())->drifted();
            } catch (\PDOException|LinkException) {
                $this->markTestSkipped('DB unavailable');
            }

            $this->assertArrayHasKey('drift', $result);
            $this->assertIsBool($result['drift']);
        }
    }

    if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
        $methods = ['testCurrentVersionReadsJson', 'testDriftedReturnsBool'];
        $failures = 0;
        foreach ($methods as $method) {
            try {
                $test = (new \ReflectionClass(VersionDriftTest::class))->newInstance($method);
                $test->{$method}();
                echo 'PASS: ' . $method . PHP_EOL;
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), 'DB unavailable')) {
                    echo 'SKIP: ' . $method . PHP_EOL;
                    continue;
                }
                $failures++;
                echo 'FAIL: ' . $method . ': ' . $e->getMessage() . PHP_EOL;
            }
        }
        if ($failures > 0) {
            exit(1);
        }
    }
}
