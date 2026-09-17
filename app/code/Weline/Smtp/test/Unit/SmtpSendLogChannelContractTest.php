<?php

declare(strict_types=1);

namespace Weline\Smtp\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 发信记录按渠道 + 作用范围：模型字段 + listing 筛选/列 + 写日志契约。
 */
final class SmtpSendLogChannelContractTest extends TestCase
{
    public function testModelAndListingCarryChannel(): void
    {
        $moduleRoot = dirname(__DIR__, 2);
        $model = (string)file_get_contents($moduleRoot . '/Model/SmtpSendLog.php');
        $listing = (string)file_get_contents($moduleRoot . '/view/templates/Backend/Log/listing.phtml');
        $controller = (string)file_get_contents($moduleRoot . '/Controller/Backend/Log.php');
        $sender = (string)file_get_contents($moduleRoot . '/Helper/SmtpSender.php');
        $provider = (string)file_get_contents(
            $moduleRoot . '/extends/module/Weline_Framework/Query/SmtpQueryProvider.php'
        );

        self::assertStringContainsString("schema_fields_CHANNEL = 'channel'", $model);
        self::assertStringContainsString("schema_fields_SENDER_CODE = 'sender_code'", $model);
        self::assertStringContainsString("schema_fields_STORAGE_SCOPE = 'storage_scope'", $model);
        self::assertStringContainsString('CHANNEL', $model);

        self::assertStringContainsString('schema_fields_CHANNEL', $sender);
        self::assertStringContainsString('schema_fields_SENDER_CODE', $sender);
        self::assertStringContainsString('schema_fields_STORAGE_SCOPE', $sender);
        self::assertStringContainsString("__channel", $sender);
        self::assertStringContainsString("__storage_scope", $sender);

        self::assertStringContainsString("'__channel'", $provider);
        self::assertStringContainsString("'__sender_code'", $provider);
        self::assertStringContainsString("'__storage_scope'", $provider);

        self::assertStringContainsString('MailChannelCollector', $controller);
        self::assertStringContainsString('SmtpSendLogListPresenter', $controller);
        self::assertStringContainsString('channel', $controller);
        self::assertStringContainsString('SystemConfigTargetScopeService', $controller);
        self::assertStringContainsString('schema_fields_STORAGE_SCOPE', $controller);
        self::assertStringContainsString('data-testid="smtp-log-channel-select"', $listing);
        self::assertStringContainsString('data-testid="smtp-log-col-channel"', $listing);
        self::assertStringContainsString('data-testid="smtp-log-scope"', $listing);
        self::assertStringContainsString('<w:scope', $listing);
        self::assertStringContainsString('to_display', $listing);
        self::assertStringContainsString('locale_display', $listing);
        self::assertStringContainsString('content_excerpt', $listing);
        self::assertStringContainsString('data-testid="smtp-log-excerpt"', $listing);
        self::assertStringContainsString('data-smtp-log-preview', $listing);
        self::assertStringContainsString('data-testid="smtp-log-preview-dialog"', $listing);
        self::assertStringContainsString('data-testid="smtp-log-preview-iframe"', $listing);
        self::assertStringContainsString('channel', $listing);
        self::assertStringContainsString("embed' => '1'", $controller);
        self::assertStringContainsString('Backend/Log/embed', $controller);
        self::assertStringContainsString('respondEmbedDocument', $controller);
        self::assertStringContainsString('ResponseTerminateException', $controller);
        self::assertStringContainsString('content_excerpt', (string)file_get_contents($moduleRoot . '/Service/SmtpSendLogListPresenter.php'));
        self::assertStringNotContainsString('smtp-log-channel-tabs', $listing);
        self::assertStringNotContainsString('{{log.content}}', $listing);
        self::assertFileExists($moduleRoot . '/view/templates/Backend/Log/embed.phtml');
        self::assertStringContainsString('data-smtp-embed-standalone', (string)file_get_contents($moduleRoot . '/view/templates/Backend/Log/embed.phtml'));
    }
}
