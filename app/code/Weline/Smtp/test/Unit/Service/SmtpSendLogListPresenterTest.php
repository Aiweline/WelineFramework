<?php

declare(strict_types=1);

namespace {
    if (!\function_exists('__')) {
        function __(string $text, array $arguments = []): string
        {
            return $text;
        }
    }
}

namespace Weline\Smtp\Test\Unit\Service {

    use PHPUnit\Framework\TestCase;
    use Weline\Smtp\Service\SmtpSendLogListPresenter;

    final class SmtpSendLogListPresenterTest extends TestCase
    {
        public function testFormatRecipientsFromJsonPairs(): void
        {
            $p = new SmtpSendLogListPresenter();
            self::assertSame(
                'test@example.com',
                $p->formatRecipients('[["test@example.com",""]]')
            );
            self::assertSame(
                'a@x.com, b@y.com',
                $p->formatRecipients('[{"email":"a@x.com","name":"A"},{"email":"b@y.com"}]')
            );
            self::assertSame('—', $p->formatRecipients('[]'));
        }

        public function testFormatLocaleAndTime(): void
        {
            $p = new SmtpSendLogListPresenter();
            self::assertSame('en_US', $p->formatLocale('en_US'));
            self::assertSame('—', $p->formatLocale('default'));
            self::assertSame('2026-09-14 19:23:09', $p->formatTime('2026-09-14 19:23:09.818889'));
        }

        public function testPresentRowAddsHumanFields(): void
        {
            $p = new SmtpSendLogListPresenter();
            $row = $p->presentRow([
                'channel' => 'Weline_Marketing::unpaid_order_reminder',
                'storage_scope' => 'default.default.default',
                'to_email' => '[["buyer@example.com",""]]',
                'locale' => 'zh_Hans_CN',
                'create_time' => '2026-09-14 19:22:40.747417',
                'from_email' => 'aiweline@qq.com',
                'sender_name' => 'default',
                'sender_code' => 'default',
                'module' => 'Weline_Marketing',
                'is_html' => '1',
                'content' => '<h1>Your order</h1><p>Hello Locale Preview, Order WB-EN-ONLY is still awaiting payment.</p>',
            ], [
                'Weline_Marketing::unpaid_order_reminder' => '未付订单催付',
            ]);
            self::assertSame('未付订单催付', $row['channel_label']);
            self::assertSame('Global', $row['scope_label']);
            self::assertSame('buyer@example.com', $row['to_display']);
            self::assertSame('zh_Hans_CN', $row['locale_display']);
            self::assertSame('2026-09-14 19:22:40', $row['time_display']);
            self::assertStringContainsString('Your order', $row['content_excerpt']);
            self::assertStringNotContainsString('<h1>', $row['content_excerpt']);
        }

        public function testBuildContentExcerptStripsHtmlAndTruncates(): void
        {
            $p = new SmtpSendLogListPresenter();
            $long = '<div>' . \str_repeat('订单提醒 ', 40) . '</div>';
            $excerpt = $p->buildContentExcerpt($long, 40);
            self::assertStringNotContainsString('<div>', $excerpt);
            self::assertTrue(\mb_strlen($excerpt) <= 41);
            self::assertStringEndsWith('…', $excerpt);
            self::assertSame('—', $p->buildContentExcerpt('   '));
        }
    }
}
