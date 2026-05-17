<?php

declare(strict_types=1);

namespace WeShop\Catalog\Observer;

use WeShop\Catalog\Model\Category;
use WeShop\Catalog\Service\CategoryService;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Hook\Config\HookReader;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Runtime;

class WorkerBootstrapWarmup implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        if (!Runtime::isPersistent()) {
            return;
        }

        $this->tryWarm(function (): void {
            $this->warmCategoryData();
        });
        $this->tryWarm(function (): void {
            $this->warmHookRegistries();
        });
    }

    private function warmCategoryData(): void
    {
        ObjectManager::getInstance(Category::class);

        /** @var CategoryService $categoryService */
        $categoryService = ObjectManager::getInstance(CategoryService::class);
        $categoryService->getCategoryTree(0);
        $categoryService->getRightMenuCategoryTree(0);
        $categoryService->getHeaderSearchCategoryOptions(0);
    }

    private function warmHookRegistries(): void
    {
        foreach ([
            'WeShop_Catalog::frontend::layouts::category::products-content',
            'Weline_Theme::frontend::layouts::category::subcategories-filter',
            'Weline_Theme::frontend::partials::breadcrumb::items',
            'Weline_Theme::frontend::partials::header::categories-before',
            'Weline_Theme::frontend::partials::header::search-form-before',
        ] as $hookName) {
            /** @var HookReader $reader */
            $reader = ObjectManager::make(HookReader::class);
            $reader->setPath($hookName);
            $reader->getFileListWithMeta();
            $reader->getFileList();
        }
    }

    /**
     * @param callable(): void $callback
     */
    private function tryWarm(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            if (\function_exists('w_log_warning')) {
                \w_log_warning('[CatalogWorkerWarmup] ' . $e->getMessage());
            }
        }
    }
}
