<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Extends\Module\Weline_Seo;

use PHPUnit\Framework\TestCase;

final class ProductSeoDescriptionPadContractTest extends TestCase
{
    public function testEmptyDescriptionTemplateIsPaddedPastSoftMinimum(): void
    {
        $provider = (string)file_get_contents(
            dirname(__DIR__, 5) . '/extends/module/Weline_Seo/SeoProfileProvider/ProductSeoProfileProvider.php'
        );
        $detail = (string)file_get_contents(dirname(__DIR__, 5) . '/Controller/Frontend/Detail.php');

        self::assertStringContainsString('if (mb_strlen($description) < 80)', $provider);
        self::assertStringNotContainsString('} elseif ($length < 80)', $provider);
        self::assertStringContainsString('if (mb_strlen($description) < 80)', $detail);
        self::assertStringContainsString("\$profileProduct['meta_description'] = \$description", $provider);
    }
}
