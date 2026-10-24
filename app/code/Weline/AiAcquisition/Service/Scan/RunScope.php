<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Service\Scan;

/**
 * 当前获客运行的请求级上下文：工具需要知道 website_id / run_id 才能做页面去重记账。
 *
 * 由 AcquisitionAgent::execute() 在拿到 params 后写入；工具只读。
 */
final class RunScope
{
    /** @var array{website_id:int,run_id:int} */
    private static array $current = ['website_id' => -1, 'run_id' => 0];

    /**
     * @param array<string,mixed> $params
     */
    public static function setFromParams(array $params): void
    {
        self::$current = [
            'website_id' => (int)($params['website_id'] ?? -1),
            'run_id' => (int)($params['run_id'] ?? 0),
        ];
    }

    public static function websiteId(): int
    {
        return self::$current['website_id'];
    }

    public static function runId(): int
    {
        return self::$current['run_id'];
    }

    public static function hasWebsite(): bool
    {
        return self::$current['website_id'] >= 0;
    }

    public static function clear(): void
    {
        self::$current = ['website_id' => -1, 'run_id' => 0];
    }
}
