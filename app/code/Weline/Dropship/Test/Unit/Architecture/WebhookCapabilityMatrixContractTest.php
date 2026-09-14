<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use Weline\CjDropshipping\Extends\Module\Weline_Dropship\DropshipProvider\CjProvider;

/**
 * 锁定：壳保留全部 webhook_*；文档标明沙盒边界（纠纷沙盒不可用）。
 */
final class WebhookCapabilityMatrixContractTest extends TestCase
{
    public function testCjKeepsAllWebhookCapabilities(): void
    {
        $caps = (new CjProvider())->getCapabilities();
        self::assertTrue(!empty($caps['webhook']));
        foreach ([
            'webhook_order',
            'webhook_product',
            'webhook_stock',
            'webhook_logistics',
            'webhook_makeup',
            'webhook_private_order',
            'webhook_dispute',
        ] as $key) {
            self::assertTrue(!empty($caps[$key]), $key . ' must stay enabled on shell/provider');
        }
    }

    public function testDocsMarkSandboxDisputeUnsupportedAndShellKept(): void
    {
        $dropship = (string)file_get_contents(dirname(__DIR__, 3) . '/doc/功能现状.md');
        $cj = (string)file_get_contents(dirname(__DIR__, 4) . '/CjDropshipping/doc/功能现状.md');
        $req = (string)file_get_contents(dirname(__DIR__, 4) . '/CjDropshipping/doc/需求.md');
        $shell = (string)file_get_contents(dirname(__DIR__, 3) . '/doc/dropship-shell.md');
        $provider = (string)file_get_contents(dirname(__DIR__, 3) . '/doc/provider-development.md');

        foreach ([$dropship, $cj, $shell, $provider] as $doc) {
            self::assertStringContainsString('壳已接', $doc);
            self::assertStringContainsString('沙盒真推', $doc);
            self::assertStringContainsString('沙盒不可用', $doc);
        }
        self::assertStringContainsString('webhook_dispute', $dropship . $cj . $provider);
        self::assertStringContainsString('disputes/create', $cj . $req);
        self::assertStringContainsString('Sandbox account is not supported', $cj . $req . $dropship);
    }
}
