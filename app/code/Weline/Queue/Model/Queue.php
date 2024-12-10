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

use Weline\Eav\EavModel;
use Weline\Eav\EavModelInterface;
use Weline\Eav\Model\EavAttribute;
use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Queue\Model\Queue\Type;

class Queue extends EavModel
{

    /** 实体信息 start */
    const entity_code = 'queue';
    const  entity_name = '队列实体';
    const  eav_entity_id_field_type = 'integer';
    const  eav_entity_id_field_length = 11;
    /** 实体信息 end */

    public const fields_ID = 'queue_id';
    public const fields_type_id = 'type_id';
    public const fields_pid = 'pid';
    public const fields_name = 'name';
    public const fields_start_at = 'start_at';
    public const fields_end_at = 'end_at';
    public const fields_result = 'result';
    public const fields_content = 'content';
    public const fields_process = 'process';
    public const fields_status = 'status';
    public const fields_finished = 'finished';
    public const fields_auto = 'auto';

    /*状态*/
    public const status_pending = 'pending';
    public const status_running = 'running';
    public const status_done = 'done';
    public const status_stop = 'stop';
    public const status_error = 'error';
    public const fields_module = 'module';

    public array $_unit_primary_keys = ['queue_id'];
    public array $_index_sort_keys = ['queue_id', 'type_id', 'finished'];


    private ?Type $type = null;


    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // TODO: Implement upgrade() method.
    }

    public function install(ModelSetup $setup, Context $context): void
    {
//                $setup->dropTable();
        if (!$setup->tableExist()) {
            $setup->createTable('任务队列')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'primary key auto_increment', 'ID')
                ->addColumn(self::fields_pid, TableInterface::column_type_INTEGER, 0, 'default 0', '进程ID')
                ->addColumn(self::fields_type_id, TableInterface::column_type_INTEGER, 0, 'not null', '任务类别')
                ->addColumn(self::fields_name, TableInterface::column_type_VARCHAR, 255, 'not null', '任务名称')
                ->addColumn(self::fields_start_at, TableInterface::column_type_TIMESTAMP, null, '', '开始时间')
                ->addColumn(self::fields_end_at, TableInterface::column_type_TIMESTAMP, null, '', '结束时间')
                ->addColumn(self::fields_result, TableInterface::column_type_TEXT, null, '', '结果')
                ->addColumn(self::fields_content, TableInterface::column_type_TEXT, null, '', '内容')
                ->addColumn(self::fields_process, TableInterface::column_type_TEXT, null, '', '进度')
                ->addColumn(self::fields_status, TableInterface::column_type_VARCHAR, 12, "default 'pending'", '状态')
                ->addColumn(self::fields_finished, TableInterface::column_type_SMALLINT, 1, 'default 0', '是否完成')
                ->addColumn(self::fields_auto, TableInterface::column_type_SMALLINT, 1, 'default 1', '是否自动')
                ->addColumn(self::fields_module, TableInterface::column_type_VARCHAR, 255, 'not null', '模组')
                ->addIndex(TableInterface::index_type_KEY, 'type_id', self::fields_type_id, '类型索引')
                ->addIndex(TableInterface::index_type_KEY, self::fields_finished, self::fields_finished, '是否完成索引')
                ->create();
        }
    }

    public function getTypeId(): int
    {
        return (int)$this->getData(self::fields_type_id);
    }

    public function getPid(): int
    {
        return (int)$this->getData(self::fields_pid);
    }

    public function getName(): string
    {
        return $this->getData(self::fields_name);
    }

    public function getStartAt(): string
    {
        return $this->getData(self::fields_start_at) ?: '';
    }


    public function getEndAt(): string
    {
        return $this->getData(self::fields_end_at) ?: '';
    }

    public function getStatus(): string
    {
        return $this->getData(self::fields_status) ?: '';
    }

    public function getContent(): string
    {
        return $this->getData(self::fields_content) ?: '';
    }

    public function getResult(): string
    {
        return $this->getData(self::fields_result) ?: '';
    }

    public function getAuto(): bool
    {
        return $this->getData(self::fields_auto) == 1;
    }

    public function setTypeId(int $type_id): static
    {
        return $this->setData(self::fields_type_id, $type_id);
    }


    public function setPid(int $process_id): static
    {
        return $this->setData(self::fields_pid, $process_id);
    }

    public function setName(string $name): static
    {
        return $this->setData(self::fields_name, $name);
    }

    public function setModule(string $module_name): static
    {
        return $this->setData(self::fields_module, $module_name);
    }

    public function getModule(): string
    {
        return (string)$this->getData(self::fields_module);
    }

    public function setStartAt(string $start_at): static
    {
        return $this->setData(self::fields_start_at, $start_at);
    }

    public function setEndAt(string $end_at): static
    {
        return $this->setData(self::fields_end_at, $end_at);
    }

    public function setStatus(string $status = self::status_pending): static
    {
        return $this->setData(self::fields_status, $status);
    }

    public function setContent(string $content): static
    {
        return $this->setData(self::fields_content, $content);
    }

    public function setProcess(string $process): static
    {
        return $this->setData(self::fields_process, $process);
    }

    public function getProcess(bool $format = false, bool $isHtml = false)
    {
        if ($format) {
            $processString = '';
            $process       = $this->getData(self::fields_process);
            if ($process) {
                $process = json_decode($process);
                if (!$process) {
                    return $this->getData(self::fields_process);
                }
                foreach ($process as $key => $item) {
                    if (is_string($item)) {
                        $processString .= $key . '、' . $item;
                    } elseif (is_array($item)) {
                        $processString .= $key . ':' . ($isHtml ? '<br>' : PHP_EOL);
                        foreach ($item as $k => $v) {
                            $k             += 1;
                            $processString .= '&nbsp;&nbsp;&nbsp;&nbsp;' . $k . '、' . $v.($isHtml ? '<br>' : PHP_EOL);
                        }
                    }
                }
            }
            return $processString;
        }
        return $this->getData(self::fields_process);
    }

    public function init()
    {
        $this->setProcess('');
    }

    public function setResult(string $result_msg): static
    {
        return $this->setData(self::fields_result, $result_msg);
    }

    public function setFinished(bool $finished): static
    {
        return $this->setData(self::fields_finished, $finished ? 1 : 0);
    }

    public function isFinished(): bool
    {
        return (bool)$this->getData(self::fields_finished);
    }

    public function isRunning(): bool
    {
        return $this->getData(self::fields_status) === self::status_running;
    }

    public function isPending(): bool
    {
        return $this->getData(self::fields_status) === self::status_pending;
    }

    public function isFailed(): bool
    {
        return $this->getData(self::fields_status) === self::status_error;
    }

    public function isError(): bool
    {
        return $this->getData(self::fields_status) === self::status_error;
    }

    public function isSuccess(): bool
    {
        return $this->getData(self::fields_status) === self::status_done;
    }


    public function isDone(): bool
    {
        return $this->getData(self::fields_status) === self::status_done;
    }

    public function setAuto(bool $auto): static
    {
        return $this->setData(self::fields_auto, $auto ? 1 : 0);
    }

    public function getType(): Type
    {
        if (!$this->type) {
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

    public function getAttribute(string $code, int|string $entity_id = null, array $options_data = []): EavAttribute|null
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
            /**@var \Weline\Eav\Model\EavAttribute\Type $attrType */
            $attrType        = $attr->getType();
            $eav_model_class = $attrType->getModelClass();
            $value           = $attr->getValue();
            $options         = $attr->getOptions();
            if (!empty($eav_model_class)) {
                /**@var EavModelInterface $eav_model */
                $eav_model = ObjectManager::make($eav_model_class);
                $options   = $eav_model->getModelData([
                    'entity' => &$this,
                    'value' => $value,
                    'attribute' => &$attr,
                    'attributes' => &$attributes,
                ]) ?: $attr->getOptions();
                $params    = [];
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
        return $queue->where(self::fields_status, self::status_running)
            ->select()->getItems();
    }

    public function validateAttribute(EavAttribute $attribute): bool|string
    {
        $type = $attribute->getType();
        if ($type->getRequired() and ($attribute->getValue() == null or $attribute->getValue() == '')) {
            return __('请填写 %1', $attribute->getName());
        }
        return true;
    }
}
