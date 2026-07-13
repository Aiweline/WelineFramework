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
}
