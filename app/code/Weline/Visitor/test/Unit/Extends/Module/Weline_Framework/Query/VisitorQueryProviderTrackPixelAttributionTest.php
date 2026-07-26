<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Extends\Module\Weline_Framework\Query;

use ReflectionMethod;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\TestCore;
use Weline\Visitor\Extends\Module\Weline_Framework\Query\VisitorQueryProvider;
use Weline\Visitor\Service\PixelEventService;

/**
 * A06：worker:visitor.trackPixel → track → prepare，扁平列非空。
 */
class VisitorQueryProviderTrackPixelAttributionTest extends TestCore
{
    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function unwrapPayload(array $params): array
    {
        $method = new ReflectionMethod(VisitorQueryProvider::class, 'payload');
        $method->setAccessible(true);
        /** @var VisitorQueryProvider $provider */
        $provider = ObjectManager::getInstance(VisitorQueryProvider::class);

        /** @var array<string, mixed> $payload */
        $payload = $method->invoke($provider, $params);

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function prepareData(array $payload): array
    {
        /** @var PixelEventService $service */
        $service = ObjectManager::getInstance(PixelEventService::class);
        $prepare = new ReflectionMethod(PixelEventService::class, 'prepare');
        $prepare->setAccessible(true);
        /** @var array{data: array<string, mixed>} $prepared */
        $prepared = $prepare->invoke($service, $payload);

        return $prepared['data'];
    }

    public function testPayloadUnwrapsWorkerEnvelopeAndMarksSource(): void
    {
        $payload = $this->unwrapPayload([
            'payload' => [
                'eventName' => 'page_view',
                'url' => 'https://example.test/?wch=a06',
            ],
        ]);

        self::assertSame('page_view', $payload['eventName']);
        self::assertSame('worker', $payload['source']);
    }

    public function testPayloadAcceptsJsonStringAndDoubleWrap(): void
    {
        $inner = [
            'eventName' => 'cta_click',
            'url' => 'https://example.test/?wch=nested',
        ];
        $fromJson = $this->unwrapPayload([
            'payload' => \json_encode(['payload' => $inner], JSON_UNESCAPED_UNICODE),
        ]);
        self::assertSame('cta_click', $fromJson['eventName']);
        self::assertSame('https://example.test/?wch=nested', $fromJson['url']);
        self::assertSame('worker', $fromJson['source']);
    }

    public function testWorkerShapedPayloadProducesFlatAttributionColumns(): void
    {
        $payload = $this->unwrapPayload([
            'payload' => [
                'eventName' => 'page_view',
                'websiteId' => 930601,
                'url' => 'https://example.test/land?wch=worker_ch&utm_source=google&utm_medium=cpc&utm_campaign=spring',
                'referer' => 'https://google.com/',
                'additionalInfo' => [
                    'environment' => [
                        'session_id' => 'wps-a06-worker',
                        'page_path' => '/land',
                    ],
                ],
            ],
        ]);

        $data = $this->prepareData($payload);

        self::assertSame('wps-a06-worker', $data['session_id']);
        self::assertSame('worker_ch', $data['channel_code']);
        self::assertSame('paid', $data['traffic_type']);
        self::assertSame('google', $data['utm_source']);
        self::assertSame('cpc', $data['utm_medium']);
        self::assertSame('spring', $data['utm_campaign']);
        self::assertNotSame('', $data['channel_code']);
    }

    public function testProviderRoutesTrackPixelToEventServiceTrack(): void
    {
        $source = (string)\file_get_contents(
            BP . '/app/code/Weline/Visitor/extends/module/Weline_Framework/Query/VisitorQueryProvider.php'
        );
        self::assertStringContainsString("'trackPixel' => \$this->pixelEventService->track(\$this->payload(\$params))", $source);
    }
}
