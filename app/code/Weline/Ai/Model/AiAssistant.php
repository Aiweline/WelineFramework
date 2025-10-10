<?php

declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

/**
 * AI Assistant Entity
 * 
 * @package Weline_Ai
 */
class AiAssistant extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_ARCHIVED = 'archived';

    public function _init(): void
    {
        $this->_table = 'ai_assistant';
        $this->_id_field_name = 'id';
    }

    public function setup(ModelSetup $setup, Context $context): void {}
    public function upgrade(ModelSetup $setup, Context $context): void {}
    public function install(ModelSetup $setup, Context $context): void {}

    public function isActive(): bool
    {
        return $this->getData('status') === self::STATUS_ACTIVE;
    }

    public function getConfig(): array
    {
        $config = $this->getData('config');
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($config) ? $config : [];
    }

    public function incrementUsageCount(): void
    {
        $this->setData('usage_count', $this->getData('usage_count') + 1);
    }

    public function validate(): bool
    {
        if (empty($this->getData('name'))) {
            throw new \InvalidArgumentException('Assistant name is required');
        }

        if (empty($this->getData('prompt_template'))) {
            throw new \InvalidArgumentException('Prompt template is required');
        }

        if (empty($this->getData('model_id'))) {
            throw new \InvalidArgumentException('Model ID is required');
        }

        if (empty($this->getData('tenant_id'))) {
            throw new \InvalidArgumentException('Tenant ID is required');
        }

        return true;
    }

    public function beforeSave(): self
    {
        $this->validate();
        
        if (is_array($this->getData('config'))) {
            $this->setData('config', json_encode($this->getData('config')));
        }

        return parent::beforeSave();
    }
}
