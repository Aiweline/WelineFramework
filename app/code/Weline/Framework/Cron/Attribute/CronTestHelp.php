<?php

declare(strict_types=1);

namespace Weline\Framework\Cron\Attribute;

/**
 * Describes an optional manual-test surface for a cron task.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class CronTestHelp
{
    /**
     * @param list<string> $examples
     * @param list<string> $manual_help
     */
    public function __construct(
        public string $description = '',
        public array $examples = [],
        public array $manual_help = [],
    ) {
    }
}
