<?php

declare(strict_types=1);

namespace Weline\DataTable\Test\Unit;

use Weline\DataTable\Helper\TableContext;
use Weline\DataTable\Helper\UiAssets;
use Weline\DataTable\Taglib\Field;
use Weline\DataTable\Taglib\Form;
use Weline\DataTable\Taglib\Table;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\TestCore;
use Weline\Framework\View\Taglib;
use Weline\Framework\View\Template;

class TaglibFrontendContractTest extends TestCore
{
    protected function setUp(): void
    {
        parent::setUp();
        TableContext::clearAll();
        UiAssets::resetRequestState();
    }

    protected function tearDown(): void
    {
        TableContext::clearAll();
        UiAssets::resetRequestState();
        parent::tearDown();
    }

    public function testTableAttrExposesFrontendContract(): void
    {
        $attributes = Table::attr();

        $this->assertArrayHasKey('allow-frontend', $attributes);
        $this->assertArrayHasKey('api-provider', $attributes);
        $this->assertArrayHasKey('dependencies', $attributes);
        $this->assertArrayHasKey('transaction', $attributes);
        $this->assertFalse($attributes['allow-frontend']);
        $this->assertFalse($attributes['api-provider']);
        $this->assertArrayNotHasKey('api-url', $attributes);
        $this->assertArrayNotHasKey('field-api-url', $attributes);
    }

    public function testFormAttrExposesFrontendContract(): void
    {
        $attributes = Form::attr();

        $this->assertArrayHasKey('allow-frontend', $attributes);
        $this->assertArrayHasKey('api-provider', $attributes);
        $this->assertArrayHasKey('dependencies', $attributes);
        $this->assertArrayHasKey('transaction', $attributes);
        $this->assertArrayHasKey('auto_fields', $attributes);
        $this->assertArrayNotHasKey('api-url', $attributes);
        $this->assertArrayNotHasKey('field-api-url', $attributes);
    }

    public function testTableCallbackEmitsProviderOperationsAndModelConfig(): void
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
                'api-provider' => 'datatable',
                'dependencies' => 'u.id->o.user_id',
                'transaction' => 'true',
            ]
        );

        $this->assertIsString($html);
        $this->assertStringContainsString('frontend-table', $html);
        $tableConfig = $this->configById($html, 'frontend-table');
        $formConfig = $this->configById($html, 'form-frontend-table');
        $this->assertSame('datatable', $tableConfig['apiProvider']);
        $this->assertSame('data', $tableConfig['operations']['data']);
        $this->assertSame('formFields', $formConfig['operations']['formFields']);
        $this->assertSame('u.id->o.user_id', $tableConfig['dependencies']);
        $this->assertTrue($tableConfig['transaction']);
        $this->assertSame('Weline\DataTable\Model\TestUser', $tableConfig['modelConfig']['models']['u']);
        $this->assertSame('Weline\DataTable\Model\TestOrder', $tableConfig['modelConfig']['models']['o']);
    }

    public function testTaglibPreservesExplicitAliasPrefixedHeaderFields(): void
    {
        $source = <<<'HTML'
<w:d-table
    id="join-contract"
    model="Weline\DataTable\Model\TestUser as u, Weline\DataTable\Model\TestOrder as o"
    join="left o on u.id=o.user_id"
    scope="join-contract"
    allow-frontend="true">
    <w:t-header>
        <w:field belong="t-header" name="u.name">User Name</w:field>
        <w:field belong="t-header" name="o.order_no">Order No</w:field>
    </w:t-header>
</w:d-table>
HTML;

        $taglib = ObjectManager::getInstance(Taglib::class);
        $template = ObjectManager::getInstance(Template::class);
        $html = $taglib->tagReplace($template, $source);

        $this->assertStringContainsString('data-field="u.name"', $html);
        $this->assertStringContainsString('data-field="o.order_no"', $html);
        $this->assertStringNotContainsString('data-field="phone"', $html);
    }

    public function testTableDimensionsUseValidatedScalarAttributesWithoutInlineStyle(): void
    {
        $callback = Table::callback();
        $html = $callback(
            'd-table',
            [],
            ['', '', '<w:t-header></w:t-header>'],
            [
                'id' => 'dimension-table',
                'model' => 'Weline\\DataTable\\Model\\TestUser',
                'scope' => 'dimension-table-scope',
                'height' => '36rem',
                'width' => '100%',
            ]
        );

        $this->assertIsString($html);
        $this->assertStringContainsString('data-w-datatable-height="36rem"', $html);
        $this->assertStringContainsString('data-w-datatable-width="100%"', $html);
        $this->assertStringNotContainsString('style=', $html);
        $this->assertStringNotContainsString('--w-datatable-height:', $html);
        $this->assertStringNotContainsString('--w-datatable-width:', $html);
    }

    public function testStandaloneFormLoadsOnlyItsOwnedComponentBundle(): void
    {
        $callback = Form::callback();
        $html = $callback(
            'd-form',
            [],
            ['', '', ''],
            [
                'id' => 'standalone-form-assets',
                'model' => 'Weline\\DataTable\\Model\\TestUser',
                'scope' => 'standalone-form-assets',
                'form-mode' => 'inline',
            ]
        );

        $this->assertIsString($html);
        $this->assertStringContainsString('weline-datatable.css', $html);
        $this->assertStringContainsString('weline-datatable-form.js', $html);
        $this->assertMatchesRegularExpression('/weline-datatable\.css\?v=[a-f0-9]{12}/', $html);
        $this->assertMatchesRegularExpression('/weline-datatable-form\.js\?v=[a-f0-9]{12}/', $html);
        $this->assertStringNotContainsString('components/weline-datatable.js', $html);
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
            'api-provider' => 'datatable',
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
        $formConfig = $this->configById($html, 'frontend-child-form');
        $this->assertSame('datatable', $formConfig['apiProvider']);
        $this->assertSame('u.id->o.user_id', $formConfig['dependencies']);
        $this->assertTrue($formConfig['transaction']);
        $this->assertSame('Weline\DataTable\Model\TestUser', $formConfig['modelConfig']['models']['u']);
        $this->assertSame('Weline\DataTable\Model\TestOrder', $formConfig['modelConfig']['models']['o']);
        $this->assertSame('u', $formConfig['modelConfig']['aliases']['Weline\DataTable\Model\TestUser']);
        $this->assertSame('o', $formConfig['modelConfig']['aliases']['Weline\DataTable\Model\TestOrder']);
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
        $this->assertStringContainsString('data-field="name"', $html);
        $this->assertStringContainsString('name="name"', $html);
        $fieldConfig = $this->jsonAttributes($html, 'data-w-field')[0] ?? [];
        $this->assertSame('d-form', $fieldConfig['belong'] ?? null);
        $this->assertSame('frontend-standalone-form', $fieldConfig['formId'] ?? null);
    }

    /** @return array<string,mixed> */
    private function configById(string $html, string $id): array
    {
        foreach ($this->jsonAttributes($html, 'data-w-config') as $config) {
            if (($config['id'] ?? null) === $id) {
                return $config;
            }
        }

        $this->fail('Missing data-w-config for ' . $id);
    }

    /** @return list<array<string,mixed>> */
    private function jsonAttributes(string $html, string $attribute): array
    {
        preg_match_all('/\\b' . preg_quote($attribute, '/') . '="([^"]*)"/u', $html, $matches);
        $configs = [];
        foreach ($matches[1] ?? [] as $encoded) {
            $decoded = html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $value = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($value)) {
                $configs[] = $value;
            }
        }
        return $configs;
    }
}
