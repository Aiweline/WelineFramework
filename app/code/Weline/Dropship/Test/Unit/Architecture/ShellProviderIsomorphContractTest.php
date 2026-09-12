<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Contract: 壳 Controller 不得承载供应商业务实现（同构 Payment；MCP shell_provider_business_isomorph）。
 */
final class ShellProviderIsomorphContractTest extends TestCase
{
    public function testBackendControllersDoNotEmbedVendorClientsOrApis(): void
    {
        $dir = dirname(__DIR__, 3) . '/Controller/Backend';
        self::assertDirectoryExists($dir);
        $files = glob($dir . '/*.php') ?: [];
        self::assertNotEmpty($files);

        $forbidden = [
            'CjApiClient',
            'Weline\\CjDropshipping',
            'curl_exec',
            "file_get_contents('https://",
        ];

        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            foreach ($forbidden as $needle) {
                self::assertStringNotContainsString(
                    $needle,
                    $src,
                    basename($file) . ' must not embed vendor business (' . $needle . '); put it in Extends DropshipProvider.'
                );
            }
        }
    }

    public function testProviderDevelopmentDocumentsIsomorphHardRule(): void
    {
        $root = dirname(__DIR__, 3) . '/doc';
        $provider = (string) file_get_contents($root . '/provider-development.md');
        $shell = (string) file_get_contents($root . '/dropship-shell.md');

        self::assertStringContainsString('shell_provider_business_isomorph', $provider);
        self::assertStringContainsString('硬性同构', $provider);
        self::assertStringContainsString('禁止', $provider);
        self::assertStringContainsString('shell_provider_business_isomorph', $shell);
        self::assertStringContainsString('Provider 必须拥有', $shell);
    }
}
