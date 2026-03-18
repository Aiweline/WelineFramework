<?php
declare(strict_types=1);

/**
 * CLI 手动跑 Websites 相关 Cron 时的测试上下文：按域名过滤 + 详细调试输出。
 * 仅应在 websites:cron:test 等命令中 begin/end 成对使用；已注册 StateManager 重置。
 */

namespace Weline\Websites\Service;

use Weline\Framework\Runtime\StateManager;

final class WebsitesCronTestContext
{
    private static ?string $domainFilter = null;

    private static bool $verbose = false;

    private static bool $forceHourlyAddons = false;

    private static bool $forcePoolCertVerify = false;

    private static bool $registered = false;

    public static function begin(?string $domainFilter, bool $verbose, bool $forceHourlyAddons = false): void
    {
        self::ensureRegistered();
        self::$domainFilter = $domainFilter !== null && $domainFilter !== ''
            ? \strtolower(\trim($domainFilter))
            : null;
        self::$verbose = $verbose;
        self::$forceHourlyAddons = $forceHourlyAddons;
        if (self::$verbose) {
            self::detail('begin', [
                'domain_filter' => self::$domainFilter,
                'verbose' => self::$verbose,
                'force_hourly' => self::$forceHourlyAddons,
            ]);
        }
    }

    public static function setForceHourlyAddons(bool $value): void
    {
        self::$forceHourlyAddons = $value;
        if (self::$verbose) {
            self::detail('set_force_hourly', ['value' => $value]);
        }
    }

    public static function end(): void
    {
        if (self::$verbose) {
            self::detail('end', []);
        }
        self::$domainFilter = null;
        self::$verbose = false;
        self::$forceHourlyAddons = false;
        self::$forcePoolCertVerify = false;
    }

    public static function setForcePoolCertVerify(bool $value): void
    {
        self::$forcePoolCertVerify = $value;
    }

    public static function forcePoolCertVerify(): bool
    {
        return self::$forcePoolCertVerify;
    }

    public static function getDomainFilter(): ?string
    {
        return self::$domainFilter;
    }

    public static function isVerbose(): bool
    {
        return self::$verbose;
    }

    public static function forceHourlyAddons(): bool
    {
        return self::$forceHourlyAddons;
    }

    /**
     * 是否应处理该主体域名（池子 FQDN 或根域）。
     *
     * @param string      $subject   当前记录的域名（池行 domain 或根域 domain）
     * @param string|null $rootDomain 池行的根域，可为 null
     */
    public static function matchesSubject(string $subject, ?string $rootDomain = null): bool
    {
        if (self::$domainFilter === null) {
            return true;
        }
        $f = self::$domainFilter;
        $s = \strtolower(\trim($subject));
        if ($s === $f) {
            return true;
        }
        if ($rootDomain !== null && \strtolower(\trim($rootDomain)) === $f) {
            return true;
        }
        if ($f !== '' && \str_ends_with($s, '.' . $f)) {
            return true;
        }

        return false;
    }

    public static function detail(string $message, array $context = []): void
    {
        if (!self::$verbose) {
            return;
        }
        $line = '[websites-cron-test] ' . $message;
        if ($context !== []) {
            $line .= ' ' . \json_encode($context, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        }
        if (\PHP_SAPI === 'cli') {
            \fwrite(\STDOUT, $line . \PHP_EOL);
        }
        w_log_info($line, [], 'websites_cron_test');
    }

    public static function skipNote(string $subject, string $reason): void
    {
        if (self::$domainFilter === null) {
            return;
        }
        $line = '[websites-cron-test] skip: ' . $subject . ' — ' . $reason;
        if (\PHP_SAPI === 'cli') {
            \fwrite(\STDOUT, $line . \PHP_EOL);
        }
        w_log_info($line, [], 'websites_cron_test');
    }

    private static function ensureRegistered(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        StateManager::registerStaticReset(self::class, 'domainFilter', null);
        StateManager::registerStaticReset(self::class, 'verbose', false);
        StateManager::registerStaticReset(self::class, 'forceHourlyAddons', false);
        StateManager::registerStaticReset(self::class, 'forcePoolCertVerify', false);
    }
}
