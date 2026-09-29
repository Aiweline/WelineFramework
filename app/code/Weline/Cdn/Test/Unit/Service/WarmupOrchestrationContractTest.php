<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Service\WarmupCollectService;
use Weline\Cdn\Service\WarmupRunner;

final class WarmupOrchestrationContractTest extends TestCase
{
    public function testCollectServiceFiltersByDomainInOrchestrationLayer(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/WarmupCollectService.php';
        $src = (string)file_get_contents($path);
        $this->assertStringContainsString('collectProvider(string $providerFqcn, ?int $domainId = null, ?int $siteIdFilter', $src);
        $this->assertStringContainsString('PER_PROVIDER_CAP', $src);
        $this->assertStringContainsString("'provider' => \$providerFqcn", $src);
        $this->assertStringContainsString('filtered_count', $src);
        $this->assertStringNotContainsString('provider=scanner', $src);
        $this->assertStringContainsString("dispatch('Weline_Cdn::send_warmup', \$eventData)", $src);
        $this->assertStringContainsString("\$eventData['result']", $src);
        $this->assertStringNotContainsString('new Event(', $src);
    }

    public function testCollectDispatchMustNotPassEventObject(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/WarmupCollectService.php';
        $src = (string)file_get_contents($path);
        $this->assertStringNotContainsString('use Weline\\Framework\\Event\\Event;', $src);
        $this->assertStringNotContainsString('$event->getData(', $src);
    }

    public function testRunnerSkippedDoesNotCountAsSuccess(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/WarmupRunner.php';
        $src = (string)file_get_contents($path);
        $this->assertStringContainsString("\$outcome === 'skipped'", $src);
        $this->assertStringContainsString('$skipped++', $src);
        $this->assertStringContainsString("'skipped' => \$skipped", $src);
        $skippedPos = strpos($src, "\$outcome === 'skipped'");
        $successPos = strpos($src, "\$outcome === 'success'");
        $this->assertNotFalse($skippedPos);
        $this->assertNotFalse($successPos);
        $this->assertLessThan($successPos, (int)$skippedPos);
        $this->assertTrue(method_exists(WarmupRunner::class, 'run'));
        $ref = new \ReflectionMethod(WarmupRunner::class, 'run');
        $this->assertCount(4, $ref->getParameters());
    }

    public function testRunnerSqlFiltersIncompleteBeforeLimitWindow(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/WarmupRunner.php';
        $src = (string)file_get_contents($path);
        $this->assertStringContainsString('schema_fields_PROCESSED_COUNT', $src);
        $this->assertStringContainsString('schema_fields_TARGET_COUNT', $src);
        $this->assertStringContainsString('->additional(', $src);
        $this->assertStringContainsString("['wls.host', 'server.host']", $src);
        $this->assertStringContainsString("['wls.port', 'server.port'", $src);
        $this->assertStringContainsString('resolveFetchUrl', $src);
        $this->assertStringContainsString('CURLOPT_SSL_VERIFYPEER', $src);
        $this->assertStringContainsString('localDevFetch', $src);
        $this->assertTrue(method_exists(WarmupRunner::class, 'resolveFetchUrl'));
    }

    public function testWarmupUrlHasEnabledDomainIndexAndProvider255(): void
    {
        $path = dirname(__DIR__, 3) . '/Model/WarmupUrl.php';
        $src = (string)file_get_contents($path);
        $this->assertStringContainsString('idx_enabled_domain', $src);
        $this->assertStringContainsString("['enabled', 'domain_id']", $src);
        $this->assertStringContainsString("#[Col('varchar', 255, nullable: false, comment: '提供者FQCN')]", $src);
    }

    public function testSourceModuleResolutionDocumentedInCollectService(): void
    {
        $svc = new \ReflectionClass(WarmupCollectService::class);
        $this->assertTrue($svc->hasMethod('sourceModuleForFqcn'));
        $this->assertTrue($svc->hasMethod('collectAllProviders'));
    }
}
