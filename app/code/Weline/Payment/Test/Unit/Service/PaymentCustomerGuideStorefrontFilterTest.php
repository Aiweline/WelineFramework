<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Payment\Service\PaymentCustomerGuideRegistry;

/**
 * 契约：指南 hub「不启用即隐藏」，但详情页（含供应商登记的协议链接）始终可达。
 *
 * 可用性只在 Provider 层判定一次（{@see \Weline\Payment\Service\PaymentMethodManager::getStorefrontAvailableMethodCodes()}），
 * 指南层只消费结果；`getEntry()` 不过滤，避免把支付商的协议 URL 变成 404。
 */
final class PaymentCustomerGuideStorefrontFilterTest extends TestCase
{
    /**
     * @param (callable(string):string)|null $urlBuilder
     * @param (callable(array<string,mixed>):(array<string,true>|null))|null $availability
     */
    private function registry(?callable $urlBuilder = null, ?callable $availability = null): PaymentCustomerGuideRegistry
    {
        $builder = $urlBuilder ?? static fn (string $route): string => 'https://url.test/' . $route;

        return new PaymentCustomerGuideRegistry(null, $builder, $availability);
    }

    /** @return list<string> */
    private function codes(array $entries): array
    {
        $codes = [];
        foreach ($entries as $entry) {
            $codes[] = (string) ($entry['method_code'] ?? '');
        }

        return $codes;
    }

    public function testStorefrontListHidesUnavailableMethods(): void
    {
        $registry = $this->registry(
            availability: static fn (array $context): array => ['paypal' => true],
        );

        $all = $this->codes($registry->listPublishedEntries(true));
        self::assertContains('paypal', $all, '前置条件：内置指南应包含 paypal。');
        self::assertContains('fake_card', $all, '前置条件：内置指南应包含 fake_card。');

        $visible = $this->codes($registry->listStorefrontPublishedEntries(true));
        self::assertContains('paypal', $visible);
        self::assertNotContains('fake_card', $visible, '未启用的支付方式不应出现在指南 hub。');
        self::assertNotContains('stripe', $visible, '未启用的支付方式不应出现在指南 hub。');
    }

    public function testStorefrontListKeepsActiveEntryEvenWhenUnavailable(): void
    {
        $registry = $this->registry(
            availability: static fn (array $context): array => ['paypal' => true],
        );

        $visible = $this->codes($registry->listStorefrontPublishedEntries(true, [], 'fake_card'));
        self::assertContains('paypal', $visible);
        self::assertContains('fake_card', $visible, '当前浏览的详情页必须留在导航里，否则用户会「掉出」列表。');
        self::assertNotContains('stripe', $visible);
    }

    public function testStorefrontListDoesNotFilterWhenAvailabilityUnknown(): void
    {
        $registry = $this->registry(
            availability: static fn (array $context): ?array => null,
        );

        self::assertSame(
            $this->codes($registry->listPublishedEntries(true)),
            $this->codes($registry->listStorefrontPublishedEntries(true)),
            '可用性不可判定时不得隐藏任何条目，避免把指南 hub 变成空页。',
        );
    }

    public function testUnavailableMethodDetailEntryStaysResolvable(): void
    {
        $registry = $this->registry(
            availability: static fn (array $context): array => ['paypal' => true],
        );

        $entry = $registry->getEntry('fake_card');
        self::assertIsArray($entry, '未启用方式的指南详情仍须可解析（供应商登记的协议链接是法律链接）。');
        self::assertSame('fake_card', $entry['method_code'] ?? null);
    }

    public function testHubControllerUsesStorefrontFilteredList(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Frontend/Guide/Payment.php'
        );
        self::assertStringContainsString(
            'listStorefrontPublishedEntries',
            $src,
            '指南 hub 必须用「不启用即隐藏」的列表，而不是全量列表。',
        );
    }

    public function testMethodManagerOwnsStorefrontAvailabilityGate(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Service/PaymentMethodManager.php'
        );
        self::assertStringContainsString('public function getStorefrontAvailableMethodCodes', $src);
        self::assertStringContainsString('isMethodActiveForScope', $src);
        self::assertStringContainsString('getActiveMethods($context)', $src);
    }
}
