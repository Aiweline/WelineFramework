<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 契约：服务端支付方式 HTML 重注入后必须保留用户已选支付方式。
 *
 * 背景：传统 getData 按硬隔离规则忽略 payment_method（CheckoutQueryProvider），
 * 服务端渲染恒为 selected_index=0（首项）。地址 soft 刷新等路径若 applyServerHtml
 * 重注入支付区，必须还原用户已选方式，否则会闪回首项。
 * 支付切换本身禁止再 getData（仅 renderTotals）。
 */
final class CheckoutPaymentMethodSelectionPersistContractTest extends TestCase
{
    private function templateSource(): string
    {
        $path = dirname(__DIR__, 3) . '/view/frontend/checkout/index.phtml';
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testApplyServerHtmlKeepsSelectedPaymentMethodAcrossInjection(): void
    {
        $src = $this->templateSource();

        // 注入前先记住用户当前选中的支付方式。
        self::assertStringContainsString('const keepPaymentMethod = node === paymentBox && form', $src);
        self::assertStringContainsString("selectedValue('payment_method')", $src);
        // 注入后还原支付/配送已选。
        self::assertStringContainsString("restoreNamedMethodSelection(node, 'payment_method', keepPaymentMethod);", $src);
        self::assertStringContainsString("restoreNamedMethodSelection(node, 'shipping_method', keepShippingMethod);", $src);
        // 仍以服务端 HTML 为唯一来源，禁止前端拼选项 DOM。
        self::assertStringContainsString('node.innerHTML = html;', $src);
    }

    public function testRestoreSkipsUnavailableMethodAndKeepsServerDefault(): void
    {
        $src = $this->templateSource();

        self::assertStringContainsString('function restoreNamedMethodSelection(node, name, code)', $src);
        // 已下线/禁用（disabled）的方式不还原，退回服务端默认项。
        self::assertStringContainsString("input.value === code && !input.disabled", $src);
        self::assertStringContainsString("node.querySelectorAll('input[name=\"' + name + '\"]')", $src);
    }

    public function testRestoreDoesNotDispatchChangeToAvoidReconcileLoop(): void
    {
        $src = $this->templateSource();

        $helper = $this->extractFunctionBody($src, 'function restoreNamedMethodSelection(node, name, code)');
        self::assertNotSame('', $helper);
        // 只改 checked 属性；派发 change 会再次触发优惠核对软刷新，形成互相触发的循环。
        self::assertStringContainsString('input.checked = input === target;', $helper);
        self::assertStringNotContainsString('dispatchEvent', $helper);
    }

    public function testSoftRenderSkipsItemsAndAddressPanelsByDefault(): void
    {
        $src = $this->templateSource();
        self::assertStringContainsString('const soft = opts.soft === true', $src);
        self::assertStringContainsString('items: false, shipping: true, payment: true, address: false', $src);
        self::assertStringContainsString('if (panels.items)', $src);
        self::assertStringContainsString('if (panels.shipping)', $src);
        self::assertStringContainsString('if (panels.payment)', $src);
    }

    public function testPaymentMethodChangeDoesNotReloadCheckout(): void
    {
        $src = $this->templateSource();
        $reconcile = $this->extractFunctionBody($src, 'function schedulePaymentIncentiveReconcile()');
        self::assertNotSame('', $reconcile);
        self::assertStringContainsString('renderTotals()', $reconcile);
        self::assertStringNotContainsString('loadCheckout', $reconcile);
        self::assertStringNotContainsString('getData', $reconcile);
        // paymentBox change 监听也不得再挂 getData 核对。
        self::assertMatchesRegularExpression(
            '/paymentBox\.addEventListener\(\s*[\'"]change[\'"][\s\S]{0,400}?renderTotals\(\)/',
            $src,
        );
        self::assertDoesNotMatchRegularExpression(
            '/paymentBox\.addEventListener\(\s*[\'"]change[\'"][\s\S]{0,400}?schedulePaymentIncentiveReconcile/',
            $src,
        );
    }

    public function testSelectedIncentiveFallsBackToDomSavingsWhenSsrSkipsGetData(): void
    {
        $src = $this->templateSource();
        $body = $this->extractFunctionBody($src, 'function selectedIncentiveSavingsMajor()');
        self::assertNotSame('', $body);
        self::assertStringContainsString('incentive_savings_minor', $body);
        self::assertStringContainsString('data-incentive-savings-minor', $body);
        self::assertStringContainsString('input[name="payment_method"]:checked', $body);
    }

    private function extractFunctionBody(string $src, string $signature): string
    {
        $start = strpos($src, $signature);
        if ($start === false) {
            return '';
        }
        $end = strpos($src, "\n    }", $start);
        if ($end === false) {
            return '';
        }

        return substr($src, $start, $end - $start);
    }
}
