<?php
declare(strict_types=1);
/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */
namespace Weline\Payment\Model;
use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '支付交易表')]
#[Index(name: 'idx_transaction_no', columns: ['transaction_no'], type: 'UNIQUE')]
#[Index(name: 'idx_order_id', columns: ['order_id'])]
#[Index(name: 'idx_method_code', columns: ['method_code'])]
#[Index(name: 'idx_status', columns: ['status'])]
class PaymentTransaction extends AbstractModel
{
    public const schema_table = 'weline_payment_transaction';
    public const schema_primary_key = 'transaction_id';
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REFUNDED = 'refunded';
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '交易ID')]
    public const schema_fields_ID = 'transaction_id';
    #[Col('varchar', 100, nullable: false, comment: '订单ID')]
    public const schema_fields_ORDER_ID = 'order_id';
    #[Col('varchar', 50, nullable: false, comment: '支付方式代码')]
    public const schema_fields_METHOD_CODE = 'method_code';
    #[Col('varchar', 100, nullable: false, unique: true, comment: '交易号')]
    public const schema_fields_TRANSACTION_NO = 'transaction_no';
    #[Col('decimal', '10,2', nullable: false, comment: '支付金额')]
    public const schema_fields_AMOUNT = 'amount';
    #[Col('varchar', 10, default: 'CNY', comment: '货币代码')]
    public const schema_fields_CURRENCY = 'currency';
    #[Col('varchar', 20, default: 'pending', comment: '支付状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('text', comment: '请求数据JSON')]
    public const schema_fields_REQUEST_DATA = 'request_data';
    #[Col('text', comment: '响应数据JSON')]
    public const schema_fields_RESPONSE_DATA = 'response_data';
    #[Col('text', comment: '回调数据JSON')]
    public const schema_fields_CALLBACK_DATA = 'callback_data';
    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    #[Col('datetime', comment: '支付完成时间')]
    public const schema_fields_PAID_AT = 'paid_at';
    public array $_unit_primary_keys = ['transaction_id'];
    public array $_index_sort_keys = ['transaction_id', 'order_id', 'transaction_no', 'status'];
/**
     * 是否待支付
     * 
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->getData(self::schema_fields_STATUS) === self::STATUS_PENDING;
    }
    /**
     * 是否处理中
     * 
     * @return bool
     */
    public function isProcessing(): bool
    {
        return $this->getData(self::schema_fields_STATUS) === self::STATUS_PROCESSING;
    }
    /**
     * 是否成功
     * 
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->getData(self::schema_fields_STATUS) === self::STATUS_SUCCESS;
    }
    /**
     * 是否失败
     * 
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->getData(self::schema_fields_STATUS) === self::STATUS_FAILED;
    }
    /**
     * 是否已退款
     * 
     * @return bool
     */
    public function isRefunded(): bool
    {
        return $this->getData(self::schema_fields_STATUS) === self::STATUS_REFUNDED;
    }
    /**
     * 获取请求数据
     * 
     * @return array
     */
    public function getRequestData(): array
    {
        $data = $this->getData(self::schema_fields_REQUEST_DATA);
        if (empty($data)) {
            return [];
        }
        if (is_string($data)) {
            return json_decode($data, true) ?? [];
        }
        return $data;
    }
    /**
     * 设置请求数据
     * 
     * @param array $data
     * @return $this
     */
    public function setRequestData(array $data): static
    {
        return $this->setData(self::schema_fields_REQUEST_DATA, json_encode($data, JSON_UNESCAPED_UNICODE));
    }
    /**
     * 获取响应数据
     * 
     * @return array
     */
    public function getResponseData(): array
    {
        $data = $this->getData(self::schema_fields_RESPONSE_DATA);
        if (empty($data)) {
            return [];
        }
        if (is_string($data)) {
            return json_decode($data, true) ?? [];
        }
        return $data;
    }
    /**
     * 设置响应数据
     * 
     * @param array $data
     * @return $this
     */
    public function setResponseData(array $data): static
    {
        return $this->setData(self::schema_fields_RESPONSE_DATA, json_encode($data, JSON_UNESCAPED_UNICODE));
    }
    /**
     * 获取回调数据
     * 
     * @return array
     */
    public function getCallbackData(): array
    {
        $data = $this->getData(self::schema_fields_CALLBACK_DATA);
        if (empty($data)) {
            return [];
        }
        if (is_string($data)) {
            return json_decode($data, true) ?? [];
        }
        return $data;
    }
    /**
     * 设置回调数据
     * 
     * @param array $data
     * @return $this
     */
    public function setCallbackData(array $data): static
    {
        return $this->setData(self::schema_fields_CALLBACK_DATA, json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
