<?php

declare(strict_types=1);

namespace LearningMcp;

use Closure;
use RuntimeException;
use Throwable;

/** 本机工具安装只执行一次；成功标志跨项目、跨宿主共享。 */
final class AociInstaller
{
    private const RELEASE = '0.1.0-rc17';
    private const RELEASE_URL = 'https://github.com/aoci-spec/aoci-code/releases/download/v0.1.0-rc17/';

    /** @param list<string>|null $searchDirectories */
    public function __construct(
        private readonly string $stateDirectory,
        private readonly ProcessRunner $runner,
        private readonly ?array $searchDirectories = null,
        private readonly ?Closure $download = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function ensure(string $repository): array
    {
        $markerPath = $this->stateDirectory . DIRECTORY_SEPARATOR . 'installed.json';
        $lock = null;
        $staging = null;
        try {
            $cached = $this->readMarker($markerPath);
            if ($cached !== null) {
                return $this->response($cached, $repository, true);
            }
            if (!is_dir($this->stateDirectory)
                && !mkdir($this->stateDirectory, 0700, true)
                && !is_dir($this->stateDirectory)) {
                throw new RuntimeException('无法创建 AOCI 本机安装目录');
            }
            $lock = fopen($this->stateDirectory . '/install.lock', 'c');
            if ($lock === false || !flock($lock, LOCK_EX)) {
                throw new RuntimeException('无法取得 AOCI 安装锁');
            }
            $cached = $this->readMarker($markerPath);
            if ($cached !== null) {
                return $this->response($cached, $repository, true);
            }

            $binaryName = PHP_OS_FAMILY === 'Windows' ? 'aoci.exe' : 'aoci';
            $directories = $this->searchDirectories ?? array_merge([
                $this->stateDirectory . '/bin',
                Config::expandPath('~/.local/bin'),
            ], explode(PATH_SEPARATOR, (string) getenv('PATH')));
            $binary = null;
            $version = null;
            foreach (array_unique($directories) as $directory) {
                if ($directory === '') {
                    continue;
                }
                $candidate = realpath($directory . DIRECTORY_SEPARATOR . $binaryName);
                if ($candidate === false || !is_file($candidate)
                    || (PHP_OS_FAMILY !== 'Windows' && !is_executable($candidate))) {
                    continue;
                }
                try {
                    $version = $this->identity($candidate, $repository);
                    $binary = $candidate;
                    break;
                } catch (Throwable) {
                    // 无效的同名程序不作为安装成功依据。
                }
            }
            $source = 'existing';
            if ($binary === null) {
                $source = 'download';
                $staging = $this->stateDirectory . '/download-' . bin2hex(random_bytes(8));
                if (!mkdir($staging, 0700)) {
                    throw new RuntimeException('无法创建 AOCI 下载目录');
                }
                $os = match (PHP_OS_FAMILY) {
                    'Darwin' => 'darwin',
                    'Linux' => 'linux',
                    'Windows' => 'windows',
                    default => throw new RuntimeException('AOCI 官方包不支持平台: ' . PHP_OS_FAMILY),
                };
                $arch = match (strtolower(php_uname('m'))) {
                    'arm64', 'aarch64' => 'arm64',
                    'x86_64', 'amd64' => 'amd64',
                    default => throw new RuntimeException('AOCI 官方包不支持架构: ' . php_uname('m')),
                };
                $asset = 'aoci_' . self::RELEASE . '_' . $os . '_' . $arch
                    . ($os === 'windows' ? '.zip' : '.tar.gz');
                $this->fetch(self::RELEASE_URL . 'SHA256SUMS', $staging . '/SHA256SUMS', $repository);
                $this->fetch(self::RELEASE_URL . $asset, $staging . '/' . $asset, $repository);
                $checksums = file_get_contents($staging . '/SHA256SUMS');
                if (!is_string($checksums)
                    || preg_match('/^([a-fA-F0-9]{64})\s+\*?' . preg_quote($asset, '/') . '\r?$/m', $checksums, $match) !== 1
                    || !hash_equals(strtolower($match[1]), (string) hash_file('sha256', $staging . '/' . $asset))) {
                    throw new RuntimeException('AOCI 官方包 SHA-256 校验失败');
                }
                $extracted = $this->runner->run(['tar', '-xf', $staging . '/' . $asset, '-C', $staging, $binaryName], $repository, '', 30);
                if ($extracted['exit_code'] !== 0 || !is_file($staging . '/' . $binaryName)
                    || is_link($staging . '/' . $binaryName)) {
                    throw new RuntimeException('AOCI 解压失败: ' . trim($extracted['stderr']));
                }
                $candidate = $staging . '/' . $binaryName;
                if (PHP_OS_FAMILY !== 'Windows' && !chmod($candidate, 0755)) {
                    throw new RuntimeException('无法设置 AOCI 执行权限');
                }
                $version = $this->identity($candidate, $repository);
                if ($version !== self::RELEASE) {
                    throw new RuntimeException('AOCI 下载包版本与指定 Release 不一致');
                }
                $binDirectory = $this->stateDirectory . '/bin';
                if (!is_dir($binDirectory) && !mkdir($binDirectory, 0700, true)) {
                    throw new RuntimeException('无法创建 AOCI 程序目录');
                }
                $binary = $binDirectory . '/' . $binaryName;
                if (file_exists($binary) || is_link($binary)) {
                    throw new RuntimeException('AOCI 安装目标已存在，保留现有文件: ' . $binary);
                }
                if (!rename($candidate, $binary)) {
                    throw new RuntimeException('无法保存 AOCI 程序');
                }
                $binary = realpath($binary) ?: $binary;
            }
            $marker = [
                'schema_version' => 'aoci-installation.v1',
                'status' => 'ready',
                'binary' => $binary,
                'version' => $version,
                'source' => $source,
                'verification' => $source === 'download' ? 'basic_checksum_and_identity' : 'existing_binary_identity',
                'installed_at' => Clock::now(),
                'marker_path' => $markerPath,
            ];
            $temporaryMarker = $this->stateDirectory . '/marker-' . bin2hex(random_bytes(8)) . '.tmp';
            try {
                $body = json_encode($marker, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
                if (file_put_contents($temporaryMarker, $body, LOCK_EX) !== strlen($body)
                    || !rename($temporaryMarker, $markerPath)) {
                    throw new RuntimeException('无法记录 AOCI 安装成功标志');
                }
            } finally {
                if (is_file($temporaryMarker)) {
                    @unlink($temporaryMarker);
                }
            }
            return $this->response($marker, $repository, false);
        } catch (Throwable $exception) {
            return [
                'schema_version' => 'aoci-installation.v1',
                'status' => 'failed',
                'cached' => false,
                'marker_path' => $markerPath,
                'error' => $exception->getMessage(),
                'next_action' => '修复上述安装原因后再次 prepare_project；尚未记录成功标志。',
            ];
        } finally {
            if ($staging !== null && is_dir($staging)) {
                // 此目录只含本次下载的资产，不清理其它会话或已有安装。
                foreach (scandir($staging) ?: [] as $entry) {
                    if ($entry !== '.' && $entry !== '..' && !is_dir($staging . '/' . $entry)) {
                        @unlink($staging . '/' . $entry);
                    }
                }
                @rmdir($staging);
            }
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** @return array<string,mixed>|null */
    private function readMarker(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $marker = json_decode((string) file_get_contents($path), true);
        if (!is_array($marker) || ($marker['schema_version'] ?? '') !== 'aoci-installation.v1'
            || ($marker['status'] ?? '') !== 'ready'
            || !is_string($marker['binary'] ?? null) || $marker['binary'] === ''
            || !is_string($marker['version'] ?? null) || $marker['version'] === '') {
            return null;
        }
        return $marker;
    }

    private function identity(string $binary, string $repository): string
    {
        $result = $this->runner->run([$binary, '--version'], $repository, '', 10);
        if ($result['exit_code'] !== 0
            || preg_match('/^aoci version (\S+)/m', $result['stdout'], $match) !== 1) {
            throw new RuntimeException('AOCI 程序身份验证失败: ' . $binary);
        }
        return $match[1];
    }

    private function fetch(string $url, string $path, string $repository): void
    {
        if ($this->download !== null) {
            ($this->download)($url, $path);
            return;
        }
        $result = $this->runner->run([
            'curl', '--fail', '--silent', '--show-error', '--location',
            '--proto', '=https', '--proto-redir', '=https',
            '--connect-timeout', '10', '--max-time', '45', '--output', $path, $url,
        ], $repository, '', 50);
        if ($result['exit_code'] !== 0) {
            throw new RuntimeException('AOCI 官方包下载失败（需要 curl 和 HTTPS 网络）: ' . trim($result['stderr']));
        }
    }

    /** @param array<string,mixed> $marker
     *  @return array<string,mixed>
     */
    private function response(array $marker, string $repository, bool $cached): array
    {
        $marker['cached'] = $cached;
        $marker['mcp_registration'] = [
            'command' => $marker['binary'],
            'args' => ['--repo', $repository, 'mcp'],
        ];
        $marker['next_action'] = '本机 AOCI 程序已就绪（缺失时由 Weline ensure/prepare 自动安装）。'
            . '若宿主会话尚无 aoci 工具，Agent 必须按 mcp_registration 写入宿主 MCP 配置并刷新会话；'
            . '项目 init/scan/索引建立不自动执行。';
        return $marker;
    }
}
