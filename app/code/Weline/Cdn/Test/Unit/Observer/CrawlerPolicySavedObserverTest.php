<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Api\CrawlerPolicyEdgePublisherInterface;
use Weline\Cdn\Observer\CrawlerPolicySavedObserver;
use Weline\Cdn\Service\CloudflareZoneAiSettingsService;
use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Framework\Event\Event;

final class CrawlerPolicySavedObserverTest extends TestCase
{
    public function testPublishesWithoutTransactionImmediately(): void
    {
        $publisher = $this->createMock(CrawlerPolicyEdgePublisherInterface::class);
        $publisher->expects(self::once())
            ->method('publish')
            ->with(7)
            ->willReturn(['website_id' => 7]);
        $aiSettings = $this->createMock(CloudflareZoneAiSettingsService::class);
        $aiSettings->expects(self::once())
            ->method('applyForWebsite')
            ->with(7, true)
            ->willReturn(['website_id' => 7, 'open' => true, 'results' => []]);
        $transactions = $this->createMock(TransactionCoordinatorInterface::class);

        $event = new Event(['website_id' => 7, 'connection' => null, 'rule' => ['ai_default_open' => true]]);
        (new CrawlerPolicySavedObserver($publisher, $aiSettings, $transactions))->execute($event);
    }

    public function testIgnoresEventsWithoutWebsiteId(): void
    {
        $publisher = $this->createMock(CrawlerPolicyEdgePublisherInterface::class);
        $publisher->expects(self::never())->method('publish');
        $aiSettings = $this->createMock(CloudflareZoneAiSettingsService::class);
        $aiSettings->expects(self::never())->method('applyForWebsite');
        $transactions = $this->createMock(TransactionCoordinatorInterface::class);

        (new CrawlerPolicySavedObserver($publisher, $aiSettings, $transactions))->execute(new Event([]));
    }
}
