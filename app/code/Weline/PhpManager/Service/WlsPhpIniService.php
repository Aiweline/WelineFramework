<?php
declare(strict_types=1);

namespace Weline\PhpManager\Service;

use Weline\PhpManager\Model\WlsPhpProfile;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Control\IpcControlGateway;

class WlsPhpIniService
{
    private const AUDIT_FILE = 'php-manager-audit.jsonl';
    private const APPLY_PHRASE = 'APPLY_PHP_INI';
    private const ROLLBACK_PHRASE = 'ROLLBACK_PHP_INI';
    private const MARKER_START = '; WLS PHP Manager managed directives begin';
    private const MARKER_END = '; WLS PHP Manager managed directives end';

    public function __construct(
        private readonly WlsPhpProfileService $profileService,
        private readonly IpcControlGateway $ipcControlGateway
    ) {
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function getIniPlan(array $context): array
    {
        $profile = $this->profileService->loadForContext($context);
        $latestBackup = null;
        try {
            if (!$profile instanceof WlsPhpProfile || (int)$profile->getData(WlsPhpProfile::schema_fields_ID) <= 0) {
                return $this->emptyPlan((string)__('Save a Project PHP Profile before applying php.ini.'));
            }
            if ((int)$profile->getData(WlsPhpProfile::schema_fields_ENABLED) !== 1) {
                return $this->emptyPlan((string)__('Enable the Project PHP Profile before applying php.ini.'));
            }

            $directives = $this->profileDirectives($profile);
            if ($directives === []) {
                return $this->emptyPlan((string)__('No php.ini directives are configured in this Project PHP Profile.'));
            }

            $target = $this->resolveTargetPath(
                (string)$profile->getData(WlsPhpProfile::schema_fields_PHP_INI_PATH),
                false
            );
            if (!$target['success']) {
                return $this->emptyPlan((string)$target['message']);
            }

            $content = \file_get_contents((string)$target['path']);
            if (!\is_string($content)) {
                return $this->emptyPlan((string)__('Unable to read the target php.ini file.'));
            }

            $existing = $this->extractCurrentDirectiveValues($content, \array_keys($directives));
            $changes = [];
            foreach ($directives as $name => $value) {
                $before = (string)($existing[$name] ?? '');
                $after = (string)$value;
                if ($before !== $after) {
                    $changes[] = [
                        'name' => $name,
                        'before' => $before,
                        'after' => $after,
                    ];
                }
            }
            $latestBackup = $this->latestBackupFor((string)$profile->getData(WlsPhpProfile::schema_fields_PROFILE_KEY), (string)$target['path']);

            return [
                'can_apply' => true,
                'reason' => '',
                'target_path' => (string)$target['path'],
                'profile_key' => (string)$profile->getData(WlsPhpProfile::schema_fields_PROFILE_KEY),
                'directives' => $directives,
                'changes' => $changes,
                'change_count' => \count($changes),
                'block_exists' => \str_contains($content, self::MARKER_START),
                'latest_backup' => $latestBackup,
                'apply_phrase' => self::APPLY_PHRASE,
                'rollback_phrase' => self::ROLLBACK_PHRASE,
            ];
        } catch (\Throwable $throwable) {
            return $this->emptyPlan(\mb_substr($throwable->getMessage(), 0, 220), $latestBackup);
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array{success:bool,message:string,backup_path:string,change_count:int,runtime_action:string,runtime_action_success:bool,runtime_action_message:string}
     */
    public function applyIniFromPanel(array $input): array
    {
        $profileKey = '';
        $targetPath = '';
        try {
            if ((string)($input['confirm_ini_apply'] ?? '0') !== '1') {
                throw new \InvalidArgumentException((string)__('Confirm the php.ini apply operation before submitting.'));
            }
            if (\trim((string)($input['confirm_phrase'] ?? '')) !== self::APPLY_PHRASE) {
                throw new \InvalidArgumentException((string)__('Type APPLY_PHP_INI to apply php.ini changes.'));
            }

            $context = $this->contextFromInput($input);
            $plan = $this->getIniPlan($context);
            if (empty($plan['can_apply'])) {
                throw new \InvalidArgumentException((string)($plan['reason'] ?? __('php.ini apply is not available.')));
            }

            $targetPath = (string)($plan['target_path'] ?? '');
            $profileKey = (string)($plan['profile_key'] ?? '');
            $directives = \is_array($plan['directives'] ?? null) ? $plan['directives'] : [];
            $original = \file_get_contents($targetPath);
            if (!\is_string($original)) {
                throw new \RuntimeException((string)__('Unable to read php.ini before applying changes.'));
            }
            $next = $this->upsertManagedBlock($original, $directives);
            if ($next === $original) {
                $runtimeResult = $this->applyRuntimeFromInput($input);
                $this->appendAudit('ini_apply_noop', [
                    'success' => true,
                    'profile_key' => $profileKey,
                    'target_path' => $targetPath,
                    'message' => 'no_changes',
                    'runtime_action' => $runtimeResult['action'],
                    'runtime_action_success' => $runtimeResult['success'],
                    'runtime_action_message' => $runtimeResult['message'],
                ]);

                return [
                    'success' => true,
                    'message' => (string)__('No php.ini changes were needed.'),
                    'backup_path' => '',
                    'change_count' => 0,
                    'runtime_action' => $runtimeResult['action'],
                    'runtime_action_success' => $runtimeResult['success'],
                    'runtime_action_message' => $runtimeResult['message'],
                ];
            }

            $backup = $this->createBackup($targetPath, $original, $profileKey, \is_array($plan['changes'] ?? null) ? $plan['changes'] : []);
            $this->writeTarget($targetPath, $next);
            $runtimeResult = $this->applyRuntimeFromInput($input);

            $this->appendAudit('ini_applied', [
                'success' => true,
                'profile_key' => $profileKey,
                'target_path' => $targetPath,
                'backup_path' => $backup['path'],
                'change_count' => (int)($plan['change_count'] ?? 0),
                'runtime_action' => $runtimeResult['action'],
                'runtime_action_success' => $runtimeResult['success'],
                'runtime_action_message' => $runtimeResult['message'],
            ]);

            return [
                'success' => true,
                'message' => (string)__('php.ini applied and backup created.'),
                'backup_path' => $backup['path'],
                'change_count' => (int)($plan['change_count'] ?? 0),
                'runtime_action' => $runtimeResult['action'],
                'runtime_action_success' => $runtimeResult['success'],
                'runtime_action_message' => $runtimeResult['message'],
            ];
        } catch (\Throwable $throwable) {
            $message = \mb_substr($throwable->getMessage(), 0, 220);
            $this->appendAudit('ini_apply_failed', [
                'success' => false,
                'profile_key' => $profileKey,
                'target_path' => $targetPath,
                'message' => $message,
            ]);

            return [
                'success' => false,
                'message' => $message,
                'backup_path' => '',
                'change_count' => 0,
                'runtime_action' => WlsPhpProfile::RUNTIME_ACTION_NONE,
                'runtime_action_success' => false,
                'runtime_action_message' => '',
            ];
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array{success:bool,message:string,target_path:string,runtime_action:string,runtime_action_success:bool,runtime_action_message:string}
     */
    public function rollbackIniFromPanel(array $input): array
    {
        $backupPath = '';
        $targetPath = '';
        $profileKey = '';
        try {
            if ((string)($input['confirm_ini_rollback'] ?? '0') !== '1') {
                throw new \InvalidArgumentException((string)__('Confirm the php.ini rollback operation before submitting.'));
            }
            if (\trim((string)($input['confirm_phrase'] ?? '')) !== self::ROLLBACK_PHRASE) {
                throw new \InvalidArgumentException((string)__('Type ROLLBACK_PHP_INI to restore the selected backup.'));
            }

            $backupPath = (string)($input['backup_path'] ?? '');
            $backup = $this->resolveBackupPath($backupPath);
            $meta = $this->loadBackupMeta($backup['path']);
            $profileKey = (string)($meta['profile_key'] ?? '');
            $target = $this->resolveTargetPath((string)($meta['target_path'] ?? ''), true);
            if (!$target['success']) {
                throw new \RuntimeException((string)$target['message']);
            }
            $targetPath = (string)$target['path'];
            $content = \file_get_contents((string)$backup['path']);
            if (!\is_string($content)) {
                throw new \RuntimeException((string)__('Unable to read the selected php.ini backup.'));
            }

            $this->writeTarget($targetPath, $content);
            $runtimeResult = $this->applyRuntimeFromInput($input);
            $this->appendAudit('ini_rolled_back', [
                'success' => true,
                'profile_key' => $profileKey,
                'target_path' => $targetPath,
                'backup_path' => (string)$backup['path'],
                'runtime_action' => $runtimeResult['action'],
                'runtime_action_success' => $runtimeResult['success'],
                'runtime_action_message' => $runtimeResult['message'],
            ]);

            return [
                'success' => true,
                'message' => (string)__('php.ini restored from backup.'),
                'target_path' => $targetPath,
                'runtime_action' => $runtimeResult['action'],
                'runtime_action_success' => $runtimeResult['success'],
                'runtime_action_message' => $runtimeResult['message'],
            ];
        } catch (\Throwable $throwable) {
            $message = \mb_substr($throwable->getMessage(), 0, 220);
            $this->appendAudit('ini_rollback_failed', [
                'success' => false,
                'profile_key' => $profileKey,
                'target_path' => $targetPath,
                'backup_path' => $backupPath,
                'message' => $message,
            ]);

            return [
                'success' => false,
                'message' => $message,
                'target_path' => $targetPath,
                'runtime_action' => WlsPhpProfile::RUNTIME_ACTION_NONE,
                'runtime_action_success' => false,
                'runtime_action_message' => '',
            ];
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    private function contextFromInput(array $input): array
    {
        return [
            'profile_key' => \trim((string)($input['profile_key'] ?? '')),
            'project_id' => \trim((string)($input['project_id'] ?? '')),
            'domain' => \trim((string)($input['domain'] ?? '')),
            'project_type' => \trim((string)($input['project_type'] ?? '')),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function profileDirectives(WlsPhpProfile $profile): array
    {
        $map = [
            'memory_limit' => WlsPhpProfile::schema_fields_MEMORY_LIMIT,
            'max_execution_time' => WlsPhpProfile::schema_fields_MAX_EXECUTION_TIME,
            'upload_max_filesize' => WlsPhpProfile::schema_fields_UPLOAD_MAX_FILESIZE,
            'post_max_size' => WlsPhpProfile::schema_fields_POST_MAX_SIZE,
            'date.timezone' => WlsPhpProfile::schema_fields_TIMEZONE,
            'disable_functions' => WlsPhpProfile::schema_fields_DISABLED_FUNCTIONS,
        ];
        $directives = [];
        foreach ($map as $directive => $field) {
            $value = $this->normalizeIniDirectiveValue((string)$profile->getData($field));
            if ($value !== '') {
                $directives[$directive] = $value;
            }
        }

        return $directives;
    }

    private function normalizeIniDirectiveValue(string $value): string
    {
        $value = \trim(\preg_replace('/[\r\n\t]+/', ' ', $value) ?? '');
        $value = \preg_replace('/\s{2,}/', ' ', $value) ?? $value;
        return \mb_substr($value, 0, 255);
    }

    /**
     * @return array{success:bool,path?:string,message:string}
     */
    private function resolveTargetPath(string $path, bool $mustExist): array
    {
        $path = \trim($path);
        if ($path === '') {
            $path = (string)(\php_ini_loaded_file() ?: '');
        }
        if ($path === '') {
            return ['success' => false, 'message' => (string)__('No loaded php.ini path is available.')];
        }
        if (!\preg_match('/\.ini$/i', $path)) {
            return ['success' => false, 'message' => (string)__('Only .ini files can be managed by PHP Manager.')];
        }

        $real = \realpath($path);
        if ($real === false) {
            return ['success' => false, 'message' => $mustExist ? (string)__('The target php.ini file does not exist.') : (string)__('The target php.ini file must exist before apply.')];
        }
        if (!\is_file($real) || !\is_readable($real) || !\is_writable($real)) {
            return ['success' => false, 'message' => (string)__('The target php.ini file must be readable and writable.')];
        }
        if (!$this->isAllowedIniPath($real)) {
            return ['success' => false, 'message' => (string)__('This slice can only manage bundled project php.ini files under extend/server/php or WLS PHP Manager sandbox paths.')];
        }

        return ['success' => true, 'path' => $real, 'message' => ''];
    }

    private function isAllowedIniPath(string $path): bool
    {
        $roots = [
            \realpath($this->bpPath('extend' . \DIRECTORY_SEPARATOR . 'server' . \DIRECTORY_SEPARATOR . 'php')),
            \realpath($this->bpPath('var' . \DIRECTORY_SEPARATOR . 'wls' . \DIRECTORY_SEPARATOR . 'php-manager')),
            \realpath($this->bpPath('var' . \DIRECTORY_SEPARATOR . 'tmp' . \DIRECTORY_SEPARATOR . 'wls-php-manager')),
        ];
        foreach ($roots as $root) {
            if (\is_string($root) && $this->pathWithin($path, $root)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $directives
     * @return array<string, string>
     */
    private function extractCurrentDirectiveValues(string $content, array $directives): array
    {
        $values = [];
        $lookup = \array_fill_keys($directives, true);
        foreach (\preg_split('/\R/', $content) ?: [] as $line) {
            foreach (\array_keys($lookup) as $directive) {
                if (\preg_match('/^\s*' . \preg_quote($directive, '/') . '\s*=\s*(.*)$/i', (string)$line, $matches) === 1) {
                    $values[$directive] = \trim((string)$matches[1], " \t\"'");
                }
            }
        }

        return $values;
    }

    /**
     * @param array<string, string> $directives
     */
    private function upsertManagedBlock(string $content, array $directives): string
    {
        $block = self::MARKER_START . \PHP_EOL;
        foreach ($directives as $directive => $value) {
            $block .= $directive . ' = ' . $value . \PHP_EOL;
        }
        $block .= self::MARKER_END . \PHP_EOL;

        $pattern = '/' . \preg_quote(self::MARKER_START, '/') . '.*?' . \preg_quote(self::MARKER_END, '/') . '\R?/s';
        if (\preg_match($pattern, $content) === 1) {
            return (string)\preg_replace($pattern, $block, $content, 1);
        }

        return \rtrim($content) . \PHP_EOL . \PHP_EOL . $block;
    }

    /**
     * @param array<int, array<string, string>> $changes
     * @return array{path:string,meta_path:string}
     */
    private function createBackup(string $targetPath, string $content, string $profileKey, array $changes): array
    {
        $dir = $this->backupDir();
        if (!\is_dir($dir) && !\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            throw new \RuntimeException((string)__('Unable to create the PHP Manager backup directory.'));
        }

        $safeProfile = \preg_replace('/[^a-zA-Z0-9_.-]+/', '-', $profileKey) ?: 'local';
        $stamp = \date('Ymd-His');
        $suffix = \substr(\hash('sha256', $targetPath . \microtime(true)), 0, 10);
        $backupPath = $dir . \DIRECTORY_SEPARATOR . $safeProfile . '-' . $stamp . '-' . $suffix . '.php.ini.bak';
        if (\file_put_contents($backupPath, $content, \LOCK_EX) === false) {
            throw new \RuntimeException((string)__('Unable to write the php.ini backup file.'));
        }

        $meta = [
            'time' => \date('c'),
            'profile_key' => $profileKey,
            'target_path' => $targetPath,
            'target_hash' => \hash('sha256', $targetPath),
            'backup_path' => $backupPath,
            'changes' => $changes,
        ];
        $metaPath = $backupPath . '.json';
        if (\file_put_contents($metaPath, \json_encode($meta, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), \LOCK_EX) === false) {
            throw new \RuntimeException((string)__('Unable to write the php.ini backup metadata.'));
        }

        return ['path' => $backupPath, 'meta_path' => $metaPath];
    }

    private function writeTarget(string $targetPath, string $content): void
    {
        if (\file_put_contents($targetPath, $content, \LOCK_EX) === false) {
            throw new \RuntimeException((string)__('Unable to write the target php.ini file.'));
        }
    }

    /**
     * @return array{path:string,meta_path:string,time:string,profile_key:string,target_path:string}|null
     */
    private function latestBackupFor(string $profileKey, string $targetPath): ?array
    {
        $dir = $this->backupDir();
        if (!\is_dir($dir)) {
            return null;
        }
        $targetHash = \hash('sha256', $targetPath);
        $matches = [];
        foreach (\glob($dir . \DIRECTORY_SEPARATOR . '*.php.ini.bak.json') ?: [] as $metaPath) {
            $decoded = \json_decode((string)\file_get_contents($metaPath), true);
            if (!\is_array($decoded)) {
                continue;
            }
            if ((string)($decoded['profile_key'] ?? '') !== $profileKey || (string)($decoded['target_hash'] ?? '') !== $targetHash) {
                continue;
            }
            $backupPath = (string)($decoded['backup_path'] ?? '');
            if (!\is_file($backupPath)) {
                continue;
            }
            $matches[] = [
                'path' => $backupPath,
                'meta_path' => (string)$metaPath,
                'time' => (string)($decoded['time'] ?? ''),
                'profile_key' => $profileKey,
                'target_path' => $targetPath,
            ];
        }
        \usort($matches, static fn (array $a, array $b): int => \strcmp((string)$b['time'], (string)$a['time']));

        return $matches[0] ?? null;
    }

    /**
     * @return array{path:string}
     */
    private function resolveBackupPath(string $backupPath): array
    {
        $real = \realpath(\trim($backupPath));
        if ($real === false || !\is_file($real) || !\is_readable($real)) {
            throw new \RuntimeException((string)__('The selected php.ini backup is not readable.'));
        }
        if (!$this->pathWithin($real, $this->backupDir())) {
            throw new \RuntimeException((string)__('The selected backup is outside the PHP Manager backup directory.'));
        }
        if (!\str_ends_with($real, '.php.ini.bak')) {
            throw new \RuntimeException((string)__('The selected backup file is not a PHP Manager php.ini backup.'));
        }

        return ['path' => $real];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadBackupMeta(string $backupPath): array
    {
        $metaPath = $backupPath . '.json';
        $decoded = \json_decode((string)@\file_get_contents($metaPath), true);
        if (!\is_array($decoded) || (string)($decoded['backup_path'] ?? '') === '' || (string)($decoded['target_path'] ?? '') === '') {
            throw new \RuntimeException((string)__('The selected php.ini backup metadata is missing or invalid.'));
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{action:string,success:bool,message:string}
     */
    private function applyRuntimeFromInput(array $input): array
    {
        $action = \strtolower(\trim((string)($input['runtime_action'] ?? WlsPhpProfile::RUNTIME_ACTION_NONE)));
        if (!\in_array($action, [WlsPhpProfile::RUNTIME_ACTION_NONE, WlsPhpProfile::RUNTIME_ACTION_RELOAD], true)) {
            $action = WlsPhpProfile::RUNTIME_ACTION_NONE;
        }
        if ($action === WlsPhpProfile::RUNTIME_ACTION_NONE) {
            return [
                'action' => $action,
                'success' => true,
                'message' => (string)__('Runtime reload skipped.'),
            ];
        }

        $instance = $this->normalizeInstanceName((string)($input['runtime_instance'] ?? ''));
        if ($instance === '') {
            return [
                'action' => $action,
                'success' => false,
                'message' => (string)__('Select a running WLS instance before requesting reload.'),
            ];
        }

        $result = $this->ipcControlGateway->reloadAsync($instance, ControlMessage::RELOAD_TYPE_FORCE, 8.0);
        return [
            'action' => $action,
            'success' => !empty($result['success']),
            'message' => \mb_substr((string)($result['message'] ?? __('WLS reload request failed.')), 0, 220),
        ];
    }

    private function normalizeInstanceName(string $instanceName): string
    {
        $instanceName = \trim($instanceName);
        $instanceName = \preg_replace('/[^a-zA-Z0-9_.:-]/', '', $instanceName) ?? '';
        return \substr($instanceName, 0, 120);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPlan(string $reason, ?array $latestBackup = null): array
    {
        return [
            'can_apply' => false,
            'reason' => $reason,
            'target_path' => '',
            'profile_key' => '',
            'directives' => [],
            'changes' => [],
            'change_count' => 0,
            'block_exists' => false,
            'latest_backup' => $latestBackup,
            'apply_phrase' => self::APPLY_PHRASE,
            'rollback_phrase' => self::ROLLBACK_PHRASE,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function appendAudit(string $event, array $payload): void
    {
        $dir = \dirname($this->auditPath());
        if (!\is_dir($dir)) {
            \mkdir($dir, 0775, true);
        }
        $record = [
            'time' => \date('c'),
            'event' => $event,
            'payload' => $payload,
        ];
        \file_put_contents(
            $this->auditPath(),
            \json_encode($record, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . \PHP_EOL,
            \FILE_APPEND | \LOCK_EX
        );
    }

    private function auditPath(): string
    {
        return $this->bpPath('var' . \DIRECTORY_SEPARATOR . 'log' . \DIRECTORY_SEPARATOR . 'wls' . \DIRECTORY_SEPARATOR . self::AUDIT_FILE);
    }

    private function backupDir(): string
    {
        return $this->bpPath('var' . \DIRECTORY_SEPARATOR . 'backups' . \DIRECTORY_SEPARATOR . 'wls' . \DIRECTORY_SEPARATOR . 'php-manager');
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
