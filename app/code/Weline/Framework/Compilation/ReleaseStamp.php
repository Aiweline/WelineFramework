<?php

declare(strict_types=1);

namespace Weline\Framework\Compilation;

/**
 * 当前进程的发布戳。
 *
 * 编译期由 framework:compile 写入 generated/framework/release.php；请求期只读取，
 * 不重算摘要、不 stat 产物。缺失或格式不符时由引导层决定 fail-fast（非 DEV）。
 */
final class ReleaseStamp
{
    public const FORMAT = 1;

    /** @var array<string, mixed>|null */
    private static ?array $stamp = null;

    /**
     * @param array<string, mixed> $stamp
     */
    public static function set(array $stamp): void
    {
        self::$stamp = $stamp;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(): ?array
    {
        return self::$stamp;
    }

    public static function buildId(): ?string
    {
        $buildId = self::$stamp['build_id'] ?? null;

        return \is_string($buildId) && $buildId !== '' ? $buildId : null;
    }

    /**
     * 该运行期产物是否已被构建期摘要覆盖（请求期可据此跳过逐类/逐文件校验）。
     */
    public static function hasArtifact(string $fileName): bool
    {
        $runtime = self::$stamp['runtime_artifacts'] ?? null;
        if (!\is_array($runtime)) {
            return false;
        }

        $digest = $runtime[$fileName] ?? null;

        return \is_string($digest) && \preg_match('/^[a-f0-9]{64}$/D', $digest) === 1;
    }

    /**
     * 部署期校验：戳必须存在、结构合法、与本次 generation 的产物摘要一致，
     * 且运行期产物未被事后改写。只在部署/Worker 启动跑，禁止放进请求热路径。
     *
     * @param array<string, string> $manifestArtifacts
     */
    public static function generationMatches(string $frameworkDirectory, array $manifestArtifacts): bool
    {
        $frameworkDirectory = \rtrim($frameworkDirectory, '/\\');
        $releaseFile = $frameworkDirectory . DS . FrameworkCompileManifest::RELEASE_FILE_NAME;
        \clearstatcache(true, $releaseFile);
        if (\is_link($releaseFile) || !\is_file($releaseFile)) {
            return false;
        }
        $stamp = require $releaseFile;
        if (!self::isValid($stamp) || ($stamp['artifacts'] ?? []) !== $manifestArtifacts) {
            return false;
        }
        $runtimeDirectory = \dirname($frameworkDirectory);
        foreach ((array)($stamp['runtime_artifacts'] ?? []) as $fileName => $expectedDigest) {
            $path = $runtimeDirectory . DS . (string)$fileName;
            \clearstatcache(true, $path);
            if (!\is_string($expectedDigest)
                || \preg_match('/^[a-f0-9]{64}$/D', $expectedDigest) !== 1
                || !\is_file($path)
                || !\hash_equals($expectedDigest, (string)@\hash_file('sha256', $path))
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * 结构校验：只认 format=1、同 PHP 版本、64 位 build_id 与合法摘要表。
     *
     * @param mixed $stamp
     */
    public static function isValid($stamp): bool
    {
        if (!\is_array($stamp)) {
            return false;
        }
        if (($stamp['format'] ?? null) !== self::FORMAT) {
            return false;
        }
        if (($stamp['php_version_id'] ?? null) !== \PHP_VERSION_ID) {
            return false;
        }
        if (\preg_match('/^[a-f0-9]{64}$/D', (string)($stamp['build_id'] ?? '')) !== 1) {
            return false;
        }
        if (!\is_array($stamp['artifacts'] ?? null)) {
            return false;
        }
        foreach ($stamp['artifacts'] as $digest) {
            if (!\is_string($digest) || \preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
                return false;
            }
        }

        return true;
    }
}
