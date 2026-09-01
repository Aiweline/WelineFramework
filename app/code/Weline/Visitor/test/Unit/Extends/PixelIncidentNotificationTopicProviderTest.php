<?php
declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Extends;

use Weline\Framework\Test\TestCore;
use Weline\Visitor\Extends\NotificationTopicProvider;
use Weline\Visitor\Service\PixelErrorIncidentClassifier;

class PixelIncidentNotificationTopicProviderTest extends TestCore
{
    public function testProvidesAllClassifierTopics(): void
    {
        $provider = new NotificationTopicProvider();
        $topics = $provider->getTopics();
        $codes = [];
        foreach ($topics as $topic) {
            self::assertSame(NotificationTopicProvider::GROUP, $topic['group'] ?? null);
            $codes[] = (string)($topic['code'] ?? '');
        }
        foreach (PixelErrorIncidentClassifier::ALL_TYPES as $type) {
            self::assertContains($type, $codes);
        }
    }
}
