<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Skill;

final class SkillNormalizer
{
    public const MAX_BODY_BYTES = 65535;

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

    public function hashBody(string $normalizedBody): string
    {
        return \hash('sha256', $normalizedBody);
    }
}
