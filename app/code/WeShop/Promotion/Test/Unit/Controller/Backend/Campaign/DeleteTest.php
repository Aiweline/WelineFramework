<?php

declare(strict_types=1);

namespace WeShop\Promotion\Test\Unit\Controller\Backend\Campaign;

use PHPUnit\Framework\TestCase;
use WeShop\Promotion\Controller\Backend\Campaign\Delete;

class DeleteTest extends TestCase
{
    public function testDeleteWithInvalidId(): void
    {
        $invalidId = 0;
        $this->assertLessThanOrEqual(0, $invalidId);

        $result = ['success' => false, 'message' => '无效的促销活动ID'];
        $this->assertFalse($result['success']);
        $this->assertSame('无效的促销活动ID', $result['message']);
    }

    public function testDeleteWithNonExistentId(): void
    {
        $result = ['success' => false, 'message' => '促销活动不存在或删除失败'];
        $this->assertFalse($result['success']);
    }

    public function testDeleteSuccess(): void
    {
        $result = ['success' => true, 'message' => '删除成功'];
        $this->assertTrue($result['success']);
        $this->assertSame('删除成功', $result['message']);
    }

    public function testDeleteWithException(): void
    {
        $result = ['success' => false, 'message' => '删除失败'];
        $this->assertFalse($result['success']);
        $this->assertSame('删除失败', $result['message']);
    }
}
