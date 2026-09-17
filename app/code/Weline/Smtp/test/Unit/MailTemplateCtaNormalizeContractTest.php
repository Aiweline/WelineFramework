<?php

declare(strict_types=1);

namespace Weline\Smtp\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Smtp\Service\MailTemplateRenderer;

final class MailTemplateCtaNormalizeContractTest extends TestCase
{
    public function testNormalizeRestoresCtaButtonStylesFromCkEditorMarkup(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 2) . '/Service/MailTemplateRenderer.php');
        self::assertStringContainsString('function normalizeEmailHtml', $src);
        self::assertStringContainsString('ensureCtaAnchorStyle', $src);

        $edit = (string)file_get_contents(dirname(__DIR__, 2) . '/view/Backend/Template/edit.phtml');
        self::assertStringContainsString('function normalizeEmailBody', $edit);
        self::assertStringContainsString('normalizeEmailBody(readBodyFromIframe())', $edit);

        $controller = (string)file_get_contents(dirname(__DIR__, 2) . '/Controller/Backend/Template.php');
        self::assertStringContainsString('normalizeEmailHtml', $controller);

        $ck = '<h1>重置您的账户密码</h1>'
            . '<figure class="table"><table><tbody><tr>'
            . '<td style="background-color:#e8a14a;border-color:#d48f3a;text-align:center;">'
            . '<a href="{{var.reset_url}}"><strong>立即重置密码</strong></a>'
            . '</td></tr></tbody></table></figure>';

        $out = (new MailTemplateRenderer())->normalizeEmailHtml($ck);
        self::assertStringNotContainsString('<figure', $out);
        self::assertMatchesRegularExpression('/bgcolor=["\']#e8a14a["\']/i', $out);
        self::assertMatchesRegularExpression('/<a[^>]*style="[^"]*display:\\s*inline-block/i', $out);
        self::assertMatchesRegularExpression('/<a[^>]*style="[^"]*padding:\\s*14px 26px/i', $out);
        self::assertMatchesRegularExpression('/<a[^>]*style="[^"]*text-decoration:\\s*none/i', $out);
        self::assertMatchesRegularExpression('/<a[^>]*style="[^"]*color:\\s*#16333f/i', $out);
    }
}
