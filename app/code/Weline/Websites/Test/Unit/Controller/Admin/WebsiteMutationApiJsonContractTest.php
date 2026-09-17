<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Controller\Admin;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Controller\Admin\Website;

/**
 * Website edit/add mutation：Api/XHR/worker 走 JSON，OffCanvas 表单仍 302。
 */
final class WebsiteMutationApiJsonContractTest extends TestCase
{
    public function testPrefersApiJsonDetectsAjaxHeaderAcceptAndWorkerReferer(): void
    {
        $source = $this->methodSource('prefersApiJsonResponse');
        self::assertStringContainsString('isAjax()', $source);
        self::assertStringContainsString("getHeader('X-Weline-Api'", $source);
        self::assertStringContainsString('application/json', $source);
        self::assertStringContainsString('weline-api-worker.js', $source);
    }

    public function testFinishWebsiteMutationReturnsJsonWhenApiPreferred(): void
    {
        $source = $this->methodSource('finishWebsiteMutation');
        self::assertStringContainsString('prefersApiJsonResponse()', $source);
        self::assertStringContainsString('fetchJson($payload)', $source);
        self::assertStringContainsString("'success' => !$isError", $source);
        self::assertStringContainsString("'website_id' => \$websiteId", $source);
        self::assertStringContainsString('noteApiWebsiteMutationCall', $source);
        self::assertStringContainsString("component/backend/offcanvas/getSuccess", $source);
        self::assertStringContainsString("component/backend/offcanvas/getError", $source);
    }

    public function testEditEarlyErrorsUseSharedOffcanvasResponder(): void
    {
        $edit = $this->methodSource('edit');
        self::assertStringContainsString('respondWebsiteOffcanvasError', $edit);
        self::assertStringNotContainsString(
            "redirect('component/backend/offcanvas/getError'",
            $edit,
        );
    }

    private function methodSource(string $methodName): string
    {
        $method = new \ReflectionMethod(Website::class, $methodName);
        $fileName = $method->getFileName();
        self::assertIsString($fileName);
        $lines = file($fileName);
        self::assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));
    }
}
