<?php

declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

/**
 * AI Model Monitoring Entity
 * 
 * @package Weline_Ai
 */
class AiModelMonitoring extends Model
{
    public function _init(): void
    {
        $this->_table = 'ai_model_monitoring';
        $this->_id_field_name = 'id';
    }

    public function setup(ModelSetup $setup, Context $context): void {}
    public function upgrade(ModelSetup $setup, Context $context): void {}
    public function install(ModelSetup $setup, Context $context): void {}

    public function getSuccessRate(): float
    {
        $total = $this->getData('request_count');
        if ($total == 0) {
            return 0.0;
        }

        $success = $this->getData('success_count');
        return ($success / $total) * 100;
    }

    public function getErrorRate(): float
    {
        $total = $this->getData('request_count');
        if ($total == 0) {
            return 0.0;
        }

        $errors = $this->getData('error_count');
        return ($errors / $total) * 100;
    }

    public function incrementRequest(bool $success = true, float $responseTime = 0, float $cost = 0): void
    {
        $this->setData('request_count', $this->getData('request_count') + 1);
        
        if ($success) {
            $this->setData('success_count', $this->getData('success_count') + 1);
        } else {
            $this->setData('error_count', $this->getData('error_count') + 1);
        }

        $this->setData('total_cost', $this->getData('total_cost') + $cost);
        
        // Update average response time
        $this->updateAverageResponseTime($responseTime);
    }

    private function updateAverageResponseTime(float $newTime): void
    {
        $count = $this->getData('request_count');
        $currentAvg = $this->getData('avg_response_time') ?? 0;
        
        $newAvg = (($currentAvg * ($count - 1)) + $newTime) / $count;
        $this->setData('avg_response_time', $newAvg);
    }
}

