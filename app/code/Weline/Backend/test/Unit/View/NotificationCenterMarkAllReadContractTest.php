<?php

declare(strict_types=1);

namespace Weline\Backend\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 通知中心「全部已读 / 此类全部已读」必须有前端脚本绑定，不能只靠 data-* 声明。
 */
final class NotificationCenterMarkAllReadContractTest extends TestCase
{
    private function backendRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    public function testIndexTemplateWiresMarkAllReadActionAndScript(): void
    {
        $template = (string) file_get_contents(
            $this->backendRoot() . '/view/templates/Backend/Notification/index.phtml'
        );
        $script = (string) file_get_contents(
            $this->backendRoot() . '/view/statics/js/notification-center.js'
        );

        self::assertStringContainsString('data-w-component="notification-center"', $template);
        self::assertStringContainsString('data-w-notification-action="mark-all-read"', $template);
        self::assertStringContainsString('data-w-mark-all-url=', $template);
        self::assertStringContainsString("system/backend/notification/markAllRead", $template);
        self::assertStringContainsString('Weline_Backend::js/notification-center.js', $template);

        self::assertStringContainsString("mark-all-read", $script);
        self::assertStringContainsString('data-w-mark-all-url', $script);
        // 全部已读必须走 binquery（Weline.adminRequest → query-bin），不能直接
        // window.fetch：会被 ApiModule 的 monkey-patch 路由进 Weline.Api，
        // 其按 METHOD+pathname 的 MAX_IN_FLIGHT=1 闸门会让快速连点报
        // "too many in-flight requests for POST ..."。这是历史 bug 锁定的回归断言。
        self::assertStringNotContainsString('fetch(', $script);
        self::assertStringContainsString("adminRequest('backend_admin'", $script);
        self::assertStringContainsString('POST', $script);
    }

    public function testDetailTemplateWiresMarkTopicReadActionAndScript(): void
    {
        $template = (string) file_get_contents(
            $this->backendRoot() . '/view/templates/Backend/Notification/detail.phtml'
        );
        $script = (string) file_get_contents(
            $this->backendRoot() . '/view/statics/js/notification-center.js'
        );

        self::assertStringContainsString('data-w-component="notification-center"', $template);
        self::assertStringContainsString('data-w-notification-action="mark-topic-read"', $template);
        self::assertStringContainsString('data-w-mark-topic-url=', $template);
        self::assertStringContainsString('Weline_Backend::js/notification-center.js', $template);

        self::assertStringContainsString('mark-topic-read', $script);
        self::assertStringContainsString('topic_code', $script);
        self::assertStringContainsString('ArrowLeft', $script);
        self::assertStringContainsString('ArrowRight', $script);
    }
}
