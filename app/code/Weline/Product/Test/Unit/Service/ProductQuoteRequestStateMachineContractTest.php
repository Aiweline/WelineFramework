<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Model\ProductQuoteRequest;
use Weline\Product\Service\ProductQuoteRequestStateMachine;

/**
 * Contract: quote request status machine + event names + admin template path.
 */
final class ProductQuoteRequestStateMachineContractTest extends TestCase
{
    public function testStatusesAndTransitionMap(): void
    {
        self::assertContains(ProductQuoteRequest::STATUS_PROCESSING, ProductQuoteRequest::STATUSES);
        self::assertContains(ProductQuoteRequest::STATUS_REPLIED, ProductQuoteRequest::STATUSES);
        self::assertContains(ProductQuoteRequest::STATUS_CANCELLED, ProductQuoteRequest::STATUSES);

        $src = (string)file_get_contents(BP . 'app/code/Weline/Product/Service/ProductQuoteRequestStateMachine.php');
        self::assertStringContainsString('EVENT_CAN_TRANSITION', $src);
        self::assertStringContainsString('EVENT_CHANGE_BEFORE', $src);
        self::assertStringContainsString('EVENT_CHANGED', $src);
        self::assertStringContainsString('Weline_Product::quote_request_status_can_transition', $src);
        self::assertStringContainsString('Weline_Product::quote_request_status_change_before', $src);
        self::assertStringContainsString('Weline_Product::quote_request_status_changed', $src);
        self::assertStringContainsString('STATUS_REPLIED', $src);
        self::assertStringContainsString('ADMIN_REPLY_AT', $src);
    }

    public function testEventsRegisteredInEventPhp(): void
    {
        $events = include BP . 'app/code/Weline/Product/event.php';
        self::assertIsArray($events);
        foreach ([
            'Weline_Product::quote_request_submitted',
            'Weline_Product::quote_request_status_can_transition',
            'Weline_Product::quote_request_status_change_before',
            'Weline_Product::quote_request_status_changed',
        ] as $name) {
            self::assertArrayHasKey($name, $events);
        }
        self::assertFileExists(BP . 'app/code/Weline/Product/doc/event/quote_request_status_changed.md');
    }

    public function testServiceDelegatesMarkProcessedToStateMachine(): void
    {
        $src = (string)file_get_contents(BP . 'app/code/Weline/Product/Service/ProductQuoteRequestService.php');
        self::assertStringContainsString('ProductQuoteRequestStateMachine', $src);
        self::assertStringContainsString('transitionStatus', $src);
        self::assertStringContainsString('quote_request_submitted', $src);
        self::assertStringContainsString("STATUS_PROCESSED", $src);
    }

    public function testAdminControllerUsesIndexTemplateAndTransitionAction(): void
    {
        $controller = (string)file_get_contents(BP . 'app/code/Weline/Product/Controller/Backend/QuoteRequest.php');
        self::assertStringContainsString("fetch('index')", $controller);
        self::assertStringContainsString('function transition', $controller);
        self::assertStringContainsString('transitionStatus', $controller);
        self::assertFileExists(BP . 'app/code/Weline/Product/view/templates/Backend/QuoteRequest/index.phtml');
        $tpl = (string)file_get_contents(BP . 'app/code/Weline/Product/view/templates/Backend/QuoteRequest/index.phtml');
        self::assertStringContainsString('available_transitions', $tpl);
        self::assertStringContainsString('quote-request/transition', $tpl);
        self::assertStringContainsString('w:form', $tpl);
        self::assertStringContainsString('csrf="auto"', $tpl);
        self::assertStringNotContainsString('fetch(\'backend/quote-request/index\')', $controller);
    }

    public function testAvailableTransitionsMap(): void
    {
        $om = $this->createMock(\Weline\Framework\Manager\ObjectManager::class);
        $events = $this->createMock(\Weline\Framework\Event\EventsManager::class);
        $machine = new ProductQuoteRequestStateMachine($om, $events);
        self::assertSame(
            [
                ProductQuoteRequest::STATUS_PROCESSING,
                ProductQuoteRequest::STATUS_REPLIED,
                ProductQuoteRequest::STATUS_PROCESSED,
                ProductQuoteRequest::STATUS_CANCELLED,
            ],
            $machine->getAvailableTransitions(ProductQuoteRequest::STATUS_NEW)
        );
        self::assertSame(
            [
                ProductQuoteRequest::STATUS_REPLIED,
                ProductQuoteRequest::STATUS_PROCESSED,
                ProductQuoteRequest::STATUS_CANCELLED,
            ],
            $machine->getAvailableTransitions(ProductQuoteRequest::STATUS_PROCESSING)
        );
        self::assertSame([], $machine->getAvailableTransitions(ProductQuoteRequest::STATUS_PROCESSED));
        self::assertSame([], $machine->getAvailableTransitions(ProductQuoteRequest::STATUS_CANCELLED));
    }
}
