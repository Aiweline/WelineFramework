<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Resource;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Resource\Config\ResourceReaderInterface;

class Compiler implements CompilerInterface
{
    protected ?EventsManager $eventsManager = null;

    protected ?ResourceReaderInterface $reader;

    public function setReader(ResourceReaderInterface $resourceReader): static
    {
        $this->reader = $resourceReader;
        return $this;
    }

    public function getEventManager(): EventsManager
    {
        if (!isset($this->eventsManager, $_)) {
            $this->eventsManager = ObjectManager::getInstance(EventsManager::class);
        }
        return $this->eventsManager;
    }

    public function compile(string $source_file = null, string $out_file = null)
    {
        $config_resources = $this->reader->getResourceFiles();
        foreach ($config_resources as $area => $config_resource) {
            $this->getEventManager()->dispatch(
                'Framework_Resource::compiler',
                ['data' => new DataObject(
                    [
                        'area'      => $area,
                        'type'      => $this->reader->getSourceType(),
                        'resources' => $config_resource
                    ]
                )]
            );
        }
    }
}
