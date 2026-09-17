<?php

declare(strict_types=1);

namespace Weline\Faq\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Guards FAQ article pages against blank HTTP 200 responses.
 * Controller success paths must return fetch(view.phtml) so Theme FAQ layout wraps via fetch_file_after.
 */
final class FaqViewLayoutFetchContractTest extends TestCase
{
    public function testSuccessPathsReturnFetchNotBareEmptyString(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/Controller/Frontend/View.php');

        self::assertStringContainsString(
            "return (string)\$this->fetch('Weline_Faq::templates/frontend/view.phtml');",
            $source,
        );
        self::assertSame(
            2,
            substr_count($source, "return (string)\$this->fetch('Weline_Faq::templates/frontend/view.phtml');"),
            'SPI and CMS success paths must both return fetch(view.phtml)',
        );

        // Bare return '' after renderSpiPage / CMS assign is the blank-page regression.
        self::assertDoesNotMatchRegularExpression(
            '/renderSpiPage\([\s\S]*?return\s+[\'"]{2}\s*;/',
            $source,
        );
        self::assertDoesNotMatchRegularExpression(
            "/assign\('seo',\s*\$this->seoFacts->buildArticleProfile\([\s\S]*?return\s+['\"]{2}\s*;/",
            $source,
        );
        self::assertStringNotContainsString('$this->getResponse()', $source);
        self::assertStringContainsString('$this->request->getResponse()->setCode(404)', $source);
    }

    public function testFaqLayoutPrefersMetaContentForArticles(): void
    {
        $layout = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/theme/frontend/layouts/faq/default.phtml'
        );

        self::assertStringContainsString("\$meta['content']", $layout);
        self::assertStringContainsString('faq-article-panel', $layout);
        self::assertStringContainsString("Weline_Faq::templates/frontend/view.phtml", $layout);
    }
}
