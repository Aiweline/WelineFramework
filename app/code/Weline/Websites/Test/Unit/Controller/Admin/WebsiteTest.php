<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Controller\Admin;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;
use Weline\Websites\Controller\Admin\Website;
use Weline\Websites\Model\Website as WebsiteModel;

class WebsiteTest extends TestCore
{
    private ?Website $controller = null;
    private ?WebsiteModel $websiteModel = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareBackendRequest();
        $this->controller = ObjectManager::getInstance(Website::class);
        $this->websiteModel = ObjectManager::getInstance(WebsiteModel::class);
    }

    protected function tearDown(): void
    {
        $this->controller = null;
        $this->websiteModel = null;
        parent::tearDown();
    }

    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(Website::class));
    }

    public function testControllerHasAddMethod(): void
    {
        $reflection = new \ReflectionClass(Website::class);
        $this->assertTrue($reflection->hasMethod('add'));
    }

    public function testAddReturnsFormOnGetRequest(): void
    {
        $request = ObjectManager::getInstance(Request::class);
        $request->setMethod('GET');
        $request->setData('router/class/method', 'add');

        $result = $this->controller->add();

        $this->assertIsString($result);
    }

    public function testAddClearsWebsiteId(): void
    {
        $reflection = new \ReflectionClass(Website::class);
        $sourceCode = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString("unset(\$data['website_id'])", $sourceCode);
    }

    public function testAddErrorRedirectsToHomepage(): void
    {
        $reflection = new \ReflectionClass(Website::class);
        $sourceCode = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString("'url' => '/'", $sourceCode);
        $this->assertStringContainsString("'reload' => '0'", $sourceCode);
    }

    public function testAddSuccessRedirectsToSuccessPage(): void
    {
        $reflection = new \ReflectionClass(Website::class);
        $sourceCode = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString('component/backend/offcanvas/getSuccess', $sourceCode);
    }

    public function testWebsiteSaveRedirectsUseBackendOffcanvasResultPages(): void
    {
        $reflection = new \ReflectionClass(Website::class);
        $sourceCode = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString('component/backend/offcanvas/getSuccess', $sourceCode);
        $this->assertStringContainsString('component/backend/offcanvas/getError', $sourceCode);
        $this->assertStringNotContainsString('/component/offcanvas/success', $sourceCode);
        $this->assertStringNotContainsString('/component/offcanvas/error', $sourceCode);
    }

    public function testAddValidatesNewWebsiteId(): void
    {
        $reflection = new \ReflectionClass(Website::class);
        $sourceCode = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString('getId()', $sourceCode);
        $this->assertStringContainsString('empty($websiteId)', $sourceCode);
    }

    public function testAddDoesNotCheckWebsiteIdExists(): void
    {
        $reflection = new \ReflectionClass(Website::class);
        $sourceCode = file_get_contents($reflection->getFileName());
        $addMethodStart = strpos($sourceCode, 'public function add()');
        $addMethodEnd = strpos($sourceCode, 'public function edit()', $addMethodStart);
        $addMethodCode = substr($sourceCode, $addMethodStart, $addMethodEnd - $addMethodStart);

        $this->assertStringNotContainsString('getWebsiteId()', $addMethodCode);
    }

    public function testTemplateVariablesHaveDefaults(): void
    {
        $templatePath = BP . 'app/code/Weline/Component/view/blocks/off-canvas.phtml';
        $this->assertFileExists($templatePath);

        $templateContent = file_get_contents($templatePath);

        $this->assertStringContainsString("{{target_button_text|''}}", $templateContent);
        $this->assertStringContainsString("{{title|''}}", $templateContent);
        $this->assertStringContainsString("{{submit_button_text|'保存'}}", $templateContent);
    }

    public function testOffCanvasBlockSetsDefaultValues(): void
    {
        $reflection = new \ReflectionClass(\Weline\Component\Block\OffCanvas::class);

        $this->assertTrue($reflection->hasConstant('default_data'));

        $defaultData = $reflection->getConstant('default_data');
        $this->assertIsArray($defaultData);
        $this->assertArrayHasKey('target-button-text', $defaultData);
        $this->assertArrayHasKey('submit-button-text', $defaultData);
        $this->assertArrayHasKey('title', $defaultData);
    }

    public function testSearchAjaxUsesFrameworkJsonWithoutTerminatingWorker(): void
    {
        $searchAjaxCode = $this->getMethodSource(Website::class, 'searchAjax');

        $this->assertStringContainsString('return $this->fetchJson($payload)', $searchAjaxCode);
        $this->assertStringContainsString("template('Weline_Websites::templates/Admin/Website/table.phtml')", $searchAjaxCode);
        $this->assertStringNotContainsString('exit;', $searchAjaxCode);
        $this->assertStringNotContainsString('echo json_encode', $searchAjaxCode);
        $this->assertStringNotContainsString('header(', $searchAjaxCode);
    }

    public function testWebsiteSearchBuildsOrConditionAcrossFields(): void
    {
        $method = new \ReflectionMethod(Website::class, 'applyWebsiteSearch');
        $method->setAccessible(true);

        $websiteModel = ObjectManager::getInstance(WebsiteModel::class, [], false);
        $method->invoke($this->controller, $websiteModel, 'OpsFlow');
        $websiteModel->select();

        $sql = $websiteModel->getQuery()->getSql(false);
        $this->assertMatchesRegularExpression('/name.*LIKE.*OR.*code.*LIKE.*OR.*url.*LIKE/s', $sql);
    }

    public function testAjaxSearchResetsToFirstPage(): void
    {
        $templatePath = BP . 'app/code/Weline/Websites/view/templates/Admin/Website/index.phtml';
        $this->assertFileExists($templatePath);

        $templateContent = file_get_contents($templatePath);

        $this->assertStringContainsString("params.append('page', '1')", $templateContent);
        $this->assertStringContainsString("params.append('pageSize', pageSize)", $templateContent);
    }

    private function getMethodSource(string $className, string $methodName): string
    {
        $method = new \ReflectionMethod($className, $methodName);
        $fileName = $method->getFileName();
        $this->assertIsString($fileName);

        $lines = file($fileName);
        $this->assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));
    }

    private function prepareBackendRequest(): void
    {
        self::initRequest('/admin/website/add');
        /** @var Request $request */
        $request = ObjectManager::getInstance(Request::class);
        $request->setBackend();
        $request->setServer('WELINE_AREA', 'backend');
        $request->setServer('REQUEST_URI', '/admin/website/add');
        $request->setMethod('GET');
        $request->setData('router/module', 'Weline_Websites');
        $request->setData('router/module_path', BP . 'app/code/Weline/Websites/');
        $request->setData('router/class/controller_name', 'Admin/Website');
        $request->setData('router/class/method', 'add');
        $request->setData('router/backend_router', 'admin');
    }
}
