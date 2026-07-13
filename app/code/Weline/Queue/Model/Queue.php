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
class Queue extends EavModel implements QueueTaskContextInterface
{
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
