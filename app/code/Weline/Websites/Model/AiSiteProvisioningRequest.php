<?php

declare(strict_types=1);

namespace Weline\Websites\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'AI建站域名准备异步请求')]
#[Index(name: 'uk_ai_site_provisioning_request_id', columns: ['request_id'], type: 'UNIQUE')]
#[Index(name: 'uk_ai_site_provisioning_source_command', columns: ['source_module', 'client_request_id'], type: 'UNIQUE')]
#[Index(name: 'idx_ai_site_provisioning_admin', columns: ['admin_user_id'])]
#[Index(name: 'idx_ai_site_provisioning_source_session', columns: ['source_module', 'source_public_id'])]
#[Index(name: 'idx_ai_site_provisioning_queue_id', columns: ['queue_id'])]
class AiSiteProvisioningRequest extends Model
{
    public const schema_table = 'weline_websites_ai_site_provisioning_request';
    public const schema_primary_key = 'ai_site_provisioning_request_id';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_ERROR = 'error';

    public const DOMAIN_MODE_TEST = 'test';
    public const DOMAIN_MODE_PURCHASE = 'purchase';
    /** Pool-existing domain: bind WebsiteDomain only, no purchase / no *.weline.test force. */
    public const DOMAIN_MODE_BIND = 'bind';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '请求主键')]
    public const schema_fields_ID = 'ai_site_provisioning_request_id';

    #[Col(type: 'varchar', length: 32, nullable: false, unique: true, comment: '公开请求ID')]
    public const schema_fields_REQUEST_ID = 'request_id';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: '请求来源模块')]
    public const schema_fields_SOURCE_MODULE = 'source_module';

    #[Col(type: 'int', nullable: false, default: 0, comment: '发起请求的后台管理员ID；旧行默认0且不可被V2读取')]
    public const schema_fields_ADMIN_USER_ID = 'admin_user_id';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: '来源会话公开ID')]
    public const schema_fields_SOURCE_PUBLIC_ID = 'source_public_id';

    #[Col(type: 'varchar', length: 128, nullable: false, comment: '调用方幂等命令ID')]
    public const schema_fields_CLIENT_REQUEST_ID = 'client_request_id';

    #[Col(type: 'varchar', length: 16, nullable: false, comment: '域名模式')]
    public const schema_fields_DOMAIN_MODE = 'domain_mode';

    #[Col(type: 'varchar', length: 253, nullable: false, default: '', comment: '规范化目标域名')]
    public const schema_fields_TARGET_DOMAIN = 'target_domain';

    #[Col(type: 'varchar', length: 128, nullable: false, default: '', comment: '站点挂载子路径；空表示整域，如 /shop')]
    public const schema_fields_SUB_PATH = 'sub_path';

    #[Col(type: 'int', nullable: true, comment: '正式购买使用的注册商账号ID')]
    public const schema_fields_REGISTRAR_ACCOUNT_ID = 'registrar_account_id';

    #[Col(type: 'smallint', nullable: false, default: 1, comment: '正式域名购买年限')]
    public const schema_fields_YEARS = 'years';

    #[Col(type: 'smallint', length: 1, nullable: false, default: 0, comment: '是否已明确确认付费购买')]
    public const schema_fields_PURCHASE_CONFIRMED = 'purchase_confirmed';

    #[Col(type: 'smallint', length: 1, nullable: false, default: 0, comment: '是否已开始调用外部购买接口')]
    public const schema_fields_PURCHASE_ATTEMPTED = 'purchase_attempted';

    #[Col(type: 'int', nullable: false, default: 0, comment: '真实域名购买订单ID；测试域名为0')]
    public const schema_fields_PURCHASE_ORDER_ID = 'purchase_order_id';

    #[Col(type: 'int', nullable: true, comment: '显式请求绑定的网站ID；0为系统默认站')]
    public const schema_fields_REQUESTED_WEBSITE_ID = 'requested_website_id';

    #[Col(type: 'smallint', length: 1, nullable: false, default: 0, comment: '是否已完成站点绑定')]
    public const schema_fields_WEBSITE_BOUND = 'website_bound';

    #[Col(type: 'int', nullable: false, default: 0, comment: '已绑定网站ID；website_bound=1时0为系统默认站')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col(type: 'varchar', length: 16, nullable: false, default: self::STATUS_PENDING, comment: '请求状态')]
    public const schema_fields_STATUS = 'status';

    #[Col(type: 'int', nullable: false, default: 0, comment: '关联队列ID')]
    public const schema_fields_QUEUE_ID = 'queue_id';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: '本次执行令牌')]
    public const schema_fields_EXECUTION_TOKEN = 'execution_token';

    #[Col(type: 'varchar', length: 64, nullable: false, default: '', comment: '稳定错误码')]
    public const schema_fields_ERROR_CODE = 'error_code';

    #[Col(type: 'varchar', length: 1024, nullable: false, default: '', comment: '状态消息')]
    public const schema_fields_MESSAGE = 'message';

    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function save_before(): void
    {
        parent::save_before();

        $now = \date('Y-m-d H:i:s');
        $this->setData(self::schema_fields_UPDATED_AT, $now);
        if ((int)$this->getData(self::schema_fields_ID) <= 0) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }
    }

    public function getId(mixed $default = 0): int
    {
        return (int)($this->getData(self::schema_fields_ID) ?? $default);
    }

    public function getRequestId(): string
    {
        return (string)($this->getData(self::schema_fields_REQUEST_ID) ?? '');
    }

    public function getRequestedWebsiteId(): ?int
    {
        $value = $this->getData(self::schema_fields_REQUESTED_WEBSITE_ID);

        return $value === null || $value === '' ? null : (int)$value;
    }
}
