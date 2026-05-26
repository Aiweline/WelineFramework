<?php

declare(strict_types=1);

namespace WeShop\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Payment\Service\PaymentCatalogueService;
use WeShop\Payment\Service\PaymentDocumentationService;

class PaymentDocumentationServiceTest extends TestCase
{
    public function testEveryCatalogueMethodHasCompleteEmbeddedDocumentation(): void
    {
        $catalogue = new PaymentCatalogueService();
        $documentationService = new PaymentDocumentationService();
        $invalid = [];

        foreach ($catalogue->getMethodRegistry() as $code => $method) {
            $documentation = $documentationService->getDocumentation($method);
            if (!($documentation['valid'] ?? false)) {
                $invalid[$code] = [
                    'exists' => $documentation['exists'] ?? false,
                    'missing_sections' => $documentation['missing_sections'] ?? [],
                    'path' => $documentation['relative_path'] ?? '',
                ];
            }
        }

        $this->assertSame([], $invalid);
    }

    public function testRenderedMarkdownEscapesRawHtml(): void
    {
        $documentationService = new PaymentDocumentationService();

        $html = $documentationService->renderMarkdown("# 标题\n\n<script>alert(1)</script>\n\n- [官方](https://example.com/docs)");

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('href="https://example.com/docs"', $html);
    }
}
