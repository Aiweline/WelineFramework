<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Security\CrawlerBlockCatalog;
use Weline\Server\Service\WlsPanelSecurityDataService;

final class WlsPanelCrawlerBlockMergeTest extends TestCase
{
    public function testMergeVisualRulesWritesDisabledBuiltinsAndCustomEntries(): void
    {
        $service = new class extends WlsPanelSecurityDataService {
            public function __construct()
            {
            }

            public function exposeMerge(array $rules, array $visual): array
            {
                $ref = new \ReflectionClass(WlsPanelSecurityDataService::class);
                $method = $ref->getMethod('mergeVisualRules');
                $method->setAccessible(true);

                return $method->invoke($this, $rules, $visual);
            }
        };

        $rules = [
            'crawler_block' => CrawlerBlockCatalog::defaultRule(),
        ];
        $builtinFlags = [];
        foreach (CrawlerBlockCatalog::builtins() as $item) {
            $builtinFlags[$item['id']] = $item['id'] === 'gptbot' ? '0' : '1';
        }

        $merged = $service->exposeMerge($rules, [
            'crawler_block' => [
                'enabled' => '1',
                'block_duration' => '3600',
                'builtin_enabled' => $builtinFlags,
                'custom_entries' => [
                    [
                        'id' => 'evil',
                        'name' => 'Evil',
                        'pattern' => '/EvilScraper/i',
                        'reason' => 'test',
                        'enabled' => '1',
                    ],
                    [
                        'id' => 'skip',
                        'name' => 'Skip',
                        'pattern' => '',
                        'enabled' => '1',
                    ],
                ],
            ],
        ]);

        self::assertTrue($merged['crawler_block']['enabled']);
        self::assertSame(3600, $merged['crawler_block']['block_duration']);
        self::assertContains('gptbot', $merged['crawler_block']['disabled_builtin_ids']);
        self::assertCount(1, $merged['crawler_block']['custom_entries']);
        self::assertSame('evil', $merged['crawler_block']['custom_entries'][0]['id']);
        self::assertTrue($merged['crawler_block']['custom_entries'][0]['enabled']);
    }
}
