<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 契约：服务端支付方式 HTML 重注入后必须保留用户已选支付方式。
 *
 * 背景：传统 getData 按硬隔离规则忽略 payment_method（CheckoutQueryProvider），
 * 服务端渲染恒为 selected_index=0（首项）。选中支付方式后前端会用软刷新核对优惠
 * （schedulePaymentIncentiveReconcile → loadCheckout → applyServerHtml），
 * 若重注入不还原选中项，刚点的 PayPal 会闪一下被打回首项「本地测试支付」。
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
        // 注入后还原。
        self::assertStringContainsString('restorePaymentMethodSelection(node, keepPaymentMethod);', $src);
        // 仍以服务端 HTML 为唯一来源，禁止前端拼选项 DOM。
        self::assertStringContainsString('node.innerHTML = html;', $src);
    }

    public function testRestoreSkipsUnavailableMethodAndKeepsServerDefault(): void
    {
        $src = $this->templateSource();

        self::assertStringContainsString('function restorePaymentMethodSelection(node, code)', $src);
        // 已下线/禁用（disabled）的方式不还原，退回服务端默认项。
        self::assertStringContainsString("input.value === code && !input.disabled", $src);
        self::assertStringContainsString("node.querySelectorAll('input[name=\"payment_method\"]')", $src);
    }

    public function testRestoreDoesNotDispatchChangeToAvoidReconcileLoop(): void
    {
        $src = $this->templateSource();

        $helper = $this->extractFunctionBody($src, 'function restorePaymentMethodSelection(node, code)');
        self::assertNotSame('', $helper);
        // 只改 checked 属性；派发 change 会再次触发优惠核对软刷新，形成互相触发的循环。
        self::assertStringContainsString('input.checked = input === target;', $helper);
        self::assertStringNotContainsString('dispatchEvent', $helper);
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
