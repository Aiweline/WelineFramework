<?php
declare(strict_types=1);

namespace Weline\Server\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'WLS panel managed project registry')]
#[Index(name: 'uk_wls_panel_project_domain', columns: ['domain'], type: 'UNIQUE')]
#[Index(name: 'idx_wls_panel_project_status', columns: ['status'])]
#[Index(name: 'idx_wls_panel_project_gateway_proxy', columns: ['gateway_proxy_id'])]
class WlsPanelProject extends Model
{
    public const schema_table = 'weline_server_panel_project';
    public const schema_primary_key = 'project_id';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Project ID')]
    public const schema_fields_ID = 'project_id';

    #[Col('varchar', 160, nullable: false, comment: 'Project display name')]
    public const schema_fields_NAME = 'name';

    #[Col('varchar', 255, nullable: false, comment: 'Public domain managed by the panel')]
    public const schema_fields_DOMAIN = 'domain';

    #[Col('varchar', 500, nullable: true, comment: 'Project admin URL')]
    public const schema_fields_ADMIN_URL = 'admin_url';

    #[Col('varchar', 500, nullable: true, comment: 'Child WLS panel URL')]
    public const schema_fields_PANEL_URL = 'panel_url';

    #[Col('varchar', 500, nullable: true, comment: 'Project root path')]
    public const schema_fields_PROJECT_PATH = 'project_path';

    #[Col('varchar', 120, nullable: true, comment: 'PHP runtime profile')]
    public const schema_fields_PHP_PROFILE = 'php_profile';

    #[Col('varchar', 120, nullable: true, comment: 'Database profile')]
    public const schema_fields_DATABASE_PROFILE = 'database_profile';

    #[Col('tinyint', 1, nullable: false, default: 0, comment: 'Whether gateway routing is enabled')]
    public const schema_fields_GATEWAY_ENABLED = 'gateway_enabled';

    #[Col('varchar', 255, nullable: true, comment: 'Gateway backend host')]
    public const schema_fields_BACKEND_HOST = 'backend_host';

    #[Col('int', 11, nullable: false, default: 0, comment: 'Gateway backend port')]
    public const schema_fields_BACKEND_PORT = 'backend_port';

    #[Col('tinyint', 1, nullable: false, default: 0, comment: 'Gateway backend SSL')]
    public const schema_fields_BACKEND_SSL = 'backend_ssl';

    #[Col('int', 11, nullable: false, default: 0, comment: 'Linked reverse proxy ID')]
    public const schema_fields_GATEWAY_PROXY_ID = 'gateway_proxy_id';

    #[Col('varchar', 20, nullable: false, default: self::STATUS_ACTIVE, comment: 'Project status')]
    public const schema_fields_STATUS = 'status';

    #[Col('text', nullable: true, comment: 'Project note')]
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

        $name = \trim((string)$this->getData(self::schema_fields_NAME));
        if ($name === '') {
            throw new \InvalidArgumentException((string)__('Project name is required.'));
        }
        $this->setData(self::schema_fields_NAME, $name);

        $domain = $this->normalizeDomain((string)$this->getData(self::schema_fields_DOMAIN));
        if ($domain === '' || !$this->validateDomain($domain)) {
            throw new \InvalidArgumentException((string)__('Project domain is invalid.'));
        }
        $this->setData(self::schema_fields_DOMAIN, $domain);

        $status = \trim((string)$this->getData(self::schema_fields_STATUS));
        if (!\in_array($status, [self::STATUS_ACTIVE, self::STATUS_INACTIVE], true)) {
            $status = self::STATUS_ACTIVE;
        }
        $this->setData(self::schema_fields_STATUS, $status);

        $gatewayEnabled = (int)$this->getData(self::schema_fields_GATEWAY_ENABLED) === 1 ? 1 : 0;
        $this->setData(self::schema_fields_GATEWAY_ENABLED, $gatewayEnabled);
        $this->setData(self::schema_fields_BACKEND_SSL, (int)$this->getData(self::schema_fields_BACKEND_SSL) === 1 ? 1 : 0);

        $backendHost = \trim((string)$this->getData(self::schema_fields_BACKEND_HOST));
        $backendPort = (int)$this->getData(self::schema_fields_BACKEND_PORT);
        if ($gatewayEnabled === 1) {
            if ($backendHost === '') {
                throw new \InvalidArgumentException((string)__('Gateway backend host is required.'));
            }
            if ($backendPort < 1 || $backendPort > 65535) {
                throw new \InvalidArgumentException((string)__('Gateway backend port must be between 1 and 65535.'));
            }
        } elseif ($backendPort < 0 || $backendPort > 65535) {
            throw new \InvalidArgumentException((string)__('Gateway backend port must be between 1 and 65535.'));
        }
        $this->setData(self::schema_fields_BACKEND_HOST, $backendHost !== '' ? $backendHost : null);
        $this->setData(self::schema_fields_BACKEND_PORT, $backendPort);

        foreach ([self::schema_fields_ADMIN_URL, self::schema_fields_PANEL_URL] as $urlField) {
            $url = \trim((string)$this->getData($urlField));
            if ($url !== '' && !$this->validateHttpUrl($url)) {
                throw new \InvalidArgumentException((string)__('Project URL must start with http:// or https://.'));
            }
            $this->setData($urlField, $url !== '' ? $url : null);
        }

        foreach ([self::schema_fields_PROJECT_PATH, self::schema_fields_PHP_PROFILE, self::schema_fields_DATABASE_PROFILE] as $textField) {
            $value = \trim((string)$this->getData($textField));
            $this->setData($textField, $value !== '' ? $value : null);
        }
    }

    public function loadByDomain(string $domain): self
    {
        return $this->clearQuery()
            ->where(self::schema_fields_DOMAIN, $this->normalizeDomain($domain))
            ->find()
            ->fetch();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAllProjects(): array
    {
        return $this->clearQuery()
            ->order(self::schema_fields_UPDATED_AT, 'DESC')
            ->order(self::schema_fields_DOMAIN)
            ->select()
            ->fetchArray();
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = \strtolower(\trim($domain));
        $domain = \preg_replace('#^https?://#i', '', $domain) ?? $domain;
        $domain = \explode('/', $domain, 2)[0] ?? $domain;
        return \trim($domain);
    }

    private function validateDomain(string $domain): bool
    {
        if (\str_starts_with($domain, '*.')) {
            $domain = \substr($domain, 2);
        }

        return \preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/i', $domain) === 1;
    }

    private function validateHttpUrl(string $url): bool
    {
        if (!\str_starts_with($url, 'http://') && !\str_starts_with($url, 'https://')) {
            return false;
        }

        return \filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
