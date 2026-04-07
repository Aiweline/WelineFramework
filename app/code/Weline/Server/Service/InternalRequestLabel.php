<?php

declare(strict_types=1);

namespace Weline\Server\Service;

final class InternalRequestLabel
{
    public const HEADER_NAME = 'X-WLS-Internal-Request';
    public const HEALTH_PROBE = 'health-probe';
    public const HOMEPAGE_WARMUP = 'homepage-warmup';

    /**
     * @var string[]
     */
    private const KNOWN_LABELS = [
        self::HEALTH_PROBE,
        self::HOMEPAGE_WARMUP,
    ];

    public static function buildHeaderLine(string $label): string
    {
        return self::HEADER_NAME . ': ' . self::normalize($label) . "\r\n";
    }

    public static function detectFromRawRequest(string $rawRequest): string
    {
        if (!\preg_match('/^' . \preg_quote(self::HEADER_NAME, '/') . ':\s*([^\r\n]+)/mi', $rawRequest, $matches)) {
            return '';
        }

        return self::normalize((string) ($matches[1] ?? ''));
    }

    public static function buildLogPrefix(string $rawRequest): string
    {
        $label = self::detectFromRawRequest($rawRequest);
        if ($label === '') {
            return '';
        }

        return '[internal:' . $label . '] ';
    }

    public static function normalize(string $label): string
    {
        $label = \strtolower(\trim($label));
        if ($label === '') {
            return self::HEALTH_PROBE;
        }

        $label = \preg_replace('/[^a-z0-9-]+/', '-', $label) ?? '';
        $label = \trim($label, '-');
        if ($label === '') {
            return self::HEALTH_PROBE;
        }

        if (\in_array($label, self::KNOWN_LABELS, true)) {
            return $label;
        }

        return $label;
    }
}
