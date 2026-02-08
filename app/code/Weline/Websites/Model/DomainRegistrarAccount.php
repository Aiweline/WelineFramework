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

use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class DomainRegistrarAccount extends Model
{
    public const fields_ID = 'account_id';
    public const fields_REGISTRAR_ID = 'registrar_id';      // 关联域名商 ID
    public const fields_ACCOUNT_NAME = 'account_name';      // 账号名称
    public const fields_API_KEY = 'api_key';                // API Key（编码存储）
    public const fields_API_SECRET = 'api_secret';          // API Secret（编码存储）
    public const fields_REGION = 'region';                  // 区域（如 AWS 区域）
    public const fields_EXTRA_CONFIG = 'extra_config';      // 额外配置 JSON
    public const fields_STATUS = 'status';                  // 状态：active / disabled
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    // 状态常量
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    public array $_unit_primary_keys = ['account_id'];
    public array $_index_sort_keys = ['account_id', 'registrar_id', 'account_name'];

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $this->install($setup, $context);
        }
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }

        $setup->createTable(__('域名商账号表'))
            ->addColumn(
                self::fields_ID,
                TableInterface::column_type_INTEGER,
                11,
                'primary key auto_increment',
                __('账号ID')
            )
            ->addColumn(
                self::fields_REGISTRAR_ID,
                TableInterface::column_type_INTEGER,
                11,
                'not null',
                __('域名商ID')
            )
            ->addColumn(
                self::fields_ACCOUNT_NAME,
                TableInterface::column_type_VARCHAR,
                255,
                'not null',
                __('账号名称')
            )
            ->addColumn(
                self::fields_API_KEY,
                TableInterface::column_type_VARCHAR,
                500,
                "default ''",
                __('API Key')
            )
            ->addColumn(
                self::fields_API_SECRET,
                TableInterface::column_type_VARCHAR,
                500,
                "default ''",
                __('API Secret')
            )
            ->addColumn(
                self::fields_REGION,
                TableInterface::column_type_VARCHAR,
                100,
                "default ''",
                __('区域')
            )
            ->addColumn(
                self::fields_EXTRA_CONFIG,
                TableInterface::column_type_TEXT,
                0,
                '',
                __('额外配置JSON')
            )
            ->addColumn(
                self::fields_STATUS,
                TableInterface::column_type_VARCHAR,
                20,
                "default 'active'",
                __('状态')
            )
            ->addColumn(
                self::fields_CREATED_AT,
                TableInterface::column_type_DATETIME,
                0,
                '',
                __('创建时间')
            )
            ->addColumn(
                self::fields_UPDATED_AT,
                TableInterface::column_type_DATETIME,
                0,
                '',
                __('更新时间')
            )
            ->addIndex(TableInterface::index_type_KEY, 'idx_registrar_id', self::fields_REGISTRAR_ID)
            ->addIndex(TableInterface::index_type_KEY, 'idx_status', self::fields_STATUS)
            ->create();
    }

    /**
     * 保存前自动更新时间戳
     */
    public function save_before(): void
    {
        parent::save_before();

        $now = \date('Y-m-d H:i:s');
        $this->setData(self::fields_UPDATED_AT, $now);

        if (!$this->getData(self::fields_ID)) {
            $this->setData(self::fields_CREATED_AT, $now);
        }
    }

    // =============== Getter / Setter ===============

    public function getAccountId(): int
    {
        return (int) $this->getData(self::fields_ID);
    }

    public function setRegistrarId(int $registrarId): self
    {
        $this->setData(self::fields_REGISTRAR_ID, $registrarId);
        return $this;
    }

    public function getRegistrarId(): int
    {
        return (int) $this->getData(self::fields_REGISTRAR_ID);
    }

    public function setAccountName(string $name): self
    {
        $this->setData(self::fields_ACCOUNT_NAME, $name);
        return $this;
    }

    public function getAccountName(): string
    {
        return (string) $this->getData(self::fields_ACCOUNT_NAME);
    }

    public function setApiKey(string $key): self
    {
        // 编码存储
        $this->setData(self::fields_API_KEY, \base64_encode($key));
        return $this;
    }

    public function getApiKey(): string
    {
        $encoded = (string) $this->getData(self::fields_API_KEY);
        if (empty($encoded)) {
            return '';
        }
        $decoded = \base64_decode($encoded, true);
        return $decoded !== false ? $decoded : $encoded;
    }

    public function setApiSecret(string $secret): self
    {
        // 编码存储
        $this->setData(self::fields_API_SECRET, \base64_encode($secret));
        return $this;
    }

    public function getApiSecret(): string
    {
        $encoded = (string) $this->getData(self::fields_API_SECRET);
        if (empty($encoded)) {
            return '';
        }
        $decoded = \base64_decode($encoded, true);
        return $decoded !== false ? $decoded : $encoded;
    }

    public function setRegion(string $region): self
    {
        $this->setData(self::fields_REGION, $region);
        return $this;
    }

    public function getRegion(): string
    {
        return (string) $this->getData(self::fields_REGION);
    }

    public function setExtraConfig(array $config): self
    {
        $this->setData(self::fields_EXTRA_CONFIG, \json_encode($config, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    public function getExtraConfig(): array
    {
        $json = (string) $this->getData(self::fields_EXTRA_CONFIG);
        if (empty($json)) {
            return [];
        }
        $decoded = \json_decode($json, true);
        return \is_array($decoded) ? $decoded : [];
    }

    public function setStatus(string $status): self
    {
        $this->setData(self::fields_STATUS, $status);
        return $this;
    }

    public function getStatus(): string
    {
        return (string) $this->getData(self::fields_STATUS);
    }

    // =============== 业务方法 ===============

    /**
     * 获取指定域名商的所有账号
     */
    public function getAccountsByRegistrarId(int $registrarId): array
    {
        return $this->clearQuery()
            ->where(self::fields_REGISTRAR_ID, $registrarId)
            ->order(self::fields_ACCOUNT_NAME, 'ASC')
            ->select()
            ->fetchArray();
    }

    /**
     * 获取所有活跃账号
     */
    public function getActiveAccounts(): array
    {
        return $this->clearQuery()
            ->where(self::fields_STATUS, self::STATUS_ACTIVE)
            ->order(self::fields_ACCOUNT_NAME, 'ASC')
            ->select()
            ->fetchArray();
    }

    /**
     * 获取 API 凭据数组（用于传递给适配器）
     */
    public function getCredentials(): array
    {
        return [
            'api_key' => $this->getApiKey(),
            'api_secret' => $this->getApiSecret(),
            'region' => $this->getRegion(),
            'extra' => $this->getExtraConfig(),
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
                'main_table.' . self::fields_REGISTRAR_ID . ' = dr.' . DomainRegistrar::fields_ID,
                'left',
                'dr.' . DomainRegistrar::fields_CODE . ' as registrar_code, dr.' . DomainRegistrar::fields_NAME . ' as registrar_name'
            )
            ->order('dr.' . DomainRegistrar::fields_NAME, 'ASC')
            ->order('main_table.' . self::fields_ACCOUNT_NAME, 'ASC')
            ->select()
            ->fetchArray();
    }
}
