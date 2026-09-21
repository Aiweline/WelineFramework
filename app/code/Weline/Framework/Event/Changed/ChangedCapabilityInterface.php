<?php

declare(strict_types=1);

namespace Weline\Framework\Event\Changed;

use Weline\Framework\Event\ResourceChange\ResourceChange;

interface ChangedCapabilityInterface
{
    public const EXTENDS_RELATIVE_PREFIX = 'extends/module/weline_framework/changed/capability/';

    public function code(): string;

    public function description(): string;

    /** @return list<string> */
    public function supportedEffects(): array;

    /**
     * @param InvalidationEffect $effect
     * @param ResourceChange $change 已 Enrich 后的信封
     */
    public function execute(InvalidationEffect $effect, ResourceChange $change): void;
}
