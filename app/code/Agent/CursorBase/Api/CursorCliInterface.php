<?php

declare(strict_types=1);

namespace Agent\CursorBase\Api;

/**
 * Cursor CLI 操作接口
 * 
 * 职责：Cursor 编辑器的 CLI 命令操作（唤醒、定位、状态检查）
 */
interface CursorCliInterface
{
    /**
     * 唤醒 Cursor 并定位到指定文件和行
     *
     * @param string $filePath 文件路径
     * @param int $line 行号
     * @return bool 是否成功
     */
    public function wake(string $filePath, int $line = 1): bool;

    /**
     * 检查 Cursor 是否正在运行
     *
     * @return bool
     */
    public function isRunning(): bool;

    /**
     * 获取 Cursor 窗口句柄（Windows）
     *
     * @return int|null
     */
    public function getWindowHandle(): ?int;

    /**
     * 获取 Cursor 可执行文件路径
     *
     * @return string|null
     */
    public function getExecutablePath(): ?string;
}
