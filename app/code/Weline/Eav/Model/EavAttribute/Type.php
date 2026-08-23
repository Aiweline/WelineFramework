<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/3/6 21:28:26
 */

namespace Weline\Eav\Model\EavAttribute;

use Weline\Eav\EavModelInterface;
use Weline\Eav\Model\EavAttribute;
use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

/**
 * EAV属性类型模型 (SRP - 单一职责原则)
 * 
 * 表结构定义已迁移到 Schema/EavAttributeTypeSchema.php
 * 本类只负责数据操作和业务逻辑
 */
class Type extends \Weline\Framework\Database\Model
{
    public const fields_ID = 'type_id';
    public const fields_type_id = 'type_id';
    public const fields_code = 'code';
    public const fields_name = 'name';
    public const fields_is_swatch = 'is_swatch';
    public const fields_swatch_image = 'swatch_image';
    public const fields_swatch_color = 'swatch_color';
    public const fields_swatch_text = 'swatch_text';
    public const fields_frontend_attrs = 'frontend_attrs';
    public const fields_model_class = 'model_class';
    public const fields_model_class_data = 'model_class_data';
    public const fields_element = 'element';
    public const fields_default_value = 'default_value';
    public const fields_required = 'required';
    public const fields_field_type = 'field_type';
    public const fields_field_length = 'field_length';

    public const schema_fields_ID = 'type_id';
    public const schema_fields_type_id = 'type_id';
    public const schema_fields_code = 'code';
    public const schema_fields_name = 'name';
    public const schema_fields_is_swatch = 'is_swatch';
    public const schema_fields_swatch_image = 'swatch_image';
    public const schema_fields_swatch_color = 'swatch_color';
    public const schema_fields_swatch_text = 'swatch_text';
    public const schema_fields_frontend_attrs = 'frontend_attrs';
    public const schema_fields_model_class = 'model_class';
    public const schema_fields_model_class_data = 'model_class_data';
    public const schema_fields_element = 'element';
    public const schema_fields_default_value = 'default_value';
    public const schema_fields_required = 'required';
    public const schema_fields_field_type = 'field_type';
    public const schema_fields_field_length = 'field_length';
    public array $_unit_primary_keys = ['type_id'];
    public array $_index_sort_keys = ['type_id'];

    // 表结构已迁移到 Schema/EavAttributeTypeSchema.php
    // 由 Setup/Install.php 统一管理表创建
    public function setup(\Weline\Framework\Setup\Db\ModelSetup $setup, \Weline\Framework\Setup\Data\Context $context): void {}
    public function upgrade(\Weline\Framework\Setup\Db\ModelSetup $setup, \Weline\Framework\Setup\Data\Context $context): void {}
    public function install(\Weline\Framework\Setup\Db\ModelSetup $setup, \Weline\Framework\Setup\Data\Context $context): void {}

    public function getName(): string
    {
        return $this->getData(self::schema_fields_name) ?: '';
    }

    public function setName(string $name): static
    {
        return $this->setData(self::schema_fields_name, $name);
    }

    public function getCode(): string
    {
        return $this->getData(self::schema_fields_code) ?: '';
    }

    public function setCode(string $code): static
    {
        return $this->setData(self::schema_fields_code, $code, true);
    }

    public function getFieldType(): string
    {
        return $this->getData(self::schema_fields_field_type) ?: '';
    }

    public function setFieldType(string $field_type): static
    {
        return $this->setData(self::schema_fields_field_type, $field_type);
    }

    public function getFieldLength(): int
    {
        return intval($this->getData(self::schema_fields_field_length));
    }

    public function setFieldLength(int $field_length): static
    {
        return $this->setData(self::schema_fields_field_length, $field_length);
    }

    public function isSwatch(): bool
    {
        return boolval($this->getData(self::schema_fields_is_swatch));
    }

    public function getIsSwatch(): bool
    {
        return boolval($this->getData(self::schema_fields_is_swatch));
    }

    public function setElement(string $element): static
    {
        return $this->setData(self::schema_fields_element, $element);
    }

    public function getElement(): string
    {
        return $this->getData(self::schema_fields_element) ?: 'input';
    }

    public function getModelClass(): string
    {
        return $this->getData(self::schema_fields_model_class) ?: '';
    }

    public function setModelClass(string $model): static
    {
        return $this->setData(self::schema_fields_model_class, $model);
    }

    public function getModelClassData(): string
    {
        return $this->getData(self::schema_fields_model_class_data) ?: '';
    }

    public function setModelClassData(string $model_data): static
    {
        return $this->setData(self::schema_fields_model_class_data, $model_data);
    }

    public function getDefaultValue(): string|null
    {
        return $this->getData(self::schema_fields_default_value) ?: null;
    }

    public function setDefaultValue(string $default_value): static
    {
        return $this->setData(self::schema_fields_default_value, $default_value);
    }

    public function getRequired(): bool
    {
        return boolval($this->getData(self::schema_fields_required));
    }

    public function setRequired(bool $required): static
    {
        return $this->setData(self::schema_fields_required, $required ? 1 : 0);
    }

    public function getFrontendAttrs(): string
    {
        return $this->getData(self::schema_fields_frontend_attrs) ?: '';
    }

    public function setFrontendAttrs(string $frontend_attrs): static
    {
        return $this->setData(self::schema_fields_frontend_attrs, $frontend_attrs);
    }


    public function setIsSwatch(bool $is_swatch): static
    {
        return $this->setData(self::schema_fields_is_swatch, $is_swatch ? 1 : 0);
    }

    public function hasSwatchColor(): bool
    {
        return boolval($this->getData(self::schema_fields_swatch_color));
    }

    public function setHasSwatchColor(bool $has_swatch_color): static
    {
        return $this->setData(self::schema_fields_swatch_color, $has_swatch_color ? 1 : 0);
    }

    public function hasSwatchImage(): bool
    {
        return boolval($this->getData(self::schema_fields_swatch_image));
    }

    public function setHasSwatchImage(bool $has_swatch_image): static
    {
        return $this->setData(self::schema_fields_swatch_image, $has_swatch_image ? 1 : 0);
    }

    public function hasSwatchText(): bool
    {
        return boolval($this->getData(self::schema_fields_swatch_text));
    }

    public function setHasSwatchText(bool $has_swatch_text): static
    {
        return $this->setData(self::schema_fields_swatch_text, $has_swatch_text ? 1 : 0);
    }

    /**
     * @DESC          # 获取关联属性类型的属性模型
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/7/27 22:22
     * 参数区：
     */
    public function getAttributeModel(): EavAttribute
    {
        /**@var EavAttribute $attrbiute */
        $attrbiute = ObjectManager::getInstance(EavAttribute::class);
        $attrbiute->where(EavAttribute::schema_fields_type_id, $this->getId());
        return $attrbiute;
    }

    public static function processOptions(EavAttribute &$attribute, array &$options = []): array
    {
        $option_items = $options['options'] ?? [];
        $values = $options['values'] ?? [];
        $type = $attribute->getType();
        # 模型默认的选项
        $only_custom_options = $options['only_custom_options'] ?? true;
        if (!$only_custom_options and $model_class_data = $type->getModelClassData()) {
            $model_class_data = json_decode($model_class_data, true);
            # 数组合并，兼容键是数字时的合并
            if ($model_class_data) {
                foreach ($model_class_data as $key => $model_class_data_item) {
                    $option_items[$key] = $model_class_data_item;
                }
            }
            $type->setModelClassData(json_encode($model_class_data));
        }
        # 模型数据
        if ($model_class = $type->getModelClass()) {
            /**@var EavModelInterface $model_object */
            $model_object = ObjectManager::getInstance($model_class);
            if ($model_object instanceof EavModelInterface) {
                $type->setModelClassData(json_encode($model_object->getModelData()));
            } else {
                throw new \Exception(__('模型类: %{1} 必须是 EavModelInterface 接口类的实例', $model_class));
            }
        }
        $attrs = $options['attrs'] ?? [];
        $label_class = $options['label_class'] ?? '';
        $attrs = array_merge($attribute->getData(), [
            'field_type' => $type->getFieldType(),
            'length' => $type->getFieldLength(),
            'name' => $attribute->getCode(),
            'model_class_data' => htmlspecialchars($type->getModelClassData()),
            'title' => $attribute->getName() ?: $type->getName(),
            'placeholder' => __('请输入 ') . $type->getName(),
            'required' => $type->getRequired() ? 'required' : '',
        ], $attrs);
        unset($attrs['frontend_attrs']);
        unset($attrs['type']);
        return [$label_class, $attrs, $option_items, $values, $only_custom_options];
    }

    /**
     * @DESC          # 获取类型输出html
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 26/4/2024 下午1:59
     * 参数区：
     * @param EavAttribute $attribute
     * @param array $options ['options' => ['1' => '选项1', '2' => '选项2'], 'attrs' => ['class' => 'form-control'], 'label_class' => 'label-class']
     * @return string
     * @throws \Exception
     */
    function getHtml(EavAttribute &$attribute, array &$options = []): string
    {
        list($label_class, $attrs, $option_items, $values, $only_custom_options) = self::processOptions($attribute, $options);
        # 提取配置值
        $value = null;
        if (isset($values[$attribute->getCode()])) {
            $value = $values[$attribute->getCode()];
        }
        if ($value === null) {
            if (isset($options['entity']) and $options['entity']) {
                try {
                    $value = $attribute->getValue();
                } catch (Exception $e) {
                    $value = $this->getDefaultValue();
                }
            } else {
                $default_value = $this->getValue();
                if ($default_value) {
                    $value = $default_value;
                } else {
                    $value = $this->getDefaultValue();
                }
            }
        }
        # 如果有模型则直接返回模型
        if ($this->getModelClass()) {
            /** @var EavModelInterface $model */
            $model = ObjectManager::getInstance($this->getModelClass());
            return $model->getHtml($attribute, $value, $label_class, $attrs, $option_items, $only_custom_options);
        } else {
            $html = '';
            $element = $this->getElement();
            switch ($element) {
                case 'select':
                    $options = array_merge($options, json_decode($this->getModelClassData(), true));
                    if (empty($options)) {
                        throw new \Exception(__('Eav属性输入：缺少select选项'));
                    }
                    break;
                case 'input':
                case 'checkbox':
                case 'radio':
                    $attrs['value'] = $value;
                    break;
                case 'textarea':
                default:
                    break;
            }
            $attrsString = $this->processElementAttr($attribute, $attrs);
            switch ($element) {
                case 'select':
                    $html .= '<select ' . $attrsString . '>';
                    foreach ($option_items as $k => $v) {
                        $html .= '<option value="' . self::escape($k) . '" ' . ($value == $k ? 'selected' : '') . '>' . self::escape($v) . '</option>';
                    }
                    $html .= '</select>';
                    break;
                case 'textarea':
                    $html .= '<textarea ' . $attrsString . '>' . self::escape($value) . '</textarea>';
                    break;
                case 'radio':
                case 'checkbox':
                default:
                    $html .= '<input ' . $attrsString . '>';
                    break;
            }
            self::processLabel($attribute, $label_class, $html);
            self::processDependence($attribute, $html);
            return $html;
        }
    }

    function processElementAttr(EavAttribute &$attribute, array &$attrs): string
    {
        $type = $attribute->getTypeModel();
        $element = strtolower($type->getElement());
        $id = self::controlId($attribute);
        $fieldType = (string)($attrs['type'] ?? $attrs['field_type'] ?? 'text');
        $inputType = match ($fieldType) {
            'int', 'integer', 'float', 'smallint', 'bigint' => 'number',
            self::schema_fields_swatch_image => 'file',
            self::schema_fields_swatch_color => 'color',
            default => in_array($fieldType, ['text', 'email', 'url', 'password', 'date', 'datetime-local', 'time', 'tel', 'search'], true)
                ? $fieldType
                : 'text',
        };
        $baseClass = match ($element) {
            'select' => 'w-select',
            'textarea' => 'w-textarea',
            default => 'w-input',
        };
        $providedClasses = preg_split('/\s+/', trim((string)($attrs['class'] ?? ''))) ?: [];
        $classes = [$baseClass];
        foreach ($providedClasses as $providedClass) {
            if (preg_match('/^w-[a-z0-9_-]+$/', $providedClass)) {
                $classes[] = $providedClass;
            }
        }

        $render = [
            'id' => $id,
            'name' => (string)($attrs['name'] ?? $attribute->getCode()),
            'class' => implode(' ', array_values(array_unique($classes))),
            'title' => (string)($attrs['title'] ?? $attribute->getName()),
            'placeholder' => (string)($attrs['placeholder'] ?? ''),
            'data-w-field-code' => $attribute->getCode(),
        ];
        if ($element !== 'select' && $element !== 'textarea') {
            $render['type'] = $inputType;
        }
        foreach (['value', 'min', 'max', 'step', 'pattern', 'autocomplete', 'accept', 'rows', 'cols'] as $name) {
            if (array_key_exists($name, $attrs) && $attrs[$name] !== null && !is_array($attrs[$name])) {
                $render[$name] = (string)$attrs[$name];
            }
        }
        if (!empty($attrs['length'])) {
            $render['maxlength'] = (string)(int)$attrs['length'];
        }
        if (array_key_exists('model_class_data', $attrs)) {
            $modelData = is_array($attrs['model_class_data'])
                ? json_encode($attrs['model_class_data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : (string)$attrs['model_class_data'];
            $render['data-w-model-class-data'] = $modelData ?: '';
        }
        foreach (['required', 'disabled', 'readonly', 'multiple'] as $booleanName) {
            if (!empty($attrs[$booleanName])) {
                $render[$booleanName] = $booleanName;
            }
        }

        $dependence = trim((string)$attribute->getDependence());
        if ($dependence !== '') {
            /** @var Request $request */
            $request = ObjectManager::getInstance(Request::class);
            $eavModule = Env::getInstance()->getModuleByName('Weline_Eav');
            $router = trim((string)($eavModule['router'] ?? 'eav'), '/');
            $render['data-w-component'] = 'dependent-field';
            $render['data-w-dependencies'] = $dependence;
            $render['data-w-dependence-resource'] = 'eav_admin';
            $render['data-w-dependence-url'] = $request->isBackend()
                ? $request->getUrlBuilder()->getBackendUrl($router . '/backend/attribute/dependence')
                : $request->getUrlBuilder()->getUrl($router . '/backend/attribute/dependence');
        }

        $parts = [];
        foreach ($render as $name => $renderValue) {
            $parts[] = $name . '="' . self::escape($renderValue) . '"';
        }
        return implode(' ', $parts);
    }

    static function processLabel(EavAttribute &$attribute, string &$label_class, string &$html): void
    {
        $type = $attribute->getTypeModel();
        $required = $type->getRequired() ? '<span class="w-text" data-tone="danger" aria-hidden="true">*</span>' : '';
        $name = self::escape((string)__($attribute->getName()));
        $typeCode = self::escape($type->getCode());
        $attributeCode = self::escape($attribute->getCode());
        $dependenceValue = trim((string)$attribute->getDependence());
        $dependence = $dependenceValue !== ''
            ? '<small class="w-field__hint">' . self::escape((string)__('依赖：')) . '<span class="w-text" data-tone="info">' . self::escape($dependenceValue) . '</span></small>'
            : '';
        $controlId = self::escape(self::controlId($attribute));
        $label = <<<LABEL
<label for="$controlId" title="$attributeCode-$name" data-w-type-code="$typeCode" class="w-field__label">$required $name <span class="w-text" data-tone="primary">$attributeCode</span>$dependence</label>
LABEL;
        $html = $label . $html;
    }

    static function processDependence(EavAttribute &$attribute, string &$html): void
    {
        // Dependency behavior is declared by processElementAttr() and mounted by Weline.UI.
    }

    private static function controlId(EavAttribute $attribute): string
    {
        $raw = $attribute->getTypeModel()->getCode() . '-' . $attribute->getCode() . '-' . $attribute->getTypeId();
        return trim((string)preg_replace('/[^a-zA-Z0-9_-]+/', '-', $raw), '-');
    }

    private static function escape(mixed $value): string
    {
        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
