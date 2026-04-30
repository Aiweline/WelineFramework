<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Contract;

final class QaGateHelper
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PASS = 'pass';
    public const STATUS_FAIL = 'fail';
    public const STATUS_WARN = 'warn';

    /**
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    public function gate(string $key, string $status = self::STATUS_PENDING, string $message = '', array $details = []): array
    {
        return [
            'key' => $key,
            'status' => $status,
            'message' => $message,
            'details' => $details,
        ];
    }

    /**
     * @param list<string> $keys
     * @return array<string, array<string, mixed>>
     */
    public function pendingSet(array $keys): array
    {
        $gates = [];
        foreach ($keys as $key) {
            $key = \trim($key);
            if ($key === '') {
                continue;
            }
            $gates[$key] = $this->gate($key);
        }

        return $gates;
    }
}
