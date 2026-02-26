<?php

declare(strict_types=1);

namespace Agent\CursorBase\Api;

/**
 * Cursor AI 聊天接口
 * 
 * 职责：劫持 Cursor IDE 的 AI 模型进行聊天，支持流式响应
 */
interface CursorAiInterface
{
    /**
     * 发送消息并获取响应（阻塞模式）
     *
     * @param string $prompt 用户消息
     * @param string $systemPrompt 系统提示
     * @param array $context 上下文（聊天历史）
     * @param int $timeout 超时时间（秒）
     * @return array ['success' => bool, 'response' => string, 'error' => string|null]
     */
    public function chat(string $prompt, string $systemPrompt = '', array $context = [], int $timeout = 0): array;

    /**
     * 发送消息并流式获取响应
     *
     * @param string $prompt 用户消息
     * @param string $systemPrompt 系统提示
     * @param array $context 上下文
     * @param callable(string $chunk): void $onChunk 回调函数，每次有新内容时调用
     * @param int $timeout 超时时间（秒）
     * @return array ['success' => bool, 'response' => string, 'error' => string|null]
     */
    public function chatStream(string $prompt, string $systemPrompt = '', array $context = [], ?callable $onChunk = null, int $timeout = 0): array;

    /**
     * 检查 Cursor 是否可用
     *
     * @return bool
     */
    public function isAvailable(): bool;

    /**
     * 获取会话目录
     *
     * @return string
     */
    public function getSessionDir(): string;

    /**
     * 清理过期会话
     *
     * @param int $maxAgeSeconds 最大保留时间（秒）
     * @return int 清理的文件数
     */
    public function cleanupSessions(int $maxAgeSeconds = 86400): int;
}
