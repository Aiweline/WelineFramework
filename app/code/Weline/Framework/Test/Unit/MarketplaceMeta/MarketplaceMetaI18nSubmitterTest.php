<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\MarketplaceMeta;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\MarketplaceMeta\MarketplaceMetaI18nSubmitter;

final class MarketplaceMetaI18nSubmitterTest extends TestCase
{
    public function testSubmitDispatchesCollectTranslationsEvent(): void
    {
        $events = new class extends EventsManager {
            public array $dispatches = [];

            public function __construct()
            {
            }

            public function dispatch(string $eventName, mixed &$data = []): static
            {
                $this->dispatches[] = [
                    'event' => $eventName,
                    'data' => $data,
                ];

                return $this;
            }
        };

        $submitter = new MarketplaceMetaI18nSubmitter($events);
        $result = $submitter->submit('Acme_Demo', [
            'i18n' => [
                'source_locale' => 'zh_Hans_CN',
                'locales' => [
                    'zh_Hans_CN' => [
                        'display_name' => '演示模块',
                        'description' => '演示描述',
                    ],
                    'en_US' => [
                        'display_name' => 'Demo Module',
                        'description' => 'Demo description',
                    ],
                ],
            ],
            'tags' => [
                [
                    'code' => 'surface.backend',
                    'labels' => [
                        'zh_Hans_CN' => '后台应用',
                        'en_US' => 'Backend',
                    ],
                ],
            ],
            'seo' => [
                'title' => [
                    'zh_Hans_CN' => '演示 SEO 标题',
                    'en_US' => 'Demo SEO title',
                ],
            ],
        ]);

        self::assertSame(MarketplaceMetaI18nSubmitter::EVENT_NAME, $result['event']);
        self::assertSame(8, $result['count']);
        self::assertCount(1, $events->dispatches);
        self::assertSame(MarketplaceMetaI18nSubmitter::EVENT_NAME, $events->dispatches[0]['event']);
        self::assertSame('Acme_Demo', $events->dispatches[0]['data']['module']);

        $translations = $events->dispatches[0]['data']['translations'];
        self::assertContains([
            'word' => '演示模块',
            'translate' => 'Demo Module',
            'locale' => 'en_US',
            'module' => 'Acme_Demo',
            'is_backend' => 1,
        ], $translations);
        self::assertContains([
            'word' => '后台应用',
            'translate' => '后台应用',
            'locale' => 'zh_Hans_CN',
            'module' => 'Acme_Demo',
            'is_backend' => 1,
        ], $translations);
        self::assertContains([
            'word' => '演示 SEO 标题',
            'translate' => 'Demo SEO title',
            'locale' => 'en_US',
            'module' => 'Acme_Demo',
            'is_backend' => 1,
        ], $translations);
    }

    public function testSubmitReturnsZeroWhenNoSourceWordsExist(): void
    {
        $events = new class extends EventsManager {
            public array $dispatches = [];

            public function __construct()
            {
            }

            public function dispatch(string $eventName, mixed &$data = []): static
            {
                $this->dispatches[] = $eventName;
                return $this;
            }
        };

        $result = (new MarketplaceMetaI18nSubmitter($events))->submit('Acme_Demo', []);

        self::assertSame(0, $result['count']);
        self::assertSame([], $events->dispatches);
    }
}
