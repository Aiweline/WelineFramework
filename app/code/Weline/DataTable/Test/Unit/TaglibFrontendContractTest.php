<?php

declare(strict_types=1);

namespace Weline\DataTable\Test\Unit;

use Weline\DataTable\Helper\TableContext;
use Weline\DataTable\Taglib\Field;
use Weline\DataTable\Taglib\Form;
use Weline\DataTable\Taglib\Table;
use Weline\Framework\UnitTest\TestCore;

class TaglibFrontendContractTest extends TestCore
{
    protected function setUp(): void
    {
        parent::setUp();
        TableContext::clearAll();
    }

    protected function tearDown(): void
    {
        TableContext::clearAll();
        parent::tearDown();
    }

    public function testTableAttrExposesFrontendContract(): void
    {
        $attributes = Table::attr();

        $this->assertArrayHasKey('allow-frontend', $attributes);
        $this->assertArrayHasKey('api-url', $attributes);
        $this->assertArrayHasKey('field-api-url', $attributes);
        $this->assertArrayHasKey('dependencies', $attributes);
        $this->assertArrayHasKey('transaction', $attributes);
        $this->assertFalse($attributes['allow-frontend']);
        $this->assertFalse($attributes['api-url']);
        $this->assertFalse($attributes['field-api-url']);
    }

    public function testFormAttrExposesFrontendContract(): void
    {
        $attributes = Form::attr();

        $this->assertArrayHasKey('allow-frontend', $attributes);
        $this->assertArrayHasKey('api-url', $attributes);
        $this->assertArrayHasKey('field-api-url', $attributes);
        $this->assertArrayHasKey('dependencies', $attributes);
        $this->assertArrayHasKey('transaction', $attributes);
        $this->assertArrayHasKey('auto_fields', $attributes);
    }

    public function testTableCallbackEmitsFrontendApiOverridesAndModelConfig(): void
    {
        $callback = Table::callback();
        $html = $callback(
            'd-table',
            [],
            ['', '', '<w:t-header></w:t-header>'],
            [
                'id' => 'frontend-table',
                'model' => 'Weline\DataTable\Model\TestUser as u, Weline\DataTable\Model\TestOrder as o',
                'scope' => 'frontend-table-scope',
                'allow-frontend' => 'true',
                'api-url' => 'datatable/rest/v1/demo-table',
                'field-api-url' => 'datatable/rest/v1/demo-form/fields',
                'dependencies' => 'u.id->o.user_id',
                'transaction' => 'true',
            ]
        );

        $this->assertIsString($html);
        $this->assertStringContainsString('frontend-table', $html);
        $this->assertStringContainsString('datatable/rest/v1/demo-table', $html);
        $this->assertStringContainsString('datatable/rest/v1/demo-form/fields', $html);
        $this->assertStringContainsString("dependencies: 'u.id->o.user_id'", $html);
        $this->assertStringContainsString('transaction: true', $html);
        $this->assertStringContainsString('modelConfig:', $html);
        $this->assertStringContainsString('Weline\\\\DataTable\\\\Model\\\\TestUser', $html);
        $this->assertStringContainsString('Weline\\\\DataTable\\\\Model\\\\TestOrder', $html);
    }

    public function testFormCallbackInheritsFrontendOptionsFromTableContext(): void
    {
        TableContext::setTableContext('demo-multi-scope', [
            'id' => 'frontend-parent-table',
            'scope' => 'demo-multi-scope',
            'model' => 'Weline\DataTable\Model\TestUser as u, Weline\DataTable\Model\TestOrder as o',
            'model_config' => [
                'models' => [
                    'u' => 'Weline\DataTable\Model\TestUser',
                    'o' => 'Weline\DataTable\Model\TestOrder',
                ],
                'main_model' => 'Weline\DataTable\Model\TestUser',
                'aliases' => [
                    'Weline\DataTable\Model\TestUser' => 'u',
                    'Weline\DataTable\Model\TestOrder' => 'o',
                ],
            ],
            'allow-frontend' => true,
            'api-url' => 'datatable/rest/v1/demo-table',
            'field-api-url' => 'datatable/rest/v1/demo-form/fields',
            'dependencies' => 'u.id->o.user_id',
            'transaction' => true,
        ]);

        $callback = Form::callback();
        $html = $callback(
            'd-form',
            [],
            ['', '', '<fieldset id="u"><legend>User</legend></fieldset><fieldset id="o"><legend>Order</legend></fieldset>'],
            [
                'id' => 'frontend-child-form',
                'scope' => 'demo-multi-scope',
                'form-mode' => 'inline',
                'title' => 'Child Form',
                'allow-frontend' => 'true',
                'auto_fields' => 'false',
            ]
        );

        $this->assertIsString($html);
        $this->assertStringContainsString('frontend-child-form', $html);
        $this->assertStringContainsString('datatable/rest/v1/demo-table', $html);
        $this->assertStringContainsString('datatable/rest/v1/demo-form/fields', $html);
        $this->assertStringContainsString('data-table-alias="u"', $html);
        $this->assertStringContainsString('data-table-alias="o"', $html);
        $this->assertStringContainsString('dependencies: "u.id->o.user_id"', $html);
        $this->assertStringContainsString('transaction: true', $html);
        $this->assertStringContainsString('modelConfig:', $html);
        $this->assertStringContainsString('"u":"Weline\\\\DataTable\\\\Model\\\\TestUser"', $html);
        $this->assertStringContainsString('"o":"Weline\\\\DataTable\\\\Model\\\\TestOrder"', $html);
    }

    public function testStandaloneFormFieldUsesRenderStackContext(): void
    {
        TableContext::pushChildTag('d-form', 'frontend-standalone-form', [
            'type' => 'd-form',
            'scope' => 'frontend-standalone-form',
            'model' => 'Weline\DataTable\Model\TestUser',
            'attributes' => [
                'scope' => 'frontend-standalone-form',
                'model' => 'Weline\DataTable\Model\TestUser',
                'allow-frontend' => true,
            ],
        ]);

        $callback = Field::callback();
        $html = $callback(
            'field',
            [],
            ['', '', 'Name'],
            [
                'belong' => 'd-form',
                'name' => 'name',
                'type' => 'text',
                'label' => 'Name',
            ]
        );

        TableContext::popTag();

        $this->assertIsString($html);
        $this->assertStringContainsString('data-belong="d-form"', $html);
        $this->assertStringContainsString('data-field="name"', $html);
        $this->assertStringContainsString('name="name"', $html);
    }
}
