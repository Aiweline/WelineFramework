<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;

/**
 * 后台订单页必须覆盖 BackendPageController 默认「WelineFramework Admin」标题。
 */
final class BackendOrderPageTitleContractTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = (string) file_get_contents(
            dirname(__DIR__, 4) . '/Controller/Backend/Order.php',
        );
    }

    public function testViewAndEditAssignOrderEditTitle(): void
    {
        self::assertStringContainsString("assignBackendPageTitle((string)__('订单编辑'))", $this->src);
        self::assertGreaterThanOrEqual(
            2,
            substr_count($this->src, "assignBackendPageTitle((string)__('订单编辑'))"),
            'view 与 edit 都必须写入「订单编辑」顶栏标题',
        );
    }

    public function testIndexAssignsOrderListTitle(): void
    {
        self::assertStringContainsString("assignBackendPageTitle((string)__('订单列表'))", $this->src);
    }

    public function testHelperAssignsTitleAndPageTitle(): void
    {
        self::assertStringContainsString("\$this->assign('title', \$title);", $this->src);
        self::assertStringContainsString("\$this->assign('page_title', \$title);", $this->src);
        self::assertStringNotContainsString(
            "assign('title', __('WelineFramework Admin'))",
            $this->src,
        );
    }
}
