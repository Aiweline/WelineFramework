<?php

declare(strict_types=1);

namespace Weline\Framework\Service\Query\Value;

/**
 * Server-constructed authority context for one Worker session or stream ticket.
 */
final readonly class FrontendWorkerExecutionContext
{
    public const AREA_FRONTEND = 'frontend';
    public const AREA_BACKEND = 'backend';
    public const REQUEST_CONTEXT_KEY = 'frontend_worker.execution_context';

    private function __construct(
        public string $area,
        public ?FrontendWorkerScopeBinding $scopeBinding,
        public ?FrontendWorkerBackendBinding $backendBinding,
    ) {
        if ($area === self::AREA_FRONTEND) {
            if ($backendBinding !== null) {
                throw new \InvalidArgumentException('Frontend Worker context cannot contain a backend binding.');
            }
            return;
        }
        if ($area === self::AREA_BACKEND && $backendBinding !== null && $scopeBinding === null) {
            return;
        }

        throw new \InvalidArgumentException('Worker execution context authority is invalid.');
    }

    public static function frontend(?FrontendWorkerScopeBinding $scopeBinding = null): self
    {
        return new self(self::AREA_FRONTEND, $scopeBinding, null);
    }

    public static function backend(FrontendWorkerBackendBinding $backendBinding): self
    {
        return new self(self::AREA_BACKEND, null, $backendBinding);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        self::assertExactKeys($data, ['area', 'backend_binding', 'scope_binding']);
        if (!\is_string($data['area'])) {
            throw new \InvalidArgumentException('Worker execution context area is invalid.');
        }

        $scopeBinding = null;
        if ($data['scope_binding'] !== null) {
            if (!\is_array($data['scope_binding'])) {
                throw new \InvalidArgumentException('Worker execution Scope binding is invalid.');
            }
            $scopeBinding = FrontendWorkerScopeBinding::fromArray($data['scope_binding']);
        }
        $backendBinding = null;
        if ($data['backend_binding'] !== null) {
            if (!\is_array($data['backend_binding'])) {
                throw new \InvalidArgumentException('Worker execution backend binding is invalid.');
            }
            $backendBinding = FrontendWorkerBackendBinding::fromArray($data['backend_binding']);
        }

        return new self($data['area'], $scopeBinding, $backendBinding);
    }

    /** @return array{area:string,scope_binding:?array,backend_binding:?array} */
    public function toArray(): array
    {
        return [
            'area' => $this->area,
            'scope_binding' => $this->scopeBinding?->toArray(),
            'backend_binding' => $this->backendBinding?->toArray(),
        ];
    }

    /** @param array<string, mixed> $data @param list<string> $expected */
    private static function assertExactKeys(array $data, array $expected): void
    {
        if (\array_is_list($data)) {
            throw new \InvalidArgumentException('Worker execution context must be an object map.');
        }
        $actual = \array_keys($data);
        \sort($actual, SORT_STRING);
        \sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \InvalidArgumentException('Worker execution context fields are incomplete or unknown.');
        }
    }
}
