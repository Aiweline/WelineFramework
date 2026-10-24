<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Theme\Service\ThemeLayoutLocator;

/**
 * Last-resort layout_resolve: public path ↔ Theme layout file 1:1.
 * Modules claim first; option defaults to "default" when the path is a single segment.
 * Claims module-shipped layouts and page types declared by the active design theme chain.
 */
final class LayoutResolveObserver implements ObserverInterface
{
    public function __construct(
        private readonly ThemeLayoutLocator $layoutLocator,
    ) {
    }

    public function execute(Event &$event): void
    {
        $data = $event->getData('data');
        if (!$data instanceof DataObject) {
            return;
        }
        if ((bool)$data->getData('claimed')) {
            return;
        }

        $requestPath = strtolower(trim(str_replace('\\', '/', (string)$data->getData('request_path')), '/'));
        if ($requestPath === ''
            || str_contains($requestPath, '..')
            || str_contains($requestPath, '.')
        ) {
            return;
        }

        if (!str_contains($requestPath, '/')) {
            if (!$this->layoutExists($requestPath, 'default')) {
                return;
            }
            $data->setData('claimed', true);
            $data->setData('layout_path', $requestPath);
            $data->setData('layout_option', 'default');
            $data->setData('entity_slug', '');
            $data->setData('entity_kind', '');

            return;
        }

        $parts = explode('/', $requestPath);
        if (count($parts) !== 2) {
            return;
        }
        [$layoutPath, $layoutOption] = $parts;
        if ($layoutPath === '' || $layoutOption === ''
            || !preg_match('/^[a-z][a-z0-9_-]*$/', $layoutPath)
            || !preg_match('/^[a-z][a-z0-9_-]*$/', $layoutOption)
        ) {
            return;
        }
        if (!$this->layoutExists($layoutPath, $layoutOption)) {
            return;
        }

        $data->setData('claimed', true);
        $data->setData('layout_path', $layoutPath);
        $data->setData('layout_option', $layoutOption);
        $data->setData('entity_slug', '');
        $data->setData('entity_kind', '');
    }

    private function layoutExists(string $layoutPath, string $layoutOption): bool
    {
        $tmpDebugDirs = (new \ReflectionProperty($this->layoutLocator, 'themeDirectoryResolver'))->getValue($this->layoutLocator)->getAreaDirectories('frontend'); // TMPDEBUG
        file_put_contents(BP . 'tmp/theme-pollution/debug-router.log', date('c') . ' OBS ' . $layoutPath . '/' . $layoutOption . ' dirs=' . json_encode(array_map(static fn($d) => [$d['layer_type'] ?? '', $d['path'] ?? ''], $tmpDebugDirs)) . "\n", FILE_APPEND); // TMPDEBUG
        return $this->layoutLocator->moduleDefaultFile($layoutPath, $layoutOption) !== null
            || $this->layoutLocator->themeDeclaredFile($layoutPath, $layoutOption) !== null;
    }
}
