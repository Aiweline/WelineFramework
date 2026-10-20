<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Service;


use Weline\Framework\Env\WelineEnv;
use PHPUnit\Framework\TestCase;
use Weline\Blog\Controller\Frontend\View;
use Weline\Blog\Model\Post;
use Weline\Blog\Model\Post\LocalDescription;
use Weline\Blog\Service\BlogContentResolver;
use Weline\Blog\Service\BlogKeywordLocalizer;
use Weline\Blog\Service\BlogNewsCategoryBootstrap;
use Weline\Blog\Service\BlogWebsiteScope;
use Weline\I18n\Api\Localization\LocalModel;

/**
 * Aggregated contract suite (merged from 11 *ContractTest files).
 * Observe one overall pass/fail via testAllBlogServiceContractsPass().
 */
final class BlogServiceContractsTest extends TestCase
{
    public function testAllBlogServiceContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'BlogCategoryLocalizedName::testContentResolverUsesCategoryAttributeService' => function () {
            $this->assert_BlogCategoryLocalizedName_testContentResolverUsesCategoryAttributeService();
        },
            'BlogCategoryLocalizedName::testSearchScopeServiceUsesLocalizedLabels' => function () {
            $this->assert_BlogCategoryLocalizedName_testSearchScopeServiceUsesLocalizedLabels();
        },
            'BlogCategoryLocalizedName::testLocaleSyncMapsKnownChineseLabelsToEnglish' => function () {
            $this->assert_BlogCategoryLocalizedName_testLocaleSyncMapsKnownChineseLabelsToEnglish();
        },
            'BlogCategoryTree::testAdminServiceEnforcesTwoLevelTree' => function () {
            $this->assert_BlogCategoryTree_testAdminServiceEnforcesTwoLevelTree();
        },
            'BlogCategoryTree::testCategoryModelDeclaresParentId' => function () {
            $this->assert_BlogCategoryTree_testCategoryModelDeclaresParentId();
        },
            'BlogCategoryTree::testContentResolverAggregatesDescendantPosts' => function () {
            $this->assert_BlogCategoryTree_testContentResolverAggregatesDescendantPosts();
        },
            'BlogCategoryTree::testFrontendFilterSupportsExpandableChildren' => function () {
            $this->assert_BlogCategoryTree_testFrontendFilterSupportsExpandableChildren();
        },
            'BlogCategoryTree::testEthnicSeedRemountsUnderChinaEthnicDress' => function () {
            $this->assert_BlogCategoryTree_testEthnicSeedRemountsUnderChinaEthnicDress();
        },
            'BlogCategoryTree::testCatalogProviderPassesParentIdOnSave' => function () {
            $this->assert_BlogCategoryTree_testCatalogProviderPassesParentIdOnSave();
        },
            'BlogContentMutationFpcUrls::testPublishImpactIncludesBlogUrls' => function () {
            $this->assert_BlogContentMutationFpcUrls_testPublishImpactIncludesBlogUrls();
        },
            'BlogLayoutHtmlBagIdentity::testViewSetsPostSlugContextForThemeBag' => function () {
            $this->assert_BlogLayoutHtmlBagIdentity_testViewSetsPostSlugContextForThemeBag();
        },
            'BlogLayoutHtmlBagIdentity::testMutationBumpsStorefrontBlogNamespaceForBagDependency' => function () {
            $this->assert_BlogLayoutHtmlBagIdentity_testMutationBumpsStorefrontBlogNamespaceForBagDependency();
        },
            'BlogContentResolver::testResolverSourceChecksPostBeforeCms' => function () {
            $this->assert_BlogContentResolver_testResolverSourceChecksPostBeforeCms();
        },
            'BlogContentResolver::testScopeResolverUsesCurrentRequestLanguageState' => function () {
            $this->assert_BlogContentResolver_testScopeResolverUsesCurrentRequestLanguageState();
        },
            'BlogContentResolver::testEnglishStorageSuffixStaysBehindPublicSlugBoundary' => function () {
            $this->assert_BlogContentResolver_testEnglishStorageSuffixStaysBehindPublicSlugBoundary();
        },
            'BlogContentResolverReuse::testResolverRemembersPublishedRowsAndBatchesSlugLookups' => function () {
            $this->assert_BlogContentResolverReuse_testResolverRemembersPublishedRowsAndBatchesSlugLookups();
        },
            'BlogContentResolverReuse::testViewReadsContentFromContextBeforeLoad' => function () {
            $this->assert_BlogContentResolverReuse_testViewReadsContentFromContextBeforeLoad();
        },
            'BlogKeywordLocalizer::testPostLocalDescriptionIsLocalModelWithKeywordsField' => function () {
            $this->assert_BlogKeywordLocalizer_testPostLocalDescriptionIsLocalModelWithKeywordsField();
        },
            'BlogKeywordLocalizer::testEnglishLocaleKeepsLatinAndDropsUntranslatedHan' => function () {
            $this->assert_BlogKeywordLocalizer_testEnglishLocaleKeepsLatinAndDropsUntranslatedHan();
        },
            'BlogKeywordLocalizer::testEnglishLocaleUsesDictionaryTranslationForHanTags' => function () {
            $this->assert_BlogKeywordLocalizer_testEnglishLocaleUsesDictionaryTranslationForHanTags();
        },
            'BlogKeywordLocalizer::testChineseLocaleKeepsHanTags' => function () {
            $this->assert_BlogKeywordLocalizer_testChineseLocaleKeepsHanTags();
        },
            'BlogKeywordLocalizer::testLocalKeywordsBypassScriptFilter' => function () {
            $this->assert_BlogKeywordLocalizer_testLocalKeywordsBypassScriptFilter();
        },
            'BlogKeywordLocalizer::testContentResolverWiresKeywordLocalizer' => function () {
            $this->assert_BlogKeywordLocalizer_testContentResolverWiresKeywordLocalizer();
        },
            'BlogNewsCategoryBootstrap::testNewsSlugAndNameConstants' => function () {
            $this->assert_BlogNewsCategoryBootstrap_testNewsSlugAndNameConstants();
        },
            'BlogNewsCategoryBootstrap::testUpgradeInvokesNewsCategoryBootstrap' => function () {
            $this->assert_BlogNewsCategoryBootstrap_testUpgradeInvokesNewsCategoryBootstrap();
        },
            'BlogPostAdminDefaultSiteFilter::test_listing_filters_include_default_website_zero' => function () {
            $this->assert_BlogPostAdminDefaultSiteFilter_test_listing_filters_include_default_website_zero();
        },
            'BlogPostAdminDefaultSiteFilter::test_list_presenter_does_not_call_zero_global' => function () {
            $this->assert_BlogPostAdminDefaultSiteFilter_test_list_presenter_does_not_call_zero_global();
        },
            'BlogPostAdminSaveReturn::test_save_returns_row_via_getPostId_not_empty_fetchArray' => function () {
            $this->assert_BlogPostAdminSaveReturn_test_save_returns_row_via_getPostId_not_empty_fetchArray();
        },
            'BlogPublishAgent::test_requires_positive_post_id_from_save' => function () {
            $this->assert_BlogPublishAgent_test_requires_positive_post_id_from_save();
        },
            'BlogPostUrlNotifier::test_draft_upsert_does_not_rewrite_to_seo_delete' => function () {
            $this->assert_BlogPostUrlNotifier_test_draft_upsert_does_not_rewrite_to_seo_delete();
        },
            'BlogSearchCategoryScopeNoCrossBrandLeak::testSearchScopesPreferOwnedOnlyTreeForNonDefaultWebsites' => function () {
            $this->assert_BlogSearchCategoryScopeNoCrossBrandLeak_testSearchScopesPreferOwnedOnlyTreeForNonDefaultWebsites();
        },
            'BlogSlugConflictGuard::testGuardUsesCmsQueryInsteadOfCmsModel' => function () {
            $this->assert_BlogSlugConflictGuard_testGuardUsesCmsQueryInsteadOfCmsModel();
        },
            'BlogWebsiteScope::testDefaultSiteZeroDoesNotLeakToOtherWebsites' => function () {
            $this->assert_BlogWebsiteScope_testDefaultSiteZeroDoesNotLeakToOtherWebsites();
        },
            'BlogWebsiteScope::testScopedWebsiteDoesNotMatchForeignWebsite' => function () {
            $this->assert_BlogWebsiteScope_testScopedWebsiteDoesNotMatchForeignWebsite();
        },
            'BlogWebsiteScope::testDefaultSiteQueriesOnlyDefaultSiteContent' => function () {
            $this->assert_BlogWebsiteScope_testDefaultSiteQueriesOnlyDefaultSiteContent();
        },
        ];
    }

private function assert_BlogCategoryLocalizedName_testContentResolverUsesCategoryAttributeService(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/Service/BlogContentResolver.php');
    self::assertStringContainsString('BlogCategoryAttributeService', $source);
    self::assertStringContainsString('resolveDisplayName', $source);
    self::assertStringContainsString('listCategories(int $websiteId = 0, string $locale = \'\')', $source);
}

private function assert_BlogCategoryLocalizedName_testSearchScopeServiceUsesLocalizedLabels(): void
{
    $service = (new \ReflectionClass(\Weline\Blog\Service\BlogSearchCategoryScopeService::class))->newInstanceWithoutConstructor();
    $mapped = (new \ReflectionMethod($service, 'mapTree'))->invoke($service, [['category_id' => 1, 'name' => 'News Center', 'slug' => 'news', 'nodes' => [['category_id' => 2, 'name' => '新闻中心', 'slug' => 'updates', 'nodes' => []]]]]);
    self::assertSame('News Center', $mapped[0]['label']);
    self::assertSame('新闻中心', $mapped[0]['children'][0]['label']);
    self::assertSame(['category_id' => 2], $mapped[0]['children'][0]['params']);
}

private function assert_BlogCategoryLocalizedName_testLocaleSyncMapsKnownChineseLabelsToEnglish(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/Service/BlogCategoryLocaleSyncService.php');
    self::assertStringContainsString('技术分享', $source);
    self::assertStringContainsString('Tech Sharing', $source);
    self::assertStringContainsString('writeName', $source);
}

private function assert_BlogCategoryTree_testAdminServiceEnforcesTwoLevelTree(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/Service/BlogCategoryAdminService.php');
    self::assertStringContainsString('public const MAX_DEPTH = 2', $source);
    self::assertStringContainsString('schema_fields_PARENT_ID', $source);
    self::assertStringContainsString('selfAndDescendantIds', $source);
    self::assertStringContainsString('博客分类最多支持两级', $source);
    self::assertStringContainsString("\$node['nodes'] = \$categoryId > 0 ? \$walk(\$categoryId) : []", $source);
}

private function assert_BlogCategoryTree_testCategoryModelDeclaresParentId(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/Model/Category.php');
    self::assertStringContainsString("schema_fields_PARENT_ID = 'parent_id'", $source);
}

private function assert_BlogCategoryTree_testContentResolverAggregatesDescendantPosts(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/Service/BlogContentResolver.php');
    self::assertStringContainsString('selfAndDescendantIds', $source);
    self::assertStringContainsString("Post::schema_fields_CATEGORY_ID, \$categoryIds, 'IN'", $source);
    self::assertStringContainsString("'children' => \$children", $source);
}

private function assert_BlogCategoryTree_testFrontendFilterSupportsExpandableChildren(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/view/templates/frontend/partials/category-filter.phtml');
    self::assertStringContainsString('data-blog-filter-toggle', $source);
    self::assertStringContainsString('amazon-blog-filter__children', $source);
    self::assertStringContainsString("\$category['children']", $source);
}

private function assert_BlogCategoryTree_testEthnicSeedRemountsUnderChinaEthnicDress(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app' . '/design/Weline/hanfu/data/seed-china-ethnic-categories.php');
    self::assertStringContainsString('remountEthnicChildren', $source);
    self::assertStringContainsString("str_starts_with(\$code, 'ethnic-cn-')", $source);
    self::assertStringContainsString("\$admin->reorder(WEBSITE_ID, \$categoryId, \$hubId, 2, \$position)", $source);
}

private function assert_BlogCategoryTree_testCatalogProviderPassesParentIdOnSave(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/extends/module/Weline_Catalog/Space/BlogCatalogSpaceProvider.php');
    self::assertStringContainsString("\$payload['parent_id'] ?? \$payload['pid'] ?? 0", $source);
    self::assertStringContainsString('two-level tree', $source);
}

private function assert_BlogContentMutationFpcUrls_testPublishImpactIncludesBlogUrls(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/Service/BlogContentMutation.php');
    self::assertStringContainsString('impactUrls', $src);
    self::assertStringContainsString("'/blog/'", $src);
    self::assertStringContainsString("'previous_urls'", $src);
    self::assertStringContainsString("'urls' => \$urlImpact['urls']", $src);
}

private function assert_BlogLayoutHtmlBagIdentity_testViewSetsPostSlugContextForThemeBag(): void
{
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/Controller/Frontend/View.php');
    self::assertStringContainsString("RequestContext::set('blog.post.slug.v1', \$slug)", $source);
    self::assertStringContainsString('StorefrontBlogLayoutHtmlBag', $source);
}

private function assert_BlogLayoutHtmlBagIdentity_testMutationBumpsStorefrontBlogNamespaceForBagDependency(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/Service/BlogContentMutation.php');
    self::assertStringContainsString('storefrontBlogLayoutHtmlPolicy', $src);
    $cache = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/Service/BlogContentCache.php');
    self::assertStringContainsString("global('storefront', ['blog'])", $cache);
    $themePolicy = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/StorefrontThemeCacheCoordinator.php');
    self::assertStringContainsString("'global/storefront/blog'", $themePolicy);
    self::assertStringContainsString('theme.storefront_blog_layout_html', $themePolicy);
}

private function assert_BlogContentResolver_testResolverSourceChecksPostBeforeCms(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/Service/BlogContentResolver.php';
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('findPublishedPost', $source);
    self::assertStringContainsString('getPublishedPage', $source);
    self::assertStringContainsString('getPublishedPage', $source);
    self::assertStringContainsString('toBlogArticle', $source);
}

private function assert_BlogContentResolver_testScopeResolverUsesCurrentRequestLanguageState(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/Service/BlogScopeResolver.php');
    self::assertStringContainsString('WelineEnv::getLangLocal()', $source);
    self::assertStringContainsString("str_replace('-', '_', \$locale)", $source);
    self::assertStringNotContainsString('$scope->localeCode', $source);
    self::assertStringNotContainsString("w_env('lang'", $source);
}

private function assert_BlogContentResolver_testEnglishStorageSuffixStaysBehindPublicSlugBoundary(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/Service/BlogContentResolver.php');
    self::assertStringContainsString('storageSlugForLocale', $source);
    self::assertStringContainsString('public function publicSlugForLocale', $source);
    self::assertStringContainsString('localeSlugSuffixMap', $source);
    self::assertStringContainsString("return \$slug . '-' . \$suffix;", $source);
    self::assertStringContainsString("'storage_slug' => \$storageSlug", $source);
    self::assertStringContainsString('if (!is_array($row)', $source);
    self::assertStringContainsString('$row === []', $source);
    self::assertStringContainsString('(int)($row[Post::schema_fields_ID] ?? 0) <= 0', $source);
    $indexBuilder = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/Service/BlogSearchIndexDocumentBuilder.php');
    self::assertStringContainsString('$this->resolver->publicSlugForLocale', $indexBuilder);
    self::assertStringContainsString("'storage_slug' => \$storageSlug", $indexBuilder);
    self::assertStringContainsString('locale: trim($article->locale)', $indexBuilder);
    self::assertStringNotContainsString("locale: ''", $indexBuilder);
    self::assertStringContainsString('contentLocaleCandidates', $source);
    self::assertStringContainsString('defaultContentLocale', $source);
    self::assertStringContainsString('content_fallback_v2', $source);
    self::assertStringContainsString('contentIdentityKey', $source);
    self::assertStringContainsString('localeSlugSuffixMap', $source);
    self::assertStringContainsString("'hi_IN' => 'hi'", $source);
    self::assertStringContainsString("'bg_BG' => 'bg'", $source);
    self::assertStringContainsString("'de_DE' => 'de'", $source);
    self::assertStringContainsString("'ru_RU' => 'ru'", $source);
}

private function assert_BlogContentResolverReuse_testResolverRemembersPublishedRowsAndBatchesSlugLookups(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/Service/BlogContentResolver.php');
    self::assertStringContainsString('rememberPublishedPostRow', $source);
    self::assertStringContainsString('findPublishedPostsBySlugs', $source);
    self::assertStringContainsString('blog.published_post.by_id.v1.', $source);
    self::assertStringContainsString('CTX_SLUG_MISS', $source);
    self::assertStringNotContainsString('max($limit, 50)', $source);
    self::assertStringContainsString('hydrateLoadedRow', $source);
}

private function assert_BlogContentResolverReuse_testViewReadsContentFromContextBeforeLoad(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/Controller/Frontend/View.php');
    self::assertStringContainsString('blog.published_post.by_id.v1.', $source);
    self::assertTrue(method_exists(Post::class, 'hydrateLoadedRow'));
    self::assertTrue(class_exists(View::class));
    self::assertTrue(class_exists(BlogContentResolver::class));
}

private function assert_BlogKeywordLocalizer_testPostLocalDescriptionIsLocalModelWithKeywordsField(): void
{
    self::assertTrue(is_subclass_of(LocalDescription::class, LocalModel::class));
    self::assertSame(Post::schema_fields_ID, LocalDescription::schema_fields_ID);
    self::assertSame(Post::schema_fields_KEYWORDS, LocalDescription::schema_fields_KEYWORDS);
    self::assertSame('weline_blog_post_local', LocalDescription::schema_table);
}

private function assert_BlogKeywordLocalizer_testEnglishLocaleKeepsLatinAndDropsUntranslatedHan(): void
{
    $localizer = new BlogKeywordLocalizer();
    $tags = $localizer->localizeTags('俄罗斯族服饰,Russian dress,民族服装', 'en_US', static fn(string $text): string => $text);
    self::assertSame(['Russian dress'], $tags);
}

private function assert_BlogKeywordLocalizer_testEnglishLocaleUsesDictionaryTranslationForHanTags(): void
{
    $localizer = new BlogKeywordLocalizer();
    $map = ['俄罗斯族服饰' => 'Russian ethnic dress', '民族服装' => 'Ethnic clothing'];
    $tags = $localizer->localizeTags('俄罗斯族服饰,Russian dress,民族服装', 'en_US', static fn(string $text): string => $map[$text] ?? $text);
    self::assertSame(['Russian ethnic dress', 'Russian dress', 'Ethnic clothing'], $tags);
}

private function assert_BlogKeywordLocalizer_testChineseLocaleKeepsHanTags(): void
{
    $localizer = new BlogKeywordLocalizer();
    $tags = $localizer->localizeTags('俄罗斯族服饰,Russian dress,民族服装', 'zh_Hans_CN', static fn(string $text): string => $text);
    self::assertSame(['俄罗斯族服饰', '民族服装'], $tags);
}

private function assert_BlogKeywordLocalizer_testLocalKeywordsBypassScriptFilter(): void
{
    $localizer = new BlogKeywordLocalizer();
    $out = $localizer->localizeKeywordsString('俄罗斯族服饰,Russian dress,民族服装', 'en_US', 'Russian ethnic dress,Ethnic clothing');
    self::assertSame('Russian ethnic dress,Ethnic clothing', $out);
}

private function assert_BlogKeywordLocalizer_testContentResolverWiresKeywordLocalizer(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/Service/BlogContentResolver.php');
    self::assertStringContainsString('BlogKeywordLocalizer', $source);
    self::assertStringContainsString('LocalDescription', $source);
    self::assertStringContainsString('resolveKeywords', $source);
}

private function assert_BlogNewsCategoryBootstrap_testNewsSlugAndNameConstants(): void
{
    self::assertSame('news', BlogNewsCategoryBootstrap::NEWS_SLUG);
    self::assertSame('新闻中心', BlogNewsCategoryBootstrap::NEWS_NAME);
}

private function assert_BlogNewsCategoryBootstrap_testUpgradeInvokesNewsCategoryBootstrap(): void
{
    $upgrade = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/Setup/Upgrade.php');
    self::assertStringContainsString('BlogNewsCategoryBootstrap', $upgrade);
    self::assertStringContainsString('->ensure(0)', $upgrade);
}

private function assert_BlogPostAdminDefaultSiteFilter_test_listing_filters_include_default_website_zero(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/Service/BlogPostAdminService.php');
    self::assertStringContainsString('?int $websiteId', $src);
    self::assertStringContainsString('$websiteId !== null && $websiteId >= 0', $src);
    self::assertStringContainsString('禁止 website_id>0 才筛', $src);
    self::assertStringNotContainsString('if ($websiteId > 0)', $src);
}

private function assert_BlogPostAdminDefaultSiteFilter_test_list_presenter_does_not_call_zero_global(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/Service/BlogPostAdminListPresenter.php');
    self::assertStringNotContainsString("websiteId > 0 ? '#' . \$websiteId : '全站'", $src);
    self::assertStringContainsString("'#' . max(0, \$websiteId)", $src);
}

private function assert_BlogPostAdminSaveReturn_test_save_returns_row_via_getPostId_not_empty_fetchArray(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/Service/BlogPostAdminService.php');
    self::assertStringContainsString('rowFromSavedModel', $src);
    self::assertStringContainsString('getPostId()', $src);
    self::assertStringContainsString('博客保存后缺少 post_id', $src);
    self::assertStringNotContainsString("return is_array(\$row) ? \$row : \$model->getData();", $src);
}

private function assert_BlogPublishAgent_test_requires_positive_post_id_from_save(): void
{
    $src = (string) file_get_contents(
        '/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/extends/module/Weline_Ai/Agent/BlogPublishAgent.php'
    );
    self::assertStringContainsString('Post::schema_fields_ID', $src);
    self::assertStringContainsString('博客草稿保存成功但未返回 post_id', $src);
    self::assertStringContainsString('AgentResult::failure', $src);
}

private function assert_BlogPostUrlNotifier_test_draft_upsert_does_not_rewrite_to_seo_delete(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/Service/BlogPostUrlNotifier.php');
    self::assertStringContainsString("\$action !== 'delete' && \$status !== Post::STATUS_PUBLISHED", $src);
    self::assertStringContainsString('return;', $src);
    self::assertStringNotContainsString("\$action = 'delete';", $src);
    $admin = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/Service/BlogPostAdminService.php');
    self::assertStringContainsString("notifyFromPostRow(\$row, 'delete')", $admin);
    self::assertStringContainsString("notifyFromPostRow(\$row, 'upsert')", $admin);
}

private function assert_BlogSearchCategoryScopeNoCrossBrandLeak_testSearchScopesPreferOwnedOnlyTreeForNonDefaultWebsites(): void
{
    $scopeSource = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/Service/BlogSearchCategoryScopeService.php');
    self::assertStringContainsString('treeOwnedOnly', $scopeSource);
    self::assertStringContainsString('if ($websiteId > 0)', $scopeSource);
    self::assertStringContainsString('return [];', $scopeSource);
    // Hot path must not append debug logs (was writing multi-MB blog-search-scope.log).
    self::assertStringNotContainsString('file_put_contents', $scopeSource);
    self::assertStringNotContainsString('blog-search-scope.log', $scopeSource);
    $adminSource = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/Service/BlogCategoryAdminService.php');
    self::assertStringContainsString('function treeOwnedOnly(', $adminSource);
    self::assertStringContainsString('category_tree_owned', $adminSource);
    self::assertStringContainsString('$includeGlobal', $adminSource);
}

private function assert_BlogSlugConflictGuard_testGuardUsesCmsQueryInsteadOfCmsModel(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/Service/BlogSlugConflictGuard.php';
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('getPageForConflictCheck', $source);
    self::assertStringNotContainsString('Weline\Cms\Model\Page', $source);
    self::assertStringContainsString('博客 slug 与 CMS blog 页面冲突', (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/i18n/zh_Hans_CN.csv'));
}

private function assert_BlogWebsiteScope_testDefaultSiteZeroDoesNotLeakToOtherWebsites(): void
{
    self::assertFalse(BlogWebsiteScope::matchesWebsite(3, 0));
    self::assertSame([3], BlogWebsiteScope::websiteIdsForQuery(3));
}

private function assert_BlogWebsiteScope_testScopedWebsiteDoesNotMatchForeignWebsite(): void
{
    self::assertFalse(BlogWebsiteScope::matchesWebsite(3, 5));
    self::assertTrue(BlogWebsiteScope::matchesWebsite(3, 3));
}

private function assert_BlogWebsiteScope_testDefaultSiteQueriesOnlyDefaultSiteContent(): void
{
    self::assertSame([0], BlogWebsiteScope::websiteIdsForQuery(0));
    self::assertTrue(BlogWebsiteScope::matchesWebsite(0, 0));
    self::assertFalse(BlogWebsiteScope::matchesWebsite(0, 544));
}
}
