<?php
declare(strict_types=1);

namespace Weline\PhpManager\Service\Adapter;

class WindowsBundledPhpExtensionAdapter
{
    public const CONFIRM_PHRASE = 'RUN_PHP_EXTENSION_ACTION';

    private const MARKER_START = '; WLS PHP Manager managed extensions begin';
    private const MARKER_END = '; WLS PHP Manager managed extensions end';
    private const EXTENSION_ALLOWLIST = [
        'bcmath' => true,
        'bz2' => true,
        'curl' => true,
        'event' => true,
        'exif' => true,
        'fileinfo' => true,
        'gd' => true,
        'gettext' => true,
        'gmp' => true,
        'intl' => true,
        'ldap' => true,
        'mbstring' => true,
        'mysqli' => true,
        'odbc' => true,
        'openssl' => true,
        'pdo_mysql' => true,
        'pdo_odbc' => true,
        'pdo_pgsql' => true,
        'pdo_sqlite' => true,
        'pgsql' => true,
        'shmop' => true,
        'snmp' => true,
        'soap' => true,
        'sockets' => true,
        'sqlite3' => true,
        'tidy' => true,
        'xsl' => true,
        'zip' => true,
    ];

    /**
     * @param array<string, mixed> $runtime
     * @param array<string, mixed> $projectProfile
     * @param array<int, string> $errors
     * @return array<string, mixed>
     */
    public function buildPlan(
        string $action,
        string $extension,
        array $runtime,
        array $projectProfile,
        bool $isLoaded,
        array $errors
    ): array {
        $base = $this->basePlan($runtime);
        if ($action === '' || $extension === '') {
            return $base;
        }

        $extensionKey = \strtolower($extension);
        $reasons = [];
        if ((string)($runtime['os'] ?? \PHP_OS_FAMILY) !== 'Windows') {
            $reasons[] = (string)__('This adapter only supports the bundled Windows PHP runtime.');
        }
        if (!isset(self::EXTENSION_ALLOWLIST[$extensionKey])) {
            $reasons[] = (string)__('This extension is not in the WLS PHP Manager allowlist.');
        }

        $binary = $this->resolvePhpBinary((string)($projectProfile['php_binary'] ?? ''), (string)($runtime['binary'] ?? ''));
        if (!$binary['success']) {
            $reasons[] = (string)$binary['message'];
        }
        $ini = $this->resolveIniPath((string)($projectProfile['php_ini_path'] ?? ''), (string)($runtime['ini_file'] ?? ''));
        if (!$ini['success']) {
            $reasons[] = (string)$ini['message'];
        }

        $extensionFile = '';
        $extensionLine = '';
        if (!empty($binary['path'])) {
            $extensionFile = \dirname((string)$binary['path']) . \DIRECTORY_SEPARATOR . 'ext' . \DIRECTORY_SEPARATOR . 'php_' . $extensionKey . '.dll';
            $extensionLine = 'extension=php_' . $extensionKey . '.dll';
            if (!\is_file($extensionFile) || !\is_readable($extensionFile)) {
                $reasons[] = (string)__('The bundled extension DLL was not found for the selected extension.');
            }
        }

        $managedExtensions = [];
        $existingExtension = false;
        $content = '';
        if (!empty($ini['path']) && \is_file((string)$ini['path'])) {
            $read = \file_get_contents((string)$ini['path']);
            $content = \is_string($read) ? $read : '';
            $managedExtensions = $this->extractManagedExtensions($content);
            $existingExtension = $this->extensionExistsAnywhere($content, $extensionKey);
        }

        $isManaged = isset($managedExtensions[$extensionKey]);
        if ($action === 'install' && ($isManaged || $existingExtension || $isLoaded)) {
            $reasons[] = (string)__('The extension already appears to be loaded or configured, so WLS will not add a duplicate extension line.');
        }
        if ($action === 'remove' && !$isManaged) {
            $reasons[] = (string)__('Only extension lines created inside the WLS-managed block can be removed by this adapter.');
        }

        $canExecute = $errors === [] && $reasons === [];
        $state = $canExecute ? 'ready' : 'adapter_blocked';
        $base['adapter_state'] = $state;
        $base['adapter_state_label'] = $canExecute ? (string)__('Ready') : (string)__('Adapter Blocked');
        $base['can_execute'] = $canExecute;
        $base['execution_label'] = $canExecute ? (string)__('Guarded Adapter Ready') : (string)__('Guarded Adapter Blocked');
        $base['adapter_reasons'] = $reasons;
        $base['target_ini_path'] = (string)($ini['path'] ?? '');
        $base['php_binary'] = (string)($binary['path'] ?? '');
        $base['extension_file'] = $extensionFile;
        $base['extension_line'] = $extensionLine;
        $base['managed_block_state'] = $isManaged ? (string)__('Managed by WLS') : (string)__('Not Managed by WLS');
        $base['command_plan'] = $this->commandPlan($action, $extensionLine, (string)($ini['path'] ?? ''));
        $base['post_verification'] = $this->postVerification($action, $extension);
        $base['rollback_guidance'] = $this->rollbackGuidance($action, $extension);

        return $base;
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    public function applyPlan(array $plan): array
    {
        if (empty($plan['can_execute'])) {
            throw new \InvalidArgumentException((string)($plan['execution_label'] ?? __('The guarded extension adapter is not ready.')));
        }

        $action = (string)($plan['action'] ?? '');
        $extension = \strtolower((string)($plan['extension'] ?? ''));
        $targetPath = (string)($plan['target_ini_path'] ?? '');
        $extensionLine = (string)($plan['extension_line'] ?? '');
        if (!\in_array($action, ['install', 'remove'], true) || $extension === '' || $targetPath === '' || $extensionLine === '') {
            throw new \InvalidArgumentException((string)__('The extension execution plan is incomplete.'));
        }

        $target = $this->resolveIniPath($targetPath, '');
        if (!$target['success']) {
            throw new \RuntimeException((string)$target['message']);
        }
        $targetPath = (string)$target['path'];
        $original = \file_get_contents($targetPath);
        if (!\is_string($original)) {
            throw new \RuntimeException((string)__('Unable to read the target php.ini file.'));
        }

        $managed = $this->extractManagedExtensions($original);
        if ($action === 'install') {
            $managed[$extension] = $extensionLine;
        } else {
            unset($managed[$extension]);
        }

        $next = $this->upsertManagedBlock($original, $managed);
        if ($next === $original) {
            return [
                'success' => true,
                'message' => (string)__('No php.ini extension changes were needed.'),
                'backup_path' => '',
                'change_count' => 0,
                'verification_state' => 'noop',
            ];
        }

        $backup = $this->createBackup($targetPath, $original, $action, $extension);
        if (\file_put_contents($targetPath, $next, \LOCK_EX) === false) {
            throw new \RuntimeException((string)__('Unable to write the target php.ini file.'));
        }

        $verified = $this->verifyManagedState($next, $action, $extension);
        if (!$verified) {
            throw new \RuntimeException((string)__('The php.ini extension state could not be verified after writing.'));
        }

        return [
            'success' => true,
            'message' => $action === 'install'
                ? (string)__('Extension line enabled in the WLS-managed php.ini block.')
                : (string)__('Extension line removed from the WLS-managed php.ini block.'),
            'backup_path' => $backup['path'],
            'change_count' => 1,
            'verification_state' => 'verified',
        ];
    }

    /**
     * @param array<string, mixed> $runtime
     * @return array<string, mixed>
     */
    private function basePlan(array $runtime): array
    {
        return [
            'adapter_key' => 'windows_bundled_php_ini',
            'adapter_label' => (string)__('Windows bundled PHP ini adapter'),
            'adapter_state' => 'idle',
            'adapter_state_label' => (string)__('Waiting for Input'),
            'adapter_reasons' => [],
            'can_execute' => false,
            'execution_label' => (string)__('Guarded Adapter Waiting'),
            'confirmation_phrase' => self::CONFIRM_PHRASE,
            'target_ini_path' => '',
            'php_binary' => (string)($runtime['binary'] ?? \PHP_BINARY),
            'extension_file' => '',
            'extension_line' => '',
            'managed_block_state' => (string)__('Not Evaluated'),
            'command_plan' => [],
            'post_verification' => [],
            'rollback_guidance' => [],
        ];
    }

    /**
     * @return array{success:bool,path:string,message:string}
     */
    private function resolvePhpBinary(string $profileBinary, string $runtimeBinary): array
    {
        $path = \trim($profileBinary) !== '' ? \trim($profileBinary) : \trim($runtimeBinary);
        $real = $path !== '' ? \realpath($path) : false;
        if ($real === false || !\is_file($real)) {
            return ['success' => false, 'path' => '', 'message' => (string)__('The PHP binary does not exist.')];
        }
        if (\strtolower(\basename($real)) !== 'php.exe') {
            return ['success' => false, 'path' => $real, 'message' => (string)__('The Windows adapter only supports php.exe.')];
        }
        $root = \realpath($this->bpPath('extend' . \DIRECTORY_SEPARATOR . 'server' . \DIRECTORY_SEPARATOR . 'php'));
        if (!\is_string($root) || !$this->pathWithin($real, $root)) {
            return ['success' => false, 'path' => $real, 'message' => (string)__('The Windows adapter only supports the bundled extend/server/php runtime.')];
        }

        return ['success' => true, 'path' => $real, 'message' => ''];
    }

    /**
     * @return array{success:bool,path:string,message:string}
     */
    private function resolveIniPath(string $profileIni, string $runtimeIni): array
    {
        $path = \trim($profileIni) !== '' ? \trim($profileIni) : \trim($runtimeIni);
        $real = $path !== '' ? \realpath($path) : false;
        if ($real === false || !\is_file($real) || !\is_readable($real) || !\is_writable($real)) {
            return ['success' => false, 'path' => '', 'message' => (string)__('The target php.ini file must exist and be readable/writable.')];
        }
        if (!\preg_match('/\.ini$/i', $real)) {
            return ['success' => false, 'path' => $real, 'message' => (string)__('Only .ini files can be managed by PHP Manager.')];
        }

        $roots = [
            \realpath($this->bpPath('extend' . \DIRECTORY_SEPARATOR . 'server' . \DIRECTORY_SEPARATOR . 'php')),
            \realpath($this->bpPath('var' . \DIRECTORY_SEPARATOR . 'wls' . \DIRECTORY_SEPARATOR . 'php-manager')),
            \realpath($this->bpPath('var' . \DIRECTORY_SEPARATOR . 'tmp' . \DIRECTORY_SEPARATOR . 'wls-php-manager')),
        ];
        foreach ($roots as $root) {
            if (\is_string($root) && $this->pathWithin($real, $root)) {
                return ['success' => true, 'path' => $real, 'message' => ''];
            }
        }

        return ['success' => false, 'path' => $real, 'message' => (string)__('The target php.ini path is outside the allowed PHP Manager roots.')];
    }

    /**
     * @return array<string, string>
     */
    private function extractManagedExtensions(string $content): array
    {
        $extensions = [];
        $inside = false;
        foreach (\preg_split('/\R/', $content) ?: [] as $line) {
            $line = (string)$line;
            if (\trim($line) === self::MARKER_START) {
                $inside = true;
                continue;
            }
            if (\trim($line) === self::MARKER_END) {
                $inside = false;
                continue;
            }
            if (!$inside) {
                continue;
            }
            $extension = $this->extensionNameFromLine($line);
            if ($extension !== '') {
                $extensions[$extension] = \trim($line);
            }
        }

        return $extensions;
    }

    private function extensionExistsAnywhere(string $content, string $needle): bool
    {
        foreach (\preg_split('/\R/', $content) ?: [] as $line) {
            if ($this->extensionNameFromLine((string)$line) === $needle) {
                return true;
            }
        }

        return false;
    }

    private function extensionNameFromLine(string $line): string
    {
        $line = \trim($line);
        if ($line === '' || \str_starts_with($line, ';') || \str_starts_with($line, '#')) {
            return '';
        }
        if (\preg_match('/^extension\s*=\s*(.+)$/i', $line, $matches) !== 1) {
            return '';
        }

        $value = \trim((string)$matches[1], " \t\"'");
        $value = \strtolower(\basename(\str_replace('\\', '/', $value)));
        if (\str_starts_with($value, 'php_')) {
            $value = \substr($value, 4);
        }
        if (\str_ends_with($value, '.dll')) {
            $value = \substr($value, 0, -4);
        }

        return \preg_match('/^[a-z0-9_.-]{1,80}$/', $value) === 1 ? $value : '';
    }

    /**
     * @param array<string, string> $managed
     */
    private function upsertManagedBlock(string $content, array $managed): string
    {
        \ksort($managed);
        $block = '';
        if ($managed !== []) {
            $block = self::MARKER_START . \PHP_EOL;
            foreach ($managed as $line) {
                $block .= \trim($line) . \PHP_EOL;
            }
            $block .= self::MARKER_END . \PHP_EOL;
        }

        $pattern = '/' . \preg_quote(self::MARKER_START, '/') . '.*?' . \preg_quote(self::MARKER_END, '/') . '\R?/s';
        if (\preg_match($pattern, $content) === 1) {
            return (string)\preg_replace($pattern, $block, $content, 1);
        }
        if ($block === '') {
            return $content;
        }

        return \rtrim($content) . \PHP_EOL . \PHP_EOL . $block;
    }

    private function verifyManagedState(string $content, string $action, string $extension): bool
    {
        $managed = $this->extractManagedExtensions($content);
        return $action === 'install' ? isset($managed[$extension]) : !isset($managed[$extension]);
    }

    /**
     * @return array<int, string>
     */
    private function commandPlan(string $action, string $extensionLine, string $iniPath): array
    {
        if ($action === '') {
            return [];
        }

        return $action === 'install'
            ? [
                (string)__('Read target php.ini: %{1}', $iniPath !== '' ? $iniPath : '-'),
                (string)__('Create a PHP Manager backup before writing.'),
                (string)__('Add allowlisted line inside the WLS-managed extension block: %{1}', $extensionLine !== '' ? $extensionLine : '-'),
            ]
            : [
                (string)__('Read target php.ini: %{1}', $iniPath !== '' ? $iniPath : '-'),
                (string)__('Create a PHP Manager backup before writing.'),
                (string)__('Remove only the matching line from the WLS-managed extension block: %{1}', $extensionLine !== '' ? $extensionLine : '-'),
            ];
    }

    /**
     * @return array<int, string>
     */
    private function postVerification(string $action, string $extension): array
    {
        if ($action === '') {
            return [];
        }
        $expected = $action === 'install' ? 'true' : 'false';

        return [
            (string)__('Verify the WLS-managed extension block after writing.'),
            (string)__('After reload, the target runtime should report extension_loaded("%{1}") as %{2}.', $extension, $expected),
            (string)__('Record backup path, change count, runtime reload state, and verification state in PHP Manager audit.'),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function rollbackGuidance(string $action, string $extension): array
    {
        if ($action === '') {
            return [];
        }

        return $action === 'install'
            ? [
                (string)__('Use Remove Extension for %{1} to disable the WLS-managed line again.', $extension),
                (string)__('Or restore the latest PHP Manager backup from the php.ini section.'),
            ]
            : [
                (string)__('Use Install Extension for %{1} to enable the WLS-managed line again.', $extension),
                (string)__('Or restore the latest PHP Manager backup from the php.ini section.'),
            ];
    }

    /**
     * @return array{path:string,meta_path:string}
     */
    private function createBackup(string $targetPath, string $content, string $action, string $extension): array
    {
        $dir = $this->bpPath('var' . \DIRECTORY_SEPARATOR . 'backups' . \DIRECTORY_SEPARATOR . 'wls' . \DIRECTORY_SEPARATOR . 'php-manager');
        if (!\is_dir($dir) && !\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            throw new \RuntimeException((string)__('Unable to create the PHP Manager backup directory.'));
        }

        $stamp = \date('Ymd-His');
        $suffix = \substr(\hash('sha256', $targetPath . $action . $extension . \microtime(true)), 0, 10);
        $safeExtension = \preg_replace('/[^a-zA-Z0-9_.-]+/', '-', $extension) ?: 'extension';
        $backupPath = $dir . \DIRECTORY_SEPARATOR . $safeExtension . '-' . $action . '-' . $stamp . '-' . $suffix . '.php.ini.bak';
        if (\file_put_contents($backupPath, $content, \LOCK_EX) === false) {
            throw new \RuntimeException((string)__('Unable to write the php.ini backup file.'));
        }

        $meta = [
            'time' => \date('c'),
            'operation' => 'extension_' . $action,
            'extension' => $extension,
            'target_path' => $targetPath,
            'target_hash' => \hash('sha256', $targetPath),
            'backup_path' => $backupPath,
        ];
        $metaPath = $backupPath . '.json';
        if (\file_put_contents($metaPath, \json_encode($meta, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), \LOCK_EX) === false) {
            throw new \RuntimeException((string)__('Unable to write the php.ini backup metadata.'));
        }

        return ['path' => $backupPath, 'meta_path' => $metaPath];
    }

    private function bpPath(string $relative): string
    {
        return \rtrim((string)BP, '\\/') . \DIRECTORY_SEPARATOR . \ltrim($relative, '\\/');
    }

    private function pathWithin(string $path, string $root): bool
    {
        $path = \strtolower(\str_replace('\\', '/', \rtrim($path, '\\/')));
        $root = \strtolower(\str_replace('\\', '/', \rtrim($root, '\\/')));
        return $path === $root || \str_starts_with($path, $root . '/');
    }
}
