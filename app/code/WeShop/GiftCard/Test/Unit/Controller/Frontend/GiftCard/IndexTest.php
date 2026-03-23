<?php

declare(strict_types=1);

namespace WeShop\GiftCard\Test\Unit\Controller\Frontend\GiftCard;

use PHPUnit\Framework\TestCase;
use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\GiftCard\Controller\Frontend\GiftCard\Index;
use WeShop\GiftCard\Service\GiftCardPageDataService;
use Weline\Framework\Manager\ObjectManager;

class IndexTest extends TestCase
{
    public function testIndexRedirectsGuestCustomersToLogin(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(null);

        $pageDataService = $this->createMock(GiftCardPageDataService::class);
        $pageDataService->expects($this->never())->method('build');

        $controller = $this->getMockBuilder(Index::class)
            ->setConstructorArgs([$customerContext, $pageDataService])
            ->onlyMethods(['assign', 'fetch', 'redirect'])
            ->getMock();

        $controller->expects($this->once())
            ->method('redirect')
            ->with('customer/account/login');
        $controller->expects($this->never())->method('assign');
        $controller->expects($this->never())->method('fetch');
        $this->setProtectedProperty($controller, '_objectManager', ObjectManager::getInstance());

        $this->assertSame('', $controller->index());
    }

    public function testIndexAssignsGiftCardPageDataForLoggedInCustomer(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(8);

        $pageDataService = $this->createMock(GiftCardPageDataService::class);
        $pageDataService->expects($this->once())
            ->method('build')
            ->with(8)
            ->willReturn([
                'gift_cards' => [['card_id' => 11]],
                'gift_card_count' => 1,
                'gift_card_active_count' => 1,
                'gift_card_total_balance' => 80.0,
                'gift_card_total_amount' => 100.0,
            ]);

        $controller = $this->getMockBuilder(Index::class)
            ->setConstructorArgs([$customerContext, $pageDataService])
            ->onlyMethods(['assign', 'fetch', 'redirect'])
            ->getMock();

        $controller->expects($this->never())->method('redirect');
        $controller->expects($this->exactly(5))->method('assign');
        $controller->expects($this->once())->method('fetch')->willReturn('page');

        $this->assertSame('page', $controller->index());
    }

    private function setProtectedProperty(object $target, string $property, mixed $value): void
    {
        $reflection = new \ReflectionObject($target);
        while (!$reflection->hasProperty($property) && ($reflection = $reflection->getParentClass())) {
        }

        $reflectionProperty = $reflection->getProperty($property);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($target, $value);
    }
}
