<?php

declare(strict_types=1);

namespace Weline\Ai\Service\Skill;

final class SkillNormalizer
{
    public const MAX_BODY_BYTES = 262144;

    public function normalizeCode(string $code): string
    {
        $code = \strtolower(\trim($code));
        $code = (string)\preg_replace('/[^a-z0-9_-]+/', '-', $code);
        $code = \trim($code, '-_');

        if ($code === '') {
            throw new \InvalidArgumentException('Skill code cannot be empty.');
        }
        if (\strlen($code) > 64) {
            throw new \InvalidArgumentException('Skill code cannot exceed 64 characters.');
        }

        return $code;
    }

    public function normalizeBody(string $body): string
    {
        $body = \str_replace(["\r\n", "\r"], "\n", $body);
        $body = \trim($body);
        $body = (string)\preg_replace("/\n{4,}/", "\n\n\n", $body);

        if ($body === '') {
            throw new \InvalidArgumentException('Skill body cannot be empty.');
        }
        if (\strlen($body) > self::MAX_BODY_BYTES) {
            throw new \InvalidArgumentException('Skill body cannot exceed ' . self::MAX_BODY_BYTES . ' bytes.');
        }

        return $body;
    }

    public function normalizeCodeList(mixed $raw): array
    {
        if (\is_string($raw)) {
            $decoded = \json_decode($raw, true);
            $raw = \is_array($decoded) ? $decoded : \preg_split('/[\s,;]+/', $raw);
        }
        if (!\is_array($raw)) {
            return [];
        }

        $codes = [];
        foreach ($raw as $item) {
            if (!\is_scalar($item)) {
                continue;
            }
            try {
                $code = $this->normalizeCode((string)$item);
            } catch (\InvalidArgumentException) {
                continue;
            }
            if (!\in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    public function hashBody(string $normalizedBody): string
    {
        return \hash('sha256', $normalizedBody);
    }
}
