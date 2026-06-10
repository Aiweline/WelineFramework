<?php

declare(strict_types=1);

namespace Weline\Payment\Api\Data;

final class ScopeContext extends AbstractPaymentData
{
    public const FIELD_SCOPE = 'scope';
    public const FIELD_ENVIRONMENT = 'environment';
    public const FIELD_PAYABLE_TYPE = 'payable_type';
    public const FIELD_PAYABLE_ID = 'payable_id';
    public const FIELD_ACTION = 'action';
    public const FIELD_ACTOR = 'actor';
    public const FIELD_CONTEXT = 'context';

    public function getScope(): string
    {
        return $this->getString(self::FIELD_SCOPE, 'default');
    }

    public function getEnvironment(): string
    {
        return $this->getString(self::FIELD_ENVIRONMENT, 'sandbox');
    }

    public function getAction(): string
    {
        return $this->getString(self::FIELD_ACTION, 'view');
    }

    public function getActor(): ?Actor
    {
        $actor = $this->getData(self::FIELD_ACTOR);
        if ($actor instanceof Actor) {
            return $actor;
        }

        return \is_array($actor) ? Actor::fromArray($actor) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->getArray(self::FIELD_CONTEXT);
    }
}
