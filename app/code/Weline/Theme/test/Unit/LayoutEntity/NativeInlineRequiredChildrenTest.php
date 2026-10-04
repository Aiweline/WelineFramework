<?php
declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Dto\ThemeComponentDefinition;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\LayoutEntity\LayoutRelationCompiler;
use Weline\Theme\Service\ThemePlaceableRegistry;

final class NativeInlineRequiredChildrenTest extends TestCase
{
    private function compiler(): LayoutRelationCompiler
    {
        $registry = new class extends ThemePlaceableRegistry {
            public function __construct() {}
            public function find(string $module, string $type, string $code, ?WelineTheme $theme = null, string $area = 'frontend'): ?ThemeComponentDefinition
            {
                return new ThemeComponentDefinition(module: $module, type: $type, code: $code, name: $code,
                    defaultConfig: ['frozen_default' => 'kept'], slots: $code === 'container' ? ['inside'] : []);
            }
        };
        return new LayoutRelationCompiler($registry);
    }

    private function child(): array
    {
        return ['node_uid' => 'child', 'widget_module' => 'ThirdParty', 'widget_type' => 'content',
            'widget_code' => 'required-child', 'slot_id' => 'inside', 'source' => 'default_injection', 'config' => ['label' => 'required']];
    }

    public function testNativeParentWithoutSavedPlacementBindsRequiredChildAndFreezesParams(): void
    {
        $source = '<w:slot id="main"><w:hook>fixture::before</w:hook><w:widget module="Owner" type="content" name="container" params=\'{"title":"native"}\' /></w:slot>';
        $compiled = $this->compiler()->compile($source, [$this->child()]);
        self::assertSame(1, substr_count($compiled, "'widget_code' => 'required-child'"));
        self::assertSame(1, substr_count($compiled, "'widget_code' => 'container'"));
        self::assertStringContainsString("'title' => 'native'", $compiled);
        self::assertStringContainsString("'frozen_default' => 'kept'", $compiled);
        self::assertStringContainsString('<w:hook>fixture::before</w:hook>', $compiled);
    }

    public function testSavedParentDoesNotAcquireDuplicateNativeOwner(): void
    {
        $parent = ['node_uid' => 'parent', 'widget_module' => 'Owner', 'widget_type' => 'content',
            'widget_code' => 'container', 'slot_id' => 'main', 'config' => ['title' => 'saved']];
        $compiled = $this->compiler()->compile('<w:slot id="main"><w:widget module="Owner" type="content" name="container" /></w:slot>', [$parent, $this->child()]);
        self::assertSame(1, substr_count($compiled, "'widget_code' => 'container'"));
        self::assertSame(1, substr_count($compiled, "'widget_code' => 'required-child'"));
    }

    public function testRepeatedNativeParentsKeepSeparateFrozenParamsAndBranches(): void
    {
        $source = '<w:slot id="main"><?php if ($left): ?><w:widget module="Owner" type="content" name="container" params=\'{"title":"left"}\' /><?php else: ?><w:widget module="Owner" type="content" name="container" params=\'{"title":"right"}\' /><?php endif; ?></w:slot>';
        $compiled = $this->compiler()->compile($source, [$this->child()]);
        self::assertSame(2, substr_count($compiled, "'widget_code' => 'container'"));
        self::assertSame(2, substr_count($compiled, "'widget_code' => 'required-child'"));
        self::assertStringContainsString("'title' => 'left'", $compiled);
        self::assertStringContainsString("'title' => 'right'", $compiled);
        self::assertStringContainsString('<?php if ($left): ?>', $compiled);
        self::assertStringContainsString('<?php else: ?>', $compiled);
    }

    public function testSavedFirstParentAndNativeSecondParentBothHaveLocalChildren(): void
    {
        $parent = ['node_uid' => 'saved', 'widget_module' => 'Owner', 'widget_type' => 'content',
            'widget_code' => 'container', 'slot_id' => 'main', 'config' => ['title' => 'saved']];
        $source = '<w:slot id="main"><w:widget module="Owner" type="content" name="container" ref="first" /><w:widget module="Owner" type="content" name="container" ref="second" params=\'{"title":"native"}\' /></w:slot>';
        $compiled = $this->compiler()->compile($source, [$parent, $this->child()]);
        self::assertSame(2, substr_count($compiled, "'widget_code' => 'container'"));
        self::assertSame(2, substr_count($compiled, "'widget_code' => 'required-child'"));
        self::assertStringContainsString("'title' => 'saved'", $compiled);
        self::assertStringContainsString("'title' => 'native'", $compiled);
    }

    public function testDynamicNativeParamsRemainAtOriginalCallWithExplicitChildCallbacks(): void
    {
        $source = '<w:slot id="main"><w:widget module="Owner" type="content" name="container" params=\'{"title":"<?= $title ?>"}\' /></w:slot>';
        $compiled = $this->compiler()->compile($source, [$this->child()]);
        self::assertStringContainsString('<?= $title ?>', $compiled);
        self::assertStringNotContainsString('<w:widget module="Owner"', $compiled);
        self::assertSame(1, substr_count($compiled, "'widget_code' => 'required-child'"));
        self::assertStringContainsString('renderResolved(', $compiled);
        self::assertStringContainsString('ob_get_clean()', $compiled);
    }

    public function testDiscoveryPrecedesFrozenThemeAndLocaleConfiguration(): void
    {
        $compiler = $this->compiler();
        $source = '<w:slot id="main"><w:widget module="Owner" type="content" name="container" params=\'{"title":"native"}\' /></w:slot>';
        $nodes = $compiler->discoverNativeOwners($source, ['child' => $this->child()]);
        self::assertArrayHasKey('child', $nodes);
        self::assertNotEmpty($nodes['child']['parent_uid']);
        self::assertSame($nodes, $compiler->discoverNativeOwners($source, $nodes));
        $ownerUid = '';
        foreach ($nodes as $uid => &$node) {
            if ($node['widget_code'] !== 'container') { continue; }
            $ownerUid = $uid;
            self::assertArrayNotHasKey('frozen_default', $node['config']);
            $node['config']['frozen_default'] = 'THEME SNAPSHOT';
            $node['_explicit_config'] = $node['config'];
        }
        unset($node);
        $compiled = $compiler->compile($source, $nodes, [], ['ar_SA' => [$ownerUid => ['title' => 'LOCALE SNAPSHOT', 'frozen_default' => 'THEME SNAPSHOT']]]);
        self::assertStringContainsString("'frozen_default' => 'THEME SNAPSHOT'", $compiled);
        self::assertStringContainsString("'title' => 'LOCALE SNAPSHOT'", $compiled);
    }
    public function testNativeParentOwnsRequiredSlotInItsLiteralFetchDependency(): void
    {
        $registry = new class extends ThemePlaceableRegistry {
            public function __construct() {}
            public function find(string $module, string $type, string $code, ?WelineTheme $theme = null, string $area = 'frontend'): ?ThemeComponentDefinition
            {
                return new ThemeComponentDefinition(module: $module, type: $type, code: $code, name: $code,
                    templateContent: $code === 'login-owner'
                        ? '<?php echo $this->fetch("Weline_Customer::templates/frontend/account/login.phtml"); ?>' : '');
            }
        };
        $child = ['node_uid' => 'social', 'widget_module' => 'Weline_Customer', 'widget_type' => 'form',
            'widget_code' => 'account-social-login', 'slot_id' => 'account-login-social-providers',
            'source' => 'default_injection', 'config' => ['enable_google' => true]];
        $compiler = new LayoutRelationCompiler($registry);
        $source = '<w:slot id="account-auth-content"><w:widget module="Owner" type="form" name="login-owner" /></w:slot>';
        $nodes = $compiler->discoverNativeOwners($source, ['social' => $child]);
        self::assertNotEmpty($nodes['social']['parent_uid'] ?? null,
            'The login social slot belongs to the native form even when declared in a literal fetched template.');
        $compiled = $compiler->compile($source, $nodes);
        self::assertSame(1, substr_count($compiled, "'widget_code' => 'account-social-login'"));
        self::assertSame(1, substr_count($compiled, "'widget_code' => 'login-owner'"));
    }

    public function testRelativeFetchedSlotsUseTheirSourceDirectoryAndStopCycles(): void
    {
        $root = sys_get_temp_dir() . '/weline-native-fetch-slots-' . bin2hex(random_bytes(5));
        mkdir($root);
        file_put_contents($root . '/bait.phtml', '<w:slot id="bait" />');
        file_put_contents($root . '/owner.phtml', <<<'SOURCE'
<?php /* $this->fetch("./bait.phtml"); */ $text = '$this->fetch("./bait.phtml")'; $other->fetch("./bait.phtml"); echo $this->fetch("./child.phtml"); echo $this->fetch($dynamic); ?><script>$this->fetch("./bait.phtml")</script>
SOURCE
        );
        file_put_contents($root . '/child.phtml', '<?php echo $this->fetch("./owner.phtml"); ?><w:slot id="inside" />');
        $registry = new class($root) extends ThemePlaceableRegistry {
            public function __construct(private readonly string $root) {}
            public function find(string $module, string $type, string $code, ?WelineTheme $theme = null, string $area = 'frontend'): ?ThemeComponentDefinition
            {
                return new ThemeComponentDefinition(module: $module, type: $type, code: $code, name: $code,
                    templatePath: $code === 'container' ? $this->root . '/owner.phtml' : null);
            }
        };
        try {
            $compiled = (new LayoutRelationCompiler($registry))->compile(
                '<w:slot id="main"><w:widget module="Owner" type="content" name="container" /></w:slot>', [$this->child()]);
            self::assertSame(1, substr_count($compiled, "'widget_code' => 'required-child'"));
            self::assertSame(1, substr_count($compiled, "'widget_code' => 'container'"));
            $slots = new \ReflectionMethod(LayoutRelationCompiler::class, 'componentSlots');
            self::assertNotContains('bait', $slots->invoke(new LayoutRelationCompiler($registry), ['widget_module'=>'Owner','widget_type'=>'content','widget_code'=>'container']));
        } finally {
            unlink($root . '/bait.phtml');
            unlink($root . '/owner.phtml');
            unlink($root . '/child.phtml');
            rmdir($root);
        }
    }

}
