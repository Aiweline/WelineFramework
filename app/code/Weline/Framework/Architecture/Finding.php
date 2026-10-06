<?php

declare(strict_types=1);

namespace Weline\Framework\Architecture;

final readonly class Finding
{
    public function __construct(
        public string $rule,
        public string $message,
        public string $file = '',
        public int $line = 0,
    ) {
    }

    /**
     * @return array{rule: string, message: string, file: string, line: int}
     */
    public function toArray(): array
    {
        return [
            'rule' => $this->rule,
            'message' => $this->message,
            'file' => $this->file,
            'line' => $this->line,
        ];
    }

    /**
     * 位置不入库：行号会随无关编辑漂移；身份 = 规则 + 文件 + 消息。
     */
    public function fingerprint(): string
    {
        return hash('sha256', $this->file . "\0" . $this->message);
    }
}
