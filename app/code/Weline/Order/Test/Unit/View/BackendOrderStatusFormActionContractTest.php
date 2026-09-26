<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Order\Controller\Backend\Order as BackendOrderController;

/**
 * 后台订单状态表单：action 用 kebab 路径；控制器须 postUpdateStatus（POST），
 * 不可叫 updateStatus（会被路由解析成 ::UPDATE，HTML form method=post 会落到前端 404）。
 */
final class BackendOrderStatusFormActionContractTest extends TestCase
{
    public function testControllerExposesPostUpdateStatusNotUpdateStatus(): void
    {
        $ref = new ReflectionClass(BackendOrderController::class);
        self::assertTrue(
            $ref->hasMethod('postUpdateStatus'),
            'Backend Order 须暴露 postUpdateStatus，以便路由注册为 update-status::POST',
        );
        self::assertFalse(
            $ref->hasMethod('updateStatus'),
            '禁止 updateStatus：首段 update 会注册为 HTTP UPDATE，表单 POST 无法命中',
        );
        $method = $ref->getMethod('postUpdateStatus');
        self::assertTrue($method->isPublic());
    }

    public function testPostActionsRedirectToBackendOrderEditNotModuleRoot(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Backend/Order.php',
        );
        self::assertStringContainsString(
            "redirect('*/backend/order/edit?id='",
            $src,
            'postUpdateStatus/addComment/cancel 须回跳 order/backend/order/edit（* 仅展开为模块 frontName）',
        );
        self::assertStringNotContainsString(
            "redirect('*/edit",
            $src,
            '禁止 */edit：会变成 order/edit 并落到前端 404',
        );
    }

    public function testViewUpdateStatusFormUsesKebabRoute(): void
    {
        $html = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Backend/Order/view.phtml',
        );
        self::assertStringContainsString(
            "@backend-url{'order/backend/order/update-status'}",
            $html,
        );
        self::assertStringNotContainsString(
            "@backend-url{'order/backend/order/updateStatus'}",
            $html,
        );
    }

    public function testEditUpdateStatusAndAddCommentFormsUseKebabRoutes(): void
    {
        $html = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Backend/Order/edit.phtml',
        );
        self::assertStringContainsString(
            "@backend-url{'order/backend/order/update-status'}",
            $html,
        );
        self::assertStringContainsString(
            "@backend-url{'order/backend/order/add-comment'}",
            $html,
        );
        self::assertStringNotContainsString(
            "@backend-url{'order/backend/order/updateStatus'}",
            $html,
        );
        self::assertStringNotContainsString(
            "@backend-url{'order/backend/order/addComment'}",
            $html,
        );
    }
}
