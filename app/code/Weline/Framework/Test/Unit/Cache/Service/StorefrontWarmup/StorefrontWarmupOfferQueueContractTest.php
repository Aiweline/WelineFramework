<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Cache\Service\StorefrontWarmup;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Service\StorefrontWarmup\StorefrontWarmupOfferQueue;
use Weline\Framework\Event\Changed\InvalidationEffect;
use Weline\Framework\Extends\Module\Weline_Framework\Changed\Capability\FpcWarmCapability;

final class StorefrontWarmupOfferQueueContractTest extends TestCase
{
    public function testOfferQueueAndWarmEffectExistWithoutCdn(): void
    {
        self::assertTrue(class_exists(StorefrontWarmupOfferQueue::class));
        self::assertSame(256, StorefrontWarmupOfferQueue::MAX_OFFER_BATCH);
        self::assertSame(512, StorefrontWarmupOfferQueue::MAX_PENDING);
        self::assertSame('warm_fpc_urls', InvalidationEffect::CODE_WARM_FPC_URLS);
        $cap = new FpcWarmCapability();
        self::assertSame('fpc_warm', $cap->code());
        self::assertContains(InvalidationEffect::CODE_WARM_FPC_URLS, $cap->supportedEffects());
        $src = (string)file_get_contents((new \ReflectionClass(FpcWarmCapability::class))->getFileName() ?: '');
        self::assertStringContainsString('StorefrontWarmupOfferQueue', $src);
        self::assertStringNotContainsString('Weline\\Cdn\\', $src);
        self::assertStringNotContainsString('send_warmup', $src);
        self::assertStringNotContainsString('curl_', $src);
    }

    public function testPipelineEnqueuesExpandedUrlsAfterPurge(): void
    {
        $pipeline = (string)file_get_contents(
            dirname(__DIR__, 5) . '/Event/Changed/ChangedPipeline.php'
        );
        self::assertStringContainsString('CODE_WARM_FPC_URLS', $pipeline);
        self::assertStringContainsString('expandUrlMatrix', $pipeline);
        self::assertStringContainsString("\$effect->payload['urls']", $pipeline);
        self::assertStringContainsString('Closed loop', $pipeline);
    }

    public function testEventXmlRegistersStorefrontWarmupOffer(): void
    {
        $xml = (string)file_get_contents(dirname(__DIR__, 5) . '/etc/event.xml');
        self::assertStringContainsString('Weline_Framework::storefront_warmup_offer', $xml);
        self::assertStringContainsString('StorefrontWarmupOfferObserver', $xml);
    }

    public function testOfferTakeRoundTripOnDisk(): void
    {
        self::assertTrue(\defined('BP') || \is_dir(\dirname(__DIR__, 8)), 'repo root must be resolvable');
        $queue = new StorefrontWarmupOfferQueue();
        $marker = 'https://offer-contract-' . \uniqid('', true) . '.example/product/x';
        $rel = '/offer-rel-' . \uniqid('', true);
        $accepted = $queue->offer([$marker, $marker, $rel], 'unit');
        self::assertSame(2, $accepted, 'dedupe identical absolute URL; keep relative');
        $foundMarker = false;
        $foundRel = false;
        $restore = [];
        // take until both test URLs appear or queue empty; restore unrelated rows
        for ($i = 0; $i < 40; $i++) {
            $batch = $queue->take(3);
            if ($batch === []) {
                break;
            }
            foreach ($batch as $row) {
                $u = (string)($row['url'] ?? '');
                if ($u === $marker) {
                    $foundMarker = true;
                    continue;
                }
                if ($u === $rel) {
                    $foundRel = true;
                    continue;
                }
                $restore[] = $row;
            }
            if ($foundMarker && $foundRel) {
                break;
            }
        }
        foreach ($restore as $row) {
            $queue->offer([(string)$row['url']], (string)($row['source'] ?? 'restore'), is_array($row['meta'] ?? null) ? $row['meta'] : []);
        }
        self::assertTrue($foundMarker, 'absolute offer must be take()-able');
        self::assertTrue($foundRel, 'relative offer must be take()-able');
    }

    public function testOrchestratorDrainsOffersBeforePhases(): void
    {
        $src = (string)file_get_contents(
            (new \ReflectionClass(\Weline\Framework\Cache\Service\StorefrontWarmup\StorefrontWarmupOrchestrator::class))
                ->getFileName() ?: ''
        );
        self::assertStringContainsString('drainOneOffer', $src);
        self::assertStringContainsString('StorefrontWarmupOfferQueue', $src);
        self::assertStringContainsString('getAbsolute', $src);
        self::assertStringContainsString('OFFER_DRAIN_PER_SLICE', $src);
    }
}
