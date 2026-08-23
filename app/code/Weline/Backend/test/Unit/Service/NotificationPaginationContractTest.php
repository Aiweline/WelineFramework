<?php

declare(strict_types=1);

namespace Weline\Backend\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * 通知列表分页必须读 getPaginationState()，不能用返回 HTML 的 getPagination()。
 */
final class NotificationPaginationContractTest extends TestCase
{
    public function testNotificationServiceUsesPaginationStateNotHtmlRenderer(): void
    {
        $service = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/NotificationService.php');
        $template = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Backend/Notification/index.phtml'
        );

        self::assertStringContainsString('->getPaginationState()', $service);
        self::assertStringNotContainsString('$this->statusModel->getPagination()', $service);
        self::assertStringContainsString("\$pagination['totalSize']", $service);
        self::assertStringContainsString("\$pagination['lastPage']", $service);
        self::assertStringContainsString('w-notification-center__pagination', $template);
        self::assertStringContainsString('$totalPages > 1', $template);
    }
}
