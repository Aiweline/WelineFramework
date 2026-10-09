<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\TemplateCompileService;

/**
 * Process-pool locale compile: concurrency clamp + real multi-locale fan-out smoke.
 */
final class TemplateCompileProcessPoolTest extends TestCase
{
    public function testEnvConcurrencyOverrideAndClamp(): void
    {
        $prev = getenv(TemplateCompileService::ENV_CONCURRENCY);
        try {
            putenv(TemplateCompileService::ENV_CONCURRENCY . '=4');
            $svc = new TemplateCompileService();
            self::assertSame(4, $svc->resolveConcurrency());
            putenv(TemplateCompileService::ENV_CONCURRENCY . '=99');
            self::assertSame(32, $svc->resolveConcurrency());
            putenv(TemplateCompileService::ENV_CONCURRENCY . '=0');
            self::assertSame(1, $svc->resolveConcurrency());
        } finally {
            if ($prev === false) {
                putenv(TemplateCompileService::ENV_CONCURRENCY);
            } else {
                putenv(TemplateCompileService::ENV_CONCURRENCY . '=' . $prev);
            }
        }
    }

    public function testProcessPoolCompilesDistinctLocalesFailClosedOnWorkerMissing(): void
    {
        $svc = new TemplateCompileService();
        $worker = dirname(__DIR__, 3) . '/View/bin/compile-locale-job.php';
        self::assertFileExists($worker);

        $prevWorker = getenv('WLS_WORKER_ID');
        putenv('WLS_WORKER_ID');
        unset($_ENV['WLS_WORKER_ID'], $_SERVER['WLS_WORKER_ID']);

        $bytes = '<div><?= \'pool-smoke\' ?></div>';
        $logical = 'framework://test/template-compile-pool-smoke.phtml';
        $progressLocales = [];

        try {
            $paths = $svc->compilePinnedSources(
                [
                    $logical => [
                        'bytes' => $bytes,
                        'origin' => $logical,
                        'label' => 'pool-smoke',
                    ],
                ],
                ['zh_Hans_CN', 'en_US', 'ja_JP', 'ko_KR'],
                'CNY',
                [],
                static function (int $done, int $total, string $locale, string $label) use (&$progressLocales): void {
                    $progressLocales[$locale] = true;
                },
                ['concurrency' => 2],
            );
            self::assertNotSame([], $paths);
            self::assertGreaterThanOrEqual(4, count($paths));
            foreach ($paths as $path) {
                self::assertIsString($path);
                self::assertNotSame('', $path);
                self::assertFileExists($path);
            }
            self::assertArrayHasKey('zh_Hans_CN', $progressLocales);
            self::assertArrayHasKey('en_US', $progressLocales);
        } finally {
            if ($prevWorker === false || $prevWorker === '') {
                putenv('WLS_WORKER_ID');
            } else {
                putenv('WLS_WORKER_ID=' . $prevWorker);
                $_ENV['WLS_WORKER_ID'] = $prevWorker;
            }
        }
    }

    public function testWlsWorkerForcesInProcessEvenWhenConcurrencyHigh(): void
    {
        $svc = new TemplateCompileService();
        $prev = getenv('WLS_WORKER_ID');
        putenv('WLS_WORKER_ID=ut-pool');
        $_ENV['WLS_WORKER_ID'] = 'ut-pool';
        try {
            $paths = $svc->compilePinnedSources(
                [
                    'framework://test/template-compile-pool-serial.phtml' => [
                        'bytes' => '<p>serial</p>',
                        'origin' => 'framework://test/template-compile-pool-serial.phtml',
                        'label' => 'serial',
                    ],
                ],
                ['zh_Hans_CN', 'en_US'],
                'USD',
                [],
                null,
                ['concurrency' => 10],
            );
            self::assertCount(2, $paths);
            foreach ($paths as $path) {
                self::assertFileExists($path);
            }
        } finally {
            if ($prev === false || $prev === '') {
                putenv('WLS_WORKER_ID');
                unset($_ENV['WLS_WORKER_ID']);
            } else {
                putenv('WLS_WORKER_ID=' . $prev);
                $_ENV['WLS_WORKER_ID'] = $prev;
            }
        }
    }
}
