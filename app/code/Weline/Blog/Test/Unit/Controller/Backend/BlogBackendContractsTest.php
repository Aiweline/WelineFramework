<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllBlogBackendContractsPass().
 */
final class BlogBackendContractsTest extends TestCase
{
    public function testAllBlogBackendContractsPass(): void
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
            'PostAdminTemplate::testIndexUsesExplicitBackendTemplatePath' => function () {
            $this->assert_PostAdminTemplate_testIndexUsesExplicitBackendTemplatePath();
        },
            'PostAdminTemplate::testIndexTemplateUsesDataTableComponent' => function () {
            $this->assert_PostAdminTemplate_testIndexTemplateUsesDataTableComponent();
        },
            'PostAdminTemplate::testFormTemplateUsesCmsEditorShellAndFileManager' => function () {
            $this->assert_PostAdminTemplate_testFormTemplateUsesCmsEditorShellAndFileManager();
        },
            'PostAdminTemplate::testControllerExposesSuggestSlugAction' => function () {
            $this->assert_PostAdminTemplate_testControllerExposesSuggestSlugAction();
        },
            'PostAdminTemplate::testIndexUsesAdminWebsiteScopeIncludingDefaultSiteZero' => function () {
            $this->assert_PostAdminTemplate_testIndexUsesAdminWebsiteScopeIncludingDefaultSiteZero();
        },
        ];
    }

private function assert_PostAdminTemplate_testIndexUsesExplicitBackendTemplatePath(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/Controller/Backend/PostAdmin.php');
    self::assertStringContainsString("fetch('Weline_Blog::templates/backend/post-admin/index.phtml')", $source);
    self::assertStringContainsString("fetch('Weline_Blog::templates/backend/post-admin/form.phtml')", $source);
}

private function assert_PostAdminTemplate_testIndexTemplateUsesDataTableComponent(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/view/templates/backend/post-admin/index.phtml');
    self::assertStringContainsString('<w:d-table', $source);
    self::assertStringContainsString('mode="local"', $source);
    self::assertStringContainsString('name="cover_image"', $source);
    self::assertStringContainsString('name="article_head"', $source);
    self::assertStringContainsString('name="scope_markers"', $source);
    self::assertStringContainsString('name="author"', $source);
    self::assertStringContainsString('标题 / slug / 作者', $source);
    self::assertStringContainsString('<w:websites:website:select', $source);
    self::assertStringContainsString('<w:i18n:language:select', $source);
    self::assertStringContainsString('value="locale_filter"', $source);
    self::assertStringNotContainsString('value="<?= $escape($currentLocale)', $source);
    self::assertStringNotContainsString('table table-striped', $source);
    self::assertStringNotContainsString('alert(', $source);
    self::assertStringNotContainsString('confirm(', $source);
}

private function assert_PostAdminTemplate_testFormTemplateUsesCmsEditorShellAndFileManager(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/view/templates/backend/post-admin/form.phtml');
    self::assertStringContainsString('blog-post-settings-bar', $source);
    self::assertStringContainsString('method="post"', $source);
    self::assertStringContainsString('$postRow', $source);
    self::assertStringContainsString('blog-post-slug-prefix', $source);
    self::assertStringContainsString('blog-post-cover-panel', $source);
    self::assertStringContainsString('<file-manager', $source);
    self::assertStringContainsString('path="media/blog/"', $source);
    self::assertStringContainsString('lockPath="1"', $source);
    self::assertStringContainsString('identity_scope=', $source);
    self::assertStringContainsString('cms-theme-editor-workspace', $source);
    self::assertStringContainsString('cms-theme-editor-frame', $source);
    self::assertStringContainsString('data-blog-slug-ai', $source);
    self::assertStringContainsString('data-blog-slug-auto', $source);
    self::assertStringContainsString('<w:websites:website:select', $source);
    self::assertStringContainsString('<w:i18n:language:select', $source);
    self::assertStringContainsString('name="slug_mode"', $source);
    self::assertStringNotContainsString('name="content"', $source);
    self::assertStringNotContainsString('form-control', $source);
    self::assertStringNotContainsString('alert(', $source);
    self::assertStringNotContainsString('confirm(', $source);
}

private function assert_PostAdminTemplate_testControllerExposesSuggestSlugAction(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/Controller/Backend/PostAdmin.php');
    self::assertStringContainsString('function postSuggestSlug', $source);
    self::assertStringContainsString('WebsiteAclGrantService', $source);
    self::assertStringContainsString('resolveFormWebsiteId', $source);
    self::assertStringContainsString('theme_editor_url', $source);
    self::assertStringContainsString('media_identity_scope', $source);
}

private function assert_PostAdminTemplate_testIndexUsesAdminWebsiteScopeIncludingDefaultSiteZero(): void
{
    $controller = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/Controller/Backend/PostAdmin.php');
    self::assertStringContainsString('AdminWebsiteScope::resolveFilterIdFromGet', $controller);
    self::assertStringContainsString('AdminWebsiteScope::buildSelectView', $controller);
    self::assertStringNotContainsString('websiteId ?? 0', $controller);
    self::assertStringNotContainsString('websiteId > 0', $controller);
    $service = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/Service/BlogPostAdminService.php');
    self::assertStringContainsString('$websiteId !== null && $websiteId >= 0', $service);
    self::assertStringNotContainsString('if ($websiteId > 0)', $service);
}
}
