<?php
declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\App\Env;

/**
 * macOS --win 观察窗口登记：实例级持久化 window_id / shell_pid，供停服与重启回收。
 */
final class DarwinWinModeWindowRegistry
{
    public const SCHEMA_VERSION = 1;

    /**
     * @return array{
     *   schema:int,
     *   instance:string,
     *   windows:array<string, array{
     *     process_name:string,
     *     title:string,
     *     log_file:string,
     *     window_id:int,
     *     shell_pid:int,
     *     opened_at:string
     *   }>
     * }
     */
    public function load(string $instanceName): array
    {
        $instanceName = \trim($instanceName);
        if ($instanceName === '') {
            return $this->emptyRecord('');
        }

        $path = $this->pathFor($instanceName);
        if (!\is_file($path)) {
            return $this->emptyRecord($instanceName);
        }

        $raw = @\file_get_contents($path);
        if (!\is_string($raw) || $raw === '') {
            return $this->emptyRecord($instanceName);
        }

        $decoded = \json_decode($raw, true);
        if (!\is_array($decoded)) {
            return $this->emptyRecord($instanceName);
        }

        $windows = [];
        $rawWindows = \is_array($decoded['windows'] ?? null) ? $decoded['windows'] : [];
        foreach ($rawWindows as $key => $entry) {
            if (!\is_string($key) || $key === '' || !\is_array($entry)) {
                continue;
            }
            $processName = \trim((string)($entry['process_name'] ?? $key));
            if ($processName === '') {
                continue;
            }
            $windows[$processName] = [
                'process_name' => $processName,
                'title' => \trim((string)($entry['title'] ?? $processName)),
                'log_file' => \trim((string)($entry['log_file'] ?? '')),
                'window_id' => (int)($entry['window_id'] ?? 0),
                'shell_pid' => (int)($entry['shell_pid'] ?? 0),
                'opened_at' => \trim((string)($entry['opened_at'] ?? '')),
            ];
        }

        return [
            'schema' => self::SCHEMA_VERSION,
            'instance' => $instanceName,
            'windows' => $windows,
        ];
    }

    /**
     * @param array{
     *   process_name:string,
     *   title:string,
     *   log_file:string,
     *   window_id:int,
     *   shell_pid:int,
     *   opened_at?:string
     * } $entry
     */
    public function remember(string $instanceName, array $entry): void
    {
        $instanceName = \trim($instanceName);
        $processName = \trim((string)($entry['process_name'] ?? ''));
        if ($instanceName === '' || $processName === '') {
            return;
        }

        $record = $this->load($instanceName);
        $record['windows'][$processName] = [
            'process_name' => $processName,
            'title' => \trim((string)($entry['title'] ?? $processName)),
            'log_file' => \trim((string)($entry['log_file'] ?? '')),
            'window_id' => (int)($entry['window_id'] ?? 0),
            'shell_pid' => (int)($entry['shell_pid'] ?? 0),
            'opened_at' => \trim((string)($entry['opened_at'] ?? '')) !== ''
                ? \trim((string)$entry['opened_at'])
                : \date('c'),
        ];
        $this->save($record);
    }

    public function forget(string $instanceName, string $processName): void
    {
        $instanceName = \trim($instanceName);
        $processName = \trim($processName);
        if ($instanceName === '' || $processName === '') {
            return;
        }

        $record = $this->load($instanceName);
        if (!isset($record['windows'][$processName])) {
            return;
        }
        unset($record['windows'][$processName]);
        $this->save($record);
    }

    public function clear(string $instanceName): void
    {
        $instanceName = \trim($instanceName);
        if ($instanceName === '') {
            return;
        }
        $path = $this->pathFor($instanceName);
        if (\is_file($path)) {
            @\unlink($path);
        }
    }

    /**
     * @param array{
     *   schema:int,
     *   instance:string,
     *   windows:array<string, array<string, mixed>>
     * } $record
     */
    public function save(array $record): void
    {
        $instanceName = \trim((string)($record['instance'] ?? ''));
        if ($instanceName === '') {
            return;
        }

        $dir = $this->directory();
        if (!\is_dir($dir) && !@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            return;
        }

        $payload = [
            'schema' => self::SCHEMA_VERSION,
            'instance' => $instanceName,
            'windows' => \is_array($record['windows'] ?? null) ? $record['windows'] : [],
        ];
        $json = \json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT);
        if (!\is_string($json)) {
            return;
        }

        $path = $this->pathFor($instanceName);
        $tmp = $path . '.tmp.' . \getmypid();
        if (@\file_put_contents($tmp, $json . "\n") === false) {
            return;
        }
        @\rename($tmp, $path);
    }

    public function pathFor(string $instanceName): string
    {
        $safe = \preg_replace('/[^a-zA-Z0-9._-]+/', '_', \trim($instanceName)) ?: 'instance';

        return $this->directory() . $safe . '.json';
    }

    public function directory(): string
    {
        return Env::VAR_DIR . 'server' . \DIRECTORY_SEPARATOR . 'darwin-win-windows' . \DIRECTORY_SEPARATOR;
    }

    /**
     * @return array{schema:int,instance:string,windows:array<string, array<string, mixed>>}
     */
    private function emptyRecord(string $instanceName): array
    {
        return [
            'schema' => self::SCHEMA_VERSION,
            'instance' => $instanceName,
            'windows' => [],
        ];
    }
}
