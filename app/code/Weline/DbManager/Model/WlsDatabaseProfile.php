<?php
declare(strict_types=1);

namespace Weline\DbManager\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'WLS panel project database profile')]
#[Index(name: 'uk_wls_db_manager_profile_key', columns: ['profile_key'], type: 'UNIQUE')]
#[Index(name: 'idx_wls_db_manager_project', columns: ['project_id'])]
#[Index(name: 'idx_wls_db_manager_domain', columns: ['domain'])]
#[Index(name: 'idx_wls_db_manager_enabled', columns: ['enabled'])]
class WlsDatabaseProfile extends Model
{
    public const schema_table = 'w_db_manager_project_profile';
    public const schema_primary_key = 'profile_id';

    public const DRIVER_MYSQL = 'mysql';
    public const DRIVER_PGSQL = 'pgsql';
    public const DRIVER_SQLITE = 'sqlite';
    public const RUNTIME_ACTION_NONE = 'none';
    public const RUNTIME_ACTION_RELOAD = 'reload';

    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Profile ID')]
    public const schema_fields_ID = 'profile_id';

    #[Col('varchar', 190, nullable: false, comment: 'Stable WLS database profile key')]
    public const schema_fields_PROFILE_KEY = 'profile_key';

    #[Col('varchar', 80, nullable: true, comment: 'WLS managed project ID')]
    public const schema_fields_PROJECT_ID = 'project_id';

    #[Col('varchar', 255, nullable: true, comment: 'Project domain')]
    public const schema_fields_DOMAIN = 'domain';

    #[Col('varchar', 80, nullable: true, comment: 'Project type')]
    public const schema_fields_PROJECT_TYPE = 'project_type';

    #[Col('tinyint', 1, nullable: false, default: 1, comment: 'Profile enabled')]
    public const schema_fields_ENABLED = 'enabled';

    #[Col('varchar', 80, nullable: true, comment: 'Source env connection key')]
    public const schema_fields_SOURCE_CONNECTION_KEY = 'source_connection_key';

    #[Col('varchar', 16, nullable: false, default: self::DRIVER_MYSQL, comment: 'Database driver')]
    public const schema_fields_TYPE = 'type';

    #[Col('varchar', 255, nullable: true, comment: 'Database host')]
    public const schema_fields_HOSTNAME = 'hostname';

    #[Col('varchar', 10, nullable: true, comment: 'Database port')]
    public const schema_fields_HOSTPORT = 'hostport';

    #[Col('varchar', 255, nullable: true, comment: 'Database name')]
    public const schema_fields_DATABASE = 'database';

    #[Col('varchar', 500, nullable: true, comment: 'SQLite database path')]
    public const schema_fields_PATH = 'path';

    #[Col('varchar', 190, nullable: true, comment: 'Database username')]
    public const schema_fields_USERNAME = 'username';

    #[Col('text', nullable: true, comment: 'Encrypted database password')]
    public const schema_fields_PASSWORD_SECRET = 'password_secret';

    #[Col('varchar', 80, nullable: true, comment: 'Table prefix')]
    public const schema_fields_PREFIX = 'prefix';

    #[Col('varchar', 40, nullable: true, comment: 'Database charset')]
    public const schema_fields_CHARSET = 'charset';

    #[Col('varchar', 80, nullable: true, comment: 'Database collation')]
    public const schema_fields_COLLATE = 'collate';

    #[Col('tinyint', 1, nullable: false, default: 0, comment: 'Persistent PDO connection')]
    public const schema_fields_PERSISTENT = 'persistent';

    #[Col('text', nullable: true, comment: 'Connection pre SQL')]
    public const schema_fields_PRE_SQL = 'pre_sql';

    #[Col('varchar', 20, nullable: true, comment: 'Last connection test status')]
    public const schema_fields_LAST_TEST_STATUS = 'last_test_status';

    #[Col('varchar', 255, nullable: true, comment: 'Last connection test message')]
    public const schema_fields_LAST_TEST_MESSAGE = 'last_test_message';

    #[Col('datetime', nullable: true, comment: 'Last connection test time')]
    public const schema_fields_LAST_TEST_AT = 'last_test_at';

    #[Col('varchar', 20, nullable: false, default: self::RUNTIME_ACTION_NONE, comment: 'Last runtime action')]
    public const schema_fields_LAST_RUNTIME_ACTION = 'last_runtime_action';

    #[Col('varchar', 120, nullable: true, comment: 'Last runtime target instance')]
    public const schema_fields_LAST_RUNTIME_INSTANCE = 'last_runtime_instance';

    #[Col('varchar', 255, nullable: true, comment: 'Last runtime action message')]
    public const schema_fields_LAST_RUNTIME_MESSAGE = 'last_runtime_message';

    #[Col('datetime', nullable: true, comment: 'Last runtime action time')]
    public const schema_fields_LAST_RUNTIME_AT = 'last_runtime_at';

    #[Col('text', nullable: true, comment: 'Profile note')]
    public const schema_fields_DESCRIPTION = 'description';

    #[Col('datetime', nullable: false, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: false, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function save_before(): void
    {
        parent::save_before();

        $now = \date('Y-m-d H:i:s');
        $this->setData(self::schema_fields_UPDATED_AT, $now);
        if (!$this->getData(self::schema_fields_ID)) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }

        $projectId = $this->normalizeToken((string)$this->getData(self::schema_fields_PROJECT_ID), 80);
        $domain = $this->normalizeDomain((string)$this->getData(self::schema_fields_DOMAIN));
        $profileKey = $this->normalizeToken((string)$this->getData(self::schema_fields_PROFILE_KEY), 190);
        if ($profileKey === '') {
            $profileKey = self::buildProfileKey($projectId, $domain);
        }
        if ($profileKey === '') {
            $profileKey = 'local';
        }

        $this->setData(self::schema_fields_PROFILE_KEY, $profileKey);
        $this->setData(self::schema_fields_PROJECT_ID, $projectId !== '' ? $projectId : null);
        $this->setData(self::schema_fields_DOMAIN, $domain !== '' ? $domain : null);
        $this->setData(self::schema_fields_PROJECT_TYPE, $this->nullableToken(self::schema_fields_PROJECT_TYPE, 80));
        $this->setData(self::schema_fields_ENABLED, (int)$this->getData(self::schema_fields_ENABLED) === 1 ? 1 : 0);
        $this->setData(self::schema_fields_SOURCE_CONNECTION_KEY, $this->nullableToken(self::schema_fields_SOURCE_CONNECTION_KEY, 80));

        $driver = \strtolower(\trim((string)$this->getData(self::schema_fields_TYPE)));
        if (!\in_array($driver, [self::DRIVER_MYSQL, self::DRIVER_PGSQL, self::DRIVER_SQLITE], true)) {
            $driver = self::DRIVER_MYSQL;
        }
        $this->setData(self::schema_fields_TYPE, $driver);

        $hostname = $this->singleLineText(self::schema_fields_HOSTNAME, 255);
        $hostport = $this->normalizePort((string)$this->getData(self::schema_fields_HOSTPORT), $driver);
        $database = $this->singleLineText(self::schema_fields_DATABASE, 255);
        $path = $this->singleLineText(self::schema_fields_PATH, 500);
        $username = $this->singleLineText(self::schema_fields_USERNAME, 190);

        if ((int)$this->getData(self::schema_fields_ENABLED) === 1) {
            if ($driver === self::DRIVER_SQLITE) {
                if ($path === null) {
                    throw new \InvalidArgumentException((string)__('SQLite path is required.'));
                }
            } elseif ($hostname === null || $database === null || $username === null) {
                throw new \InvalidArgumentException((string)__('Database host, name, and username are required.'));
            }
        }

        $this->setData(self::schema_fields_HOSTNAME, $hostname);
        $this->setData(self::schema_fields_HOSTPORT, $hostport);
        $this->setData(self::schema_fields_DATABASE, $database);
        $this->setData(self::schema_fields_PATH, $path);
        $this->setData(self::schema_fields_USERNAME, $username);
        $this->setData(self::schema_fields_PASSWORD_SECRET, $this->secretText(self::schema_fields_PASSWORD_SECRET, 4000));
        $this->setData(self::schema_fields_PREFIX, $this->nullableToken(self::schema_fields_PREFIX, 80));
        $this->setData(self::schema_fields_CHARSET, $this->nullableToken(self::schema_fields_CHARSET, 40));
        $this->setData(self::schema_fields_COLLATE, $this->nullableToken(self::schema_fields_COLLATE, 80));
        $this->setData(self::schema_fields_PERSISTENT, (int)$this->getData(self::schema_fields_PERSISTENT) === 1 ? 1 : 0);
        $this->setData(self::schema_fields_PRE_SQL, $this->singleLineText(self::schema_fields_PRE_SQL, 2000));
        $this->setData(self::schema_fields_LAST_TEST_STATUS, $this->nullableToken(self::schema_fields_LAST_TEST_STATUS, 20));
        $this->setData(self::schema_fields_LAST_TEST_MESSAGE, $this->singleLineText(self::schema_fields_LAST_TEST_MESSAGE, 255));

        $runtimeAction = \strtolower(\trim((string)$this->getData(self::schema_fields_LAST_RUNTIME_ACTION)));
        if (!\in_array($runtimeAction, [self::RUNTIME_ACTION_NONE, self::RUNTIME_ACTION_RELOAD], true)) {
            $runtimeAction = self::RUNTIME_ACTION_NONE;
        }
        $this->setData(self::schema_fields_LAST_RUNTIME_ACTION, $runtimeAction);
        $this->setData(self::schema_fields_LAST_RUNTIME_INSTANCE, $this->nullableToken(self::schema_fields_LAST_RUNTIME_INSTANCE, 120));
        $this->setData(self::schema_fields_LAST_RUNTIME_MESSAGE, $this->singleLineText(self::schema_fields_LAST_RUNTIME_MESSAGE, 255));
        $this->setData(self::schema_fields_DESCRIPTION, $this->nullableText(self::schema_fields_DESCRIPTION, 2000));
    }

    public static function buildProfileKey(string $projectId, string $domain): string
    {
        $projectId = \trim($projectId);
        if ($projectId !== '') {
            return 'project:' . $projectId;
        }

        $domain = \trim($domain);
        return $domain !== '' ? 'domain:' . $domain : '';
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

    private function nullableToken(string $field, int $maxLength): ?string
    {
        $value = $this->normalizeToken((string)$this->getData($field), $maxLength);
        return $value !== '' ? $value : null;
    }

    private function nullableText(string $field, int $maxLength): ?string
    {
        $value = \trim((string)$this->getData($field));
        if ($value === '') {
            return null;
        }

        return \mb_substr($value, 0, $maxLength);
    }

    private function singleLineText(string $field, int $maxLength): ?string
    {
        $value = \trim((string)$this->getData($field));
        $value = \str_replace(["\r", "\n"], ' ', $value);
        $value = \preg_replace('/\s+/', ' ', $value) ?? $value;
        if ($value === '') {
            return null;
        }

        return \mb_substr($value, 0, $maxLength);
    }

    private function secretText(string $field, int $maxLength): ?string
    {
        $value = \trim((string)$this->getData($field));
        if ($value === '') {
            return null;
        }
        if (\preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new \InvalidArgumentException((string)__('Secret fields cannot contain control characters.'));
        }

        return \mb_substr($value, 0, $maxLength);
    }

    private function normalizePort(string $value, string $driver): ?string
    {
        $value = \trim($value);
        if ($value === '') {
            return $driver === self::DRIVER_MYSQL ? '3306' : ($driver === self::DRIVER_PGSQL ? '5432' : null);
        }

        $port = (int)$value;
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException((string)__('Database port must be between 1 and 65535.'));
        }

        return (string)$port;
    }
}
