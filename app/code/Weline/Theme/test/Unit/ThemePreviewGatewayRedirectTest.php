<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

final class ThemePreviewGatewayRedirectTest extends TestCase
{
    public function testRelativeBackendExitRedirectBecomesSameOriginAbsoluteUrl(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 2) . '/Controller/Frontend/ThemePreview/Gateway.php'
        );
        $start = strpos($source, 'private function resolveExitRedirectUrl(): string');
        $end = strpos($source, 'private function stripPreviewTokenFromRedirect', $start ?: 0);

        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $redirectMethod = substr($source, (int)$start, (int)$end - (int)$start);

        self::assertStringContainsString('$this->request->getBaseHost()', $redirectMethod);
        self::assertMatchesRegularExpression(
            '/str_starts_with\(\$redirect, \'\/\'\).*?rtrim\(\$this->request->getBaseHost\(\), \'\/\'\).*?stripPreviewTokenFromRedirect\(\$redirect\)/s',
            $redirectMethod,
        );
    }
}
