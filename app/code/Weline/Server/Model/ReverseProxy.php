<?php
declare(strict_types=1);

/**
 * Weline Server - 反向代理配置模型
 *
 * 存储 WLS Gateway 的路由规则
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 反向代理配置模型 - 存储域名路由规则
 */
#[Table(comment: '反向代理配置表')]
#[Index(name: 'uk_domain', columns: ['domain'], type: 'UNIQUE')]
#[Index(name: 'idx_status', columns: ['status'])]
#[Index(name: 'idx_priority', columns: ['priority'])]
class ReverseProxy extends Model
{
    public const schema_table = 'weline_server_reverse_proxy';
    public const schema_primary_key = 'proxy_id';

    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: '代理规则ID')]
    public const schema_fields_ID = 'proxy_id';

    #[Col('varchar', 255, nullable: false, comment: '域名（支持通配符 *.example.com）')]
    public const schema_fields_DOMAIN = 'domain';

    #[Col('varchar', 255, nullable: false, comment: '后端主机地址')]
    public const schema_fields_BACKEND_HOST = 'backend_host';

    #[Col('int', 11, nullable: false, comment: '后端端口')]
    public const schema_fields_BACKEND_PORT = 'backend_port';

    #[Col('tinyint', 1, nullable: false, default: 1, comment: '后端是否使用SSL')]
    public const schema_fields_BACKEND_SSL = 'backend_ssl';

    #[Col('int', 11, nullable: false, default: 0, comment: '优先级（数字越大优先级越高）')]
    public const schema_fields_PRIORITY = 'priority';

    #[Col('varchar', 20, nullable: false, default: 'active', comment: '状态：active/inactive')]
    public const schema_fields_STATUS = 'status';

    #[Col('text', nullable: true, comment: '规则描述')]
    public const schema_fields_DESCRIPTION = 'description';

    #[Col('datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: false, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    // 状态常量
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    /**
     * 保存前自动更新时间戳，并验证域名格式
     */
    public function save_before(): void
    {
        parent::save_before();

        $now = \date('Y-m-d H:i:s');
        $this->setData(self::schema_fields_UPDATED_AT, $now);

        if (!$this->getData(self::schema_fields_ID)) {
            $this->setData(self::schema_fields_CREATED_AT, $now);

            // 验证域名不能为空
            $domain = \strtolower(\trim((string) $this->getData(self::schema_fields_DOMAIN)));
            if ($domain === '') {
                throw new \InvalidArgumentException(
                    __('ReverseProxy 新建记录时 domain 不能为空')
                );
            }

            // 验证域名格式
            if (!$this->validateDomain($domain)) {
                throw new \InvalidArgumentException(
                    __('域名格式无效: %{1}', [$domain])
                );
            }
        }

        // 验证端口范围
        $port = (int) $this->getData(self::schema_fields_BACKEND_PORT);
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException(
                __('端口必须在 1-65535 范围内')
            );
        }
    }

    /**
     * 验证域名格式
     *
     * @param string $domain 域名
     * @return bool
     */
    private function validateDomain(string $domain): bool
    {
        // 支持通配符 *.example.com
        if (\str_starts_with($domain, '*.')) {
            $domain = \substr($domain, 2);
        }

        // 基本域名格式验证
        return \preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/i', $domain) === 1;
    }

    /**
     * 根据域名加载
     *
     * @param string $domain 域名
     * @return self
     */
    public function loadByDomain(string $domain): self
    {
        return $this->clearQuery()
            ->where(self::schema_fields_DOMAIN, $domain)
            ->find()
            ->fetch();
    }

    /**
     * 获取所有启用的规则（按优先级排序）
     *
     * @return array
     */
    public function getActiveRules(): array
    {
        return $this->clearQuery()
            ->where(self::schema_fields_STATUS, self::STATUS_ACTIVE)
            ->order(self::schema_fields_PRIORITY, 'DESC')
            ->order(self::schema_fields_DOMAIN)
            ->select()
            ->fetchArray();
    }

    /**
     * 获取所有规则（按优先级排序）
     *
     * @return array
     */
    public function getAllRules(): array
    {
        return $this->clearQuery()
            ->order(self::schema_fields_PRIORITY, 'DESC')
            ->order(self::schema_fields_DOMAIN)
            ->select()
            ->fetchArray();
    }

    /**
     * 切换状态
     *
     * @param int $proxyId 代理ID
     * @param string $status 状态
     * @return bool
     */
    public function toggleStatus(int $proxyId, string $status): bool
    {
        $proxy = $this->clearQuery()->where(self::schema_fields_ID, $proxyId)->find()->fetch();
        if (!$proxy->getData(self::schema_fields_ID)) {
            return false;
        }

        $proxy->setData(self::schema_fields_STATUS, $status);
        $proxy->save();

        return true;
    }
}
