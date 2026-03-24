<?php

declare(strict_types=1);

namespace WeShop\Analytics\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use WeShop\Analytics\Extends\Module\Weline_Framework\Query\AnalyticsQueryProvider;
use WeShop\Analytics\Service\AnalyticsConfigService;
use WeShop\Analytics\Service\AnalyticsSnippetService;

class AnalyticsQueryProviderTest extends TestCase
{
    public function testExecuteReturnsFrontendSnippets(): void
    {
        $snippetService = $this->createMock(AnalyticsSnippetService::class);
        $configService = $this->createMock(AnalyticsConfigService::class);

        $snippetService->expects(self::once())
            ->method('getFrontendPixelSnippets')
            ->willReturn([
                ['provider' => 'google', 'snippet' => '<script>ga</script>'],
            ]);

        $provider = new AnalyticsQueryProvider($snippetService, $configService);
        $result = $provider->execute('getFrontendPixelSnippets');

        self::assertCount(1, $result);
        self::assertSame('google', $result[0]['provider']);
    }

    public function testExecuteReturnsProviderStatuses(): void
    {
        $snippetService = $this->createMock(AnalyticsSnippetService::class);
        $configService = $this->createMock(AnalyticsConfigService::class);

        $configService->expects(self::once())
            ->method('getProviderStatuses')
            ->willReturn([
                ['code' => 'google', 'enabled' => true, 'ready' => true],
            ]);

        $provider = new AnalyticsQueryProvider($snippetService, $configService);
        $result = $provider->execute('getProviderStatuses');

        self::assertTrue($result[0]['ready']);
    }
}
