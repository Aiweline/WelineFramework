<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Connection\Api;

/** Optional exact-view catalog and mutation capability. */
interface PhysicalViewMetadataInterface
{
    public function quotePhysicalView(PhysicalViewIdentity $identity): string;

    public function physicalViewExists(PhysicalViewIdentity $identity): bool;

    public function getPhysicalViewDefinition(PhysicalViewIdentity $identity): string;

    public function createOrReplacePhysicalView(
        PhysicalViewIdentity $identity,
        string $selectSql,
        bool $replace,
    ): void;

    public function dropPhysicalViewIfExists(PhysicalViewIdentity $identity): void;
}
