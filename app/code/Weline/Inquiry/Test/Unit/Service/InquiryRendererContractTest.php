<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class InquiryRendererContractTest extends TestCase
{
    public function testRenderedFormIsAttachedToTheInquiryBody(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/InquiryRenderer.php';

        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString(
            'form.appendChild(submit);body.appendChild(form);',
            $source,
            'The renderer must attach the constructed form after all fields and the submit button are added.'
        );
    }

    public function testAddressAssetsResolveViaFetchTagSourceWithoutDevFallback(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/InquiryRenderer.php';
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('ADDRESS_SCRIPT_SOURCE = \'Weline_Theme::js/address.js\'', $source);
        self::assertStringContainsString('ADDRESS_LOADER_SOURCE = \'Weline_Theme::js/address-loader.js\'', $source);
        self::assertStringContainsString('fetchTagSource(DataInterface::dir_type_STATICS', $source);
        self::assertStringContainsString('function addressAssetUrl', $source);
        self::assertStringNotContainsString('/Weline/Theme/view/statics/', $source);
        self::assertDoesNotMatchRegularExpression(
            '/addressScript\|\|["\']\/Weline\//',
            $source,
            'JS must not fall back to DEV-shaped /Weline/*/view/statics/ paths'
        );
    }
}
