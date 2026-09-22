<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Runtime\WorkerReadinessState;

final class WorkerReadinessHomepageStatusReportContractTest extends TestCase
{
    protected function setUp(): void
    {
        WorkerReadinessState::reset('direct');
    }

    protected function tearDown(): void
    {
        WorkerReadinessState::reset('direct');
    }

    public function testStatusReportHomepageFieldsFlattenHitProof(): void
    {
        WorkerReadinessState::markBusinessHomepageHot([
            'hit' => true,
            'fpc_status' => 'HIT',
            'source' => 'process',
            'full_uri' => 'https://p05113ef3.test.weline.com:9555/',
            'reason' => 'homepage-fpc:deferred-warmup:adopted',
            'http_status' => 200,
        ]);

        $fields = WorkerReadinessState::statusReportHomepageFields();
        self::assertSame(1, $fields['homepage_fpc_hit']);
        self::assertSame('HIT', $fields['homepage_fpc_status']);
        self::assertSame('process', $fields['homepage_fpc_source']);
        self::assertSame('homepage-fpc:deferred-warmup:adopted', $fields['homepage_fpc_reason']);
        self::assertSame('https://p05113ef3.test.weline.com:9555/', $fields['homepage_fpc_full_uri']);
        self::assertSame(200, $fields['homepage_fpc_http_status']);
        self::assertSame('hot', $fields['warmup_state']);
    }

    public function testOrchestratorAuditAppliesHomepageFpcFromStatusReport(): void
    {
        $source = \file_get_contents(
            BP . 'app/code/Weline/Server/Service/ServiceOrchestrator.php'
        );
        self::assertIsString($source);
        self::assertStringContainsString("array_key_exists('homepage_fpc_hit', \$msg)", $source);
        self::assertStringContainsString("setMeta('homepage_fpc', \$homepageFpc)", $source);
    }

    public function testWorkersPushHomepageFieldsOnDeferredWarmupSuccess(): void
    {
        foreach (['worker.php', 'worker_ssl.php'] as $script) {
            $source = \file_get_contents(BP . 'app/code/Weline/Server/bin/' . $script);
            self::assertIsString($source, $script);
            self::assertStringContainsString(
                'WorkerReadinessState::statusReportHomepageFields',
                $source,
                $script,
            );
            self::assertStringContainsString(
                'homepage_fpc_hit',
                $source,
                $script,
            );
        }
    }
}
