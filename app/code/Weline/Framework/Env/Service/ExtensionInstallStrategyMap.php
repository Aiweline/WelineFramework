<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Env\Service;

/**
 * 扩展安装策略映射：按系统类型优先使用常用包管理器，找不到再回退逐个尝试（先检查命令是否存在）
 *
 * 平台与常用命令映射：macOS → brew；Debian/Ubuntu → apt-get；CentOS/RHEL → yum；Fedora → dnf；Alpine → apk；Arch → pacman；通用 → pecl
 */
class ExtensionInstallStrategyMap
{
    /** 平台：macOS */
    public const PLATFORM_DARWIN = 'darwin';

    /** 平台：Debian/Ubuntu 系 (apt) */
    public const PLATFORM_LINUX_APT = 'linux_apt';

    /** 平台：Fedora/RHEL 新系 (dnf) */
    public const PLATFORM_LINUX_DNF = 'linux_dnf';

    /** 平台：CentOS/RHEL 旧系 (yum) */
    public const PLATFORM_LINUX_YUM = 'linux_yum';

    /** 平台：Alpine (apk) */
    public const PLATFORM_LINUX_APK = 'linux_apk';

    /** 平台：Arch (pacman) */
    public const PLATFORM_LINUX_PACMAN = 'linux_pacman';

    /** 平台：Docker 容器内 */
    public const PLATFORM_DOCKER = 'docker';

    /** 平台：Linux 通用（未识别发行版） */
    public const PLATFORM_LINUX_GENERIC = 'linux_generic';

    /**
     * 检测当前平台（仅 Unix：Linux/macOS）
     */
    public function getCurrentPlatform(): string
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            return self::PLATFORM_DARWIN;
        }

        if (PHP_OS_FAMILY !== 'Linux') {
            return self::PLATFORM_LINUX_GENERIC;
        }

        if ($this->isDocker()) {
            return self::PLATFORM_DOCKER;
        }

        $id = $this->getLinuxOsId();
        $idLike = $this->getLinuxOsIdLike();

        if (in_array($id, ['debian', 'ubuntu', 'linuxmint', 'pop'], true)
            || in_array($idLike, ['debian', 'ubuntu'], true)) {
            return self::PLATFORM_LINUX_APT;
        }
        if (in_array($id, ['fedora', 'rhel', 'rocky', 'almalinux'], true)
            || str_contains($idLike, 'fedora') || str_contains($idLike, 'rhel')) {
            return self::PLATFORM_LINUX_DNF;
        }
        if (in_array($id, ['centos'], true) || str_contains($idLike, 'centos')) {
            return self::PLATFORM_LINUX_YUM;
        }
        if (in_array($id, ['alpine'], true)) {
            return self::PLATFORM_LINUX_APK;
        }
        if (in_array($id, ['arch', 'manjaro'], true)) {
            return self::PLATFORM_LINUX_PACMAN;
        }

        return self::PLATFORM_LINUX_GENERIC;
    }

    /**
     * 当前平台是否优先使用某命令（用于提示）
     */
    public function getPreferredPackageManagerName(string $platform): string
    {
        $names = [
            self::PLATFORM_DARWIN       => 'brew',
            self::PLATFORM_LINUX_APT    => 'apt',
            self::PLATFORM_LINUX_DNF    => 'dnf',
            self::PLATFORM_LINUX_YUM    => 'yum',
            self::PLATFORM_LINUX_APK    => 'apk',
            self::PLATFORM_LINUX_PACMAN => 'pacman',
            self::PLATFORM_DOCKER       => 'docker-php-ext-install',
            self::PLATFORM_LINUX_GENERIC => 'pecl',
        ];
        return $names[$platform] ?? 'pecl';
    }

    /**
     * 获取当前平台优先的安装策略（仅返回当前平台对应的策略，不保证命令存在）
     */
    public function getPreferredStrategies(string $platform, string $ext, string $phpVersion): array
    {
        $strategies = $this->buildAllStrategies($ext, $phpVersion);
        $preferred = [];
        foreach ($strategies as $s) {
            if (in_array($platform, $s['platforms'], true)) {
                $preferred[] = $s;
            }
        }
        return $preferred;
    }

    /**
     * 获取回退策略（非当前平台或通用策略，用于优先策略均失败后逐个尝试）
     */
    public function getFallbackStrategies(string $platform, string $ext, string $phpVersion): array
    {
        $strategies = $this->buildAllStrategies($ext, $phpVersion);
        $fallback = [];
        foreach ($strategies as $s) {
            if (!in_array($platform, $s['platforms'], true)) {
                $fallback[] = $s;
            }
        }
        return $fallback;
    }

    /**
     * 检查命令是否存在于 PATH（Unix：which）
     */
    public function commandExists(string $cmd): bool
    {
        $bin = trim(explode(' ', $cmd)[0]);
        if ($bin === '') {
            return false;
        }
        $out = [];
        $code = -1;
        @exec('which ' . escapeshellarg($bin) . ' 2>/dev/null', $out, $code);
        return $code === 0 && !empty($out);
    }

    private function isDocker(): bool
    {
        if (file_exists('/.dockerenv')) {
            return true;
        }
        $cgroup = '/proc/1/cgroup';
        if (is_file($cgroup)) {
            $content = @file_get_contents($cgroup);
            return $content !== false && str_contains($content, 'docker');
        }
        return false;
    }

    private function getLinuxOsId(): string
    {
        $release = '/etc/os-release';
        if (!is_readable($release)) {
            return '';
        }
        $content = @file_get_contents($release);
        if ($content === false) {
            return '';
        }
        if (preg_match('/^\s*ID\s*=\s*["\']?([^"\'\n]+)["\']?\s*$/mi', $content, $m)) {
            return strtolower(trim($m[1], '"\''));
        }
        return '';
    }

    private function getLinuxOsIdLike(): string
    {
        $release = '/etc/os-release';
        if (!is_readable($release)) {
            return '';
        }
        $content = @file_get_contents($release);
        if ($content === false) {
            return '';
        }
        if (preg_match('/^\s*ID_LIKE\s*=\s*["\']?([^"\'\n]+)["\']?\s*$/mi', $content, $m)) {
            return strtolower(trim($m[1], '"\''));
        }
        return '';
    }

    /**
     * 扩展名 → 发行版包名映射（PHP 扩展名与 apt/dnf 包名不一致时使用）
     * 例如：pdo_pgsql 在 Debian 中包名为 pgsql（对应 apt 包 php-pgsql）
     */
    public function getDistroPackageName(string $ext): string
    {
        $map = [
            'pdo_pgsql' => 'pgsql',
        ];
        return $map[$ext] ?? $ext;
    }

    /**
     * 构建全部策略：每条含 cmd, name, check, platforms
     */
    private function buildAllStrategies(string $ext, string $phpVersion): array
    {
        $extQ = escapeshellarg($ext);
        $pkg = $this->getDistroPackageName($ext);
        $pkgQ = escapeshellarg($pkg);
        $list = [];

        // Docker（优先在容器内识别）
        $list[] = [
            'cmd'       => 'docker-php-ext-install ' . $extQ,
            'name'      => 'docker-php-ext-install',
            'check'     => 'docker-php-ext-install',
            'platforms' => [self::PLATFORM_DOCKER],
        ];

        // macOS (Homebrew)
        if ($ext === 'event') {
            $list[] = [
                'cmd'       => 'brew install libevent && pecl install event',
                'name'      => 'brew libevent + pecl',
                'check'     => 'brew',
                'platforms' => [self::PLATFORM_DARWIN],
            ];
        }
        $list[] = [
            'cmd'       => 'brew install php-' . $pkgQ,
            'name'      => 'brew (php-' . $pkg . ')',
            'check'     => 'brew',
            'platforms' => [self::PLATFORM_DARWIN],
        ];

        // phpenmod (Debian/Ubuntu 已安装未启用)，使用 PHP 扩展名
        $list[] = [
            'cmd'       => 'phpenmod ' . $extQ,
            'name'      => 'phpenmod',
            'check'     => 'phpenmod',
            'platforms' => [self::PLATFORM_LINUX_APT],
        ];

        // apt (Debian/Ubuntu)，使用发行版包名（如 pdo_pgsql → pgsql）
        foreach (['php' . $phpVersion . '-' . $pkg, 'php-' . $pkg] as $pkgName) {
            $list[] = [
                'cmd'       => 'apt-get install -y ' . escapeshellarg($pkgName),
                'name'      => 'apt (' . $pkgName . ')',
                'check'     => 'apt-get',
                'platforms' => [self::PLATFORM_LINUX_APT],
            ];
        }

        // dnf (Fedora/RHEL 新)
        $list[] = [
            'cmd'       => 'dnf install -y php-' . $pkgQ,
            'name'      => 'dnf',
            'check'     => 'dnf',
            'platforms' => [self::PLATFORM_LINUX_DNF],
        ];

        // yum (CentOS/RHEL 旧)
        $list[] = [
            'cmd'       => 'yum install -y php-' . $pkgQ,
            'name'      => 'yum',
            'check'     => 'yum',
            'platforms' => [self::PLATFORM_LINUX_YUM],
        ];

        // apk (Alpine)
        $list[] = [
            'cmd'       => 'apk add php' . $phpVersion . '-' . $pkg . ' 2>/dev/null || apk add php-' . $pkg,
            'name'      => 'apk',
            'check'     => 'apk',
            'platforms' => [self::PLATFORM_LINUX_APK],
        ];

        // pacman (Arch)
        $list[] = [
            'cmd'       => 'pacman -S --noconfirm php-' . $pkg,
            'name'      => 'pacman',
            'check'     => 'pacman',
            'platforms' => [self::PLATFORM_LINUX_PACMAN],
        ];

        // pecl（通用，所有 Unix 平台都可作为回退）
        $list[] = [
            'cmd'       => 'pecl install ' . $extQ,
            'name'      => 'pecl',
            'check'     => 'pecl',
            'platforms' => [
                self::PLATFORM_DARWIN,
                self::PLATFORM_LINUX_APT,
                self::PLATFORM_LINUX_DNF,
                self::PLATFORM_LINUX_YUM,
                self::PLATFORM_LINUX_APK,
                self::PLATFORM_LINUX_PACMAN,
                self::PLATFORM_LINUX_GENERIC,
            ],
        ];

        return $list;
    }
}
