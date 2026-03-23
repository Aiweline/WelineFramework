<?php

declare(strict_types=1);

namespace WeShop\Auth\Data;

final class ActorContext implements \JsonSerializable
{
    public const ACTOR_CUSTOMER = 'customer';
    public const ACTOR_BACKEND = 'backend';
    public const ACTOR_INTEGRATION = 'integration';

    /**
     * @param string[] $scopes
     */
    public function __construct(
        private readonly string $actorType,
        private readonly int $actorId,
        private readonly string $area,
        private readonly array $scopes = [],
        private readonly bool $is2faVerified = false
    ) {
    }

    public function getActorType(): string
    {
        return $this->actorType;
    }

    public function getActorId(): int
    {
        return $this->actorId;
    }

    public function getArea(): string
    {
        return $this->area;
    }

    /**
     * @return string[]
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    public function is2faVerified(): bool
    {
        return $this->is2faVerified;
    }

    public function withTwoFactorVerified(bool $verified = true): self
    {
        return new self(
            $this->actorType,
            $this->actorId,
            $this->area,
            $this->scopes,
            $verified
        );
    }

    public function toArray(): array
    {
        return [
            'actor_type' => $this->actorType,
            'actor_id' => $this->actorId,
            'area' => $this->area,
            'scopes' => $this->scopes,
            'is_2fa_verified' => $this->is2faVerified,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
