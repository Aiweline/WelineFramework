<?php
declare(strict_types=1);

namespace Weline\Server\EventLoop;

final class EventLoopFactory
{
    public const DRIVER_AUTO = 'auto';
    public const DRIVER_SELECT = 'select';
    public const DRIVER_EVENT = 'event';

    /**
     * @return array{loop: EventLoopInterface, requested: string, resolved: string}
     */
    public static function create(string $driver): array
    {
        $normalized = self::normalizeDriver($driver);
        if ($normalized === self::DRIVER_EVENT) {
            return [
                'loop' => new EventExtLoop(),
                'requested' => $normalized,
                'resolved' => self::DRIVER_EVENT,
            ];
        }

        // AUTO keeps the stable select backend; event remains opt-in until its
        // watcher churn is removed and TLS fresh-connection latency improves.
        return [
            'loop' => new SelectEventLoop(),
            'requested' => $normalized,
            'resolved' => self::DRIVER_SELECT,
        ];
    }

    public static function normalizeDriver(string $driver): string
    {
        $driver = \strtolower(\trim($driver));
        return match ($driver) {
            self::DRIVER_EVENT => self::DRIVER_EVENT,
            self::DRIVER_SELECT => self::DRIVER_SELECT,
            default => self::DRIVER_AUTO,
        };
    }
}
