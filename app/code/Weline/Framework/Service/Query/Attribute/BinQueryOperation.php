<?php
declare(strict_types=1);

namespace Weline\Framework\Service\Query\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class BinQueryOperation
{
    public function __construct(
        public readonly string $name,
        public readonly string $description = '',
        public readonly string $mode = 'read',
        /** Default deny: stand outside /bin/query only when explicitly true. */
        public readonly bool $external = false,
        /** Default deny: Worker/query-bin only when explicitly true. */
        public readonly bool $frontend = false,
        public readonly bool $backend = false,
        public readonly bool $graph = false,
        public readonly int $cost = 1,
        public readonly string $summary = '',
        /**
         * Worker auth: any|guest|customer|backend.
         * Empty = undeclared. Worker treats undeclared as open today — new ops MUST set
         * explicitly (public → any; admin → backend + backend_acl in descriptor).
         */
        public readonly string $auth = ''
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toDescriptor(): array
    {
        $descriptor = [
            'name' => $this->name,
            'description' => $this->description,
            'mode' => $this->mode,
            'external' => $this->external,
            'frontend' => $this->frontend,
            'backend' => $this->backend,
            'graph' => $this->graph,
            'cost' => $this->cost,
        ];

        if ($this->summary !== '') {
            $descriptor['summary'] = $this->summary;
        }
        if ($this->auth !== '') {
            $descriptor['auth'] = $this->auth;
        }

        return $descriptor;
    }
}
