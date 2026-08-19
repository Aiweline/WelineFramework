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
}
