<?php
declare(strict_types=1);

namespace Weline\Server\Service;

/**
 * Manage local hosts entries for WLS development domains.
 */
class HostsFileManager
{
    private const MARKER_START = '# Weline WLS Auto-Config Start';
    private const MARKER_END = '# Weline WLS Auto-Config End';

    public static function getHostsFilePath(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return (string) getenv('SystemRoot') . '\System32\drivers\etc\hosts';
        }

        return '/etc/hosts';
    }

    public static function hasPermission(): bool
    {
        return is_writable(self::getHostsFilePath());
    }

    /**
     * @return array{success: bool, message: string, needs_admin?: bool, command?: string, already_exists?: bool, elevated?: bool}
     */
    public static function addDomain(string $domain, string $ip = '127.0.0.1'): array
    {
        $hostsFile = self::getHostsFilePath();
        if (!file_exists($hostsFile)) {
            return [
                'success' => false,
                'message' => "Hosts file not found: {$hostsFile}",
                'needs_admin' => false,
            ];
        }

        $content = file_get_contents($hostsFile);
        if ($content === false) {
            return [
                'success' => false,
                'message' => "Unable to read hosts file: {$hostsFile}",
                'needs_admin' => false,
            ];
        }

        if (self::domainExists($content, $domain)) {
            return [
                'success' => true,
                'message' => "Domain {$domain} already exists in hosts file",
                'needs_admin' => false,
                'already_exists' => true,
            ];
        }

        $newContent = self::addDomainToContent($content, $domain, $ip);

        if (!self::hasPermission()) {
            $elevated = self::tryAddDomainWithElevation($hostsFile, $newContent, $domain, $ip);
            if ($elevated !== null) {
                return $elevated;
            }

            return [
                'success' => false,
                'message' => 'Administrator privileges are required to modify the hosts file',
                'needs_admin' => true,
                'command' => self::getAdminCommand($domain, $ip),
            ];
        }

        if (file_put_contents($hostsFile, $newContent) === false) {
            return [
                'success' => false,
                'message' => "Unable to write hosts file: {$hostsFile}",
                'needs_admin' => true,
            ];
        }

        return [
            'success' => true,
            'message' => "Added {$domain} to hosts file",
            'needs_admin' => false,
        ];
    }

    /**
     * @return array{success: bool, message: string, needs_admin?: bool, already_removed?: bool}
     */
    public static function removeDomain(string $domain): array
    {
        $hostsFile = self::getHostsFilePath();
        if (!self::hasPermission()) {
            return [
                'success' => false,
                'message' => 'Administrator privileges are required to modify the hosts file',
                'needs_admin' => true,
            ];
        }

        $content = file_get_contents($hostsFile);
        if ($content === false) {
            return [
                'success' => false,
                'message' => "Unable to read hosts file: {$hostsFile}",
            ];
        }

        $lines = explode("\n", $content);
        $newLines = [];
        $removed = false;
        foreach ($lines as $line) {
            if (preg_match('/\s+' . preg_quote($domain, '/') . '(\s|$)/', $line)) {
                $removed = true;
                continue;
            }
            $newLines[] = $line;
        }

        if (!$removed) {
            return [
                'success' => true,
                'message' => "Domain {$domain} was not present in hosts file",
                'already_removed' => true,
            ];
        }

        if (file_put_contents($hostsFile, implode("\n", $newLines)) === false) {
            return [
                'success' => false,
                'message' => "Unable to write hosts file: {$hostsFile}",
            ];
        }

        return [
            'success' => true,
            'message' => "Removed {$domain} from hosts file",
        ];
    }

    private static function tryAddDomainWithElevation(string $hostsFile, string $newContent, string $domain, string $ip): ?array
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return self::tryAddDomainWithSudo($hostsFile, $newContent, $domain, $ip);
        }

        $payloadPath = tempnam(sys_get_temp_dir(), 'wls-hosts-');
        $scriptBase = tempnam(sys_get_temp_dir(), 'wls-hosts-');
        if ($payloadPath === false || $scriptBase === false) {
            return null;
        }

        $scriptPath = $scriptBase . '.ps1';
        @rename($scriptBase, $scriptPath);

        if (file_put_contents($payloadPath, $newContent) === false) {
            @unlink($payloadPath);
            @unlink($scriptPath);
            return null;
        }

        $hostsLiteral = str_replace("'", "''", $hostsFile);
        $payloadLiteral = str_replace("'", "''", $payloadPath);
        $scriptBody = <<<PS1
\$hostsFile = '{$hostsLiteral}'
\$payloadFile = '{$payloadLiteral}'
\$content = Get-Content -LiteralPath \$payloadFile -Raw
Set-Content -LiteralPath \$hostsFile -Value \$content -Encoding ASCII
PS1;
        if (file_put_contents($scriptPath, $scriptBody) === false) {
            @unlink($payloadPath);
            @unlink($scriptPath);
            return null;
        }

        $scriptLiteral = str_replace("'", "''", $scriptPath);
        $command = "powershell -NoProfile -ExecutionPolicy Bypass -Command \"Start-Process -FilePath PowerShell.exe -Verb RunAs -Wait -WindowStyle Hidden -ArgumentList '-NoProfile','-ExecutionPolicy','Bypass','-File','{$scriptLiteral}'\" 2>&1";
        @shell_exec($command);

        @unlink($scriptPath);
        @unlink($payloadPath);

        $content = file_get_contents($hostsFile);
        if ($content !== false && self::domainExists($content, $domain)) {
            return [
                'success' => true,
                'message' => "Added {$domain} to hosts file",
                'needs_admin' => false,
                'elevated' => true,
            ];
        }

        return [
            'success' => false,
            'message' => 'Administrator privileges are required to modify the hosts file',
            'needs_admin' => true,
            'command' => self::getAdminCommand($domain, $ip),
        ];
    }

    private static function tryAddDomainWithSudo(string $hostsFile, string $newContent, string $domain, string $ip): ?array
    {
        if (!\function_exists('exec')) {
            return null;
        }

        $sudoPath = self::findUnixCommand('sudo');
        if ($sudoPath === '') {
            return null;
        }

        $payloadPath = \tempnam(\sys_get_temp_dir(), 'wls-hosts-');
        if ($payloadPath === false) {
            return null;
        }

        if (\file_put_contents($payloadPath, $newContent) === false) {
            @\unlink($payloadPath);
            return null;
        }
        @\chmod($payloadPath, 0600);

        $script = 'cat ' . \escapeshellarg($payloadPath) . ' > ' . \escapeshellarg($hostsFile);
        $sudoArgs = self::canUseInteractiveSudo()
            ? ' -p ' . \escapeshellarg('[WLS] sudo password for hosts: ')
            : ' -n';
        $command = \escapeshellcmd($sudoPath) . $sudoArgs . ' /bin/sh -c ' . \escapeshellarg($script) . ' 2>&1';
        $exitCode = 1;
        if (self::canUseInteractiveSudo() && \function_exists('passthru')) {
            @\passthru($command, $exitCode);
        } else {
            $output = [];
            @\exec($command, $output, $exitCode);
        }
        @\unlink($payloadPath);

        if ($exitCode === 0) {
            $content = \file_get_contents($hostsFile);
            if ($content !== false && self::domainExists($content, $domain)) {
                return [
                    'success' => true,
                    'message' => "Added {$domain} to hosts file",
                    'needs_admin' => false,
                    'elevated' => true,
                ];
            }
        }

        return null;
    }

    private static function canUseInteractiveSudo(): bool
    {
        if (PHP_SAPI !== 'cli' || !\defined('STDIN')) {
            return false;
        }
        if (\function_exists('posix_isatty')) {
            return (bool) @\posix_isatty(STDIN);
        }
        if (\function_exists('stream_isatty')) {
            return (bool) @\stream_isatty(STDIN);
        }

        return true;
    }

    private static function findUnixCommand(string $command): string
    {
        if (!\function_exists('exec')) {
            return '';
        }

        $output = [];
        $exitCode = 1;
        @\exec('command -v ' . \escapeshellarg($command) . ' 2>/dev/null', $output, $exitCode);
        if ($exitCode !== 0 || empty($output[0])) {
            return '';
        }

        return \trim((string)$output[0]);
    }

    private static function domainExists(string $content, string $domain): bool
    {
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/\s+' . preg_quote($domain, '/') . '(\s|$)/', $line)) {
                return true;
            }
        }

        return false;
    }

    private static function addDomainToContent(string $content, string $domain, string $ip): string
    {
        $pattern = '/' . preg_quote(self::MARKER_START, '/') . '(.*?)' . preg_quote(self::MARKER_END, '/') . '/s';
        if (preg_match($pattern, $content, $matches)) {
            $block = rtrim((string) $matches[1], "\r\n");
            $newBlock = $block === '' ? "{$ip} {$domain}" : $block . "\n{$ip} {$domain}";
            return (string) str_replace(
                $matches[0],
                self::MARKER_START . "\n" . $newBlock . "\n" . self::MARKER_END,
                $content
            );
        }

        $suffix = str_ends_with($content, "\n") ? '' : "\n";

        return $content
            . $suffix
            . self::MARKER_START . "\n"
            . "{$ip} {$domain}\n"
            . self::MARKER_END . "\n";
    }

    private static function getAdminCommand(string $domain, string $ip): string
    {
        return self::getAdminCommandForOs($domain, $ip, PHP_OS_FAMILY);
    }

    private static function getAdminCommandForOs(string $domain, string $ip, string $osFamily): string
    {
        $hostsFile = self::getHostsFilePath();
        if ($osFamily === 'Windows') {
            return "Add-Content -Path '{$hostsFile}' -Value '{$ip} {$domain}'";
        }

        $phpBinary = \defined('PHP_BINARY') ? PHP_BINARY : 'php';
        if (\defined('BP') && \is_file(BP . 'bin' . DIRECTORY_SEPARATOR . 'w')) {
            return 'sudo ' . \escapeshellarg($phpBinary) . ' ' . \escapeshellarg(BP . 'bin' . DIRECTORY_SEPARATOR . 'w')
                . ' server:hosts:add ' . \escapeshellarg($domain) . ' --ip=' . \escapeshellarg($ip);
        }

        return 'sudo /bin/sh -c ' . \escapeshellarg(
            'printf ' . \escapeshellarg("\n{$ip} {$domain}\n") . ' >> ' . \escapeshellarg($hostsFile)
        );
    }
}
