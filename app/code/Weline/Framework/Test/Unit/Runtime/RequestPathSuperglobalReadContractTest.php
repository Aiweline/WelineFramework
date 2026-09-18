<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;

/**
 * Request-path code must read request data from Context via WelineEnv,
 * not from the process superglobal tables.
 */
final class RequestPathSuperglobalReadContractTest extends TestCase
{
    public function testHotPathFilesDoNotReadProcessSuperglobals(): void
    {
        $root = \dirname(__DIR__, 3);
        $files = [
            '/View/Template.php',
            '/App/State.php',
            '/App.php',
            '/Http/ErrorPageRenderer.php',
            '/Http/StorefrontNotFoundStaticPage.php',
            '/Http/Security/SecurityHeaderPolicyService.php',
            '/Http/HeaderCollector.php',
            '/Cache/KeyBuilder.php',
            '/Controller/Api/CspPolicy.php',
            '/Service/Query/QueryProviderRegistry.php',
        ];

        foreach ($files as $relative) {
            $source = (string)\file_get_contents($root . $relative);
            $code = \preg_replace('/\/\*.*?\*\//s', '', $source) ?? $source;
            $code = \preg_replace('/^\s*(\/\/|#).*$/m', '', $code) ?? $code;
            self::assertDoesNotMatchRegularExpression(
                '/\$_(GET|POST|COOKIE|REQUEST|FILES)\b/',
                $code,
                $relative
            );
            self::assertDoesNotMatchRegularExpression(
                '/\$_SERVER\s*\[/',
                $code,
                $relative
            );
        }
    }

    public function testUrlParserReadsOriginFromContextNotServerGlobal(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Http/Url.php');
        self::assertStringNotContainsString("?? \$_SERVER['WELINE_ORIGIN_REQUEST_URI']", $source);
        self::assertStringNotContainsString("?? \$_SERVER['WELINE_URL_PATH_LANG']", $source);
        self::assertStringNotContainsString("\$_SERVER['WELINE_WEBSITE_URL']", $source);
        self::assertStringContainsString("WelineEnv::server('WELINE_ORIGIN_REQUEST_URI'", $source);
    }
}
