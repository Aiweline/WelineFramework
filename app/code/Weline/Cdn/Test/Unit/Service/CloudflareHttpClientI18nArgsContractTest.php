<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

/**
 * __() 第二参须为数组，才能替换 %{1}/%{2}；分参会被忽略，出现字面「代码 %{2}」。
 */
final class CloudflareHttpClientI18nArgsContractTest extends TestCase
{
    public function testHttpErrorUsesArrayI18nArgs(): void
    {
        $src = (string)file_get_contents(BP . 'app/code/Weline/Cdn/Service/CloudflareHttpClient.php');
        self::assertStringContainsString(
            "'Cloudflare 请求失败（HTTP %{1}，代码 %{2}）。'",
            $src
        );
        self::assertStringContainsString(
            '[$status, $this->publicErrorCode($decoded)]',
            $src
        );
        self::assertStringNotContainsString(
            "'Cloudflare 请求失败（HTTP %{1}，代码 %{2}）。',\n                    \$status,\n                    \$this->publicErrorCode(\$decoded),",
            $src
        );
    }
}
