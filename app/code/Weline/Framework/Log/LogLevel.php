<?php

declare(strict_types=1);

/**
 * Weline Framework 日志级别枚举
 * 
 * PHP 8.1+ 枚举类型，定义标准日志级别及其优先级
 */

namespace Weline\Framework\Log;

enum LogLevel: int
{
    case EMERGENCY = 800;
    case ALERT     = 700;
    case CRITICAL  = 600;
    case ERROR     = 500;
    case WARNING   = 400;
    case NOTICE    = 300;
    case INFO      = 200;
    case DEBUG     = 100;

    /**
     * 判断当前级别是否应该被记录
     *
     * @param self $minLevel 最小记录级别
     * @return bool 如果当前级别 >= 最小级别则返回 true
     */
    public function shouldLog(self $minLevel): bool
    {
        return $this->value >= $minLevel->value;
    }

    /**
     * 从字符串获取日志级别
     *
     * @param string $level 级别名称（不区分大小写）
     * @return self
     * @throws \ValueError 如果级别名称无效
     */
    public static function fromString(string $level): self
    {
        $level = strtoupper(trim($level));
        
        return match ($level) {
            'EMERGENCY' => self::EMERGENCY,
            'ALERT'     => self::ALERT,
            'CRITICAL'  => self::CRITICAL,
            'ERROR'     => self::ERROR,
            'WARNING', 'WARN' => self::WARNING,
            'NOTICE'    => self::NOTICE,
            'INFO'      => self::INFO,
            'DEBUG'     => self::DEBUG,
            default     => throw new \ValueError("Invalid log level: {$level}"),
        };
    }

    /**
     * 安全地从字符串获取日志级别，无效时返回默认值
     *
     * @param string $level 级别名称
     * @param self $default 默认级别
     * @return self
     */
    public static function tryFromString(string $level, self $default = self::INFO): self
    {
        try {
            return self::fromString($level);
        } catch (\ValueError) {
            return $default;
        }
    }

    /**
     * 获取级别的小写名称
     */
    public function toLowerCase(): string
    {
        return strtolower($this->name);
    }

    /**
     * 获取所有级别名称
     *
     * @return array<string>
     */
    public static function names(): array
    {
        return array_map(fn(self $level) => $level->name, self::cases());
    }
}
