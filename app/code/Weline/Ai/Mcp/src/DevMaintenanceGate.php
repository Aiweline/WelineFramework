<?php

declare(strict_types=1);

namespace LearningMcp;

use RuntimeException;
use Throwable;

/**
 * Explicit maintenance flag so MCP source edits do not keep serving other agents.
 * Flag lives under the isolated project data directory (not in Git).
 */
final class DevMaintenanceGate
{
    public const SCHEMA = 'mcp-dev-maintenance.v1';
    public const FILE = 'mcp-dev-maintenance.json';
    public const DEFAULT_TTL = '2h';

    public function __construct(private readonly Config $config)
    {
    }

    public function path(): string
    {
        return $this->config->dataDir() . DIRECTORY_SEPARATOR . self::FILE;
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $payload = $this->read();
        if ($payload === null) {
            return [
                'schema_version' => self::SCHEMA,
                'enabled' => false,
                'active' => false,
                'path' => $this->path(),
            ];
        }
        $expired = $this->isExpired($payload);
        if ($expired && (bool) ($payload['enabled'] ?? false)) {
            $this->clearExpired($payload);
            $payload['enabled'] = false;
            $payload['expired'] = true;
        }

        return [
            'schema_version' => self::SCHEMA,
            'enabled' => (bool) ($payload['enabled'] ?? false),
            'active' => (bool) ($payload['enabled'] ?? false) && !$expired,
            'expired' => $expired,
            'reason' => (string) ($payload['reason'] ?? ''),
            'owner' => (string) ($payload['owner'] ?? ''),
            'started_at' => (string) ($payload['started_at'] ?? ''),
            'expires_at' => (string) ($payload['expires_at'] ?? ''),
            'path' => $this->path(),
        ];
    }

    public function isActive(): bool
    {
        return (bool) ($this->status()['active'] ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function enable(string $reason, string $owner = '', string $ttl = self::DEFAULT_TTL): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('--reason is required to enable MCP development maintenance');
        }
        $owner = trim($owner);
        if ($owner === '') {
            $owner = get_current_user() ?: 'mcp-dev';
        }
        $seconds = Config::durationSeconds($ttl === '' ? self::DEFAULT_TTL : $ttl);
        $startedAt = Clock::now();
        $expiresAt = gmdate('Y-m-d\TH:i:s.000\Z', time() + $seconds);
        $payload = [
            'schema_version' => self::SCHEMA,
            'enabled' => true,
            'reason' => Text::truncate($reason, 500),
            'owner' => Text::truncate($owner, 120),
            'started_at' => $startedAt,
            'expires_at' => $expiresAt,
            'ttl_seconds' => $seconds,
        ];
        $this->write($payload);
        $this->appendLog('enabled by ' . $owner . ': ' . $reason);

        return $this->status() + [
            'caller_instruction' => 'use_host_native_tools',
            'next_action' => 'Restart/bounce host learning-mcp so running processes load this gate. Blocked callers must use host-native tools until disable.',
        ];
    }

    /** @return array<string, mixed> */
    public function disable(string $owner = ''): array
    {
        $previous = $this->read();
        $path = $this->path();
        if (is_file($path) || is_link($path)) {
            @unlink($path);
        }
        $owner = trim($owner);
        $this->appendLog('disabled' . ($owner !== '' ? ' by ' . $owner : ''));

        return [
            'schema_version' => self::SCHEMA,
            'enabled' => false,
            'active' => false,
            'cleared' => true,
            'previous' => $previous,
            'path' => $path,
            'caller_instruction' => 'mcp_available_again',
            'next_action' => 'Callers may use prepare_project again after host MCP reload if the process was still serving the maintenance response.',
        ];
    }

    /**
     * @throws ToolException
     */
    public function assertCallable(string $toolName): void
    {
        if ($toolName === 'health' || !$this->isActive()) {
            return;
        }
        $status = $this->status();
        throw new ToolException(
            'MCP_DEV_MAINTENANCE',
            'Weline MCP 当前不可用（开发维护中）。请改用宿主原生工具继续：Read/Grep/Glob/Shell 读码与排查，'
            . 'Write/StrReplace/ApplyPatch 改文件；硬规则可宿主 Read app/code/Weline/Ai/doc/AI硬规则索引.md。'
            . '不要空等 MCP，也不要假装已遵守 prepare_project。'
            . '原因：' . (string) ($status['reason'] ?? 'unspecified')
            . '；维护方：' . (string) ($status['owner'] ?? '')
            . '；预计结束：' . (string) ($status['expires_at'] ?? ''),
            true,
            [
                'dev_maintenance' => $status,
                'mcp_available' => false,
                'caller_instruction' => 'use_host_native_tools',
                'next_action' => 'Use host-native tools now (Read/Grep/Write/Shell). Re-try MCP only after learningctl mcp-dev disable or TTL expiry; then prepare_project again if engineering continues.',
                'host_fallback' => [
                    'read_code' => ['Read', 'Grep', 'Glob'],
                    'edit_code' => ['Write', 'StrReplace', 'ApplyPatch'],
                    'hard_rules' => 'app/code/Weline/Ai/doc/AI硬规则索引.md',
                    'forbid' => 'Do not claim prepare_project/hard_constraints were obeyed while MCP is blocked.',
                ],
            ],
        );
    }

    /** @return array<string, mixed>|null */
    private function read(): ?array
    {
        $path = $this->path();
        if (!is_file($path) || is_link($path)) {
            return null;
        }
        $body = @file_get_contents($path);
        if (!is_string($body) || trim($body) === '') {
            return null;
        }
        try {
            $decoded = Json::decode($body, []);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed> $payload */
    private function write(array $payload): void
    {
        $directory = $this->config->dataDir();
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create MCP data directory for maintenance gate');
        }
        $path = $this->path();
        $encoded = Json::encode($payload);
        if (@file_put_contents($path, $encoded, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write MCP development maintenance flag');
        }
        @chmod($path, 0600);
    }

    /** @param array<string, mixed> $payload */
    private function isExpired(array $payload): bool
    {
        $expiresAt = trim((string) ($payload['expires_at'] ?? ''));
        if ($expiresAt === '') {
            return false;
        }
        $expires = strtotime($expiresAt);

        return $expires !== false && $expires <= time();
    }

    /** @param array<string, mixed> $payload */
    private function clearExpired(array $payload): void
    {
        $path = $this->path();
        if (is_file($path) || is_link($path)) {
            @unlink($path);
        }
        $this->appendLog('auto-cleared expired gate (was owner=' . (string) ($payload['owner'] ?? '') . ')');
    }

    private function appendLog(string $message): void
    {
        $line = sprintf("%s mcp-dev-maintenance: %s\n", Clock::now(), $message);
        @file_put_contents($this->config->dataDir() . '/learningd.log', $line, FILE_APPEND | LOCK_EX);
    }
}
