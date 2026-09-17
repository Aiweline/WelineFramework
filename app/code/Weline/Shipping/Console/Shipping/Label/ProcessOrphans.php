<?php

declare(strict_types=1);

namespace Weline\Shipping\Console\Shipping\Label;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Shipping\Service\ShippingLabelOrphanProcessor;

final class ProcessOrphans extends CommandAbstract
{
    public function tip(): string
    {
        return 'Process Shipping label orphan cancel queue (--limit=20).';
    }

    public function execute(array $args = [], array $data = []): string
    {
        $printing = ObjectManager::getInstance(Printing::class);
        $limit = 20;
        foreach ($args as $arg) {
            if (!\is_string($arg) || !str_starts_with($arg, '--limit=')) {
                continue;
            }
            $limit = max(1, (int)substr($arg, 8));
        }
        /** @var ShippingLabelOrphanProcessor $processor */
        $processor = ObjectManager::getInstance(ShippingLabelOrphanProcessor::class);
        $result = $processor->processDue($limit);
        $printing->success(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}');

        return 'OK';
    }
}
