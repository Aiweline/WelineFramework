<?php
declare(strict_types=1);
namespace Weline\Framework\Test\Unit\Rules;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Rules\Frontend\ThemeLayoutWidgetOwnerScanner;
use Weline\Framework\Rules\Frontend\RequiredInjectionSiblingFetchScanner;
final class ThemeLayoutWidgetOwnerScannerTest extends TestCase
{
    public function testBusinessOwnerAndInstalledDesignOwner(): void
    {
        $app = sys_get_temp_dir() . '/weline-owner-' . uniqid();
        $root = $app . '/code';
        $definition = $root . '/Weline/Customer/extends/module/Weline_Widget/Weline_Wrong/widget.php';
        $business = $root . '/Weline/Customer/view/templates/frontend/layouts/login.phtml';
        $design = $app . '/design/Weline/example/frontend/layouts/login.phtml';
        $themeDefinition = $root . '/Weline/Theme/extends/module/Weline_Widget/Weline_Theme/widget.php';
        $files = [
            $definition => "<?php return ['login'=>['code'=>'login','type'=>'form','template'=>'Weline_Customer::templates/frontend/widgets/login.phtml']];",
            $themeDefinition => "<?php return ['shell'=>['code'=>'shell','type'=>'form','template'=>'Weline_Theme::theme/frontend/widgets/shell.phtml']];",
            $business => '<w:widget name="login" type="form"/>' . "<?php echo \$this->fetch('Weline_Customer::templates/frontend/widgets/login.phtml'); ?>" . '<w:widget name="shell" type="form"/>' . "<?php echo \$this->fetch('Weline_Theme::theme/frontend/widgets/shell.phtml'); ?>",
            $design => '<!-- <w:widget name="login"/> -->' . "<?php /* echo \$this->fetch('Weline_Customer::templates/frontend/widgets/login.phtml'); */ ?>" . '<w:widget name="login" type="form"/>' . "<?php echo \$this->fetch('Weline_Customer::templates/frontend/widgets/login.phtml'); ?>",
        ];
        try {
            foreach ($files as $path => $content) { if (!is_dir(dirname($path))) { mkdir(dirname($path), 0777, true); } file_put_contents($path, $content); }
            $hits = (new ThemeLayoutWidgetOwnerScanner())->scanProject($root);
            self::assertCount(4, $hits, json_encode($hits));
            self::assertSame(['Weline_Theme', 'Weline_Theme', 'Weline_Customer', 'Weline_Customer'], array_column($hits, 'module'));
        } finally {
            $this->removeFixture($app);
        }
    }
    public function testExplicitLayoutPlacementRejectsOptionalInjectionWithoutTemplateSlot(): void
    {
        $app = sys_get_temp_dir() . '/weline-placement-' . uniqid();
        $root = $app . '/code';
        $path = $root . '/Weline/Customer/extends/module/Weline_Widget/Weline_Customer/widget.php';
        try {
            mkdir(dirname($path), 0777, true);
            file_put_contents($path, "<?php return ['login'=>['code'=>'login','placement'=>'layout','default_injections'=>[['slot'=>'account-main','required'=>false]]]];");
            $hits = (new RequiredInjectionSiblingFetchScanner())->scanProject($root);
            self::assertCount(1, $hits);
            self::assertSame('layout-placement-default-injection', $hits[0]['type']);
            self::assertSame('login', $hits[0]['code']);
        } finally { $this->removeFixture($app); }
    }
    public function testForeignInjectionOverrideRequiresDifferentOwnerSlot(): void
    {
        $app = sys_get_temp_dir() . '/weline-foreign-placement-' . uniqid();
        $root = $app . '/code';
        $path = $root . '/Weline/Customer/extends/module/Weline_Widget/Weline_Customer/widget.php';
        $design = $app . '/design/Weline/example/frontend/layouts/account.phtml';
        try {
            mkdir(dirname($path), 0777, true);
            mkdir(dirname($design), 0777, true);
            file_put_contents($design, '<w:slot id="foreign-account-main"></w:slot>');
            file_put_contents($path, "<?php return ['login'=>['code'=>'login','placement'=>'layout','default_injections'=>[['placement'=>'injection','slot'=>'foreign-account-main','required'=>false]]]];");
            $scanner = new RequiredInjectionSiblingFetchScanner();
            self::assertSame([], $scanner->scanProject($root));
            file_put_contents($design, '<w:slot id="other-slot"></w:slot>');
            self::assertCount(1, $scanner->scanProject($root));
            $native = $root . '/Weline/Customer/view/templates/frontend/layouts/account.phtml';
            mkdir(dirname($native), 0777, true);
            file_put_contents($native, '<w:slot id="foreign-account-main"><w:widget name="login"/></w:slot>');
            file_put_contents($design, '<w:slot id="foreign-account-main"></w:slot>');
            self::assertCount(1, $scanner->scanProject($root));
        } finally { $this->removeFixture($app); }
    }
    public function testRequiredForeignInjectionDoesNotConflictWithNativeSlotAccept(): void
    {
        $app = sys_get_temp_dir() . '/weline-native-slot-' . uniqid();
        $root = $app . '/code';
        $definition = $root . '/Weline/Customer/extends/module/Weline_Widget/Weline_Customer/widget.php';
        $native = $root . '/Weline/Customer/view/templates/frontend/layouts/account.phtml';
        $design = $app . '/design/Weline/example/frontend/layouts/account.phtml';
        try {
            foreach ([$definition, $native, $design] as $path) { mkdir(dirname($path), 0777, true); }
            file_put_contents($definition, "<?php return ['login'=>['code'=>'login','placement'=>'layout','default_injections'=>[['placement'=>'injection','slot'=>'foreign-account-main','required'=>true]]]];");
            file_put_contents($design, '<w:slot id="foreign-account-main"></w:slot>');
            file_put_contents($native, '<!-- <w:widget name="login"/> --> <w:slot id="account-main" accept="login"><w:widget name="login"/></w:slot>');
            self::assertSame([], (new RequiredInjectionSiblingFetchScanner())->scanProject($root));
            file_put_contents($design, '<w:slot id="foreign-account-main"><w:widget name="login"/></w:slot>');
            self::assertCount(1, (new RequiredInjectionSiblingFetchScanner())->scanProject($root));
        } finally { $this->removeFixture($app); }
    }
    private function removeFixture(string $path): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
        rmdir($path);
    }
}
