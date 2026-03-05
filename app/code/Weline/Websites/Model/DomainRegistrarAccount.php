<?php
declare(strict_types=1);
/**
 * 域名商账号模型
 *
 * 同一个域名商渠道可以配置多个账号（如多个 AWS 账号）。
 * API 凭据使用 base64 编码存储（生产环境建议加密）。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */
namespace Weline\Websites\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '域名商账号表')]
#[Index(name: 'idx_registrar_id', columns: ['registrar_id'])]
#[Index(name: 'idx_status', columns: ['status'])]
class DomainRegistrarAccount extends Model
{
    public const schema_table = 'weline_websites_domain_registrar_account';
    public const schema_primary_key = 'account_id';
    #[Col('int', 11, nullable: false, primaryKey: true, autoIncrement: true, comment: '账号ID')]
    public const schema_fields_ID = 'account_id';
    #[Col('int', 11, nullable: false, comment: '域名商ID')]
    public const schema_fields_REGISTRAR_ID = 'registrar_id';
    #[Col('varchar', 255, nullable: false, comment: '账号名称')]
    public const schema_fields_ACCOUNT_NAME = 'account_name';
    #[Col('varchar', 500, nullable: true, default: '', comment: 'API Key')]
    public const schema_fields_API_KEY = 'api_key';
    #[Col('varchar', 500, nullable: true, default: '', comment: 'API Secret')]
    public const schema_fields_API_SECRET = 'api_secret';
    #[Col('varchar', 100, nullable: true, default: '', comment: '区域')]
    public const schema_fields_REGION = 'region';
    #[Col('text', nullable: true, comment: '额外配置JSON')]
    public const schema_fields_EXTRA_CONFIG = 'extra_config';
    #[Col('varchar', 20, nullable: true, default: 'active', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: true, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    // 状态常量
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';
    public array $_unit_primary_keys = ['account_id'];
    public array $_index_sort_keys = ['account_id', 'registrar_id', 'account_name'];
    /**
     * 保存前自动更新时间戳
     */
    public function save_before(): void
    {
        parent::save_before();
        $now = \date('Y-m-d H:i:s');
        $this->setData(self::schema_fields_UPDATED_AT, $now);
        if (!$this->getData(self::schema_fields_ID)) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }
    }
    // =============== Getter / Setter ===============
    public function getAccountId(): int
    {
        return (int) $this->getData(self::schema_fields_ID);
    }
    public function setRegistrarId(int $registrarId): self
    {
        $this->setData(self::schema_fields_REGISTRAR_ID, $registrarId);
        return $this;
    }
    public function getRegistrarId(): int
    {
        return (int) $this->getData(self::schema_fields_REGISTRAR_ID);
    }
    public function setAccountName(string $name): self
    {
        $this->setData(self::schema_fields_ACCOUNT_NAME, $name);
        return $this;
    }
    public function getAccountName(): string
    {
        return (string) $this->getData(self::schema_fields_ACCOUNT_NAME);
    }
    public function setApiKey(string $key): self
    {
        // 编码存储
        $this->setData(self::schema_fields_API_KEY, \base64_encode($key));
        return $this;
    }
    public function getApiKey(): string
    {
        $encoded = (string) $this->getData(self::schema_fields_API_KEY);
        if (empty($encoded)) {
            return '';
        }
        $decoded = \base64_decode($encoded, true);
        return $decoded !== false ? $decoded : $encoded;
    }
    public function setApiSecret(string $secret): self
    {
        // 编码存储
        $this->setData(self::schema_fields_API_SECRET, \base64_encode($secret));
        return $this;
    }
    public function getApiSecret(): string
    {
        $encoded = (string) $this->getData(self::schema_fields_API_SECRET);
        if (empty($encoded)) {
            return '';
        }
        $decoded = \base64_decode($encoded, true);
        return $decoded !== false ? $decoded : $encoded;
    }
    public function setRegion(string $region): self
    {
        $this->setData(self::schema_fields_REGION, $region);
        return $this;
    }
    public function getRegion(): string
    {
        return (string) $this->getData(self::schema_fields_REGION);
    }
    public function setExtraConfig(array $config): self
    {
        $this->setData(self::schema_fields_EXTRA_CONFIG, \json_encode($config, JSON_UNESCAPED_UNICODE));
        return $this;
    }
    public function getExtraConfig(): array
    {
        $json = (string) $this->getData(self::schema_fields_EXTRA_CONFIG);
        if (empty($json)) {
            return [];
        }
        $decoded = \json_decode($json, true);
        return \is_array($decoded) ? $decoded : [];
    }
    public function setStatus(string $status): self
    {
        $this->setData(self::schema_fields_STATUS, $status);
        return $this;
    }
    public function getStatus(): string
    {
        return (string) $this->getData(self::schema_fields_STATUS);
    }
    /**
     * 获取关联的域名商代码
     *
     * 如果已通过 JOIN 查询加载了 registrar_code，直接返回；
     * 否则查询 DomainRegistrar 表获取。
     */
    public function getRegistrarCode(): ?string
    {
        $code = $this->getData('registrar_code');
        if ($code !== null && $code !== '') {
            return (string) $code;
        }
        $registrarId = $this->getRegistrarId();
        if ($registrarId <= 0) {
            return null;
        }
        $registrar = \Weline\Framework\Manager\ObjectManager::getInstance(DomainRegistrar::class);
        $registrar->clearData(true);
        $registrar->load($registrarId);
        if (!$registrar->getId()) {
            return null;
        }
        $code = $registrar->getData(DomainRegistrar::schema_fields_CODE);
        $this->setData('registrar_code', $code);
        return $code !== null && $code !== '' ? (string) $code : null;
    }
    // =============== 业务方法 ===============
    /**
     * 获取指定域名商的所有账号
     */
    public function getAccountsByRegistrarId(int $registrarId): array
    {
        return $this->clearQuery()
            ->where(self::schema_fields_REGISTRAR_ID, $registrarId)
            ->order(self::schema_fields_ACCOUNT_NAME, 'ASC')
            ->select()
            ->fetchArray();
    }
    /**
     * 获取所有活跃账号
     */
    public function getActiveAccounts(): array
    {
        return $this->clearQuery()
            ->where(self::schema_fields_STATUS, self::STATUS_ACTIVE)
            ->order(self::schema_fields_ACCOUNT_NAME, 'ASC')
            ->select()
            ->fetchArray();
    }
    /**
     * 获取 API 凭据数组（用于传递给适配器）
     */
    public function getCredentials(): array
    {
        $extraConfig = $this->getExtraConfig();
        return [
            'api_key' => $this->getApiKey(),
            'api_secret' => $this->getApiSecret(),
            'region' => $this->getRegion(),
            'extra_config' => $extraConfig,
            'extra' => $extraConfig, // 兼容旧代码
            'account_id' => $extraConfig['account_id'] ?? '', // Cloudflare 特殊需要
        ];
    }
    /**
     * 获取带域名商信息的全部账号列表
     */
    public function getAccountsWithRegistrar(): array
    {
        return $this->clearQuery()
            ->joinModel(
                DomainRegistrar::class,
                'dr',
                'main_table.' . self::schema_fields_REGISTRAR_ID . ' = dr.' . DomainRegistrar::schema_fields_ID,
                'left',
                'dr.' . DomainRegistrar::schema_fields_CODE . ' as registrar_code, dr.' . DomainRegistrar::schema_fields_NAME . ' as registrar_name'
            )
            ->order('dr.' . DomainRegistrar::schema_fields_NAME, 'ASC')
            ->order('main_table.' . self::schema_fields_ACCOUNT_NAME, 'ASC')
            ->select()
            ->fetchArray();
    }
}
