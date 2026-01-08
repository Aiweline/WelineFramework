<?php

declare(strict_types=1);

namespace Weline\AiKnowledge\Console;

use Weline\AiKnowledge\Service\SearchService;
use Weline\AiKnowledge\Service\Collector\DbDocCollector;
use Weline\AiKnowledge\Service\Collector\ReflectApiCollector;
use Weline\AiKnowledge\Service\Collector\SourceGraphCollector;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

/**
 * Index Command
 * 
 * Builds or rebuilds the AI knowledge index.
 * 
 * Usage:
 *   php weline ai:index              - Build full index
 *   php weline ai:index --stats      - Show index statistics
 *   php weline ai:index --clear      - Clear the index
 *   php weline ai:index --collector=db_doc  - Run specific collector
 */
class IndexCommand implements CommandInterface
{
    private Printing $printing;
    private SearchService $searchService;
    
    /**
     * Available collectors
     */
    private array $collectors = [
        'db_doc' => DbDocCollector::class,
        'reflect_api' => ReflectApiCollector::class,
        'source_graph' => SourceGraphCollector::class,
    ];
    
    public function __construct()
    {
        $this->printing = new Printing();
        $this->searchService = ObjectManager::getInstance(SearchService::class);
    }
    
    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = []): void
    {
        // Parse arguments
        $showStats = in_array('--stats', $args);
        $clearIndex = in_array('--clear', $args);
        $showHelp = in_array('--help', $args) || in_array('-h', $args);
        
        // Check for collector filter
        $collectorFilter = null;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--collector=')) {
                $collectorFilter = substr($arg, strlen('--collector='));
                break;
            }
        }
        
        if ($showHelp) {
            $this->showHelp();
            return;
        }
        
        if ($showStats) {
            $this->showStats();
            return;
        }
        
        if ($clearIndex) {
            $this->clearIndex();
            return;
        }
        
        $this->buildIndex($collectorFilter);
    }
    
    /**
     * Build the index
     */
    private function buildIndex(?string $collectorFilter): void
    {
        $this->printing->println('Building AI Knowledge Index...');
        $this->printing->println('');
        
        // Determine which collectors to run
        $collectorsToRun = [];
        if ($collectorFilter) {
            if (!isset($this->collectors[$collectorFilter])) {
                $this->printing->error("Unknown collector: {$collectorFilter}");
                $this->printing->println("Available collectors: " . implode(', ', array_keys($this->collectors)));
                return;
            }
            $collectorsToRun = [$this->collectors[$collectorFilter]];
            $this->printing->println("Running collector: {$collectorFilter}");
        } else {
            $collectorsToRun = array_values($this->collectors);
            $this->printing->println("Running all collectors: " . implode(', ', array_keys($this->collectors)));
        }
        
        $this->printing->println('');
        
        $startTime = microtime(true);
        $stats = $this->searchService->buildIndex($collectorsToRun);
        $elapsed = round(microtime(true) - $startTime, 2);
        
        $this->printing->println('');
        $this->printing->success("Index build completed in {$elapsed}s");
        $this->printing->println("  Documents indexed: {$stats['documents']}");
        $this->printing->println("  Total chunks: {$stats['chunks']}");
        
        if (!empty($stats['errors'])) {
            $this->printing->println('');
            $this->printing->warning("Errors encountered:");
            foreach ($stats['errors'] as $error) {
                $this->printing->println("  - {$error}");
            }
        }
    }
    
    /**
     * Show index statistics
     */
    private function showStats(): void
    {
        $stats = $this->searchService->getStats();
        
        $this->printing->println('AI Knowledge Index Statistics');
        $this->printing->println('=============================');
        $this->printing->println("  Documents: {$stats['documents']}");
        $this->printing->println("  Chunks: {$stats['chunks']}");
    }
    
    /**
     * Clear the index
     */
    private function clearIndex(): void
    {
        $this->printing->println('Clearing AI Knowledge Index...');
        
        $this->searchService->clearIndex();
        
        $this->printing->success('Index cleared successfully');
    }
    
    /**
     * Show help
     */
    private function showHelp(): void
    {
        $help = <<<HELP
Weline AI Knowledge - Index Management

USAGE:
  php weline ai:index [OPTIONS]

DESCRIPTION:
  Builds or manages the AI knowledge index used for semantic search.

OPTIONS:
  -h, --help              Show this help message
  --stats                 Show index statistics
  --clear                 Clear the entire index
  --collector=NAME        Run only a specific collector

COLLECTORS:
  db_doc          Database documentation (table structures, comments)
  reflect_api     API reflection (PHPDoc, Attributes)
  source_graph    Source code topology (class hierarchies, dependencies)

EXAMPLES:
  # Build full index
  php weline ai:index

  # Show statistics
  php weline ai:index --stats

  # Clear index
  php weline ai:index --clear

  # Run only API collector
  php weline ai:index --collector=reflect_api

HELP;
        
        $this->printing->println($help);
    }
    
    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return 'Build or manage the AI knowledge index for semantic search';
    }
    
    /**
     * @inheritDoc
     */
    public function help(): array|string
    {
        return [
            'usage' => 'php weline ai:index [OPTIONS]',
            'options' => [
                '--stats' => 'Show index statistics',
                '--clear' => 'Clear the index',
                '--collector=NAME' => 'Run specific collector (db_doc, reflect_api, source_graph)',
            ],
            'description' => 'Builds the AI knowledge index from documentation, APIs, and source code.',
        ];
    }
}
