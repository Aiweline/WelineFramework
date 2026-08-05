<?php

declare(strict_types=1);

namespace Weline\Eav\Api\Attribute;

/**
 * Public boundary for resolving an EAV attribute's dependence options.
 *
 * Consumers provide EAV identifiers and scalar values only. Implementations
 * keep EAV ORM models and attribute-type model classes inside Weline_Eav.
 */
interface AttributeDependenceResolverInterface
{
    /**
     * @param array{
     *     eav_entity_id: int|numeric-string,
     *     dependence_attribute: string,
     *     dependence_value: scalar|list<scalar>,
     *     attribute: string,
     *     attribute_value?: scalar|list<scalar>|null
     * } $params
     * @return array<int|string, scalar>
     * @throws \InvalidArgumentException When required input is missing or invalid.
     * @throws \DomainException When EAV metadata or the type-model result is invalid.
     */
    public function resolve(array $params): array;
}
