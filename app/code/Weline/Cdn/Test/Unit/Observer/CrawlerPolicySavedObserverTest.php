<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Api\CrawlerPolicyEdgePublisherInterface;
use Weline\Cdn\Observer\CrawlerPolicySavedObserver;
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
        $transactions = $this->createMock(TransactionCoordinatorInterface::class);

        $event = new Event(['website_id' => 7, 'connection' => null]);
        (new CrawlerPolicySavedObserver($publisher, $transactions))->execute($event);
    }

    public function testIgnoresEventsWithoutWebsiteId(): void
    {
        $publisher = $this->createMock(CrawlerPolicyEdgePublisherInterface::class);
        $publisher->expects(self::never())->method('publish');
        $transactions = $this->createMock(TransactionCoordinatorInterface::class);

        (new CrawlerPolicySavedObserver($publisher, $transactions))->execute(new Event([]));
    }
}
