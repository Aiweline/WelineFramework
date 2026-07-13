<?php
declare(strict_types=1);

namespace Weline\PhpManager\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\PhpManager\Model\WlsPhpProfile;
use Weline\Server\Api\Control\RuntimeReloadGateway;

class WlsPhpProfileService
{
    private const AUDIT_FILE = 'php-manager-audit.jsonl';

    public function __construct(
        private readonly RuntimeReloadGateway $runtimeReloadGateway
    ) {
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function getFormData(array $context): array
    {
        $profile = $this->loadForContext($context);
        $data = $profile instanceof WlsPhpProfile ? $profile->getData() : [];
        $profileContext = $this->getProfileContext($context);
        $runtime = $this->getRuntimeInfo();
        $profileKey = $profileContext['profile_key'] !== '' ? $profileContext['profile_key'] : 'local';

        return [
            'has_profile' => $profile instanceof WlsPhpProfile && (int)$profile->getData(WlsPhpProfile::schema_fields_ID) > 0,
            'profile_id' => (int)($data[WlsPhpProfile::schema_fields_ID] ?? 0),
            'profile_key' => (string)($data[WlsPhpProfile::schema_fields_PROFILE_KEY] ?? $profileKey),
            'project_id' => (string)($data[WlsPhpProfile::schema_fields_PROJECT_ID] ?? $profileContext['project_id']),
            'domain' => (string)($data[WlsPhpProfile::schema_fields_DOMAIN] ?? $profileContext['domain']),
            'project_type' => (string)($data[WlsPhpProfile::schema_fields_PROJECT_TYPE] ?? $profileContext['project_type']),
            'enabled' => (int)($data[WlsPhpProfile::schema_fields_ENABLED] ?? 0) === 1,
            'php_binary' => (string)($data[WlsPhpProfile::schema_fields_PHP_BINARY] ?? ($runtime['binary'] ?? '')),
            'php_ini_path' => (string)($data[WlsPhpProfile::schema_fields_PHP_INI_PATH] ?? ($runtime['ini_file'] ?? '')),
            'memory_limit' => (string)($data[WlsPhpProfile::schema_fields_MEMORY_LIMIT] ?? ($runtime['memory_limit'] ?? '')),
            'max_execution_time' => (string)($data[WlsPhpProfile::schema_fields_MAX_EXECUTION_TIME] ?? ($runtime['max_execution_time'] ?? '')),
            'upload_max_filesize' => (string)($data[WlsPhpProfile::schema_fields_UPLOAD_MAX_FILESIZE] ?? ($runtime['upload_max_filesize'] ?? '')),
            'post_max_size' => (string)($data[WlsPhpProfile::schema_fields_POST_MAX_SIZE] ?? ($runtime['post_max_size'] ?? '')),
            'timezone' => (string)($data[WlsPhpProfile::schema_fields_TIMEZONE] ?? ($runtime['timezone'] ?? '')),
            'required_extensions' => (string)($data[WlsPhpProfile::schema_fields_REQUIRED_EXTENSIONS] ?? ''),
            'disabled_functions' => (string)($data[WlsPhpProfile::schema_fields_DISABLED_FUNCTIONS] ?? ($runtime['disable_functions'] ?? '')),
            'description' => (string)($data[WlsPhpProfile::schema_fields_DESCRIPTION] ?? ''),
            'last_runtime_action' => (string)($data[WlsPhpProfile::schema_fields_LAST_RUNTIME_ACTION] ?? WlsPhpProfile::RUNTIME_ACTION_NONE),
            'last_runtime_instance' => (string)($data[WlsPhpProfile::schema_fields_LAST_RUNTIME_INSTANCE] ?? ''),
            'last_runtime_message' => (string)($data[WlsPhpProfile::schema_fields_LAST_RUNTIME_MESSAGE] ?? ''),
            'last_runtime_at' => (string)($data[WlsPhpProfile::schema_fields_LAST_RUNTIME_AT] ?? ''),
            'source_label' => $profile instanceof WlsPhpProfile ? (string)__('Panel Profile') : (string)__('Current Runtime'),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{success:bool,message:string,profile_id:int,runtime_action:string,runtime_action_success:bool,runtime_action_message:string}
     */
    public function saveFromPanel(array $input): array
    {
        $profileKey = '';
        try {
            if ((string)($input['confirm_profile_save'] ?? '0') !== '1') {
                throw new \InvalidArgumentException((string)__('Confirm the PHP profile save before submitting.'));
            }

            $context = [
                'profile_key' => (string)($input['profile_key'] ?? ''),
                'project_id' => (string)($input['project_id'] ?? ''),
                'domain' => (string)($input['domain'] ?? ''),
                'project_type' => (string)($input['project_type'] ?? ''),
            ];
            $profileContext = $this->getProfileContext($context);
            $profileKey = $profileContext['profile_key'] !== '' ? $profileContext['profile_key'] : 'local';
            $existing = $this->loadForContext($profileContext);
            /** @var WlsPhpProfile $profile */
            $profile = $existing instanceof WlsPhpProfile
                ? $existing
                : ObjectManager::getInstance(WlsPhpProfile::class);

            $profile->setData([
                WlsPhpProfile::schema_fields_PROFILE_KEY => $profileKey,
                WlsPhpProfile::schema_fields_PROJECT_ID => $profileContext['project_id'],
                WlsPhpProfile::schema_fields_DOMAIN => $profileContext['domain'],
                WlsPhpProfile::schema_fields_PROJECT_TYPE => $profileContext['project_type'],
                WlsPhpProfile::schema_fields_ENABLED => (string)($input['enabled'] ?? '0') === '1' ? 1 : 0,
                WlsPhpProfile::schema_fields_PHP_BINARY => (string)($input['php_binary'] ?? ''),
                WlsPhpProfile::schema_fields_PHP_INI_PATH => (string)($input['php_ini_path'] ?? ''),
                WlsPhpProfile::schema_fields_MEMORY_LIMIT => (string)($input['memory_limit'] ?? ''),
                WlsPhpProfile::schema_fields_MAX_EXECUTION_TIME => (string)($input['max_execution_time'] ?? ''),
                WlsPhpProfile::schema_fields_UPLOAD_MAX_FILESIZE => (string)($input['upload_max_filesize'] ?? ''),
                WlsPhpProfile::schema_fields_POST_MAX_SIZE => (string)($input['post_max_size'] ?? ''),
                WlsPhpProfile::schema_fields_TIMEZONE => (string)($input['timezone'] ?? ''),
                WlsPhpProfile::schema_fields_REQUIRED_EXTENSIONS => (string)($input['required_extensions'] ?? ''),
                WlsPhpProfile::schema_fields_DISABLED_FUNCTIONS => (string)($input['disabled_functions'] ?? ''),
                WlsPhpProfile::schema_fields_DESCRIPTION => (string)($input['description'] ?? ''),
            ]);
            $profile->save();

            $runtimeAction = $this->normalizeRuntimeAction((string)($input['runtime_action'] ?? WlsPhpProfile::RUNTIME_ACTION_NONE));
            $runtimeInstance = $this->normalizeInstanceName((string)($input['runtime_instance'] ?? ''));
            $runtimeResult = $this->applyRuntimeAction($runtimeAction, $runtimeInstance);
            $profile->setData(WlsPhpProfile::schema_fields_LAST_RUNTIME_ACTION, $runtimeAction);
            $profile->setData(WlsPhpProfile::schema_fields_LAST_RUNTIME_INSTANCE, $runtimeInstance !== '' ? $runtimeInstance : null);
            $profile->setData(WlsPhpProfile::schema_fields_LAST_RUNTIME_MESSAGE, \mb_substr((string)($runtimeResult['message'] ?? ''), 0, 255));
            $profile->setData(WlsPhpProfile::schema_fields_LAST_RUNTIME_AT, \date('Y-m-d H:i:s'));
            $profile->save();

            $this->appendAudit('profile_saved', [
                'success' => true,
                'profile' => $this->auditProfileData($profile->getData()),
                'runtime_action' => $runtimeAction,
                'runtime_instance' => $runtimeInstance,
                'runtime_action_success' => (bool)($runtimeResult['success'] ?? false),
                'runtime_action_message' => (string)($runtimeResult['message'] ?? ''),
            ]);

            return [
                'success' => true,
                'message' => (string)__('Project PHP profile saved.'),
                'profile_id' => (int)$profile->getData(WlsPhpProfile::schema_fields_ID),
                'runtime_action' => $runtimeAction,
                'runtime_action_success' => (bool)($runtimeResult['success'] ?? false),
                'runtime_action_message' => (string)($runtimeResult['message'] ?? ''),
            ];
        } catch (\Throwable $throwable) {
            $message = \mb_substr($throwable->getMessage(), 0, 220);
            $this->appendAudit('profile_save_failed', [
                'success' => false,
                'profile_key' => $profileKey,
                'message' => $message,
            ]);

            return [
                'success' => false,
                'message' => $message,
                'profile_id' => 0,
                'runtime_action' => WlsPhpProfile::RUNTIME_ACTION_NONE,
                'runtime_action_success' => false,
                'runtime_action_message' => '',
            ];
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public function loadForContext(array $context): ?WlsPhpProfile
    {
        $profileContext = $this->getProfileContext($context);
        $profileKey = $profileContext['profile_key'] !== '' ? $profileContext['profile_key'] : 'local';

        /** @var WlsPhpProfile $model */
        $model = ObjectManager::getInstance(WlsPhpProfile::class);
        $collection = $model->reset()
            ->where(WlsPhpProfile::schema_fields_PROFILE_KEY, $profileKey)
            ->select()
            ->pagination(1, 1)
            ->fetch();
        $items = $collection->getItems();
        $result = $items[0] ?? null;

        return $result instanceof WlsPhpProfile ? $result : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRuntimeInfo(): array
    {
        $extensions = \get_loaded_extensions();
        \sort($extensions, \SORT_NATURAL | \SORT_FLAG_CASE);
        $scanned = \php_ini_scanned_files();
        $scannedFiles = [];
        if (\is_string($scanned) && \trim($scanned) !== '') {
            foreach (\explode(',', $scanned) as $file) {
                $file = \trim($file);
                if ($file !== '') {
                    $scannedFiles[] = $file;
                }
            }
        }

        return [
            'version' => \PHP_VERSION,
            'version_id' => \PHP_VERSION_ID,
            'sapi' => \PHP_SAPI,
            'os' => \PHP_OS_FAMILY,
            'binary' => \PHP_BINARY,
            'ini_file' => (string)(\php_ini_loaded_file() ?: ''),
            'scanned_ini_files' => $scannedFiles,
            'memory_limit' => (string)\ini_get('memory_limit'),
            'max_execution_time' => (string)\ini_get('max_execution_time'),
            'upload_max_filesize' => (string)\ini_get('upload_max_filesize'),
            'post_max_size' => (string)\ini_get('post_max_size'),
            'timezone' => (string)\date_default_timezone_get(),
            'disable_functions' => (string)\ini_get('disable_functions'),
            'extension_count' => \count($extensions),
            'extensions' => $extensions,
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array{summary:array<string, int>,rows:array<int, array<string, mixed>>}
     */
    public function getInheritanceMap(array $context): array
    {
        $runtime = $this->getRuntimeInfo();
        $profile = $this->loadForContext($context);
        $data = $profile instanceof WlsPhpProfile ? $profile->getData() : [];
        $hasProfile = $profile instanceof WlsPhpProfile && (int)$profile->getData(WlsPhpProfile::schema_fields_ID) > 0;

        $rows = [];
        $rows[] = $this->buildInheritanceRow(
            (string)__('PHP Binary'),
            (string)($runtime['binary'] ?? ''),
            (string)($data[WlsPhpProfile::schema_fields_PHP_BINARY] ?? ''),
            $hasProfile
        );
        $rows[] = $this->buildInheritanceRow(
            (string)__('php.ini Path'),
            (string)($runtime['ini_file'] ?? ''),
            (string)($data[WlsPhpProfile::schema_fields_PHP_INI_PATH] ?? ''),
            $hasProfile
        );
        $rows[] = $this->buildInheritanceRow(
            (string)__('Memory Limit'),
            (string)($runtime['memory_limit'] ?? ''),
            (string)($data[WlsPhpProfile::schema_fields_MEMORY_LIMIT] ?? ''),
            $hasProfile
        );
        $rows[] = $this->buildInheritanceRow(
            (string)__('Max Execution Time'),
            (string)($runtime['max_execution_time'] ?? ''),
            (string)($data[WlsPhpProfile::schema_fields_MAX_EXECUTION_TIME] ?? ''),
            $hasProfile
        );
        $rows[] = $this->buildInheritanceRow(
            (string)__('Upload Max Filesize'),
            (string)($runtime['upload_max_filesize'] ?? ''),
            (string)($data[WlsPhpProfile::schema_fields_UPLOAD_MAX_FILESIZE] ?? ''),
            $hasProfile
        );
        $rows[] = $this->buildInheritanceRow(
            (string)__('Post Max Size'),
            (string)($runtime['post_max_size'] ?? ''),
            (string)($data[WlsPhpProfile::schema_fields_POST_MAX_SIZE] ?? ''),
            $hasProfile
        );
        $rows[] = $this->buildInheritanceRow(
            (string)__('Timezone'),
            (string)($runtime['timezone'] ?? ''),
            (string)($data[WlsPhpProfile::schema_fields_TIMEZONE] ?? ''),
            $hasProfile
        );
        $rows[] = $this->buildInheritanceRow(
            (string)__('Disabled Functions'),
            (string)($runtime['disable_functions'] ?? ''),
            (string)($data[WlsPhpProfile::schema_fields_DISABLED_FUNCTIONS] ?? ''),
            $hasProfile
        );
        $rows[] = $this->buildRequiredExtensionsRow(
            (string)($data[WlsPhpProfile::schema_fields_REQUIRED_EXTENSIONS] ?? ''),
            $runtime,
            $hasProfile
        );

        $summary = [
            'total' => \count($rows),
            'inherited' => 0,
            'overridden' => 0,
            'aligned' => 0,
            'attention' => 0,
        ];
        foreach ($rows as $row) {
            $state = (string)($row['state'] ?? '');
            if ($state === 'inherited') {
                ++$summary['inherited'];
            } elseif ($state === 'profile_override') {
                ++$summary['overridden'];
            } elseif (\in_array($state, ['profile_aligned', 'extension_satisfied'], true)) {
                ++$summary['aligned'];
            } elseif (\in_array($state, ['extension_missing'], true)) {
                ++$summary['attention'];
            }
        }

        return [
            'summary' => $summary,
            'rows' => $rows,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRecentAuditRecords(int $limit = 12): array
    {
        $path = $this->auditPath();
        if (!\is_file($path)) {
            return [];
        }
        $lines = \file($path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        if (!\is_array($lines)) {
            return [];
        }

        $records = [];
        foreach (\array_slice($lines, -\max(1, \min(50, $limit))) as $line) {
            $decoded = \json_decode((string)$line, true);
            if (\is_array($decoded)) {
                $records[] = $decoded;
            }
        }

        return \array_reverse($records);
    }

    /**
     * @param array<string, mixed> $context
     * @return array{profile_key:string,project_id:string,domain:string,project_type:string}
     */
    private function getProfileContext(array $context): array
    {
        $normalized = [
            'profile_key' => $this->normalizeToken($this->contextValue($context, 'profile_key', 'PROFILE_KEY'), 190),
            'project_id' => $this->normalizeToken($this->contextValue($context, 'project_id', 'PROJECT_ID'), 80),
            'domain' => $this->normalizeDomain($this->contextValue($context, 'domain', 'DOMAIN')),
            'project_type' => $this->normalizeToken($this->contextValue($context, 'project_type', 'PROJECT_TYPE'), 80),
        ];
        if ($normalized['profile_key'] === '') {
            $normalized['profile_key'] = WlsPhpProfile::buildProfileKey($normalized['project_id'], $normalized['domain']);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function contextValue(array $context, string $key, string $envKey): string
    {
        $value = \trim((string)($context[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }

        return \trim((string)\getenv('WLS_PANEL_' . $envKey));
    }

    private function normalizeRuntimeAction(string $runtimeAction): string
    {
        $runtimeAction = \strtolower(\trim($runtimeAction));
        return \in_array($runtimeAction, [WlsPhpProfile::RUNTIME_ACTION_NONE, WlsPhpProfile::RUNTIME_ACTION_RELOAD], true)
            ? $runtimeAction
            : WlsPhpProfile::RUNTIME_ACTION_NONE;
    }

    private function normalizeInstanceName(string $instanceName): string
    {
        $instanceName = \trim($instanceName);
        $instanceName = \preg_replace('/[^a-zA-Z0-9_.:-]/', '', $instanceName) ?? '';
        return \substr($instanceName, 0, 120);
    }

    /**
     * @return array{success:bool,message:string}
     */
    private function applyRuntimeAction(string $runtimeAction, string $runtimeInstance): array
    {
        if ($runtimeAction === WlsPhpProfile::RUNTIME_ACTION_NONE) {
            return [
                'success' => true,
                'message' => (string)__('Runtime reload skipped.'),
            ];
        }
        if ($runtimeInstance === '') {
            return [
                'success' => false,
                'message' => (string)__('Select a running WLS instance before requesting reload.'),
            ];
        }

        $result = $this->runtimeReloadGateway->forceReloadAsync($runtimeInstance, 8.0);
        return [
            'success' => $result->success,
            'message' => \mb_substr($result->message, 0, 220),
        ];
    }

    private function normalizeToken(string $value, int $maxLength): string
    {
        $value = \trim($value);
        $value = \preg_replace('/[^a-zA-Z0-9:_\-.]/', '', $value) ?? '';
        return \substr($value, 0, $maxLength);
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = \strtolower(\trim($domain));
        $domain = \preg_replace('#^https?://#i', '', $domain) ?? $domain;
        $domain = \explode('/', $domain, 2)[0] ?? $domain;
        return \trim($domain);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildInheritanceRow(string $label, string $runtimeValue, string $profileValue, bool $hasProfile): array
    {
        $runtimeValue = \trim($runtimeValue);
        $profileValue = \trim($profileValue);
        $usesProfile = $hasProfile && $profileValue !== '';
        $effectiveValue = $usesProfile ? $profileValue : $runtimeValue;
        $state = 'inherited';
        $stateLabel = (string)__('Inherited');
        $sourceLabel = (string)__('Current Runtime');

        if ($usesProfile && $profileValue === $runtimeValue) {
            $state = 'profile_aligned';
            $stateLabel = (string)__('Profile Aligned');
            $sourceLabel = (string)__('Project Profile');
        } elseif ($usesProfile) {
            $state = 'profile_override';
            $stateLabel = (string)__('Profile Override');
            $sourceLabel = (string)__('Project Profile');
        }

        return [
            'label' => $label,
            'runtime_value' => $runtimeValue,
            'profile_value' => $usesProfile ? $profileValue : '',
            'effective_value' => $effectiveValue,
            'source_label' => $sourceLabel,
            'state' => $state,
            'state_label' => $stateLabel,
        ];
    }

    /**
     * @param array<string, mixed> $runtime
     * @return array<string, mixed>
     */
    private function buildRequiredExtensionsRow(string $profileValue, array $runtime, bool $hasProfile): array
    {
        $required = $this->csvList($profileValue);
        $loaded = \is_array($runtime['extensions'] ?? null) ? $runtime['extensions'] : [];
        $loadedLookup = [];
        foreach ($loaded as $extension) {
            $extension = \strtolower(\trim((string)$extension));
            if ($extension !== '') {
                $loadedLookup[$extension] = true;
            }
        }

        $missing = [];
        foreach ($required as $extension) {
            if (!isset($loadedLookup[\strtolower($extension)])) {
                $missing[] = $extension;
            }
        }

        if ($hasProfile && $required !== [] && $missing === []) {
            $state = 'extension_satisfied';
            $stateLabel = (string)__('Satisfied');
        } elseif ($hasProfile && $missing !== []) {
            $state = 'extension_missing';
            $stateLabel = (string)__('Missing Extension');
        } else {
            $state = 'inherited';
            $stateLabel = (string)__('Inherited');
        }

        return [
            'label' => (string)__('Required Extensions'),
            'runtime_value' => (string)__('Loaded: %{1}', \count($loadedLookup)),
            'profile_value' => $required !== [] ? \implode(', ', $required) : '',
            'effective_value' => $missing !== [] ? \implode(', ', $missing) : ($required !== [] ? \implode(', ', $required) : ''),
            'source_label' => $required !== [] ? (string)__('Project Profile') : (string)__('Current Runtime'),
            'state' => $state,
            'state_label' => $stateLabel,
            'missing_extensions' => $missing,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function csvList(string $value): array
    {
        $items = [];
        foreach (\preg_split('/[,\r\n]+/', $value) ?: [] as $item) {
            $item = \trim((string)$item);
            if ($item !== '') {
                $items[] = $item;
            }
        }

        return \array_values(\array_unique($items));
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
        return \rtrim(BP, '\\/') . \DIRECTORY_SEPARATOR . 'var'
            . \DIRECTORY_SEPARATOR . 'log'
            . \DIRECTORY_SEPARATOR . 'wls'
            . \DIRECTORY_SEPARATOR . self::AUDIT_FILE;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function auditProfileData(array $data): array
    {
        return [
            'profile_id' => (int)($data[WlsPhpProfile::schema_fields_ID] ?? 0),
            'profile_key' => (string)($data[WlsPhpProfile::schema_fields_PROFILE_KEY] ?? ''),
            'project_id' => (string)($data[WlsPhpProfile::schema_fields_PROJECT_ID] ?? ''),
            'domain' => (string)($data[WlsPhpProfile::schema_fields_DOMAIN] ?? ''),
            'project_type' => (string)($data[WlsPhpProfile::schema_fields_PROJECT_TYPE] ?? ''),
            'enabled' => (int)($data[WlsPhpProfile::schema_fields_ENABLED] ?? 0) === 1,
            'php_binary_state' => \trim((string)($data[WlsPhpProfile::schema_fields_PHP_BINARY] ?? '')) !== '' ? 'configured' : 'empty',
            'php_ini_state' => \trim((string)($data[WlsPhpProfile::schema_fields_PHP_INI_PATH] ?? '')) !== '' ? 'configured' : 'empty',
            'memory_limit' => (string)($data[WlsPhpProfile::schema_fields_MEMORY_LIMIT] ?? ''),
            'max_execution_time' => (string)($data[WlsPhpProfile::schema_fields_MAX_EXECUTION_TIME] ?? ''),
            'required_extensions' => (string)($data[WlsPhpProfile::schema_fields_REQUIRED_EXTENSIONS] ?? ''),
        ];
    }
}
