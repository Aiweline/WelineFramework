<?php

declare(strict_types=1);

namespace Weline\AiKnowledge\Service\Collector;

/**
 * Interface for Knowledge Collectors
 * 
 * Collectors gather information from various sources to build the knowledge base.
 */
interface CollectorInterface
{
    /**
     * Collect knowledge from the source
     * 
     * @param array $options Collection options
     * @return array Collected knowledge items
     */
    public function collect(array $options = []): array;
    
    /**
     * Get the collector type identifier
     */
    public function getType(): string;
    
    /**
     * Get the collector description
     */
    public function getDescription(): string;
}
