<?php

declare(strict_types=1);

namespace Weline\Deploy\Model;

use Weline\Deploy\Service\DeployConfigService;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'WLS project deploy profile')]
#[Index(name: 'uk_wls_deploy_profile_key', columns: ['profile_key'], type: 'UNIQUE')]
#[Index(name: 'idx_wls_deploy_profile_project', columns: ['project_id'])]
#[Index(name: 'idx_wls_deploy_profile_domain', columns: ['domain'])]
#[Index(name: 'idx_wls_deploy_profile_enabled', columns: ['enabled'])]
class DeployProjectProfile extends Model
{
    public const schema_table = 'w_deploy_project_profile';
    public const schema_primary_key = 'profile_id';

    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Profile ID')]
    public const schema_fields_ID = 'profile_id';

    #[Col('varchar', 190, nullable: false, comment: 'Stable WLS profile key')]
    public const schema_fields_PROFILE_KEY = 'profile_key';

    #[Col('varchar', 80, nullable: true, comment: 'WLS managed project ID')]
    public const schema_fields_PROJECT_ID = 'project_id';

    #[Col('varchar', 255, nullable: true, comment: 'Project domain')]
    public const schema_fields_DOMAIN = 'domain';

    #[Col('varchar', 80, nullable: true, comment: 'Project type')]
    public const schema_fields_PROJECT_TYPE = 'project_type';

    #[Col('tinyint', 1, nullable: false, default: 1, comment: 'Profile enabled')]
    public const schema_fields_ENABLED = 'enabled';

    #[Col('varchar', 500, nullable: true, comment: 'Project repository URL')]
    public const schema_fields_REPO_URL = 'project_repo_url';

    #[Col('varchar', 120, nullable: true, comment: 'Project branch')]
    public const schema_fields_BRANCH = 'project_branch';

    #[Col('varchar', 80, nullable: false, default: 'origin', comment: 'Git remote name')]
    public const schema_fields_REMOTE = 'project_remote';

    #[Col('varchar', 500, nullable: true, comment: 'Deploy root path')]
    public const schema_fields_DEPLOY_ROOT = 'deploy_root';

    #[Col('varchar', 20, nullable: false, default: DeployConfigService::TRIGGER_MODE_TAG, comment: 'Deploy trigger mode')]
    public const schema_fields_TRIGGER_MODE = 'deploy_trigger_mode';

    #[Col('varchar', 120, nullable: true, comment: 'Webhook branch filter')]
    public const schema_fields_WEBHOOK_BRANCH = 'webhook_branch';

    #[Col('varchar', 120, nullable: true, comment: 'Webhook tag prefix')]
    public const schema_fields_WEBHOOK_TAG_PREFIX = 'webhook_tag_prefix';

    #[Col('varchar', 255, nullable: true, comment: 'Project webhook secret')]
    public const schema_fields_WEBHOOK_SECRET = 'webhook_secret';

    #[Col('varchar', 40, nullable: false, default: 'reset', comment: 'Git update mode')]
    public const schema_fields_GIT_UPDATE_MODE = 'git_update_mode';

    #[Col('tinyint', 1, nullable: false, default: 1, comment: 'Backup before deploy')]
    public const schema_fields_BACKUP_BEFORE_DEPLOY = 'backup_before_deploy';

    #[Col('tinyint', 1, nullable: false, default: 0, comment: 'Run composer install')]
    public const schema_fields_RUN_COMPOSER_INSTALL = 'run_composer_install';

    #[Col('varchar', 500, nullable: true, comment: 'Composer command')]
    public const schema_fields_COMPOSER_COMMAND = 'composer_command';

    #[Col('varchar', 500, nullable: true, comment: 'Post deploy command')]
    public const schema_fields_POST_DEPLOY_COMMAND = 'post_deploy_command';

    #[Col('varchar', 255, nullable: true, comment: 'Rollback ref or note')]
    public const schema_fields_ROLLBACK_REF = 'rollback_ref';

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
        $this->setData(self::schema_fields_PROJECT_TYPE, $this->nullableText(self::schema_fields_PROJECT_TYPE, 80));
        $this->setData(self::schema_fields_ENABLED, (int)$this->getData(self::schema_fields_ENABLED) === 1 ? 1 : 0);

        $repoUrl = \trim((string)$this->getData(self::schema_fields_REPO_URL));
        if ($repoUrl !== '' && !$this->isSafeRepositoryLocator($repoUrl)) {
            throw new \InvalidArgumentException((string)__('项目仓库地址不合法。'));
        }
        $this->setData(self::schema_fields_REPO_URL, $repoUrl !== '' ? $repoUrl : null);

        $this->setData(self::schema_fields_BRANCH, $this->nullableText(self::schema_fields_BRANCH, 120));
        $remote = $this->normalizeToken((string)$this->getData(self::schema_fields_REMOTE), 80);
        $this->setData(self::schema_fields_REMOTE, $remote !== '' ? $remote : 'origin');
        $this->setData(self::schema_fields_DEPLOY_ROOT, $this->nullableText(self::schema_fields_DEPLOY_ROOT, 500));

        $triggerMode = \trim((string)$this->getData(self::schema_fields_TRIGGER_MODE));
        if (!\in_array($triggerMode, DeployConfigService::TRIGGER_MODES, true)) {
            $triggerMode = DeployConfigService::TRIGGER_MODE_TAG;
        }
        $this->setData(self::schema_fields_TRIGGER_MODE, $triggerMode);

        $this->setData(self::schema_fields_WEBHOOK_BRANCH, $this->nullableText(self::schema_fields_WEBHOOK_BRANCH, 120));
        $this->setData(self::schema_fields_WEBHOOK_TAG_PREFIX, $this->nullableText(self::schema_fields_WEBHOOK_TAG_PREFIX, 120));
        $this->setData(self::schema_fields_WEBHOOK_SECRET, $this->secretText(self::schema_fields_WEBHOOK_SECRET, 255));

        $gitUpdateMode = \trim((string)$this->getData(self::schema_fields_GIT_UPDATE_MODE));
        if (!\in_array($gitUpdateMode, ['reset', 'pull_ff_only'], true)) {
            $gitUpdateMode = 'reset';
        }
        $this->setData(self::schema_fields_GIT_UPDATE_MODE, $gitUpdateMode);
        $this->setData(self::schema_fields_BACKUP_BEFORE_DEPLOY, (int)$this->getData(self::schema_fields_BACKUP_BEFORE_DEPLOY) === 0 ? 0 : 1);
        $this->setData(self::schema_fields_RUN_COMPOSER_INSTALL, (int)$this->getData(self::schema_fields_RUN_COMPOSER_INSTALL) === 1 ? 1 : 0);
        $this->setData(self::schema_fields_COMPOSER_COMMAND, $this->singleLineText(self::schema_fields_COMPOSER_COMMAND, 500));
        $this->setData(self::schema_fields_POST_DEPLOY_COMMAND, $this->singleLineText(self::schema_fields_POST_DEPLOY_COMMAND, 500));
        $this->setData(self::schema_fields_ROLLBACK_REF, $this->singleLineText(self::schema_fields_ROLLBACK_REF, 255));
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
            throw new \InvalidArgumentException((string)__('Webhook 密钥包含不允许的控制字符。'));
        }

        return \mb_substr($value, 0, $maxLength);
    }

    private function isSafeRepositoryLocator(string $repoUrl): bool
    {
        if (\preg_match('#^(https?|ssh|git)://#i', $repoUrl) === 1) {
            return true;
        }

        if (\preg_match('/^[a-zA-Z0-9_.-]+@[a-zA-Z0-9_.-]+:[^\s]+$/', $repoUrl) === 1) {
            return true;
        }

        return \preg_match('#^([a-zA-Z]:)?[\\\\/]#', $repoUrl) === 1;
    }
}
