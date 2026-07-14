<?php

declare(strict_types=1);

namespace LearningMcp;

use RuntimeException;

final class Scheduler
{
    public function __construct(private readonly Config $config)
    {
    }

    public function label(): string
    {
        return 'com.weline.learning-mcp.' . substr(hash('sha256', $this->config->dataDir()), 0, 12);
    }

    public function plistPath(): string
    {
        $home = getenv('HOME');
        if (!is_string($home) || $home === '') {
            throw new RuntimeException('HOME is unavailable');
        }

        return $home . '/Library/LaunchAgents/' . $this->label() . '.plist';
    }

    public function renderPlist(): string
    {
        $worker = realpath(dirname(__DIR__) . '/bin/learningd');
        if ($worker === false) {
            throw new RuntimeException('learningd executable is missing');
        }
        $arguments = [PHP_BINARY, $worker, 'drain'];
        if ($this->config->sourcePath !== null) {
            array_push($arguments, '--config', $this->config->sourcePath);
        }
        array_push($arguments, '--data-dir', $this->config->dataDir(), '--max-jobs', '100');
        $argumentXml = implode("\n", array_map(
            static fn(string $argument): string => '        <string>' . self::xml($argument) . '</string>',
            $arguments,
        ));
        $interval = max(60, $this->config->duration('scheduler.launchd_interval'));
        $log = $this->config->dataDir() . '/learningd.log';

        return <<<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>{$this->label()}</string>
    <key>ProgramArguments</key>
    <array>
{$argumentXml}
    </array>
    <key>RunAtLoad</key>
    <true/>
    <key>StartInterval</key>
    <integer>{$interval}</integer>
    <key>ProcessType</key>
    <string>Background</string>
    <key>StandardOutPath</key>
    <string>{$this->xml($log)}</string>
    <key>StandardErrorPath</key>
    <string>{$this->xml($log)}</string>
</dict>
</plist>
PLIST;
    }

    /** @return array<string, mixed> */
    public function install(): array
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            throw new RuntimeException('The built-in scheduler installer currently supports macOS launchd only');
        }
        $dataDirectory = $this->config->dataDir();
        if (!is_dir($dataDirectory) && !mkdir($dataDirectory, 0700, true) && !is_dir($dataDirectory)) {
            throw new RuntimeException('Unable to create scheduler data directory');
        }
        chmod($dataDirectory, 0700);
        $path = $this->plistPath();
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create LaunchAgents directory');
        }
        $temporary = tempnam($directory, '.learning-mcp-');
        if ($temporary === false || file_put_contents($temporary, $this->renderPlist(), LOCK_EX) === false) {
            throw new RuntimeException('Unable to write launchd plist');
        }
        chmod($temporary, 0600);
        if (!rename($temporary, $path)) {
            @unlink($temporary); // nosemgrep: php.lang.security.unlink-use.unlink-use -- tempnam path in the fixed LaunchAgents directory
            throw new RuntimeException('Unable to install launchd plist');
        }
        $domain = $this->domain();
        self::command(['launchctl', 'bootout', $domain . '/' . $this->label()]);
        $bootstrap = self::command(['launchctl', 'bootstrap', $domain, $path]);
        if ($bootstrap['exit_code'] !== 0) {
            @unlink($path); // nosemgrep: php.lang.security.unlink-use.unlink-use -- hashed fixed-name plist under current HOME
            throw new RuntimeException('launchctl bootstrap failed: ' . Text::truncate($bootstrap['stderr'], 500));
        }
        $kick = self::command(['launchctl', 'kickstart', '-k', $domain . '/' . $this->label()]);

        return $this->status() + ['installed_now' => true, 'kickstart_exit_code' => $kick['exit_code']];
    }

    /** @return array<string, mixed> */
    public function uninstall(): array
    {
        $path = $this->plistPath();
        if (PHP_OS_FAMILY === 'Darwin') {
            self::command(['launchctl', 'bootout', $this->domain() . '/' . $this->label()]);
        }
        $removed = !is_file($path) || unlink($path); // nosemgrep: php.lang.security.unlink-use.unlink-use -- hashed fixed-name plist under current HOME
        if (!$removed) {
            throw new RuntimeException('Unable to remove launchd plist');
        }

        return $this->status() + ['uninstalled_now' => true];
    }

    /** @return array<string, mixed> */
    public function kickstart(): array
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            throw new RuntimeException('launchctl is only available on macOS');
        }
        $result = self::command(['launchctl', 'kickstart', '-k', $this->domain() . '/' . $this->label()]);
        if ($result['exit_code'] !== 0) {
            throw new RuntimeException('Periodic worker is not loaded; run scheduler install first');
        }

        return $this->status() + ['kicked' => true];
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $loaded = false;
        if (PHP_OS_FAMILY === 'Darwin') {
            $result = self::command(['launchctl', 'print', $this->domain() . '/' . $this->label()]);
            $loaded = $result['exit_code'] === 0;
        }

        return [
            'supported' => PHP_OS_FAMILY === 'Darwin',
            'label' => $this->label(),
            'plist_path' => $this->plistPath(),
            'installed' => is_file($this->plistPath()),
            'loaded' => $loaded,
            'interval_seconds' => max(60, $this->config->duration('scheduler.launchd_interval')),
            'idle_after_seconds' => $this->config->duration('scheduler.session_idle_after'),
        ];
    }

    private function domain(): string
    {
        $uid = function_exists('posix_getuid') ? posix_getuid() : getmyuid();
        return 'gui/' . $uid;
    }

    /** @param list<string> $command
     *  @return array{exit_code:int,stdout:string,stderr:string}
     */
    private static function command(array $command): array
    {
        if (($command[0] ?? '') !== 'launchctl'
            || !in_array($command[1] ?? '', ['bootout', 'bootstrap', 'kickstart', 'print'], true)) {
            throw new RuntimeException('Unsupported scheduler command');
        }
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        // launchctl and its subcommand are allowlisted; argv-array execution bypasses the shell.
        $process = proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]); // nosemgrep: php.lang.security.exec-use.exec-use
        if (!is_resource($process)) {
            return ['exit_code' => 127, 'stdout' => '', 'stderr' => 'unable to start command'];
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exit_code' => proc_close($process),
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    private static function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
