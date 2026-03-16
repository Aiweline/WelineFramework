<?php
declare(strict_types=1);

namespace Weline\Bot\Service;

/**
 * 技能执行结果
 */
class SkillResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message = '',
        public readonly mixed $data = null,
        public readonly ?string $error = null,
    ) {}

    /**
     * 创建成功结果
     */
    public static function success(mixed $data = null, string $message = ''): self
    {
        return new self(
            success: true,
            message: $message,
            data: $data,
        );
    }

    /**
     * 创建失败结果
     */
    public static function error(string $error, mixed $data = null): self
    {
        return new self(
            success: false,
            error: $error,
            data: $data,
        );
    }

    /**
     * 创建取消结果
     */
    public static function cancelled(string $reason = ''): self
    {
        return new self(
            success: false,
            error: $reason ?: 'User cancelled the operation',
        );
    }

    /**
     * 获取数据
     */
    public function getData(): mixed
    {
        return $this->data;
    }

    /**
     * 是否成功
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * 转换为数组
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'data' => $this->data,
            'error' => $this->error,
        ];
    }

    /**
     * 转换为 JSON 字符串
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE);
    }
}
