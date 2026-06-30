<?php
declare(strict_types=1);

namespace Weline\FileManager\Service;

class WlsFileManagerPathPolicyService
{
    private const POLICY_RELATIVE_PATH = 'wls-panel/file-manager-path-policy.json';
    private const CONFIRM_PHRASE = 'SAVE_PATH_POLICY';
    private const RESET_CONFIRM_PHRASE = 'RESET_PATH_POLICY';
    private const ALLOWED_WRITE_ROOTS = ['var', 'pub', 'project_var', 'project_pub'];
    public const ALLOWED_SOURCE_EDIT_ROOTS = ['project', 'local_project', 'app_code'];
    public const SOURCE_EDIT_EXTENSIONS = [
        'css',
        'htm',
        'html',
        'js',
        'json',
        'jsx',
        'less',
        'md',
        'mjs',
        'php',
        'phtml',
        'scss',
        'ts',
        'tsx',
        'vue',
        'xml',
    ];

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function getPolicyForContext(array $context): array
    {
        $profile = $this->profileContext($context);
        $store = $this->readStore();
        $saved = (array)($store['policies'][$profile['profile_key']] ?? []);
        $hasPolicy = $saved !== [];

        return $profile + [
            'has_policy' => $hasPolicy,
            'allowed_roots' => self::ALLOWED_WRITE_ROOTS,
            'enabled_roots' => $hasPolicy
                ? $this->normalizeEnabledRoots($saved['enabled_roots'] ?? [])
                : self::ALLOWED_WRITE_ROOTS,
            'default_enabled_roots' => self::ALLOWED_WRITE_ROOTS,
            'source_edit_enabled' => $hasPolicy && !empty($saved['source_edit_enabled']),
            'allowed_source_edit_roots' => self::ALLOWED_SOURCE_EDIT_ROOTS,
            'source_edit_roots' => $hasPolicy && !empty($saved['source_edit_enabled'])
                ? $this->normalizeSourceEditRoots($saved['source_edit_roots'] ?? [])
                : [],
            'source_edit_extensions' => self::SOURCE_EDIT_EXTENSIONS,
            'note' => trim((string)($saved['note'] ?? '')),
            'updated_at' => trim((string)($saved['updated_at'] ?? '')),
            'updated_by' => trim((string)($saved['updated_by'] ?? '')),
            'confirm_phrase' => self::CONFIRM_PHRASE,
            'reset_confirm_phrase' => self::RESET_CONFIRM_PHRASE,
            'storage_path' => $this->storagePath(),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{success: bool, error_code: string, policy: array<string, mixed>}
     */
    public function saveFromPanel(array $input): array
    {
        if ((string)($input['confirm_path_policy'] ?? '0') !== '1'
            || trim((string)($input['confirm_phrase'] ?? '')) !== self::CONFIRM_PHRASE
        ) {
            return $this->saveResult(false, 'path_policy_confirmation_required');
        }

        $rawRoots = $this->rawEnabledRoots($input['enabled_roots'] ?? []);
        foreach ($rawRoots as $root) {
            if (!in_array($root, self::ALLOWED_WRITE_ROOTS, true)) {
                return $this->saveResult(false, 'path_policy_invalid_root');
            }
        }

        $sourceEditEnabled = (string)($input['allow_source_edit'] ?? '0') === '1';
        $sourceEditRoots = [];
        if ($sourceEditEnabled) {
            $sourceEditRoots = $this->rawEnabledRoots($input['source_edit_roots'] ?? []);
            foreach ($sourceEditRoots as $root) {
                if (!in_array($root, self::ALLOWED_SOURCE_EDIT_ROOTS, true)) {
                    return $this->saveResult(false, 'path_policy_invalid_source_root');
                }
            }

            $sourceEditRoots = $this->normalizeSourceEditRoots($sourceEditRoots);
            if ($sourceEditRoots === []) {
                return $this->saveResult(false, 'path_policy_source_root_required');
            }
        }

        $profile = $this->profileContext($input);
        $policy = $profile + [
            'enabled_roots' => $this->normalizeEnabledRoots($rawRoots),
            'source_edit_enabled' => $sourceEditEnabled,
            'source_edit_roots' => $sourceEditRoots,
            'source_edit_extensions' => self::SOURCE_EDIT_EXTENSIONS,
            'note' => $this->safeNote((string)($input['policy_note'] ?? '')),
            'updated_at' => date('Y-m-d H:i:s'),
            'updated_by' => 'wls-panel',
        ];

        $store = $this->readStore();
        $store['schema_version'] = 1;
        $store['updated_at'] = date('Y-m-d H:i:s');
        $policies = (array)($store['policies'] ?? []);
        $policies[$profile['profile_key']] = $policy;
        $store['policies'] = $policies;

        if (!$this->writeStore($store)) {
            return $this->saveResult(false, 'path_policy_write_failed', $policy);
        }

        return $this->saveResult(true, '', $policy);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{success: bool, error_code: string, policy: array<string, mixed>}
     */
    public function resetFromPanel(array $input): array
    {
        if ((string)($input['confirm_path_policy_reset'] ?? '0') !== '1'
            || trim((string)($input['reset_confirm_phrase'] ?? '')) !== self::RESET_CONFIRM_PHRASE
        ) {
            return $this->saveResult(false, 'path_policy_reset_confirmation_required');
        }

        $profile = $this->profileContext($input);
        $store = $this->readStore();
        $policies = (array)($store['policies'] ?? []);
        if (isset($policies[$profile['profile_key']])) {
            unset($policies[$profile['profile_key']]);
        }

        $store['schema_version'] = 1;
        $store['updated_at'] = date('Y-m-d H:i:s');
        $store['policies'] = $policies;

        if ($policies === []) {
            if (!$this->removeStore()) {
                return $this->saveResult(false, 'path_policy_reset_failed', $profile);
            }
            return $this->saveResult(true, '', $profile + ['reset_at' => date('Y-m-d H:i:s')]);
        }

        if (!$this->writeStore($store)) {
            return $this->saveResult(false, 'path_policy_reset_failed', $profile);
        }

        return $this->saveResult(true, '', $profile + ['reset_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * @param array<int, array<string, mixed>> $roots
     * @param array<string, mixed> $policy
     * @return array<int, array<string, mixed>>
     */
    public function applyToRoots(array $roots, array $policy): array
    {
        $hasPolicy = !empty($policy['has_policy']);
        $enabledRoots = array_flip($this->normalizeEnabledRoots($policy['enabled_roots'] ?? []));
        $allowedRoots = array_flip(self::ALLOWED_WRITE_ROOTS);
        $sourceEditEnabled = $hasPolicy && !empty($policy['source_edit_enabled']);
        $sourceEditRoots = array_flip($this->normalizeSourceEditRoots($policy['source_edit_roots'] ?? []));
        $allowedSourceEditRoots = array_flip(self::ALLOWED_SOURCE_EDIT_ROOTS);

        foreach ($roots as $index => $root) {
            $key = (string)($root['key'] ?? '');
            if (isset($allowedSourceEditRoots[$key])) {
                $roots[$index]['source_policy_managed'] = true;
                $roots[$index]['source_edit_enabled'] = $sourceEditEnabled && isset($sourceEditRoots[$key]);
                $roots[$index]['source_edit_extensions'] = implode(', ', self::SOURCE_EDIT_EXTENSIONS);
                $roots[$index]['source_policy_state'] = !$sourceEditEnabled
                    ? (string)__('源码编辑默认关闭')
                    : (isset($sourceEditRoots[$key]) ? (string)__('源码编辑策略允许') : (string)__('源码编辑策略禁用'));
                if (!empty($roots[$index]['source_edit_enabled'])) {
                    $roots[$index]['description'] = trim((string)($root['description'] ?? '') . ' ' . (string)__('源码编辑策略允许此只读根目录内的已存在小源码文件进入保存表单。'));
                }
            }

            if (!isset($allowedRoots[$key])) {
                continue;
            }

            $roots[$index]['policy_managed'] = true;
            $roots[$index]['policy_profile_key'] = (string)($policy['profile_key'] ?? '');
            $roots[$index]['policy_write_enabled'] = !$hasPolicy || isset($enabledRoots[$key]);

            if (!$hasPolicy) {
                $roots[$index]['policy_state'] = (string)__('默认继承');
                continue;
            }

            if (!isset($enabledRoots[$key])) {
                $roots[$index]['write_enabled'] = false;
                $roots[$index]['mode'] = (string)__('策略只读根目录');
                $roots[$index]['policy_state'] = (string)__('策略禁用');
                if ((string)($roots[$index]['status_tone'] ?? '') === 'ok') {
                    $roots[$index]['status'] = (string)__('策略只读');
                    $roots[$index]['status_tone'] = 'warning';
                }
                $roots[$index]['description'] = trim((string)($root['description'] ?? '') . ' ' . (string)__('当前项目路径策略已禁用此根目录写入。'));
                continue;
            }

            $roots[$index]['policy_state'] = (string)__('策略允许');
            $roots[$index]['description'] = trim((string)($root['description'] ?? '') . ' ' . (string)__('当前项目路径策略允许此根目录受控写入。'));
        }

        return $roots;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, string>
     */
    private function profileContext(array $context): array
    {
        $projectId = trim((string)($context['project_id'] ?? ''));
        $domain = mb_strtolower(trim((string)($context['domain'] ?? '')));
        $domain = preg_replace('/[^a-z0-9.-]+/', '', $domain) ?: '';
        $projectType = trim((string)($context['project_type'] ?? ''));
        $projectLookup = trim((string)($context['project_lookup'] ?? ''));

        if ($projectId !== '' && ctype_digit($projectId) && (int)$projectId > 0) {
            $profileKey = 'project:' . (int)$projectId;
            $profileLabel = 'Project #' . (int)$projectId;
        } elseif ($domain !== '') {
            $profileKey = 'domain:' . $domain;
            $profileLabel = $domain;
        } else {
            $profileKey = 'local';
            $profileLabel = 'Local WLS Panel';
        }

        return [
            'profile_key' => $profileKey,
            'profile_label' => $profileLabel,
            'project_id' => $projectId,
            'domain' => $domain,
            'project_type' => $projectType,
            'mode' => $projectLookup === 'found' || $projectId !== '' || $domain !== '' ? 'managed' : 'local',
        ];
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function rawEnabledRoots(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];
        $roots = [];
        foreach ($values as $root) {
            $root = trim((string)$root);
            if ($root !== '') {
                $roots[] = $root;
            }
        }

        return array_values(array_unique($roots));
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function normalizeEnabledRoots(mixed $value): array
    {
        $enabledRoots = [];
        foreach ($this->rawEnabledRoots($value) as $root) {
            if (in_array($root, self::ALLOWED_WRITE_ROOTS, true)) {
                $enabledRoots[] = $root;
            }
        }

        return array_values(array_unique($enabledRoots));
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function normalizeSourceEditRoots(mixed $value): array
    {
        $enabledRoots = [];
        foreach ($this->rawEnabledRoots($value) as $root) {
            if (in_array($root, self::ALLOWED_SOURCE_EDIT_ROOTS, true)) {
                $enabledRoots[] = $root;
            }
        }

        return array_values(array_unique($enabledRoots));
    }

    private function safeNote(string $note): string
    {
        $note = preg_replace('/\s+/u', ' ', trim($note)) ?: '';
        return mb_substr($note, 0, 240);
    }

    /**
     * @return array<string, mixed>
     */
    private function readStore(): array
    {
        $path = $this->storagePath();
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return ['schema_version' => 1, 'policies' => []];
        }

        $decoded = json_decode((string)file_get_contents($path), true);
        if (!is_array($decoded)) {
            return ['schema_version' => 1, 'policies' => []];
        }

        $decoded['policies'] = is_array($decoded['policies'] ?? null) ? $decoded['policies'] : [];
        return $decoded;
    }

    /**
     * @param array<string, mixed> $store
     */
    private function writeStore(array $store): bool
    {
        $path = $this->storagePath();
        if ($path === '') {
            return false;
        }

        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        $json = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || $json === '') {
            return false;
        }

        $tmpPath = $path . '.' . str_replace('.', '', uniqid('', true)) . '.tmp';
        if (file_put_contents($tmpPath, $json . PHP_EOL, LOCK_EX) === false) {
            return false;
        }

        if (!rename($tmpPath, $path)) {
            @unlink($tmpPath);
            return false;
        }

        return true;
    }

    private function removeStore(): bool
    {
        $path = $this->storagePath();
        if ($path === '' || !is_file($path)) {
            return true;
        }

        return unlink($path);
    }

    private function storagePath(): string
    {
        $varPath = defined('VAR_PATH') ? VAR_PATH : ((defined('BP') ? BP : '') . 'var' . DIRECTORY_SEPARATOR);
        $varPath = trim((string)$varPath);
        if ($varPath === '') {
            return '';
        }

        return rtrim($varPath, "\\/") . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::POLICY_RELATIVE_PATH);
    }

    /**
     * @param array<string, mixed> $policy
     * @return array{success: bool, error_code: string, policy: array<string, mixed>}
     */
    private function saveResult(bool $success, string $errorCode, array $policy = []): array
    {
        return [
            'success' => $success,
            'error_code' => $errorCode,
            'policy' => $policy,
        ];
    }
}
