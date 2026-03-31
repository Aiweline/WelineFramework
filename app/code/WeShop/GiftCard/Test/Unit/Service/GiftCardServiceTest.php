<?php

declare(strict_types=1);

namespace WeShop\GiftCard\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\GiftCard\Model\GiftCard;
use WeShop\GiftCard\Service\GiftCardService;

class GiftCardServiceTest extends TestCase
{
    public function testCreateGiftCardUsesSaveGiftCardValidationPipeline(): void
    {
        $payload = ['customer_id' => 9, 'amount' => 100];
        $giftCard = $this->createMock(GiftCard::class);
        $service = new class($giftCard) extends GiftCardService {
            public array $received = [];
            public function __construct(private readonly GiftCard $giftCard)
            {
            }

            public function saveGiftCard(array $data): GiftCard
            {
                $this->received = $data;
                return $this->giftCard;
            }
        };

        $result = $service->createGiftCard($payload);
        $this->assertSame($payload, $service->received);
        $this->assertSame($giftCard, $result);
    }

    public function testGetCustomerGiftCardSummaryCalculatesTotals(): void
    {
        $service = new class() extends GiftCardService {
            public function getCustomerGiftCards(int $customerId, int $limit = 20): array
            {
                return [
                    ['status' => 'active', 'amount' => '100.00', 'balance' => '80.50'],
                    ['status' => 'redeemed', 'amount' => '20.00', 'balance' => '0.00'],
                    ['status' => 'active', 'amount' => '30.00', 'balance' => '10.00'],
                ];
            }
        };

        $summary = $service->getCustomerGiftCardSummary(7);

        $this->assertSame(3, $summary['count']);
        $this->assertSame(2, $summary['active_count']);
        $this->assertSame(90.5, $summary['total_balance']);
        $this->assertSame(150.0, $summary['total_amount']);
    }

    public function testGetStatusOptionsReturnsAllStatuses(): void
    {
        $service = new GiftCardService();
        $statuses = $service->getStatusOptions();

        $this->assertArrayHasKey(GiftCardService::STATUS_ACTIVE, $statuses);
        $this->assertArrayHasKey(GiftCardService::STATUS_REDEEMED, $statuses);
        $this->assertArrayHasKey(GiftCardService::STATUS_EXPIRED, $statuses);
        $this->assertArrayHasKey(GiftCardService::STATUS_DISABLED, $statuses);
        $this->assertCount(4, $statuses);
    }

    public function testIsValidStatusReturnsTrueForValidStatuses(): void
    {
        $service = new GiftCardService();

        $this->assertTrue($service->isValidStatus(GiftCardService::STATUS_ACTIVE));
        $this->assertTrue($service->isValidStatus(GiftCardService::STATUS_REDEEMED));
        $this->assertTrue($service->isValidStatus(GiftCardService::STATUS_EXPIRED));
        $this->assertTrue($service->isValidStatus(GiftCardService::STATUS_DISABLED));
    }

    public function testIsValidStatusReturnsFalseForInvalidStatuses(): void
    {
        $service = new GiftCardService();

        $this->assertFalse($service->isValidStatus('invalid_status'));
        $this->assertFalse($service->isValidStatus(''));
        $this->assertFalse($service->isValidStatus('ACTIVE'));
    }

    public function testNormalizeDateTimeReturnsNullWhenNullableAndInputEmpty(): void
    {
        $service = new GiftCardService();
        $method = new \ReflectionMethod(GiftCardService::class, 'normalizeDateTime');
        $method->setAccessible(true);

        $this->assertNull($method->invoke($service, '', true));
    }

    public function testNormalizeDateTimeThrowsForInvalidInput(): void
    {
        $service = new GiftCardService();
        $method = new \ReflectionMethod(GiftCardService::class, 'normalizeDateTime');
        $method->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);
        $method->invoke($service, 'not-a-date', true);
    }
}
