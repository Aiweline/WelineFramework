<?php
declare(strict_types=1);

namespace Weline\PhpManager\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'WLS panel project PHP profile')]
#[Index(name: 'uk_wls_php_manager_profile_key', columns: ['profile_key'], type: 'UNIQUE')]
#[Index(name: 'idx_wls_php_manager_project', columns: ['project_id'])]
#[Index(name: 'idx_wls_php_manager_domain', columns: ['domain'])]
#[Index(name: 'idx_wls_php_manager_enabled', columns: ['enabled'])]
class WlsPhpProfile extends Model
{
    public const schema_table = 'w_php_manager_project_profile';
    public const schema_primary_key = 'profile_id';

    public const RUNTIME_ACTION_NONE = 'none';
    public const RUNTIME_ACTION_RELOAD = 'reload';

    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Profile ID')]
    public const schema_fields_ID = 'profile_id';

    #[Col('varchar', 190, nullable: false, comment: 'Stable WLS PHP profile key')]
    public const schema_fields_PROFILE_KEY = 'profile_key';

    #[Col('varchar', 80, nullable: true, comment: 'WLS managed project ID')]
    public const schema_fields_PROJECT_ID = 'project_id';

    #[Col('varchar', 255, nullable: true, comment: 'Project domain')]
    public const schema_fields_DOMAIN = 'domain';

    #[Col('varchar', 80, nullable: true, comment: 'Project type')]
    public const schema_fields_PROJECT_TYPE = 'project_type';

    #[Col('tinyint', 1, nullable: false, default: 1, comment: 'Profile enabled')]
    public const schema_fields_ENABLED = 'enabled';

    #[Col('varchar', 255, nullable: true, comment: 'PHP binary path or command')]
    public const schema_fields_PHP_BINARY = 'php_binary';

    #[Col('varchar', 500, nullable: true, comment: 'PHP ini path')]
    public const schema_fields_PHP_INI_PATH = 'php_ini_path';

    #[Col('varchar', 40, nullable: true, comment: 'memory_limit value')]
    public const schema_fields_MEMORY_LIMIT = 'memory_limit';

    #[Col('int', 11, nullable: true, comment: 'max_execution_time seconds')]
    public const schema_fields_MAX_EXECUTION_TIME = 'max_execution_time';

    #[Col('varchar', 40, nullable: true, comment: 'upload_max_filesize value')]
    public const schema_fields_UPLOAD_MAX_FILESIZE = 'upload_max_filesize';

    #[Col('varchar', 40, nullable: true, comment: 'post_max_size value')]
    public const schema_fields_POST_MAX_SIZE = 'post_max_size';

    #[Col('varchar', 80, nullable: true, comment: 'PHP timezone')]
    public const schema_fields_TIMEZONE = 'timezone';

    #[Col('text', nullable: true, comment: 'Required PHP extensions')]
    public const schema_fields_REQUIRED_EXTENSIONS = 'required_extensions';

    #[Col('text', nullable: true, comment: 'Disabled PHP functions')]
    public const schema_fields_DISABLED_FUNCTIONS = 'disabled_functions';

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
        $this->setData(self::schema_fields_PHP_BINARY, $this->pathText(self::schema_fields_PHP_BINARY, 255));
        $this->setData(self::schema_fields_PHP_INI_PATH, $this->pathText(self::schema_fields_PHP_INI_PATH, 500));
        $this->setData(self::schema_fields_MEMORY_LIMIT, $this->iniSizeText(self::schema_fields_MEMORY_LIMIT));
        $this->setData(self::schema_fields_MAX_EXECUTION_TIME, $this->nullableSeconds(self::schema_fields_MAX_EXECUTION_TIME));
        $this->setData(self::schema_fields_UPLOAD_MAX_FILESIZE, $this->iniSizeText(self::schema_fields_UPLOAD_MAX_FILESIZE));
        $this->setData(self::schema_fields_POST_MAX_SIZE, $this->iniSizeText(self::schema_fields_POST_MAX_SIZE));
        $this->setData(self::schema_fields_TIMEZONE, $this->timezoneText(self::schema_fields_TIMEZONE));
        $this->setData(self::schema_fields_REQUIRED_EXTENSIONS, $this->csvText(self::schema_fields_REQUIRED_EXTENSIONS, 2000));
        $this->setData(self::schema_fields_DISABLED_FUNCTIONS, $this->csvText(self::schema_fields_DISABLED_FUNCTIONS, 2000));

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

    private function pathText(string $field, int $maxLength): ?string
    {
        $value = $this->singleLineText($field, $maxLength);
        if ($value === null) {
            return null;
        }
        if (\preg_match('/[<>|;&`$]/', $value) === 1) {
            throw new \InvalidArgumentException((string)__('PHP path fields contain unsafe characters.'));
        }

        return $value;
    }

    private function iniSizeText(string $field): ?string
    {
        $value = \strtoupper(\trim((string)$this->getData($field)));
        if ($value === '') {
            return null;
        }
        if ($value !== '-1' && \preg_match('/^\d+(K|M|G)?$/', $value) !== 1) {
            throw new \InvalidArgumentException((string)__('PHP size values must use numbers with optional K, M, or G suffix.'));
        }

        return $value;
    }

    private function nullableSeconds(string $field): ?int
    {
        $value = \trim((string)$this->getData($field));
        if ($value === '') {
            return null;
        }
        $seconds = (int)$value;
        if ($seconds < 0 || $seconds > 86400) {
            throw new \InvalidArgumentException((string)__('PHP max execution time must be between 0 and 86400 seconds.'));
        }

        return $seconds;
    }

    private function timezoneText(string $field): ?string
    {
        $value = \trim((string)$this->getData($field));
        if ($value === '') {
            return null;
        }
        if (\preg_match('/^[a-zA-Z0-9_+\-\/]+$/', $value) !== 1) {
            throw new \InvalidArgumentException((string)__('PHP timezone contains unsafe characters.'));
        }

        return \mb_substr($value, 0, 80);
    }

    private function csvText(string $field, int $maxLength): ?string
    {
        $value = \str_replace(["\r", "\n", "\t"], ',', (string)$this->getData($field));
        $parts = [];
        foreach (\explode(',', $value) as $part) {
            $part = \trim($part);
            if ($part === '') {
                continue;
            }
            if (\preg_match('/^[a-zA-Z0-9_\-.]+$/', $part) !== 1) {
                throw new \InvalidArgumentException((string)__('PHP list values may contain only letters, numbers, dot, dash, or underscore.'));
            }
            $parts[\strtolower($part)] = $part;
        }
        if ($parts === []) {
            return null;
        }

        return \mb_substr(\implode(', ', \array_values($parts)), 0, $maxLength);
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
}
