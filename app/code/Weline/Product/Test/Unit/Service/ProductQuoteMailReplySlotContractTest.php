<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\ProductQuoteMailReplySlotService;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

final class ProductQuoteMailReplySlotContractTest extends TestCase
{
    public function testSourceConstantAndServiceSurface(): void
    {
        self::assertSame('product_quote', ProductQuoteMailReplySlotService::SOURCE);
        $src = (string)file_get_contents(BP . 'app/code/Weline/Product/Service/ProductQuoteMailReplySlotService.php');
        self::assertStringContainsString('resolveLocalMailboxByEmail', $src);
        self::assertStringContainsString('listLocalMailboxes', $src);
        self::assertStringContainsString('Weline_Mail::mail_send_as', $src);
        self::assertStringContainsString('buildComposerPayload', $src);
        self::assertStringContainsString('mailto:', $src);
        self::assertStringNotContainsString('getBackendUrl(\'weline_mail/backend\'', $src);
    }

    public function testAdminTemplateHasMailReplySlot(): void
    {
        $tpl = (string)file_get_contents(BP . 'app/code/Weline/Product/view/templates/Backend/QuoteRequest/index.phtml');
        self::assertStringContainsString('mail_reply', $tpl);
        self::assertStringContainsString('企业邮箱回复', $tpl);
        self::assertStringContainsString('外部邮件客户端', $tpl);
        self::assertStringContainsString('data-quote-mail-open', $tpl);
        self::assertStringContainsString('<w:mail-composer', $tpl);
        self::assertStringContainsString('WelineMailComposer', $tpl);
        self::assertStringNotContainsString('compose=1', $tpl);
    }

    public function testControllerAttachesMailReplySlots(): void
    {
        $controller = (string)file_get_contents(BP . 'app/code/Weline/Product/Controller/Backend/QuoteRequest.php');
        self::assertStringContainsString('ProductQuoteMailReplySlotService', $controller);
        self::assertStringContainsString('attachToQuotes', $controller);
    }

    public function testObserverAndEventXmlWireMailMessageSent(): void
    {
        self::assertFileExists(BP . 'app/code/Weline/Product/Observer/QuoteRequestMailMessageSentObserver.php');
        $observer = (string)file_get_contents(BP . 'app/code/Weline/Product/Observer/QuoteRequestMailMessageSentObserver.php');
        self::assertStringContainsString('ProductQuoteMailReplySlotService::SOURCE', $observer);
        self::assertStringContainsString('STATUS_REPLIED', $observer);
        $xml = (string)file_get_contents(BP . 'app/code/Weline/Product/etc/event.xml');
        self::assertStringContainsString('Weline_Mail::mail_message_sent', $xml);
        self::assertStringContainsString('QuoteRequestMailMessageSentObserver', $xml);
    }

    public function testAccountQuotesOpensComposer(): void
    {
        $hook = (string)file_get_contents(BP . 'app/code/Weline/Product/view/hooks/account.sidebar.content.phtml');
        self::assertStringContainsString('data-quote-thread-open', $hook);
        self::assertStringContainsString('<w:mail-composer', $hook);
        self::assertStringContainsString('product_quote', $hook);
    }

    public function testModuleOptionalMailDependency(): void
    {
        $module = include BP . 'app/code/Weline/Product/etc/module.php';
        self::assertSame('1.0.140', $module['version'] ?? null);
        self::assertArrayHasKey('Weline_Mail', $module['optional'] ?? []);
    }
}
