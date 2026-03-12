<?php

declare(strict_types=1);

namespace Weline\Framework\Manager;

use Weline\Framework\Runtime\StateManager;

/**
 * 控制器结果管理器
 * 用于 success/error/info/warning 与 redirect 配合，在 iframe 时自动跳转到结果桥接页显示 BackendToast。
 * 结果桥接页地址通过事件 Weline_Framework_Manager::result_bridge_url 由组件返回（如 Component Offcanvas getResult）。
 * 请求级数据，WLS 下由 StateManager 每请求重置。
 */
class ResultManager
{
    /** 获取结果桥接页 URL 的事件名，观察者可通过 data['bridge_url'] 返回地址 */
    public const EVENT_RESULT_BRIDGE_URL = 'Weline_Framework_Manager::result_bridge_url';

    private static ?array $result = null;

    public static function registerStateResets(): void
    {
        if (!\class_exists(StateManager::class, false)) {
            return;
        }
        StateManager::registerStaticResets(self::class, ['result' => null]);
    }

    public static function success(string $message, bool $reload = true): void
    {
        self::$result = ['type' => 'success', 'message' => $message, 'reload' => $reload];
    }

    public static function error(string $message, bool $reload = false): void
    {
        self::$result = ['type' => 'error', 'message' => $message, 'reload' => $reload];
    }

    public static function info(string $message, bool $reload = false): void
    {
        self::$result = ['type' => 'info', 'message' => $message, 'reload' => $reload];
    }

    public static function warning(string $message, bool $reload = false): void
    {
        self::$result = ['type' => 'warning', 'message' => $message, 'reload' => $reload];
    }

    public static function getAndClear(): ?array
    {
        $r = self::$result;
        self::$result = null;
        return $r;
    }

    public static function has(): bool
    {
        return self::$result !== null;
    }
}
