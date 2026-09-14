<?php

declare(strict_types=1);

namespace Weline\Backend\Test\Unit\Adapter;

use PHPUnit\Framework\TestCase;

/** Contract: EmailAdapter 始终带 channel；sender_code 不 unset channel；vars 发信。 */
final class EmailAdapterMailTemplateContractTest extends TestCase
{
    public function testSendKeepsChannelAndUsesVars(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Adapter/Notification/EmailAdapter.php');
        self::assertStringContainsString("'channel' => \$channel", $src);
        self::assertStringContainsString("'vars'", $src);
        self::assertStringContainsString("'title'", $src);
        self::assertStringContainsString("'content'", $src);
        self::assertStringContainsString("'type_label'", $src);
        self::assertStringContainsString("'topic_code'", $src);
        self::assertStringContainsString('sender_code', $src);
        self::assertStringNotContainsString("unset(\$params['channel'])", $src);
        self::assertStringNotContainsString("unset(\$params[\"channel\"])", $src);
        self::assertDoesNotMatchRegularExpression(
            "/'subject'\\s*=>\\s*\\\$/",
            $src
        );

        $provider = (string)file_get_contents(dirname(__DIR__, 3) . '/Extends/MailChannelProvider.php');
        self::assertStringContainsString("'default_templates'", $provider);
        self::assertStringContainsString('getEnabledTopics', $provider);
        self::assertStringContainsString('view/email/notification/', $provider);
        self::assertFileExists(dirname(__DIR__, 3) . '/view/email/notification/zh_Hans_CN.html');
    }
}
