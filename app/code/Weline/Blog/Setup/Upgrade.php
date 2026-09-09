<?php

declare(strict_types=1);

namespace Weline\Blog\Setup;

use Weline\Blog\Model\BlogCategoryAttributeEntity;
use Weline\Blog\Model\Post\LocalDescription;
use Weline\Blog\Service\BlogCategoryEavBootstrap;
use Weline\Blog\Service\BlogCategoryLocaleSyncService;
use Weline\Blog\Service\BlogNewsCategoryBootstrap;
use Weline\Blog\Service\BlogSeoFactsBuilder;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\UpgradeInterface;

final class Upgrade implements UpgradeInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        $modelSetup = ObjectManager::make(ModelSetup::class);

        /** @var BlogCategoryAttributeEntity $entity */
        $entity = ObjectManager::getInstance(BlogCategoryAttributeEntity::class);
        $modelSetup->putModel($entity);
        $entity->upgrade($modelSetup, $context);

        /** @var LocalDescription $postLocal */
        $postLocal = ObjectManager::getInstance(LocalDescription::class);
        $modelSetup->putModel($postLocal);
        $postLocal->upgrade($modelSetup, $context);

        ObjectManager::getInstance(BlogCategoryEavBootstrap::class)->ensureCategorySchema();
        ObjectManager::getInstance(BlogCategoryLocaleSyncService::class)->syncExistingCategories();
        ObjectManager::getInstance(BlogNewsCategoryBootstrap::class)->ensure(0);
        $this->ensureBlogReviewsPublished();
        $this->backfillAuthorIdentityDefaults();
    }

    /**
     * Persist Who identity defaults for published posts that only have author name.
     */
    private function backfillAuthorIdentityDefaults(): void
    {
        try {
            /** @var \Weline\Blog\Model\Post $post */
            $post = ObjectManager::getInstance(\Weline\Blog\Model\Post::class);
            $facts = ObjectManager::getInstance(BlogSeoFactsBuilder::class);
            $rows = $post->clear()
                ->where(\Weline\Blog\Model\Post::schema_fields_AUTHOR, '', 'neq')
                ->select()
                ->fetchArray();
            if (!is_array($rows)) {
                return;
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = (int)($row[\Weline\Blog\Model\Post::schema_fields_ID] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $author = trim((string)($row[\Weline\Blog\Model\Post::schema_fields_AUTHOR] ?? ''));
                if ($author === '') {
                    continue;
                }
                $url = trim((string)($row[\Weline\Blog\Model\Post::schema_fields_AUTHOR_URL] ?? ''));
                $sameAs = trim((string)($row[\Weline\Blog\Model\Post::schema_fields_AUTHOR_SAME_AS] ?? ''));
                $bio = trim((string)($row[\Weline\Blog\Model\Post::schema_fields_AUTHOR_BIO] ?? ''));
                $job = trim((string)($row[\Weline\Blog\Model\Post::schema_fields_AUTHOR_JOB_TITLE] ?? ''));
                if ($url !== '' && $sameAs !== '') {
                    continue;
                }
                $defaults = $facts->defaultAuthorIdentity(
                    (string)($row[\Weline\Blog\Model\Post::schema_fields_LOCALE] ?? ''),
                    '',
                );
                $post->clear()->load($id);
                if ((int)$post->getPostId() !== $id) {
                    continue;
                }
                if ($url === '') {
                    $post->setData(\Weline\Blog\Model\Post::schema_fields_AUTHOR_URL, $defaults['url']);
                }
                if ($sameAs === '') {
                    $post->setData(
                        \Weline\Blog\Model\Post::schema_fields_AUTHOR_SAME_AS,
                        implode("\n", $defaults['sameAs']),
                    );
                }
                if ($bio === '') {
                    $post->setData(\Weline\Blog\Model\Post::schema_fields_AUTHOR_BIO, $defaults['bio']);
                }
                if ($job === '') {
                    $post->setData(\Weline\Blog\Model\Post::schema_fields_AUTHOR_JOB_TITLE, $defaults['jobTitle']);
                }
                $post->save();
            }
        } catch (\Throwable) {
            // Optional during early install; runtime defaults still fill SEO Person.
        }
    }

    /**
     * blog-reviews default_injections only land in draft; publish so storefront reviews slot is not empty.
     */
    private function ensureBlogReviewsPublished(): void
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

            $identity = [
                'layout_option' => 'default',
                'scope' => 'default',
                'locale_code' => '',
                'target_type' => 'global',
                'target_id' => 0,
            ];

            ObjectManager::getInstance(\Weline\Theme\Service\WidgetDefaultInjectionService::class)
                ->applyRequiredMissingForIdentity(
                    $themeId,
                    \Weline\Theme\Model\ThemeLayout::PAGE_TYPE_BLOG,
                    $identity,
                    \Weline\Theme\Service\PreviewContextService::AREA_FRONTEND,
                    \Weline\Theme\Model\ThemeLayout::STATUS_DRAFT,
                );

            ObjectManager::getInstance(\Weline\Theme\Service\ThemeLayoutService::class)
                ->publishLayout(
                    $themeId,
                    \Weline\Theme\Model\ThemeLayout::PAGE_TYPE_BLOG,
                    $identity,
                    false,
                    ['reason' => 'blog-reviews storefront publish'],
                );
        } catch (\Throwable) {
            // Theme optional at install time; storefront can publish later via editor.
        }
    }
}
