<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Model;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;
use Weline\Visitor\Model\Pixel;
use Weline\Visitor\Model\PixelAdditional;

class PixelAdditionalTest extends TestCore
{
    /** @var int[] */
    private array $pixelIds = [];

    /** @var int[] */
    private array $additionalIds = [];

    protected function tearDown(): void
    {
        foreach (array_reverse(array_unique($this->additionalIds)) as $additionalId) {
            try {
                ObjectManager::make(PixelAdditional::class)->load($additionalId)->delete();
            } catch (\Throwable) {
            }
        }

        foreach (array_reverse(array_unique($this->pixelIds)) as $pixelId) {
            try {
                ObjectManager::make(Pixel::class)->load($pixelId)->delete();
            } catch (\Throwable) {
            }
        }

        parent::tearDown();
    }

    public function testSavePixelAdditional(): void
    {
        $pixelId = $this->createPixel()->getId();
        $eventData = [
            'testId' => 'test_001',
            'variant' => 'A',
            'customField' => 'customValue',
        ];

        $additional = ObjectManager::make(PixelAdditional::class);
        $additional->setPixelId($pixelId)
            ->setTotalEventData(json_encode($eventData, JSON_UNESCAPED_UNICODE) ?: '{}')
            ->save();

        $this->additionalIds[] = $additional->getId();

        $this->assertNotEmpty($additional->getId());
        $this->assertEquals($pixelId, $additional->getPixelId());
        $saved = json_decode($additional->getTotalEventData(), true);
        $this->assertSame($eventData['testId'], $saved['testId']);
        $this->assertSame($eventData['variant'], $saved['variant']);
    }

    public function testGetByPixelId(): void
    {
        $pixelId = $this->createPixel()->getId();
        $eventData = ['testId' => 'test_002', 'variant' => 'B'];

        $additional = ObjectManager::make(PixelAdditional::class);
        $additional->setPixelId($pixelId)
            ->setTotalEventData(json_encode($eventData, JSON_UNESCAPED_UNICODE) ?: '{}')
            ->save();

        $this->additionalIds[] = $additional->getId();

        $found = PixelAdditional::getByPixelId($pixelId);

        $this->assertNotNull($found);
        $this->assertEquals($pixelId, $found->getPixelId());
        $this->assertSame($eventData['testId'], json_decode($found->getTotalEventData(), true)['testId']);
    }

    public function testGetAbTestDataByPixelId(): void
    {
        $pixelId = $this->createPixel()->getId();
        $eventData = [
            'testId' => 'ab_test_001',
            'variant' => 'A',
            'otherData' => 'ignored',
        ];

        $additional = ObjectManager::make(PixelAdditional::class);
        $additional->setPixelId($pixelId)
            ->setTotalEventData(json_encode($eventData, JSON_UNESCAPED_UNICODE) ?: '{}')
            ->save();

        $this->additionalIds[] = $additional->getId();

        $abTestData = PixelAdditional::getAbTestDataByPixelId($pixelId);

        $this->assertNotNull($abTestData);
        $this->assertSame($eventData['testId'], $abTestData['testId']);
        $this->assertSame($eventData['variant'], $abTestData['variant']);
    }

    public function testGetEventDataByPixelId(): void
    {
        $pixelId = $this->createPixel()->getId();
        $eventData = [
            'customField1' => 'value1',
            'customField2' => 'value2',
            'nested' => ['key' => 'value'],
        ];

        $additional = ObjectManager::make(PixelAdditional::class);
        $additional->setPixelId($pixelId)
            ->setTotalEventData(json_encode($eventData, JSON_UNESCAPED_UNICODE) ?: '{}')
            ->save();

        $this->additionalIds[] = $additional->getId();

        $data = PixelAdditional::getEventDataByPixelId($pixelId);

        $this->assertIsArray($data);
        $this->assertSame($eventData['customField1'], $data['customField1']);
        $this->assertSame($eventData['customField2'], $data['customField2']);
        $this->assertSame($eventData['nested']['key'], $data['nested']['key']);
    }

    private function createPixel(array $overrides = []): Pixel
    {
        $pixel = ObjectManager::make(Pixel::class);
        $pixel->setData(array_merge([
            'url' => 'https://example.com/test',
            'event' => 'click',
            'website_id' => 1,
            'ip' => '192.168.1.1',
            'user_agent' => 'Test Agent',
        ], $overrides))->save();

        $this->pixelIds[] = $pixel->getId();

        return $pixel;
    }
}
