<?php

declare(strict_types=1);

namespace Weline\Framework\Service\Query\Auth;

final readonly class BinQueryAuthContext
{
    /**
     * @param list<array<string, mixed>> $accessSources
     * @param array<string, int|string|bool|null> $subject
     */
    public function __construct(
        private array $accessSources,
        private array $subject = [],
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getAccessSources(): array
    {
        return $this->accessSources;
    }

    /**
     * @return array<string, int|string|bool|null>
     */
    public function getSubject(): array
    {
        return $this->subject;
    }
}
