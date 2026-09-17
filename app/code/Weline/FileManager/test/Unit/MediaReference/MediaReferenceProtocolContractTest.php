<?php

declare(strict_types=1);

namespace Weline\FileManager\Test\Unit\MediaReference;

use PHPUnit\Framework\TestCase;
use Weline\FileManager\Service\MediaReference\MediaReferenceIdentityBuilder;
use Weline\FileManager\Service\MediaReference\MediaReferenceScopeResolver;
use Weline\FileManager\Service\MediaReference\ProductDetailMediaReferenceGuard;

final class MediaReferenceProtocolContractTest extends TestCase
{
    public function testHardConstraintCatalogContainsMediaReferenceRule(): void
    {
        $catalog = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Ai/Mcp/src/HardConstraintsCatalog.php'
        );
        self::assertStringContainsString("'id' => 'media_reference_identity_protocol'", $catalog);
        self::assertStringContainsString('w_scope', $catalog);
    }

    public function testSkillAndProtocolDocsExist(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertFileExists($root . '/doc/media-reference-identity-protocol.md');
        self::assertFileExists($root . '/doc/ai/skills/media-reference-identity/SKILL.md');
        self::assertFileExists($root . '/doc/ai/INDEX.json');
        self::assertFileExists($root . '/view/statics/js/w-scope.js');
        self::assertFileExists($root . '/etc/event.xml');
        self::assertFileExists($root . '/Observer/MediaReferenceResourceChanged.php');
        self::assertFileExists($root . '/Controller/Backend/MediaReference.php');
    }

    public function testWScopeJsExposesGlobalHelper(): void
    {
        $js = (string)file_get_contents(dirname(__DIR__, 3) . '/view/statics/js/w-scope.js');
        self::assertStringContainsString('global.w_scope = w_scope', $js);
        self::assertStringContainsString('setAmbient', $js);
    }

    public function testFilePickerFailClosedForStrongRef(): void
    {
        $js = (string)file_get_contents(dirname(__DIR__, 3) . '/view/statics/js/file-picker.js');
        self::assertStringContainsString('resolveMediaIdentity', $js);
        self::assertStringContainsString('strongRef', $js);
        self::assertStringContainsString('w_scope_missing', $js);
        self::assertStringContainsString('bindSelection', $js);
        self::assertStringContainsString("url.searchParams.set('startPath', startPath)", $js);
        self::assertStringContainsString('leftover relative startPath', $js);
        self::assertStringContainsString("url.searchParams.set('aspect_ratio_tolerance', aspectTolerance)", $js);
    }

    public function testWidgetAndThemePaths(): void
    {
        $builder = new MediaReferenceIdentityBuilder(new MediaReferenceScopeResolver());
        $widget = $builder->build(
            'default.default.default',
            'widget',
            'default',
            ['component' => 'banner', 'field' => 'image', 'instance' => 'uid1'],
        );
        self::assertStringContainsString('theme:default', $widget->path);
        self::assertStringContainsString('component:banner', $widget->path);
        self::assertStringContainsString('instance:uid1', $widget->path);

        $theme = $builder->build(
            'default.default.default',
            'theme',
            'default',
            ['field' => 'logo_dark'],
        );
        self::assertStringContainsString('field:logo_dark', $theme->path);
    }

    public function testDetailGuardRequiresKindDetail(): void
    {
        $builder = new MediaReferenceIdentityBuilder(new MediaReferenceScopeResolver());
        $identity = $builder->build(
            'default.default.default',
            'product',
            'SKU-1',
            ['kind' => 'media'],
        );
        $guard = new ProductDetailMediaReferenceGuard(
            $builder,
            (new \ReflectionClass(
                \Weline\FileManager\Service\MediaReference\MediaReferenceService::class
            ))->newInstanceWithoutConstructor(),
        );
        $this->expectException(\InvalidArgumentException::class);
        $guard->assertDetailIdentity($identity);
    }

    public function testProductDeleteEmitsWChangedContract(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Product/Service/ProductPhysicalDeleteService.php'
        );
        self::assertStringContainsString('emitMediaReferenceDeletes', $src);
        self::assertStringContainsString('\\w_changed($change)', $src);
        self::assertStringContainsString("'product'", $src);
    }

    public function testConfigEmbedAddsIdentityMeta(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 4) . '/SystemConfig/Service/ConfigEmbedResolver.php'
        );
        self::assertStringContainsString("'identity_root'", $src);
        self::assertStringContainsString("'strong_ref'", $src);
    }

    public function testAiDrawInheritsIdentity(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 4) . '/MediaManager/Controller/Backend/AiDraw.php'
        );
        self::assertStringContainsString('bindSavedAssetsToIdentity', $src);
    }

    public function testProductEditAmbientAndMmRefPanel(): void
    {
        $productEdit = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Product/view/templates/backend/catalog/edit.phtml'
        );
        self::assertStringContainsString('data-media-identity-root="product"', $productEdit);
        self::assertStringContainsString('media-identity-picker.js', $productEdit);

        $catalog = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Catalog/view/templates/backend/category/index.phtml'
        );
        self::assertStringContainsString('data-media-identity-root="catalog"', $catalog);

        $manager = (string)file_get_contents(
            dirname(__DIR__, 4) . '/MediaManager/view/templates/Backend/Manager/manager.phtml'
        );
        $managerJs = (string)file_get_contents(
            dirname(__DIR__, 4) . '/MediaManager/view/statics/js/manager.js'
        );
        self::assertStringContainsString('mmf-ref-panel', $manager);
        self::assertStringContainsString('mmf-ref-close-scope', $manager);
        self::assertStringContainsString('mmf-identity-bar', $manager);
        self::assertStringContainsString('data-mmf-identity-groups', $manager);
        self::assertStringContainsString('initIdentityBar', $managerJs);
        self::assertStringContainsString('applyIdentityFilter', $managerJs);
        self::assertStringContainsString('parseCurrentIdentityTags', $managerJs);

        $widgetParam = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Theme/view/statics/ui/pages/weline-theme-editor-widget-param.js'
        );
        self::assertStringContainsString('resolveWidgetMediaIdentity', $widgetParam);
        self::assertStringContainsString('appendWidgetMediaIdentityParams', $widgetParam);

        $backendConfig = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Backend/view/templates/Backend/Config/index.phtml'
        );
        self::assertStringContainsString('identity_root="config"', $backendConfig);
        self::assertStringContainsString('identity_code="logo_dark"', $backendConfig);
        self::assertStringContainsString('identity_scope="default.default.default"', $backendConfig);

        $blogForm = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Blog/view/templates/backend/post-admin/form.phtml'
        );
        self::assertStringContainsString('identity_root="blog"', $blogForm);
        self::assertStringContainsString('identity_scope=', $blogForm);

        $eavForm = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Eav/view/templates/Backend/Attribute/form.phtml'
        );
        self::assertStringContainsString('identity_root=eav', $eavForm);
        self::assertStringContainsString('identity_scope=', $eavForm);
        self::assertStringContainsString('identity_code=', $eavForm);
        self::assertStringContainsString('swatchMediaPickerSrc', $eavForm);

        $systemConfig = (string)file_get_contents(
            dirname(__DIR__, 4) . '/SystemConfig/view/templates/backend/config/index.phtml'
        );
        self::assertStringContainsString("'identity_root' => 'config'", $systemConfig);
        self::assertStringContainsString("'identity_scope' => \$wscIdentityScope", $systemConfig);
    }
}
