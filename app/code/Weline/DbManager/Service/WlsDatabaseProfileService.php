<?php
declare(strict_types=1);

namespace Weline\DbManager\Service;

use Weline\DbManager\Model\WlsDatabaseProfile;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Api\Control\RuntimeReloadGateway;

class WlsDatabaseProfileService
{
    private const CIPHER_ALGO = 'aes-256-gcm';
    private const SECRET_PREFIX = 'v1:';
    private const AUDIT_FILE = 'db-manager-audit.jsonl';
    public const ENV_PASSWORD_IMPORT_PHRASE = 'COPY_ENV_PASSWORD';

    public function __construct(
        private readonly RuntimeReloadGateway $runtimeReloadGateway
    ) {
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, array<string, mixed>> $rawProfiles
     * @return array<string, mixed>
     */
    public function getFormData(array $context, array $rawProfiles, string $selectedKey): array
    {
        $profile = $this->loadForContext($context);
        $data = $profile instanceof WlsDatabaseProfile ? $profile->getData() : [];
        $profileContext = $this->getProfileContext($context);
        $profileKey = $profileContext['profile_key'] !== '' ? $profileContext['profile_key'] : 'local';
        $sourceKey = \trim((string)($data[WlsDatabaseProfile::schema_fields_SOURCE_CONNECTION_KEY] ?? $selectedKey));
        if ($sourceKey === '' || !isset($rawProfiles[$sourceKey])) {
            $sourceKey = $selectedKey;
        }
        $sourceProfile = isset($rawProfiles[$sourceKey]) ? $this->normalizeConnectionConfig($rawProfiles[$sourceKey]) : [];

        return [
            'has_profile' => $profile instanceof WlsDatabaseProfile && (int)$profile->getData(WlsDatabaseProfile::schema_fields_ID) > 0,
            'profile_id' => (int)($data[WlsDatabaseProfile::schema_fields_ID] ?? 0),
            'profile_key' => (string)($data[WlsDatabaseProfile::schema_fields_PROFILE_KEY] ?? $profileKey),
            'project_id' => (string)($data[WlsDatabaseProfile::schema_fields_PROJECT_ID] ?? $profileContext['project_id']),
            'domain' => (string)($data[WlsDatabaseProfile::schema_fields_DOMAIN] ?? $profileContext['domain']),
            'project_type' => (string)($data[WlsDatabaseProfile::schema_fields_PROJECT_TYPE] ?? $profileContext['project_type']),
            'enabled' => (int)($data[WlsDatabaseProfile::schema_fields_ENABLED] ?? 0) === 1,
            'source_connection_key' => $sourceKey,
            'type' => (string)($data[WlsDatabaseProfile::schema_fields_TYPE] ?? ($sourceProfile['type'] ?? WlsDatabaseProfile::DRIVER_MYSQL)),
            'hostname' => (string)($data[WlsDatabaseProfile::schema_fields_HOSTNAME] ?? ($sourceProfile['hostname'] ?? '')),
            'hostport' => (string)($data[WlsDatabaseProfile::schema_fields_HOSTPORT] ?? ($sourceProfile['hostport'] ?? '')),
            'database' => (string)($data[WlsDatabaseProfile::schema_fields_DATABASE] ?? ($sourceProfile['database'] ?? '')),
            'path' => (string)($data[WlsDatabaseProfile::schema_fields_PATH] ?? ($sourceProfile['path'] ?? '')),
            'username' => (string)($data[WlsDatabaseProfile::schema_fields_USERNAME] ?? ($sourceProfile['username'] ?? '')),
            'password_configured' => \trim((string)($data[WlsDatabaseProfile::schema_fields_PASSWORD_SECRET] ?? '')) !== '',
            'env_password_configured' => \trim((string)($sourceProfile['password'] ?? '')) !== '',
            'password_source' => \trim((string)($data[WlsDatabaseProfile::schema_fields_PASSWORD_SECRET] ?? '')) !== ''
                ? 'profile'
                : (\trim((string)($sourceProfile['password'] ?? '')) !== '' ? 'env' : 'empty'),
            'prefix' => (string)($data[WlsDatabaseProfile::schema_fields_PREFIX] ?? ($sourceProfile['prefix'] ?? '')),
            'charset' => (string)($data[WlsDatabaseProfile::schema_fields_CHARSET] ?? ($sourceProfile['charset'] ?? '')),
            'collate' => (string)($data[WlsDatabaseProfile::schema_fields_COLLATE] ?? ($sourceProfile['collate'] ?? '')),
            'persistent' => (int)($data[WlsDatabaseProfile::schema_fields_PERSISTENT] ?? (!empty($sourceProfile['persistent']) ? 1 : 0)) === 1,
            'pre_sql' => (string)($data[WlsDatabaseProfile::schema_fields_PRE_SQL] ?? ($sourceProfile['pre_sql'] ?? '')),
            'description' => (string)($data[WlsDatabaseProfile::schema_fields_DESCRIPTION] ?? ''),
            'last_test_status' => (string)($data[WlsDatabaseProfile::schema_fields_LAST_TEST_STATUS] ?? ''),
            'last_test_message' => (string)($data[WlsDatabaseProfile::schema_fields_LAST_TEST_MESSAGE] ?? ''),
            'last_test_at' => (string)($data[WlsDatabaseProfile::schema_fields_LAST_TEST_AT] ?? ''),
            'last_runtime_action' => (string)($data[WlsDatabaseProfile::schema_fields_LAST_RUNTIME_ACTION] ?? WlsDatabaseProfile::RUNTIME_ACTION_NONE),
            'last_runtime_instance' => (string)($data[WlsDatabaseProfile::schema_fields_LAST_RUNTIME_INSTANCE] ?? ''),
            'last_runtime_message' => (string)($data[WlsDatabaseProfile::schema_fields_LAST_RUNTIME_MESSAGE] ?? ''),
            'last_runtime_at' => (string)($data[WlsDatabaseProfile::schema_fields_LAST_RUNTIME_AT] ?? ''),
            'source_label' => $profile instanceof WlsDatabaseProfile ? (string)__('Panel Profile') : (string)__('Env default'),
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
                throw new \InvalidArgumentException((string)__('Confirm the database profile save before submitting.'));
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
            /** @var WlsDatabaseProfile $profile */
            $profile = $existing instanceof WlsDatabaseProfile
                ? $existing
                : ObjectManager::getInstance(WlsDatabaseProfile::class);

            $sourceConnectionKey = $this->safeConnectionKey((string)($input['source_connection_key'] ?? ''));
            $currentSecret = $existing instanceof WlsDatabaseProfile
                ? (string)$existing->getData(WlsDatabaseProfile::schema_fields_PASSWORD_SECRET)
                : '';
            $passwordResolution = $this->resolveStoredSecret($input, $currentSecret, $sourceConnectionKey);

            $profile->setData([
                WlsDatabaseProfile::schema_fields_PROFILE_KEY => $profileKey,
                WlsDatabaseProfile::schema_fields_PROJECT_ID => $profileContext['project_id'],
                WlsDatabaseProfile::schema_fields_DOMAIN => $profileContext['domain'],
                WlsDatabaseProfile::schema_fields_PROJECT_TYPE => $profileContext['project_type'],
                WlsDatabaseProfile::schema_fields_ENABLED => (string)($input['enabled'] ?? '0') === '1' ? 1 : 0,
                WlsDatabaseProfile::schema_fields_SOURCE_CONNECTION_KEY => $sourceConnectionKey,
                WlsDatabaseProfile::schema_fields_TYPE => (string)($input['type'] ?? WlsDatabaseProfile::DRIVER_MYSQL),
                WlsDatabaseProfile::schema_fields_HOSTNAME => (string)($input['hostname'] ?? ''),
                WlsDatabaseProfile::schema_fields_HOSTPORT => (string)($input['hostport'] ?? ''),
                WlsDatabaseProfile::schema_fields_DATABASE => (string)($input['database'] ?? ''),
                WlsDatabaseProfile::schema_fields_PATH => (string)($input['path'] ?? ''),
                WlsDatabaseProfile::schema_fields_USERNAME => (string)($input['username'] ?? ''),
                WlsDatabaseProfile::schema_fields_PASSWORD_SECRET => $passwordResolution['secret'],
                WlsDatabaseProfile::schema_fields_PREFIX => (string)($input['prefix'] ?? ''),
                WlsDatabaseProfile::schema_fields_CHARSET => (string)($input['charset'] ?? ''),
                WlsDatabaseProfile::schema_fields_COLLATE => (string)($input['collate'] ?? ''),
                WlsDatabaseProfile::schema_fields_PERSISTENT => (string)($input['persistent'] ?? '0') === '1' ? 1 : 0,
                WlsDatabaseProfile::schema_fields_PRE_SQL => (string)($input['pre_sql'] ?? ''),
                WlsDatabaseProfile::schema_fields_DESCRIPTION => (string)($input['description'] ?? ''),
            ]);
            $profile->save();

            $runtimeAction = $this->normalizeRuntimeAction((string)($input['runtime_action'] ?? WlsDatabaseProfile::RUNTIME_ACTION_NONE));
            $runtimeInstance = $this->normalizeInstanceName((string)($input['runtime_instance'] ?? ''));
            $runtimeResult = $this->applyRuntimeAction($runtimeAction, $runtimeInstance);
            $profile->setData(WlsDatabaseProfile::schema_fields_LAST_RUNTIME_ACTION, $runtimeAction);
            $profile->setData(WlsDatabaseProfile::schema_fields_LAST_RUNTIME_INSTANCE, $runtimeInstance !== '' ? $runtimeInstance : null);
            $profile->setData(WlsDatabaseProfile::schema_fields_LAST_RUNTIME_MESSAGE, \mb_substr((string)($runtimeResult['message'] ?? ''), 0, 255));
            $profile->setData(WlsDatabaseProfile::schema_fields_LAST_RUNTIME_AT, \date('Y-m-d H:i:s'));
            $profile->save();

            $this->appendAudit('profile_saved', [
                'success' => true,
                'profile' => $this->auditProfileData($profile->getData()),
                'runtime_action' => $runtimeAction,
                'runtime_instance' => $runtimeInstance,
                'password_action' => $passwordResolution['action'],
                'runtime_action_success' => (bool)($runtimeResult['success'] ?? false),
                'runtime_action_message' => (string)($runtimeResult['message'] ?? ''),
            ]);

            return [
                'success' => true,
                'message' => (string)__('Project database profile saved.'),
                'profile_id' => (int)$profile->getData(WlsDatabaseProfile::schema_fields_ID),
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
                'runtime_action' => WlsDatabaseProfile::RUNTIME_ACTION_NONE,
                'runtime_action_success' => false,
                'runtime_action_message' => '',
            ];
        }
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    public function buildConnectionConfigForContext(array $context): ?array
    {
        $profile = $this->loadForContext($context);
        if (!$profile instanceof WlsDatabaseProfile) {
            return null;
        }
        if ((int)$profile->getData(WlsDatabaseProfile::schema_fields_ENABLED) !== 1) {
            return null;
        }

        return $this->buildConnectionConfigFromProfile($profile);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $sourceProfile
     * @return array<string, mixed>|null
     */
    public function buildConnectionConfigForContextWithSource(array $context, array $sourceProfile): ?array
    {
        $profile = $this->loadForContext($context);
        if (!$profile instanceof WlsDatabaseProfile) {
            return null;
        }
        if ((int)$profile->getData(WlsDatabaseProfile::schema_fields_ENABLED) !== 1) {
            return null;
        }

        return $this->buildConnectionConfigFromProfile($profile, $sourceProfile);
    }

    /**
     * @param array<string, mixed> $sourceProfile
     * @return array<string, mixed>
     */
    private function buildConnectionConfigFromProfile(WlsDatabaseProfile $profile, array $sourceProfile = []): array
    {
        $password = '';
        $secret = \trim((string)$profile->getData(WlsDatabaseProfile::schema_fields_PASSWORD_SECRET));
        if ($secret !== '') {
            $password = (string)($this->decryptSecret($secret) ?? '');
        } elseif ($sourceProfile !== []) {
            $sourceProfile = $this->normalizeConnectionConfig($sourceProfile);
            $password = (string)($sourceProfile['password'] ?? '');
        }

        return [
            'type' => (string)$profile->getData(WlsDatabaseProfile::schema_fields_TYPE),
            'hostname' => (string)$profile->getData(WlsDatabaseProfile::schema_fields_HOSTNAME),
            'hostport' => (string)$profile->getData(WlsDatabaseProfile::schema_fields_HOSTPORT),
            'database' => (string)$profile->getData(WlsDatabaseProfile::schema_fields_DATABASE),
            'path' => (string)$profile->getData(WlsDatabaseProfile::schema_fields_PATH),
            'username' => (string)$profile->getData(WlsDatabaseProfile::schema_fields_USERNAME),
            'password' => $password,
            'prefix' => (string)$profile->getData(WlsDatabaseProfile::schema_fields_PREFIX),
            'charset' => (string)$profile->getData(WlsDatabaseProfile::schema_fields_CHARSET),
            'collate' => (string)$profile->getData(WlsDatabaseProfile::schema_fields_COLLATE),
            'persistent' => (int)$profile->getData(WlsDatabaseProfile::schema_fields_PERSISTENT) === 1,
            'pre_sql' => (string)$profile->getData(WlsDatabaseProfile::schema_fields_PRE_SQL),
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    public function recordConnectionTest(array $context, bool $success, string $message): void
    {
        $profile = $this->loadForContext($context);
        if (!$profile instanceof WlsDatabaseProfile) {
            return;
        }

        $profile->setData(WlsDatabaseProfile::schema_fields_LAST_TEST_STATUS, $success ? 'passed' : 'failed');
        $profile->setData(WlsDatabaseProfile::schema_fields_LAST_TEST_MESSAGE, \mb_substr($message, 0, 255));
        $profile->setData(WlsDatabaseProfile::schema_fields_LAST_TEST_AT, \date('Y-m-d H:i:s'));
        $profile->save();

        $this->appendAudit('connection_test', [
            'success' => $success,
            'profile' => $this->auditProfileData($profile->getData()),
            'message' => \mb_substr($message, 0, 180),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function appendAuditEvent(string $event, array $payload): void
    {
        $this->appendAudit($event, $payload);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function loadForContext(array $context): ?WlsDatabaseProfile
    {
        $profileContext = $this->getProfileContext($context);
        $profileKey = $profileContext['profile_key'] !== '' ? $profileContext['profile_key'] : 'local';

        /** @var WlsDatabaseProfile $model */
        $model = ObjectManager::getInstance(WlsDatabaseProfile::class);
        $collection = $model->reset()
            ->where(WlsDatabaseProfile::schema_fields_PROFILE_KEY, $profileKey)
            ->select()
            ->pagination(1, 1)
            ->fetch();
        $items = $collection->getItems();
        $result = $items[0] ?? null;

        return $result instanceof WlsDatabaseProfile ? $result : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRecentAuditRecords(int $limit = 6): array
    {
        $path = $this->auditFilePath();
        if (!\is_file($path)) {
            return [];
        }

        $lines = \file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!\is_array($lines)) {
            return [];
        }

        $records = [];
        foreach (\array_reverse(\array_slice($lines, -max(1, $limit))) as $line) {
            $record = \json_decode($line, true);
            if (\is_array($record)) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * @param array<string, mixed> $context
     * @return array{profile_key:string,project_id:string,domain:string,project_type:string}
     */
    public function getProfileContext(array $context): array
    {
        $normalized = [
            'profile_key' => $this->normalizeToken($this->contextValue($context, 'profile_key', 'PROFILE_KEY'), 190),
            'project_id' => $this->normalizeToken($this->contextValue($context, 'project_id', 'PROJECT_ID'), 80),
            'domain' => $this->normalizeDomain($this->contextValue($context, 'domain', 'DOMAIN')),
            'project_type' => $this->normalizeToken($this->contextValue($context, 'project_type', 'PROJECT_TYPE'), 80),
        ];
        if ($normalized['profile_key'] === '') {
            $normalized['profile_key'] = WlsDatabaseProfile::buildProfileKey($normalized['project_id'], $normalized['domain']);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function normalizeConnectionConfig(array $config): array
    {
        $type = \strtolower(\trim((string)($config['type'] ?? $config['driver'] ?? '')));
        if ($type === '') {
            $type = \trim((string)($config['path'] ?? '')) !== '' ? WlsDatabaseProfile::DRIVER_SQLITE : WlsDatabaseProfile::DRIVER_MYSQL;
        }

        return [
            'type' => $type,
            'hostname' => \trim((string)($config['hostname'] ?? $config['host'] ?? '')),
            'hostport' => \trim((string)($config['hostport'] ?? $config['port'] ?? $this->defaultPort($type))),
            'database' => \trim((string)($config['database'] ?? $config['dbname'] ?? $config['name'] ?? '')),
            'path' => \trim((string)($config['path'] ?? '')),
            'username' => \trim((string)($config['username'] ?? $config['user'] ?? '')),
            'password' => (string)($config['password'] ?? ''),
            'prefix' => \trim((string)($config['prefix'] ?? '')),
            'charset' => \trim((string)($config['charset'] ?? ($type === WlsDatabaseProfile::DRIVER_MYSQL ? 'utf8mb4' : ''))),
            'collate' => \trim((string)($config['collate'] ?? '')),
            'persistent' => (bool)($config['persistent'] ?? false),
            'pre_sql' => \trim((string)($config['pre_sql'] ?? '')),
        ];
    }

    /**
     * @return array{success:bool,message:string}
     */
    private function applyRuntimeAction(string $runtimeAction, string $runtimeInstance): array
    {
        if ($runtimeAction === WlsDatabaseProfile::RUNTIME_ACTION_NONE) {
            return [
                'success' => true,
                'message' => (string)__('Runtime reload skipped.'),
            ];
        }

        if ($runtimeInstance === '') {
            return [
                'success' => false,
                'message' => (string)__('Select a target WLS instance before requesting reload.'),
            ];
        }

        $result = $this->runtimeReloadGateway->forceReloadAsync($runtimeInstance, 8.0);

        return [
            'success' => $result->success,
            'message' => $result->message,
        ];
    }

    private function normalizeRuntimeAction(string $runtimeAction): string
    {
        $runtimeAction = \strtolower(\trim($runtimeAction));
        return \in_array($runtimeAction, [WlsDatabaseProfile::RUNTIME_ACTION_NONE, WlsDatabaseProfile::RUNTIME_ACTION_RELOAD], true)
            ? $runtimeAction
            : WlsDatabaseProfile::RUNTIME_ACTION_NONE;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{secret:?string,action:string}
     */
    private function resolveStoredSecret(array $input, string $currentSecret, string $sourceConnectionKey): array
    {
        $incomingPassword = (string)($input['password'] ?? '');
        if ((string)($input['clear_password'] ?? '0') === '1') {
            return [
                'secret' => null,
                'action' => 'cleared',
            ];
        }

        if ($incomingPassword !== '') {
            return [
                'secret' => $this->encryptSecret($incomingPassword),
                'action' => 'manual',
            ];
        }

        if ((string)($input['import_env_password'] ?? '0') === '1') {
            $phrase = \trim((string)($input['import_env_password_phrase'] ?? ''));
            if ($phrase !== self::ENV_PASSWORD_IMPORT_PHRASE) {
                throw new \InvalidArgumentException((string)__('Type COPY_ENV_PASSWORD to copy the source env profile password.'));
            }

            $sourceProfile = $this->sourceProfileForKey($sourceConnectionKey);
            if ($sourceProfile === null) {
                throw new \InvalidArgumentException((string)__('Source env profile was not found.'));
            }

            $sourceConfig = $this->normalizeConnectionConfig($sourceProfile);
            $sourcePassword = (string)($sourceConfig['password'] ?? '');
            if ($sourcePassword === '') {
                throw new \InvalidArgumentException((string)__('The selected source env profile has no password to copy.'));
            }

            return [
                'secret' => $this->encryptSecret($sourcePassword),
                'action' => 'imported_env',
            ];
        }

        return [
            'secret' => $currentSecret !== '' ? $currentSecret : null,
            'action' => $currentSecret !== '' ? 'kept' : 'empty',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sourceProfileForKey(string $sourceConnectionKey): ?array
    {
        if ($sourceConnectionKey === '') {
            return null;
        }

        $profiles = $this->rawDatabaseProfiles();
        return isset($profiles[$sourceConnectionKey]) && \is_array($profiles[$sourceConnectionKey])
            ? $profiles[$sourceConnectionKey]
            : null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rawDatabaseProfiles(): array
    {
        $config = Env::getInstance()->getDbConfig();
        $profiles = [];

        if (\is_array($config['master'] ?? null)) {
            $profiles['master'] = (array)$config['master'];
        } elseif ($config !== []) {
            $profiles['default'] = $config;
        }

        $slaves = \is_array($config['slaves'] ?? null) ? (array)$config['slaves'] : [];
        $index = 1;
        foreach ($slaves as $key => $slaveConfig) {
            if (!\is_array($slaveConfig)) {
                continue;
            }
            $profileKey = \is_string($key) && \trim($key) !== ''
                ? $this->safeConnectionKey($key)
                : 'slave_' . $index;
            if ($profileKey === '' || isset($profiles[$profileKey])) {
                $profileKey = 'slave_' . $index;
            }
            $profiles[$profileKey] = $slaveConfig + ['role' => 'slave'];
            $index++;
        }

        return $profiles;
    }

    private function encryptSecret(string $secret): string
    {
        if (!\extension_loaded('openssl')) {
            throw new \RuntimeException((string)__('OpenSSL extension is required before saving database passwords.'));
        }

        $iv = \random_bytes(12);
        $tag = '';
        $encrypted = \openssl_encrypt(
            $secret,
            self::CIPHER_ALGO,
            $this->secretKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if ($encrypted === false || $tag === '') {
            throw new \RuntimeException((string)__('Database password encryption failed.'));
        }

        return self::SECRET_PREFIX . \base64_encode($iv . $tag . $encrypted);
    }

    private function decryptSecret(string $secret): ?string
    {
        if (!\str_starts_with($secret, self::SECRET_PREFIX) || !\extension_loaded('openssl')) {
            return null;
        }

        $data = \base64_decode(\substr($secret, \strlen(self::SECRET_PREFIX)), true);
        if ($data === false || \strlen($data) <= 28) {
            return null;
        }

        $iv = \substr($data, 0, 12);
        $tag = \substr($data, 12, 16);
        $cipherText = \substr($data, 28);
        $decrypted = \openssl_decrypt(
            $cipherText,
            self::CIPHER_ALGO,
            $this->secretKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return $decrypted !== false ? $decrypted : null;
    }

    private function secretKey(): string
    {
        $configured = \trim((string)Env::getInstance()->getConfig('wls.panel.secret_key', ''));
        if ($configured !== '') {
            return \hash('sha256', $configured, true);
        }

        $admin = Env::getInstance()->getConfig('admin', []);
        $adminSeed = \is_scalar($admin) ? (string)$admin : (string)\json_encode($admin);
        return \hash('sha256', 'weline_db_manager_profile:' . $adminSeed . ':' . (\defined('BP') ? BP : ''), true);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function appendAudit(string $event, array $payload): void
    {
        $record = [
            'time' => \date('c'),
            'event' => $event,
            'payload' => $payload,
        ];
        $dir = \dirname($this->auditFilePath());
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0777, true);
        }

        @\file_put_contents(
            $this->auditFilePath(),
            \json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    private function auditFilePath(): string
    {
        return (\defined('BP') ? BP : \getcwd() . DIRECTORY_SEPARATOR)
            . 'var' . DIRECTORY_SEPARATOR
            . 'log' . DIRECTORY_SEPARATOR
            . 'wls' . DIRECTORY_SEPARATOR
            . self::AUDIT_FILE;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function auditProfileData(array $data): array
    {
        return [
            'profile_id' => (int)($data[WlsDatabaseProfile::schema_fields_ID] ?? 0),
            'profile_key' => (string)($data[WlsDatabaseProfile::schema_fields_PROFILE_KEY] ?? ''),
            'project_id' => (string)($data[WlsDatabaseProfile::schema_fields_PROJECT_ID] ?? ''),
            'domain' => (string)($data[WlsDatabaseProfile::schema_fields_DOMAIN] ?? ''),
            'project_type' => (string)($data[WlsDatabaseProfile::schema_fields_PROJECT_TYPE] ?? ''),
            'enabled' => (int)($data[WlsDatabaseProfile::schema_fields_ENABLED] ?? 0) === 1,
            'source_connection_key' => (string)($data[WlsDatabaseProfile::schema_fields_SOURCE_CONNECTION_KEY] ?? ''),
            'type' => (string)($data[WlsDatabaseProfile::schema_fields_TYPE] ?? ''),
            'hostname' => (string)($data[WlsDatabaseProfile::schema_fields_HOSTNAME] ?? ''),
            'hostport' => (string)($data[WlsDatabaseProfile::schema_fields_HOSTPORT] ?? ''),
            'database' => (string)($data[WlsDatabaseProfile::schema_fields_DATABASE] ?? ''),
            'path_state' => \trim((string)($data[WlsDatabaseProfile::schema_fields_PATH] ?? '')) !== '' ? 'configured' : 'empty',
            'username' => $this->maskValue((string)($data[WlsDatabaseProfile::schema_fields_USERNAME] ?? '')),
            'password_state' => \trim((string)($data[WlsDatabaseProfile::schema_fields_PASSWORD_SECRET] ?? '')) !== '' ? 'configured' : 'empty',
        ];
    }

    private function defaultPort(string $type): string
    {
        return match ($type) {
            WlsDatabaseProfile::DRIVER_MYSQL => '3306',
            WlsDatabaseProfile::DRIVER_PGSQL => '5432',
            default => '',
        };
    }

    private function maskValue(string $value): string
    {
        $value = \trim($value);
        $length = \strlen($value);
        if ($value === '') {
            return '';
        }
        if ($length <= 2) {
            return \str_repeat('*', $length);
        }

        return \substr($value, 0, 1) . \str_repeat('*', \max(1, $length - 2)) . \substr($value, -1);
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = \strtolower(\trim($domain));
        $domain = \preg_replace('#^https?://#i', '', $domain) ?? $domain;
        $domain = \explode('/', $domain, 2)[0] ?? $domain;
        return \trim($domain);
    }

    private function normalizeToken(string $value, int $maxLength): string
    {
        $value = \trim($value);
        $value = \preg_replace('/[^a-zA-Z0-9:_\-.]/', '', $value) ?? '';
        return \substr($value, 0, $maxLength);
    }

    private function normalizeInstanceName(string $instanceName): string
    {
        $instanceName = \trim($instanceName);
        $instanceName = \preg_replace('/[^a-zA-Z0-9_.-]/', '', $instanceName) ?? '';
        return \mb_substr($instanceName, 0, 120);
    }

    private function safeConnectionKey(string $key): string
    {
        $key = \strtolower(\trim($key));
        $key = \preg_replace('/[^a-z0-9_.-]+/', '_', $key) ?? '';
        return \trim($key, '_.-');
    }

    /**
     * @param array<string, mixed> $context
     */
    private function contextValue(array $context, string $lowerKey, string $upperKey): string
    {
        $value = $context[$lowerKey] ?? $context[$upperKey] ?? '';
        return \is_scalar($value) ? \trim((string)$value) : '';
    }
}
