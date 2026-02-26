<?php

declare(strict_types=1);

namespace Agent\CursorBase\Api;

/**
 * 按键模拟器接口
 * 
 * 职责：模拟键盘按键操作，用于自动触发 Cursor 执行
 */
interface KeyboardSimulatorInterface
{
    /**
     * 触发 Cursor 执行（模拟 Ctrl+K / Cmd+K）
     *
     * @return bool 是否成功
     */
    public function triggerCursorExecution(): bool;

    /**
     * 发送按键组合
     *
     * @param array $keys 按键列表
     * @return bool
     */
    public function sendKeys(array $keys): bool;

    /**
     * 激活 Cursor 窗口
     *
     * @return bool
     */
    public function activateCursorWindow(): bool;

    /**
     * 检查是否支持按键模拟
     *
     * @return bool
     */
    public function isSupported(): bool;
}
