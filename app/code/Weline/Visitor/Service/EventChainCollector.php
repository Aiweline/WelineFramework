<?php

declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Interface\EventChainProviderInterface;

/**
 * 收集各模块注册的事件链：Framework 事件 + Extends Provider。
 */
class EventChainCollector
{
    public const EVENT_COLLECT = 'Weline_Visitor::event_chain_collect';

    public function __construct(
        private readonly ?EventsManager $eventsManager = null,
        private readonly ?ObjectManager $objectManager = null,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function collect(int $websiteId = 0): array
    {
        $chains = [];

        foreach ($this->collectFromEvent($websiteId) as $chain) {
            if (\is_array($chain)) {
                $chains[] = $chain;
            }
        }
        foreach ($this->collectFromProviders($websiteId) as $chain) {
            if (\is_array($chain)) {
                $chains[] = $chain;
            }
        }

        return $chains;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectFromEvent(int $websiteId): array
    {
        $payload = [
            'website_id' => \max(0, $websiteId),
            'chains' => [],
        ];
        try {
            $this->events()->dispatch(self::EVENT_COLLECT, $payload);
        } catch (\Throwable $e) {
            if (\defined('DEV') && DEV) {
                w_log_error('EventChainCollector event failed: ' . $e->getMessage());
            }

            return [];
        }
        $chains = $payload['chains'] ?? [];

        return \is_array($chains) ? \array_values($chains) : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectFromProviders(int $websiteId): array
    {
        $out = [];
        foreach ($this->providerInstances() as $provider) {
            try {
                $rows = $provider->getEventChains($websiteId);
            } catch (\Throwable $e) {
                if (\defined('DEV') && DEV) {
                    w_log_error('EventChainProvider failed: ' . $e->getMessage());
                }
                continue;
            }
            if (!\is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if (\is_array($row)) {
                    $out[] = $row;
                }
            }
        }

        return $out;
    }

    /**
     * @return list<EventChainProviderInterface>
     */
    private function providerInstances(): array
    {
        $providers = [];
        try {
            $extendedBy = ExtendsData::getExtendedBy('Weline_Visitor');
            $modules = Env::getInstance()->getModuleList();
        } catch (\Throwable) {
            return [];
        }

        foreach ($extendedBy as $sourceModule => $extensions) {
            $sourceModuleInfo = $modules[$sourceModule] ?? null;
            if (empty($sourceModuleInfo) || !($sourceModuleInfo['status'] ?? false)) {
                continue;
            }
            foreach ((array)$extensions as $extension) {
                if (($extension['is_sticker_extension'] ?? false) === true) {
                    continue;
                }
                $relativePath = (string)($extension['relative_path'] ?? '');
                if (!\str_starts_with($relativePath, 'extends/module/Weline_Visitor/EventChainProvider/')) {
                    continue;
                }
                $sourceFile = (string)($extension['source_file'] ?? '');
                if ($sourceFile === '' || !\is_file($sourceFile)) {
                    continue;
                }
                $className = $this->classNameFromFile($sourceFile);
                if ($className === '') {
                    continue;
                }
                try {
                    require_once $sourceFile;
                    if (!\class_exists($className)) {
                        continue;
                    }
                    $instance = $this->om()->getInstance($className);
                    if ($instance instanceof EventChainProviderInterface) {
                        $providers[] = $instance;
                    }
                } catch (\Throwable $e) {
                    if (\defined('DEV') && DEV) {
                        w_log_error('EventChainProvider load failed: ' . $className . ' ' . $e->getMessage());
                    }
                }
            }
        }

        return $providers;
    }

    private function classNameFromFile(string $file): string
    {
        $content = @\file_get_contents($file);
        if ($content === false || $content === '') {
            return '';
        }
        $ns = '';
        if (\preg_match('/namespace\s+([^;]+);/', $content, $m)) {
            $ns = \trim($m[1]);
        }
        if (!\preg_match('/class\s+(\w+)/', $content, $m)) {
            return '';
        }
        $class = $m[1];

        return $ns !== '' ? ($ns . '\\' . $class) : $class;
    }

    private function events(): EventsManager
    {
        return $this->eventsManager ?? ObjectManager::getInstance(EventsManager::class);
    }

    private function om(): ObjectManager
    {
        return $this->objectManager ?? ObjectManager::getInstance();
    }
}
