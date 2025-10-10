<?php

declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

/**
 * AI Model Entity
 * 
 * Represents an AI model with its metadata, configuration, and capabilities.
 * Supports model copying functionality with origin tracking.
 * 
 * @package Weline_Ai
 */
class AiModel extends Model
{
    /**
     * Model status constants
     */
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DEPRECATED = 'deprecated';
    public const STATUS_MAINTENANCE = 'maintenance';

    /**
     * Initialize model
     *
     * @return void
     */
    public function _init(): void
    {
        $this->_table = 'ai_model';
        $this->_id_field_name = 'id';
    }

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        // Table creation is handled in Setup/Install.php
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // No upgrades yet
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        // Table creation is handled in Setup/Install.php
    }

    /**
     * Check if this is a copy model
     *
     * @return bool
     */
    public function isCopy(): bool
    {
        return (bool) $this->getData('is_copy');
    }

    /**
     * Check if this is an original model
     *
     * @return bool
     */
    public function isOriginal(): bool
    {
        return !$this->isCopy();
    }

    /**
     * Get origin model ID (if this is a copy)
     *
     * @return int|null
     */
    public function getOriginModelId(): ?int
    {
        $originId = $this->getData('origin_model_id');
        return $originId ? (int) $originId : null;
    }

    /**
     * Check if model can be deleted
     * Original models (is_copy=false) cannot be deleted
     *
     * @return bool
     */
    public function canDelete(): bool
    {
        return $this->isCopy();
    }

    /**
     * Get model configuration as array
     *
     * @return array
     */
    public function getConfig(): array
    {
        $config = $this->getData('config');
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($config) ? $config : [];
    }

    /**
     * Get model capabilities as array
     *
     * @return array
     */
    public function getCapabilities(): array
    {
        $capabilities = $this->getData('capabilities');
        if (is_string($capabilities)) {
            $decoded = json_decode($capabilities, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($capabilities) ? $capabilities : [];
    }

    /**
     * Check if model is active
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->getData('status') === self::STATUS_ACTIVE;
    }

    /**
     * Validate model data before save
     *
     * @return bool
     */
    public function validate(): bool
    {
        // Original models must not have origin_model_id
        if (!$this->isCopy() && $this->getOriginModelId() !== null) {
            throw new \InvalidArgumentException(
                'Original models (is_copy=false) cannot have origin_model_id'
            );
        }

        // Copy models must have origin_model_id
        if ($this->isCopy() && $this->getOriginModelId() === null) {
            throw new \InvalidArgumentException(
                'Copy models (is_copy=true) must have origin_model_id'
            );
        }

        // Required fields
        if (empty($this->getData('supplier'))) {
            throw new \InvalidArgumentException('Supplier is required');
        }

        if (empty($this->getData('model_code'))) {
            throw new \InvalidArgumentException('Model code is required');
        }

        if (empty($this->getData('name'))) {
            throw new \InvalidArgumentException('Name is required');
        }

        return true;
    }

    /**
     * Before save callback
     *
     * @return $this
     */
    public function beforeSave(): self
    {
        $this->validate();
        
        // Ensure JSON fields are properly encoded
        if (is_array($this->getData('config'))) {
            $this->setData('config', json_encode($this->getData('config')));
        }

        if (is_array($this->getData('capabilities'))) {
            $this->setData('capabilities', json_encode($this->getData('capabilities')));
        }

        return parent::beforeSave();
    }

    /**
     * Before delete callback
     *
     * @return $this
     */
    public function beforeDelete(): self
    {
        if (!$this->canDelete()) {
            throw new \RuntimeException(
                'Cannot delete original model. Only copy models can be deleted.'
            );
        }

        return parent::beforeDelete();
    }
}
