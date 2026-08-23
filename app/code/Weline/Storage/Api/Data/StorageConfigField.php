<?php

declare(strict_types=1);

namespace Weline\Storage\Api\Data;

/** Immutable, renderer-neutral provider configuration-field description. */
final readonly class StorageConfigField
{
    public const TYPE_TEXT = 'text';
    public const TYPE_PASSWORD = 'password';
    public const TYPE_CHECKBOX = 'checkbox';
    public const TYPE_SELECT = 'select';
    public const TYPE_NUMBER = 'number';
    public const TYPES = [
        self::TYPE_TEXT,
        self::TYPE_PASSWORD,
        self::TYPE_CHECKBOX,
        self::TYPE_SELECT,
        self::TYPE_NUMBER,
    ];

    /**
     * @param array<string,string> $options
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $type = self::TYPE_TEXT,
        public bool $required = false,
        public string $placeholder = '',
        public string|int|bool|null $defaultValue = null,
        public array $options = [],
        public int $span = 6,
        public bool $secret = false,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $key) !== 1) {
            throw new \InvalidArgumentException((string)__('存储配置字段代码无效。'));
        }
        if (
            trim($label) === ''
            || preg_match('//u', $label) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $label) === 1
            || strlen($label) > 255
            || preg_match('/[\x00-\x1F\x7F]/', $placeholder) === 1
            || strlen($placeholder) > 512
            || !in_array($type, self::TYPES, true)
            || !in_array($span, [4, 6, 8, 12], true)
            || ($secret && $type !== self::TYPE_PASSWORD)
            || count($options) > 50
            || ($type === self::TYPE_SELECT && $options === [])
            || ($type !== self::TYPE_SELECT && $options !== [])
        ) {
            throw new \InvalidArgumentException((string)__('存储配置字段定义无效。'));
        }
        foreach ($options as $value => $optionLabel) {
            if (
                !is_string($value)
                || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,63}$/D', $value) !== 1
                || !is_string($optionLabel)
                || trim($optionLabel) === ''
                || preg_match('//u', $optionLabel) !== 1
                || preg_match('/[\x00-\x1F\x7F]/', $optionLabel) === 1
                || strlen($optionLabel) > 255
            ) {
                throw new \InvalidArgumentException((string)__('存储配置选项定义无效。'));
            }
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
            'required' => $this->required,
            'placeholder' => $this->placeholder,
            'default' => $this->defaultValue,
            'options' => $this->options,
            'span' => $this->span,
            'secret' => $this->secret,
        ];
    }
}
