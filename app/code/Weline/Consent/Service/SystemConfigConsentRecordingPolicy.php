<?php

declare(strict_types=1);

namespace Weline\Consent\Service;

use Weline\Consent\Api\ConsentRecordingPolicyInterface;
use Weline\SystemConfig\Model\SystemConfig;

final class SystemConfigConsentRecordingPolicy implements ConsentRecordingPolicyInterface
{
    public const CONFIG_KEY = 'recording_enabled';

    public function __construct(
        private readonly SystemConfig $systemConfig,
    ) {
    }

    public function isRecordingEnabled(): bool
    {
        try {
            $value = $this->systemConfig->getConfig(
                self::CONFIG_KEY,
                'Weline_Consent',
                SystemConfig::area_BACKEND,
                true,
            );
            if (is_bool($value)) {
                return $value;
            }
            if (is_int($value) || is_float($value)) {
                return (int)$value === 1;
            }
            if (is_string($value)) {
                return match (strtolower(trim($value))) {
                    '1', 'true', 'yes', 'on' => true,
                    '0', 'false', 'no', 'off', '' => false,
                    default => false,
                };
            }

            return false;
        } catch (\Throwable $throwable) {
            if (defined('DEV') && DEV) {
                w_log_error('Consent recording policy unavailable: ' . $throwable->getMessage());
            }
            return false;
        }
    }
}
