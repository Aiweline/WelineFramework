<?php

declare(strict_types=1);

namespace Weline\Backend\Integration\Acl;

use Weline\Acl\Api\Resource\MenuSourceProviderInterface;
use Weline\Backend\Config\MenuXmlReader;

final class MenuSourceProvider implements MenuSourceProviderInterface
{
    public function __construct(
        private readonly MenuXmlReader $menuReader,
    ) {
    }

    public function sourceIds(): array
    {
        $sources = [];
        foreach ($this->menuReader->read() as $menus) {
            foreach (($menus['data'] ?? []) as $menu) {
                $source = (string)($menu['source'] ?? '');
                if ($source !== '') {
                    $sources[] = $source;
                }
            }
        }
        return $sources;
    }
}
