<?php

declare(strict_types=1);

namespace Weline\Faq\Setup;

use Weline\Cms\Model\Page;
use Weline\Cms\Model\PathGroup;
use Weline\Faq\Api\Uri\FaqNamespace;
use Weline\Faq\Model\FaqItem;
use Weline\Faq\Service\FaqService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\InstallInterface;

final class Install implements InstallInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        $modelSetup = ObjectManager::make(ModelSetup::class);
        /** @var FaqItem $item */
        $item = ObjectManager::getInstance(FaqItem::class);
        $modelSetup->putModel($item);
        $item->setup($modelSetup, $context);

        ObjectManager::getInstance(FaqService::class)->seedSiteHubFaqs(0, '');
        $templateSeed = ObjectManager::getInstance(\Weline\Faq\Service\FaqTemplateSeedService::class);
        $templateSeed->migrateEmptyLocaleToZhHans();
        $templateSeed->seedAll();
        $this->migrateHelpPathGroupToFaq();
        $this->ensureProductFaqPublished();
        $this->rebuildFaqSearchIndex();
    }

    public function migrateHelpPathGroupToFaq(): void
    {
        try {
            if (!class_exists(Page::class) || !class_exists(PathGroup::class)) {
                return;
            }
            /** @var PathGroup $group */
            $group = ObjectManager::getInstance(PathGroup::class);
            $groups = $group->clear()
                ->where(PathGroup::schema_fields_PATH_GROUP, 'help')
                ->select()
                ->fetchArray();
            if (is_array($groups)) {
                foreach ($groups as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $id = (int)($row[PathGroup::schema_fields_ID] ?? 0);
                    $websiteId = (int)($row[PathGroup::schema_fields_WEBSITE_ID] ?? 0);
                    if ($id <= 0) {
                        continue;
                    }
                    $existingFaq = $group->clear()
                        ->where(PathGroup::schema_fields_WEBSITE_ID, $websiteId)
                        ->where(PathGroup::schema_fields_PATH_GROUP, FaqNamespace::PREFIX)
                        ->find()
                        ->fetch();
                    if ($existingFaq && (int)$existingFaq->getGroupId() > 0) {
                        // faq path group already exists — soft-delete duplicate help group.
                        $group->clear()->load($id);
                        if ((int)$group->getGroupId() === $id) {
                            $group->setData(PathGroup::schema_fields_DELETED_AT, date('Y-m-d H:i:s'));
                            $group->save();
                        }
                        continue;
                    }
                    $group->clear()->load($id);
                    if ((int)$group->getGroupId() !== $id) {
                        continue;
                    }
                    $group->setData(PathGroup::schema_fields_PATH_GROUP, FaqNamespace::PREFIX);
                    $alias = trim((string)$group->getAlias());
                    if ($alias === '' || strcasecmp($alias, 'help') === 0 || $alias === '帮助中心') {
                        $group->setData(PathGroup::schema_fields_ALIAS, (string)__('FAQ'));
                    }
                    $group->setData(PathGroup::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'));
                    $group->save();
                }
            }

            /** @var Page $page */
            $page = ObjectManager::getInstance(Page::class);
            $pages = $page->clear()
                ->where(Page::schema_fields_PATH_GROUP, 'help')
                ->select()
                ->fetchArray();
            if (!is_array($pages)) {
                return;
            }
            foreach ($pages as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $pageId = (int)($row[Page::schema_fields_ID] ?? $row['page_id'] ?? 0);
                if ($pageId <= 0) {
                    continue;
                }
                $page->clear()->load($pageId);
                if ((int)$page->getPageId() !== $pageId) {
                    continue;
                }
                $identifier = trim((string)$page->getIdentifier());
                if (str_starts_with(strtolower($identifier), 'help/')) {
                    $identifier = FaqNamespace::PREFIX . substr($identifier, 4);
                } elseif (strtolower($identifier) === 'help') {
                    $identifier = FaqNamespace::PREFIX;
                }
                $page->setData(Page::schema_fields_PATH_GROUP, FaqNamespace::PREFIX);
                $page->setData(Page::schema_fields_IDENTIFIER, $identifier);
                $alias = trim((string)$page->getData(Page::schema_fields_PATH_GROUP_ALIAS));
                if ($alias === '' || strcasecmp($alias, 'help') === 0) {
                    $page->setData(Page::schema_fields_PATH_GROUP_ALIAS, (string)__('FAQ'));
                }
                $page->save();
            }
        } catch (\Throwable) {
            // CMS optional during early install.
        }
    }

    public function ensureProductFaqPublished(): void
    {
        if (!class_exists(\Weline\Theme\Service\WidgetDefaultInjectionService::class)
            || !class_exists(\Weline\Theme\Service\ThemeLayoutService::class)
            || !class_exists(\Weline\Theme\Model\WelineTheme::class)
            || !class_exists(\Weline\Theme\Model\ThemeLayout::class)
        ) {
            return;
        }

        try {
            /** @var \Weline\Theme\Model\WelineTheme $themeModel */
            $themeModel = ObjectManager::getInstance(\Weline\Theme\Model\WelineTheme::class);
            $theme = $themeModel->clear()->where(\Weline\Theme\Model\WelineTheme::schema_fields_IS_ACTIVE, 1)->find()->fetch();
            $themeId = (int)($theme?->getId() ?? 0);
            if ($themeId <= 0) {
                $themeId = (int)($themeModel->clear()->load(1)->getId() ?? 0);
            }
            if ($themeId <= 0) {
                return;
            }

            $scopes = [
                'default.__store__.__channel__',
                'default.__store__.default',
                'default.__website__.default',
                'default.default.default',
                'default',
            ];

            $injection = ObjectManager::getInstance(\Weline\Theme\Service\WidgetDefaultInjectionService::class);
            $layoutService = ObjectManager::getInstance(\Weline\Theme\Service\ThemeLayoutService::class);

            foreach ($scopes as $scope) {
                $identity = [
                    'layout_option' => 'default',
                    'scope' => $scope,
                    'locale_code' => '',
                    'target_type' => 'global',
                    'target_id' => 0,
                ];

                $this->purgeIdentityLessDraftWidgets($themeId, $identity);

                $injection->applyRequiredMissingForIdentity(
                    $themeId,
                    \Weline\Theme\Model\ThemeLayout::PAGE_TYPE_PRODUCT,
                    $identity,
                    \Weline\Theme\Service\PreviewContextService::AREA_FRONTEND,
                    \Weline\Theme\Model\ThemeLayout::STATUS_DRAFT,
                );

                try {
                    $layoutService->publishLayout(
                        $themeId,
                        \Weline\Theme\Model\ThemeLayout::PAGE_TYPE_PRODUCT,
                        $identity,
                        false,
                        ['reason' => 'product-faq storefront publish'],
                    );
                } catch (\Throwable) {
                    // Other scopes may still succeed; keep trying.
                }
            }
        } catch (\Throwable) {
            // Theme optional at install time.
        }
    }

    /**
     * Draft nodes missing widget_module/widget_code block Theme publish validators.
     *
     * @param array{layout_option?:string,scope?:string,locale_code?:string,target_type?:string,target_id?:int} $identity
     */
    private function purgeIdentityLessDraftWidgets(int $themeId, array $identity): void
    {
        if ($themeId <= 0
            || !class_exists(\Weline\Theme\Service\ThemeRuntimeLayoutResolver::class)
            || !class_exists(\Weline\Theme\Service\Scoped\ThemeScopedLayoutWriteService::class)
        ) {
            return;
        }

        try {
            /** @var \Weline\Theme\Service\ThemeLayoutService $layoutService */
            $layoutService = ObjectManager::getInstance(\Weline\Theme\Service\ThemeLayoutService::class);
            $draft = $layoutService->getDraftLayout(
                $themeId,
                \Weline\Theme\Model\ThemeLayout::PAGE_TYPE_PRODUCT,
                $identity,
            );
            if ($draft === []) {
                return;
            }

            /** @var \Weline\Theme\Service\ThemeRuntimeLayoutResolver $runtime */
            $runtime = ObjectManager::getInstance(\Weline\Theme\Service\ThemeRuntimeLayoutResolver::class);
            $context = $runtime->buildContext(
                $themeId,
                \Weline\Theme\Model\ThemeLayout::PAGE_TYPE_PRODUCT,
                \Weline\Theme\Service\PreviewContextService::AREA_FRONTEND,
                $identity,
            );
            /** @var \Weline\Theme\Service\Scoped\ThemeScopedLayoutWriteService $writer */
            $writer = ObjectManager::getInstance(\Weline\Theme\Service\Scoped\ThemeScopedLayoutWriteService::class);

            foreach (['header', 'banner', 'left_sidebar', 'content', 'right_sidebar', 'footer'] as $area) {
                $widgets = $draft[$area]['widgets'] ?? null;
                if (!\is_array($widgets)) {
                    continue;
                }
                foreach ($widgets as $widget) {
                    if (!\is_array($widget)) {
                        continue;
                    }
                    $module = \trim((string)($widget['widget_module'] ?? ''));
                    $code = \trim((string)($widget['widget_code'] ?? ''));
                    $nodeUid = \strtolower(\trim((string)($widget['node_uid'] ?? '')));
                    if (($module === '' || $code === '') && $nodeUid !== '') {
                        $writer->removeWidget($context, $nodeUid, 'system:faq-setup', 'faq identity heal');
                    }
                }
            }
        } catch (\Throwable) {
            // Best-effort heal; publish may still fail for other reasons.
        }
    }

    public function rebuildFaqSearchIndex(): void
    {
        if (!class_exists(\Weline\Search\Service\SearchProviderIndexService::class)) {
            return;
        }
        try {
            ObjectManager::getInstance(\Weline\Search\Service\SearchProviderIndexService::class)
                ->rebuild('faq', 0);
        } catch (\Throwable) {
            // Search optional.
        }
    }
}
