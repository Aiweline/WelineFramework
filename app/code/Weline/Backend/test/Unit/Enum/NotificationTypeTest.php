<?php

declare(strict_types=1);

namespace Weline\Backend\test\Unit\Enum;

use PHPUnit\Framework\TestCase;
use Weline\Backend\Enum\NotificationType;

class NotificationTypeTest extends TestCase
{
    /**
     * 测试所有枚举值存在
     */
    public function testAllTypesExist(): void
    {
        $this->assertInstanceOf(NotificationType::class, NotificationType::INFO);
        $this->assertInstanceOf(NotificationType::class, NotificationType::SUCCESS);
        $this->assertInstanceOf(NotificationType::class, NotificationType::WARNING);
        $this->assertInstanceOf(NotificationType::class, NotificationType::ERROR);
        $this->assertInstanceOf(NotificationType::class, NotificationType::URGENT);
    }

    /**
     * 测试枚举值字符串
     */
    public function testTypeValues(): void
    {
        $this->assertEquals('info', NotificationType::INFO->value);
        $this->assertEquals('success', NotificationType::SUCCESS->value);
        $this->assertEquals('warning', NotificationType::WARNING->value);
        $this->assertEquals('error', NotificationType::ERROR->value);
        $this->assertEquals('urgent', NotificationType::URGENT->value);
    }

    /**
     * 测试 fromString 方法
     */
    public function testFromString(): void
    {
        $this->assertEquals(NotificationType::INFO, NotificationType::fromString('info'));
        $this->assertEquals(NotificationType::SUCCESS, NotificationType::fromString('success'));
        $this->assertEquals(NotificationType::WARNING, NotificationType::fromString('warning'));
        $this->assertEquals(NotificationType::ERROR, NotificationType::fromString('error'));
        $this->assertEquals(NotificationType::URGENT, NotificationType::fromString('urgent'));
    }

    /**
     * 测试 fromString 无效值返回默认
     */
    public function testFromStringInvalidReturnsDefault(): void
    {
        $this->assertEquals(NotificationType::INFO, NotificationType::fromString('invalid'));
        $this->assertEquals(NotificationType::INFO, NotificationType::fromString(''));
        $this->assertEquals(NotificationType::INFO, NotificationType::fromString('unknown'));
    }

    /**
     * 测试获取标签
     */
    public function testGetLabel(): void
    {
        $this->assertNotEmpty(NotificationType::INFO->getLabel());
        $this->assertNotEmpty(NotificationType::SUCCESS->getLabel());
        $this->assertNotEmpty(NotificationType::WARNING->getLabel());
        $this->assertNotEmpty(NotificationType::ERROR->getLabel());
        $this->assertNotEmpty(NotificationType::URGENT->getLabel());
    }

    /**
     * 测试获取颜色
     */
    public function testGetHexColor(): void
    {
        $colors = [
            NotificationType::INFO->getHexColor(),
            NotificationType::SUCCESS->getHexColor(),
            NotificationType::WARNING->getHexColor(),
            NotificationType::ERROR->getHexColor(),
            NotificationType::URGENT->getHexColor(),
        ];
        
        foreach ($colors as $color) {
            $this->assertMatchesRegularExpression('/^#[0-9a-fA-F]{6}$/', $color);
        }
    }

    /**
     * 测试获取优先级
     */
    public function testGetPriority(): void
    {
        $infoPriority = NotificationType::INFO->getPriority();
        $successPriority = NotificationType::SUCCESS->getPriority();
        $warningPriority = NotificationType::WARNING->getPriority();
        $errorPriority = NotificationType::ERROR->getPriority();
        $urgentPriority = NotificationType::URGENT->getPriority();
        
        $this->assertIsInt($infoPriority);
        $this->assertIsInt($successPriority);
        $this->assertIsInt($warningPriority);
        $this->assertIsInt($errorPriority);
        $this->assertIsInt($urgentPriority);
        
        $this->assertGreaterThan($infoPriority, $urgentPriority);
        $this->assertGreaterThan($successPriority, $errorPriority);
    }

    /**
     * 测试消息级别比较
     */
    public function testMeetsMinimumType(): void
    {
        $this->assertTrue(NotificationType::meetsMinimumType('info', 'info'));
        $this->assertTrue(NotificationType::meetsMinimumType('warning', 'info'));
        $this->assertTrue(NotificationType::meetsMinimumType('error', 'warning'));
        $this->assertTrue(NotificationType::meetsMinimumType('urgent', 'error'));
        
        $this->assertFalse(NotificationType::meetsMinimumType('info', 'warning'));
        $this->assertFalse(NotificationType::meetsMinimumType('info', 'error'));
        $this->assertFalse(NotificationType::meetsMinimumType('warning', 'error'));
    }

    /**
     * 测试所有类型数组
     */
    public function testAllTypesArray(): void
    {
        $allTypes = NotificationType::cases();
        
        $this->assertCount(5, $allTypes);
        $this->assertContains(NotificationType::INFO, $allTypes);
        $this->assertContains(NotificationType::SUCCESS, $allTypes);
        $this->assertContains(NotificationType::WARNING, $allTypes);
        $this->assertContains(NotificationType::ERROR, $allTypes);
        $this->assertContains(NotificationType::URGENT, $allTypes);
    }
}
