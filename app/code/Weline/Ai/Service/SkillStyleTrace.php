<?php

declare(strict_types=1);

namespace Weline\Ai\Service;

final class SkillStyleTrace
{
    private const LOG_RELATIVE_PATH = 'var/log/ai_skill_style_trace.log';

    /**
     * @param array<string, mixed> $context
     */
    public static function log(string $event, array $context = []): void
    {
        try {
            $payload = \json_encode(
                \array_replace([
                    'event' => $event,
                    'ts' => \date('c'),
                ], $context),
                \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR
            );
            $line = '[' . \date('Y-m-d H:i:s') . '] ' . $event . ' ' . ($payload ?: '{}') . "\n";
            $root = \defined('BP') ? \rtrim((string)\constant('BP'), "\\/") . DIRECTORY_SEPARATOR : \getcwd() . DIRECTORY_SEPARATOR;
            $path = $root . \str_replace('/', DIRECTORY_SEPARATOR, self::LOG_RELATIVE_PATH);
            $dir = \dirname($path);
            if (!\is_dir($dir)) {
                @\mkdir($dir, 0777, true);
            }
            @\file_put_contents($path, $line, \FILE_APPEND | \LOCK_EX);
        } catch (\Throwable) {
            // Diagnostics must never affect AI generation or UI flows.
        }
    }
}
