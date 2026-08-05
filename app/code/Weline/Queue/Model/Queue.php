<?php
declare(strict_types=1);
/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Administrator
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：11/7/2023 09:15:50
 */
namespace Weline\Queue\Model;
use Weline\Eav\Api\EavAttribute;
use Weline\Eav\Api\EavAttributeType;
use Weline\Eav\Api\EavModel;
use Weline\Eav\Api\EavModelInterface;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Manager\ObjectManager;
use Weline\Queue\Api\QueueStatus;
use Weline\Queue\Api\QueueTaskContextInterface;
use Weline\Queue\Model\Queue\Type;
#[Table(comment: '任务队列')]
#[Index(name: 'type_id', columns: ['type_id'])]
#[Index(name: 'idx_finished', columns: ['finished'])]
#[Index(name: 'idx_biz_key', columns: ['biz_key'])]
#[Index(name: 'uk_idempotency_key', columns: ['idempotency_key'], type: 'UNIQUE')]
#[Index(name: 'idx_queue_dispatch_lease', columns: ['status', 'dispatch_until'])]
#[Index(name: 'idx_queue_dispatch_ready', columns: ['status', 'finished', 'auto', 'not_before'])]
class Queue extends EavModel implements QueueTaskContextInterface
{
    public const IDEMPOTENCY_KEY_MAX_BYTES = 191;
    const entity_code = 'queue';
    const entity_name = '队列实体';
    const eav_entity_id_field_type = 'integer';
    const eav_entity_id_field_length = 11;
    public const schema_table = 'weline_queue';
    public const schema_primary_key = 'queue_id';
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'queue_id';
    #[Col('int', nullable: false, comment: '任务类别')]
    public const schema_fields_type_id = 'type_id';
    #[Col('int', default: 0, comment: '进程ID')]
    public const schema_fields_pid = 'pid';
    #[Col('varchar', 255, nullable: false, comment: '任务名称')]
    public const schema_fields_name = 'name';
    #[Col('timestamp', comment: '开始时间')]
    public const schema_fields_start_at = 'start_at';
    #[Col('timestamp', comment: '结束时间')]
    public const schema_fields_end_at = 'end_at';
    #[Col('text', comment: '结果')]
    public const schema_fields_result = 'result';
    #[Col('text', comment: '内容')]
    public const schema_fields_content = 'content';
    #[Col('text', comment: '进度')]
    public const schema_fields_process = 'process';
    #[Col('varchar', 12, default: 'pending', comment: '状态')]
    public const schema_fields_status = 'status';
    #[Col('smallint', 1, default: 0, comment: '是否完成')]
    public const schema_fields_finished = 'finished';
    #[Col('smallint', 1, default: 1, comment: '是否自动')]
    public const schema_fields_auto = 'auto';
    #[Col('varchar', 255, nullable: false, comment: '模组')]
    public const schema_fields_module = 'module';
    /** 业务侧自定义检索键（如会话/幂等标识），可建索引精确定位；留空表示未使用 */
    #[Col('varchar', 191, nullable: true, comment: '业务检索键')]
    public const schema_fields_BIZ_KEY = 'biz_key';
    /** 系统幂等键；NULL 表示未启用，非空值由唯一索引保证只创建一条队列 */
    #[Col('varchar', 191, nullable: true, comment: '系统幂等键')]
    public const schema_fields_IDEMPOTENCY_KEY = 'idempotency_key';
    /** 当前派发所有者的 fencing token；Worker 只有匹配时才能接管 */
    #[Col('char', 64, nullable: true, comment: '派发 fencing token')]
    public const schema_fields_DISPATCH_TOKEN = 'dispatch_token';
    /** pending -> running 派发 claim 的到期时间；Worker 接管后清空 */
    #[Col('datetime', nullable: true, comment: '派发 claim 到期时间')]
    public const schema_fields_DISPATCH_UNTIL = 'dispatch_until';
    /** Scheduler 在此 UTC 时间之前不得派发；NULL 表示立即可见 */
    #[Col('datetime', nullable: true, comment: '最早可派发 UTC 时间')]
    public const schema_fields_NOT_BEFORE = 'not_before';
    /** Scope 信封（P1b）：判别 kind，NULL 表示 pre-P1b 遗留行（须经冻结映射或 quarantine，禁止猜零号站） */
    #[Col('varchar', 16, nullable: true, comment: 'Scope kind: global|website|store|channel')]
    public const schema_fields_SCOPE_KIND = 'scope_kind';
    #[Col('int', 11, nullable: true, comment: 'Scope 网站ID（0 为系统默认站，合法）')]
    public const schema_fields_SCOPE_WEBSITE_ID = 'scope_website_id';
    #[Col('varchar', 64, nullable: true, comment: 'Scope 网站代码')]
    public const schema_fields_SCOPE_WEBSITE_CODE = 'scope_website_code';
    #[Col('varchar', 64, nullable: true, comment: 'Scope 店铺代码')]
    public const schema_fields_SCOPE_STORE_CODE = 'scope_store_code';
    #[Col('varchar', 64, nullable: true, comment: 'Scope 渠道代码')]
    public const schema_fields_SCOPE_CHANNEL_CODE = 'scope_channel_code';
    #[Col('varchar', 16, nullable: true, comment: 'Scope 店铺模式 normal|dev|test')]
    public const schema_fields_SCOPE_STORE_MODE = 'scope_store_mode';
    #[Col('varchar', 16, nullable: true, comment: 'Scope 信封版本（v1）')]
    public const schema_fields_SCOPE_ENVELOPE_VERSION = 'scope_envelope_version';
    public const status_pending = QueueStatus::PENDING;
    public const status_running = QueueStatus::RUNNING;
    public const status_done = QueueStatus::DONE;
    public const status_stop = QueueStatus::STOP;
    public const status_error = QueueStatus::ERROR;
    public array $_unit_primary_keys = ['queue_id'];
    public array $_index_sort_keys = ['queue_id', 'type_id', 'finished'];
    private ?Type $type = null;
public function getTypeId(): int
    {
        return (int)$this->getData(self::schema_fields_type_id);
    }
    public function getPid(): int
    {
        return (int)$this->getData(self::schema_fields_pid);
    }
    public function getName(): string
    {
        return $this->getData(self::schema_fields_name);
    }
    public function getStartAt(): string
    {
        return $this->getData(self::schema_fields_start_at) ?: '';
    }
    public function getEndAt(): string
    {
        return $this->getData(self::schema_fields_end_at) ?: '';
    }
    public function getStatus(): string
    {
        return $this->getData(self::schema_fields_status) ?: '';
    }
    public function getContent(): string
    {
        return $this->getData(self::schema_fields_content) ?: '';
    }
    public function getResult(): string
    {
        return $this->getData(self::schema_fields_result) ?: '';
    }
    public function getAuto(): bool
    {
        return $this->getData(self::schema_fields_auto) == 1;
    }
    public function setTypeId(int $type_id): static
    {
        if ($this->getTypeId() !== $type_id) {
            $this->type = null;
        }
        return $this->setData(self::schema_fields_type_id, $type_id);
    }
    public function setPid(int $process_id): static
    {
        return $this->setData(self::schema_fields_pid, $process_id);
    }
    public function setName(string $name): static
    {
        return $this->setData(self::schema_fields_name, $name);
    }
    public function setModule(string $module_name): static
    {
        return $this->setData(self::schema_fields_module, $module_name);
    }
    public function getModule(): string
    {
        return (string)$this->getData(self::schema_fields_module);
    }

    public function getBizKey(): string
    {
        $v = $this->getData(self::schema_fields_BIZ_KEY);

        return ($v === null || $v === '') ? '' : (string)$v;
    }

    /**
     * 设置业务检索键；空字符串会写入 NULL，便于索引稀疏存储
     */
    public function setBizKey(?string $bizKey): static
    {
        if ($bizKey === null) {
            return $this->setData(self::schema_fields_BIZ_KEY, null);
        }
        $v = \trim($bizKey);
        if ($v === '') {
            return $this->setData(self::schema_fields_BIZ_KEY, null);
        }
        if (\strlen($v) > 191) {
            $v = \substr($v, 0, 191);
        }

        return $this->setData(self::schema_fields_BIZ_KEY, $v);
    }

    /**
     * 读取 Scope 信封（P1b）。只有全部 v1 固定列均为空的 pre-P1b 遗留行
     * 返回 null，消费方才可按迁移策略视作 global。部分写入、非法组合或
     * 未知版本必须抛错，禁止静默降级为 Global。
     */
    public function getScopeEnvelope(): ?\Weline\Framework\Runtime\ScopeEnvelope
    {
        $storage = [
            'scope_kind' => $this->getData(self::schema_fields_SCOPE_KIND),
            'website_id' => $this->getData(self::schema_fields_SCOPE_WEBSITE_ID),
            'website_code' => $this->getData(self::schema_fields_SCOPE_WEBSITE_CODE),
            'store_code' => $this->getData(self::schema_fields_SCOPE_STORE_CODE),
            'channel_code' => $this->getData(self::schema_fields_SCOPE_CHANNEL_CODE),
            'store_mode' => $this->getData(self::schema_fields_SCOPE_STORE_MODE),
            'envelope_version' => $this->getData(self::schema_fields_SCOPE_ENVELOPE_VERSION),
        ];
        $hasStoredScopeValue = false;
        foreach ($storage as $value) {
            if ($value !== null && $value !== '') {
                $hasStoredScopeValue = true;
                break;
            }
        }
        if (!$hasStoredScopeValue) {
            return null;
        }

        return \Weline\Framework\Runtime\ScopeEnvelope::fromV1StorageArray($storage);
    }

    /**
     * P1B-002：歧义 Scope 遗留行 quarantine 标记（写入 result 前缀，不可领取）。
     */
    public function isScopeQuarantined(): bool
    {
        $result = (string)$this->getData(self::schema_fields_result);

        return \str_starts_with(
            $result,
            \Weline\Queue\Service\QueueScopeProducerMapping::QUARANTINE_RESULT_PREFIX
        );
    }

    /**
     * 写入 Scope 信封固定列（P1b）。传 null 显式写 global。
     */
    public function setScopeEnvelope(?\Weline\Framework\Runtime\ScopeEnvelope $envelope): static
    {
        $envelope ??= \Weline\Framework\Runtime\ScopeEnvelope::of(
            \Weline\Framework\Runtime\ScopeIdentity::global(),
        );
        $storage = $envelope->toV1StorageArray();

        foreach ([
            self::schema_fields_SCOPE_KIND => $storage['scope_kind'],
            self::schema_fields_SCOPE_WEBSITE_ID => $storage['website_id'],
            self::schema_fields_SCOPE_WEBSITE_CODE => $storage['website_code'],
            self::schema_fields_SCOPE_STORE_CODE => $storage['store_code'],
            self::schema_fields_SCOPE_CHANNEL_CODE => $storage['channel_code'],
            self::schema_fields_SCOPE_STORE_MODE => $storage['store_mode'],
            self::schema_fields_SCOPE_ENVELOPE_VERSION => $storage['envelope_version'],
        ] as $field => $value) {
            // AbstractData::setData(array) replaces the complete data bag. Scope
            // capture happens in save_before(), so replacing here would discard
            // type_id/name/content that were already prepared for INSERT.
            $this->setData($field, $value);
        }

        return $this;
    }

    public function save_before()
    {
        parent::save_before();
        // 先验证完整固定列；只有七列全空才是 legacy/null，任何部分写入、
        // 非规范 kind 或未知版本都必须在落库前失败。
        $envelope = $this->getScopeEnvelope();
        $isNew = !$this->hasData(self::schema_fields_ID) || (int)$this->getData(self::schema_fields_ID) <= 0;
        // P1b：新建队列行完全未指定信封时，自动捕获当前请求 Scope（无上下文 → global）
        if ($isNew && $envelope === null) {
            $this->setScopeEnvelope(\Weline\Framework\Runtime\ScopeEnvelope::capture());
        }
    }

    public function getIdempotencyKey(): string
    {
        $value = $this->getData(self::schema_fields_IDEMPOTENCY_KEY);

        return ($value === null || $value === '') ? '' : (string)$value;
    }

    public function setIdempotencyKey(?string $idempotencyKey): static
    {
        if ($idempotencyKey === null) {
            return $this->setData(self::schema_fields_IDEMPOTENCY_KEY, null);
        }
        $value = \trim($idempotencyKey);
        if ($value === '') {
            return $this->setData(self::schema_fields_IDEMPOTENCY_KEY, null);
        }
        if (\strlen($value) > self::IDEMPOTENCY_KEY_MAX_BYTES) {
            throw new \InvalidArgumentException((string)__('幂等键不能超过 %{1} 字节', [
                self::IDEMPOTENCY_KEY_MAX_BYTES,
            ]));
        }

        return $this->setData(self::schema_fields_IDEMPOTENCY_KEY, $value);
    }

    public function getDispatchToken(): string
    {
        $value = $this->getData(self::schema_fields_DISPATCH_TOKEN);

        return ($value === null || $value === '') ? '' : (string)$value;
    }

    public function setDispatchToken(?string $dispatchToken): static
    {
        if ($dispatchToken === null || \trim($dispatchToken) === '') {
            return $this->setData(self::schema_fields_DISPATCH_TOKEN, null);
        }
        $value = \strtolower(\trim($dispatchToken));
        if (\preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            throw new \InvalidArgumentException('queue_dispatch_token_invalid');
        }

        return $this->setData(self::schema_fields_DISPATCH_TOKEN, $value);
    }

    public function getDispatchUntil(): string
    {
        $value = $this->getData(self::schema_fields_DISPATCH_UNTIL);

        return ($value === null || $value === '') ? '' : (string)$value;
    }

    public function setDispatchUntil(?string $dispatchUntil): static
    {
        return $this->setData(
            self::schema_fields_DISPATCH_UNTIL,
            $dispatchUntil === null || \trim($dispatchUntil) === '' ? null : \trim($dispatchUntil),
        );
    }

    public function getNotBefore(): string
    {
        $value = $this->getData(self::schema_fields_NOT_BEFORE);

        return ($value === null || $value === '') ? '' : (string)$value;
    }

    public function setNotBefore(?string $notBefore): static
    {
        return $this->setData(
            self::schema_fields_NOT_BEFORE,
            $notBefore === null || \trim($notBefore) === '' ? null : \trim($notBefore),
        );
    }
    public function setStartAt(string $start_at): static
    {
        return $this->setData(self::schema_fields_start_at, $start_at);
    }
    public function setEndAt(string $end_at): static
    {
        return $this->setData(self::schema_fields_end_at, $end_at);
    }
    public function setStatus(string $status = self::status_pending): static
    {
        return $this->setData(self::schema_fields_status, $status);
    }
    public function setContent(string $content): static
    {
        return $this->setData(self::schema_fields_content, $this->normalizeUtf8StorageText($content));
    }
    public function setProcess(string $process): static
    {
        return $this->setData(self::schema_fields_process, $this->normalizeUtf8StorageText($process));
    }
    public function getProcess(bool $format = false, bool $isHtml = false)
    {
        if ($format) {
            $processString = '';
            $process = $this->getData(self::schema_fields_process);
            if ($process) {
                $process = json_decode($process);
                if (!$process) {
                    return $this->getData(self::schema_fields_process);
                }
                foreach ($process as $key => $item) {
                    if (is_string($item)) {
                        $processString .= $key . '、' . $item;
                    } elseif (is_array($item)) {
                        $processString .= $key . ':' . ($isHtml ? '<br>' : PHP_EOL);
                        foreach ($item as $k => $v) {
                            $k += 1;
                            $processString .= '&nbsp;&nbsp;&nbsp;&nbsp;' . $k . '、' . $v . ($isHtml ? '<br>' : PHP_EOL);
                        }
                    }
                }
            }
            return $processString;
        }
        return $this->getData(self::schema_fields_process);
    }
    public function init()
    {
        $this->setProcess('');
    }

    public function resetTaskProgress(): void
    {
        $this->init();
    }

    public function taskData(string $key = '', mixed $index = null): mixed
    {
        return $this->getData($key, $index);
    }

    public function taskAttributes(array $options = []): array
    {
        return $this->getAttributes($options);
    }

    public function validateTaskAttribute(mixed $attribute): bool|string
    {
        if (!$attribute instanceof EavAttribute) {
            return false;
        }

        return $this->validateAttribute($attribute);
    }

    public function setExecutionArgs(array $args): void
    {
        $this->setData('args', $args);
    }

    public function persist(): void
    {
        $this->save();
    }

    public function setResult(string $result_msg): static
    {
        return $this->setData(self::schema_fields_result, $this->normalizeUtf8StorageText($result_msg));
    }

    private function normalizeUtf8StorageText(string $text): string
    {
        if ($text === '' || \preg_match('//u', $text)) {
            return $text;
        }

        $converted = \function_exists('iconv') ? @\iconv('UTF-8', 'UTF-8//IGNORE', $text) : false;
        if (\is_string($converted) && \preg_match('//u', $converted)) {
            return $converted;
        }
        if (\function_exists('mb_convert_encoding')) {
            $converted = @\mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            if (\is_string($converted) && \preg_match('//u', $converted)) {
                return $converted;
            }
        }

        return (string)\preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $text);
    }
    public function setFinished(bool $finished): static
    {
        return $this->setData(self::schema_fields_finished, $finished ? 1 : 0);
    }
    public function isFinished(): bool
    {
        return (bool)$this->getData(self::schema_fields_finished);
    }
    public function isRunning(): bool
    {
        return $this->getData(self::schema_fields_status) === self::status_running;
    }
    public function isPending(): bool
    {
        return $this->getData(self::schema_fields_status) === self::status_pending;
    }
    public function isFailed(): bool
    {
        return $this->getData(self::schema_fields_status) === self::status_error;
    }
    public function isError(): bool
    {
        return $this->getData(self::schema_fields_status) === self::status_error;
    }
    public function isSuccess(): bool
    {
        return $this->getData(self::schema_fields_status) === self::status_done;
    }
    public function isDone(): bool
    {
        return $this->getData(self::schema_fields_status) === self::status_done;
    }
    public function setAuto(bool $auto): static
    {
        return $this->setData(self::schema_fields_auto, $auto ? 1 : 0);
    }
    public function getType(): Type
    {
        if (!$this->type || $this->type->getTypeId() !== $this->getTypeId()) {
            /**@var Type $type */
            $type = ObjectManager::getInstance(Type::class, []);
            $type->load($this->getTypeId());
            $this->type = clone $type;
        }
        return $this->type;
    }
    public function getAttributes(array $options_data = []): array
    {
        if (empty($options_data)) {
            $options_data = [
                'label_class' => 'control-label',
                'attrs' => ['class' => 'form-control w-100 readonly disabled', 'disabled' => 'disabled'],
                'entity' => $this,
                'no_html' => 1
            ];
        }
        return $this->getType()->getAttributes($options_data);
    }

    /**
     * Return every attribute owned by the Queue EAV entity, independent of
     * the row's current type. Safe deletion must also remove values left by a
     * previous type after an A -> B type change.
     *
     * @return array<int, \Weline\Eav\Api\EavAttribute>
     */
    public function getAllEavAttributes(): array
    {
        /** @var EavAttribute $attributeModel */
        $attributeModel = ObjectManager::make(EavAttribute::class);
        $attributeModel->clearData()->clearQuery()
            ->where(EavAttribute::schema_fields_eav_entity_id, $this->getEavEntityId())
            ->select()
            ->fetch();
        $attributes = $attributeModel->getItems();
        foreach ($attributes as $attribute) {
            $attribute->current_setEntity($this);
        }

        return $attributes;
    }
    public function getAttribute(string $code, int|string $entity_id = 0, array $options_data = []): EavAttribute|null
    {
        if ($entity_id) {
            $entity = ObjectManager::make($this::class)->load($entity_id);
        } else {
            $entity = $this;
        }
        if (empty($options_data)) {
            $options_data = [
                'label_class' => 'control-label',
                'attrs' => ['class' => 'form-control w-100 readonly disabled', 'disabled' => 'disabled'],
                'entity' => $entity,
                'eav_entity_id' => $this->getEavEntityId(),
                'no_html' => 1
            ];
        }
        return $this->getType()->getAttribute($code, $options_data);
    }
    public function getTypeAttributes(array $options_data = []): array
    {
        if (empty($options_data)) {
            $options_data = [
                'label_class' => 'control-label',
                'attrs' => ['class' => 'form-control w-100 readonly disabled', 'disabled' => 'disabled'],
                'entity' => $this,
                'eav_entity_id' => $this->getEavEntityId(),
                'no_html' => 1
            ];
        }
        return $this->getType()->getAttributes($options_data);
    }
    public function getTypeAttributesParams(array $options_data = []): array
    {
        if (empty($options_data)) {
            $options_data = [
                'label_class' => 'control-label',
                'attrs' => ['class' => 'form-control w-100 readonly disabled', 'disabled' => 'disabled'],
                'entity' => $this,
                'eav_entity_id' => $this->getEavEntityId(),
                'no_html' => 1
            ];
        }
        $attributes = $this->getType()->getAttributes($options_data);
        /**@var EavAttribute $attr */
        foreach ($attributes as &$attr) {
            /** @var EavAttributeType $attrType */
            $attrType = $attr->getType();
            $eav_model_class = $attrType->getModelClass();
            $value = $attr->getValue();
            $options = $attr->getOptions();
            if (!empty($eav_model_class)) {
                /**@var EavModelInterface $eav_model */
                $eav_model = ObjectManager::make($eav_model_class);
                $options = $eav_model->getModelData([
                    'entity' => &$this,
                    'value' => $value,
                    'attribute' => &$attr,
                    'attributes' => &$attributes,
                ]) ?: $attr->getOptions();
                $params = [];
                if (is_array($value)) {
                    foreach ($value as $i => $v) {
                        if (isset($options[$v])) {
                            $params[$v] = [
                                'value' => $v,
                                'label' => $options[$v],
                            ];
                        }
                    }
                } else {
                    if (isset($options[$value])) {
                        $params[$value] = [
                            'value' => $value,
                            'label' => $options[$value],
                        ];
                    } else {
                        $params[$value] = [
                            'value' => $value,
                            'label' => $value,
                        ];
                    }
                }
            } else {
                if (isset($options[$value])) {
                    $params[$value] = [
                        'value' => $value,
                        'label' => $options[$value],
                    ];
                } else {
                    $params[$value] = [
                        'value' => $value,
                        'label' => $value,
                    ];
                }
            }
            $attr->setData('params', $params);
            $attr->setData('options', $options);
        }
        return $attributes;
    }
    public static function getRunningItems(): array
    {
        /**@var Queue $queue */
        $queue = ObjectManager::make(self::class);
        return $queue->where(self::schema_fields_status, self::status_running)
            ->select()->getItems();
    }
    public function validateAttribute(EavAttribute $attribute): bool|string
    {
        $type = $attribute->getType();
        if ($type->getRequired() and ($attribute->getValue() == null or $attribute->getValue() == '')) {
            return __('请填写 %{1}', $attribute->getName());
        }
        return true;
    }
}
