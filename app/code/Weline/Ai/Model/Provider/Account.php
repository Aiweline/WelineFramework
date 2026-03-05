<?php
declare(strict_types=1);
namespace Weline\Ai\Model\Provider;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * AI Provider Account Model
 *
 * 管理AI供应商账户，包括API凭证、余额、连通性状态等
 *
 * @package Weline_Ai
 */
#[Table(comment: 'AI供应商账户表')]
#[Index(name: 'idx_provider_code', columns: ['provider_code'])]
#[Index(name: 'idx_is_active', columns: ['is_active'])]
#[Index(name: 'idx_provider_default', columns: ['provider_code', 'is_default'])]
class Account extends Model
{
    public const schema_table = 'ai_provider_account';
    public const schema_primary_key = 'id';
    public array $_unit_primary_keys = ['id'];
    public array $_index_sort_keys = ['id', 'provider_code', 'is_active'];
    #[Col('int', null, nullable: false, primaryKey: true, autoIncrement: true, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col('varchar', 50, nullable: false, comment: '供应商代码')]
    public const schema_fields_PROVIDER_CODE = 'provider_code';
    #[Col('varchar', 100, nullable: false, comment: '账户名称')]
    public const schema_fields_ACCOUNT_NAME = 'account_name';
    #[Col('text', nullable: false, comment: 'API密钥')]
    public const schema_fields_API_KEY = 'api_key';
    #[Col('text', comment: 'API密钥')]
    public const schema_fields_API_SECRET = 'api_secret';
    #[Col('varchar', 255, comment: 'API基础URL')]
    public const schema_fields_BASE_URL = 'base_url';
    #[Col('text', comment: '代理配置JSON')]
    public const schema_fields_PROXY_CONFIG = 'proxy_config';
    #[Col('decimal', '10,2', default: 0, comment: '账户余额')]
    public const schema_fields_BALANCE = 'balance';
    #[Col('varchar', 10, default: 'USD', comment: '货币单位')]
    public const schema_fields_CURRENCY = 'currency';
    #[Col('decimal', '10,2', default: 0, comment: '总花费')]
    public const schema_fields_TOTAL_SPENT = 'total_spent';
    #[Col('int', 1, default: 0, comment: '是否激活')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col('int', 1, default: 0, comment: '是否默认')]
    public const schema_fields_IS_DEFAULT = 'is_default';
    #[Col('varchar', 20, default: 'pending', comment: '连通状态')]
    public const schema_fields_CONNECTION_STATUS = 'connection_status';
    #[Col('int', null, comment: '最后测试时间')]
    public const schema_fields_CONNECTION_TEST_TIME = 'connection_test_time';
    #[Col('text', comment: '测试消息')]
    public const schema_fields_CONNECTION_TEST_MESSAGE = 'connection_test_message';
    #[Col('text', comment: '额外配置JSON')]
    public const schema_fields_CONFIG = 'config';
    #[Col('int', null, default: 0, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('int', null, default: 0, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public function _init(): void
    {
        $this->useMainDbMaster();
    }
    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
public function getDecryptedApiKey(): string
    {
        $apiKey = $this->getData(self::schema_fields_API_KEY);
        return $apiKey ?: '';
    }
    public function setEncryptedApiKey(string $apiKey): self
    {
        $this->setData(self::schema_fields_API_KEY, $apiKey);
        return $this;
    }
    public function getProxyConfig(): array
    {
        $config = $this->getData(self::schema_fields_PROXY_CONFIG);
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($config) ? $config : [];
    }
    public function getConfig(): array
    {
        $config = $this->getData(self::schema_fields_CONFIG);
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($config) ? $config : [];
    }
    public function isAvailable(): bool
    {
        return $this->getData(self::schema_fields_IS_ACTIVE) == 1
            && $this->getData(self::schema_fields_CONNECTION_STATUS) === self::STATUS_SUCCESS
            && $this->getData(self::schema_fields_BALANCE) > 0;
    }
    public function updateBalance(float $amount): self
    {
        $currentBalance = (float)$this->getData(self::schema_fields_BALANCE);
        $totalSpent = (float)$this->getData(self::schema_fields_TOTAL_SPENT);
        $this->setData(self::schema_fields_BALANCE, $currentBalance - $amount);
        $this->setData(self::schema_fields_TOTAL_SPENT, $totalSpent + $amount);
        return $this;
    }
}
