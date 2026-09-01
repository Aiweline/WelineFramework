<?php

declare(strict_types=1);

namespace Weline\Order\Api\Data\Tracking;

final class TrackingTestConnectionRequest extends AbstractTrackingData
{
    public const FIELD_ENVIRONMENT = 'environment';
    public const FIELD_CONTEXT = 'context';

    public function getEnvironment(): string
    {
        return $this->getString(self::FIELD_ENVIRONMENT, 'sandbox');
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->getArray(self::FIELD_CONTEXT);
    }
}
