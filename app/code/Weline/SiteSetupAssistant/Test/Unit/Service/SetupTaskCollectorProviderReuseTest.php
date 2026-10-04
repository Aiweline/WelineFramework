<?php

declare(strict_types=1);

namespace {
    if (!function_exists('__')) {
        function __(string $text, array|string|int $args = ''): string
        {
            return $text;
        }
    }
}

namespace Weline\SiteSetupAssistant\Test\Unit\Service {

use PHPUnit\Framework\TestCase;
use Weline\SiteSetupAssistant\Api\SetupTaskProviderInterface;
use Weline\SiteSetupAssistant\Service\SetupTaskCollector;

final class SetupTaskCollectorProviderReuseTest extends TestCase
{
    public function testGlobalOverviewDiscoversProvidersOnceWhileCheckingEverySite(): void
    {
        $provider = new class implements SetupTaskProviderInterface {
            public array $visited = [];

            public function provideTasks(array $context = []): array
            {
                $id = (int)($context['website_id'] ?? -1);
                $this->visited[] = $id;
                return [[
                    'code' => 'fixture',
                    'title' => '测试任务',
                    'tip' => '',
                    'status' => $id === 0 ? 'done' : 'todo',
                ]];
            }
        };
        $collector = new class($provider) extends SetupTaskCollector {
            public int $providerDiscoveries = 0;

            public function __construct(private readonly SetupTaskProviderInterface $provider) {}

            protected function listWebsiteContexts(): array
            {
                return [
                    ['website_id' => 0, 'website_code' => 'default', 'label' => '默认站'],
                    ['website_id' => 1, 'website_code' => 'second', 'label' => '第二站'],
                    ['website_id' => 2, 'website_code' => 'third', 'label' => '第三站'],
                ];
            }

            protected function getProviders(): array
            {
                $this->providerDiscoveries++;
                return [$this->provider];
            }
        };

        $overview = $collector->collectGlobalOverview();
        self::assertSame(1, $collector->providerDiscoveries);
        self::assertSame([0, 1, 2], $provider->visited);
        self::assertCount(1, $overview);
        self::assertSame('todo', $overview[0]['status']);
        self::assertSame([1, 2, 0], array_column($overview[0]['site_coverage'], 'website_id'));
    }
}
}
