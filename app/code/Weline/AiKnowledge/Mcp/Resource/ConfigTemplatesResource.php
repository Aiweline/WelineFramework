<?php

declare(strict_types=1);

namespace Weline\AiKnowledge\Mcp\Resource;

/**
 * Configuration Templates Resource
 * 
 * Provides access to boilerplate code and configuration templates.
 */
class ConfigTemplatesResource implements ResourceInterface
{
    /**
     * Base URI for config template resources
     */
    private const BASE_URI = 'weline://config_templates/';
    
    /**
     * Available templates
     */
    private array $templates = [];
    
    public function __construct()
    {
        $this->initTemplates();
    }
    
    /**
     * Initialize templates
     */
    private function initTemplates(): void
    {
        $this->templates = [
            'register.php' => [
                'name' => 'register.php',
                'description' => 'Module registration file template',
                'content' => $this->getRegisterTemplate(),
            ],
            'composer.json' => [
                'name' => 'composer.json',
                'description' => 'Module composer.json template',
                'content' => $this->getComposerTemplate(),
            ],
            'env.php' => [
                'name' => 'env.php',
                'description' => 'Module environment configuration template',
                'content' => $this->getEnvTemplate(),
            ],
            'Controller.php' => [
                'name' => 'Controller.php',
                'description' => 'Controller class template',
                'content' => $this->getControllerTemplate(),
            ],
            'Model.php' => [
                'name' => 'Model.php',
                'description' => 'Model class template',
                'content' => $this->getModelTemplate(),
            ],
            'RestController.php' => [
                'name' => 'RestController.php',
                'description' => 'REST API controller template',
                'content' => $this->getRestControllerTemplate(),
            ],
            'Observer.php' => [
                'name' => 'Observer.php',
                'description' => 'Event observer template',
                'content' => $this->getObserverTemplate(),
            ],
            'Setup/Install.php' => [
                'name' => 'Setup/Install.php',
                'description' => 'Module installation setup template',
                'content' => $this->getInstallTemplate(),
            ],
            'event.xml' => [
                'name' => 'event.xml',
                'description' => 'Event configuration template',
                'content' => $this->getEventXmlTemplate(),
            ],
            'menu.xml' => [
                'name' => 'menu.xml',
                'description' => 'Backend menu configuration template',
                'content' => $this->getMenuXmlTemplate(),
            ],
        ];
    }
    
    /**
     * @inheritDoc
     */
    public function list(): array
    {
        $resources = [];
        
        foreach ($this->templates as $key => $template) {
            $resources[] = [
                'uri' => self::BASE_URI . $key,
                'name' => $template['name'],
                'description' => $template['description'],
                'mimeType' => $this->getMimeType($key),
            ];
        }
        
        return $resources;
    }
    
    /**
     * @inheritDoc
     */
    public function read(string $path): string
    {
        if (!isset($this->templates[$path])) {
            throw new \InvalidArgumentException("Template not found: {$path}");
        }
        
        return $this->templates[$path]['content'];
    }
    
    /**
     * Get MIME type for a template
     */
    private function getMimeType(string $path): string
    {
        if (str_ends_with($path, '.php')) {
            return 'application/x-php';
        }
        if (str_ends_with($path, '.json')) {
            return 'application/json';
        }
        if (str_ends_with($path, '.xml')) {
            return 'application/xml';
        }
        return 'text/plain';
    }
    
    private function getRegisterTemplate(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

/**
 * Module Registration
 * 
 * @package {{Vendor}}_{{Module}}
 */

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    '{{Vendor}}_{{Module}}',
    __DIR__,
    '1.0.0',
    '{{Description}}'
);
PHP;
    }
    
    private function getComposerTemplate(): string
    {
        return <<<'JSON'
{
    "name": "{{vendor}}/{{module}}",
    "description": "{{Description}}",
    "type": "weline-module",
    "license": "proprietary",
    "version": "1.0.0",
    "require": {
        "php": "^8.1"
    },
    "autoload": {
        "psr-4": {
            "{{Vendor}}\\{{Module}}\\": ""
        }
    }
}
JSON;
    }
    
    private function getEnvTemplate(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

/**
 * Module Environment Configuration
 */
return [
    // Add your module configuration here
    'enabled' => true,
];
PHP;
    }
    
    private function getControllerTemplate(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace {{Vendor}}\{{Module}}\Controller;

use Weline\Framework\App\Controller\PcController;

class Index extends PcController
{
    /**
     * Index action
     */
    public function index(): string
    {
        return $this->render();
    }
}
PHP;
    }
    
    private function getModelTemplate(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace {{Vendor}}\{{Module}}\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\DataInterface;
use Weline\Framework\Setup\Db\ModelSetup;

class {{ModelName}} extends Model implements DataInterface
{
    use ModelSetup;
    
    public const fields_ID = 'id';
    public const fields_NAME = 'name';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';
    
    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }
    
    private function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable()
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 11, 'primary key auto_increment', 'ID')
                ->addColumn(self::fields_NAME, TableInterface::column_type_VARCHAR, 255, 'not null', 'Name')
                ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_TIMESTAMP, null, 'default CURRENT_TIMESTAMP', 'Created At')
                ->addColumn(self::fields_UPDATED_AT, TableInterface::column_type_TIMESTAMP, null, 'default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP', 'Updated At')
                ->create();
        }
    }
}
PHP;
    }
    
    private function getRestControllerTemplate(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace {{Vendor}}\{{Module}}\Api\Rest\V1;

use Weline\Framework\Controller\AbstractRestController;
use Weline\Framework\Api\ApiDoc;

class {{ControllerName}} extends AbstractRestController
{
    /**
     * Get resource
     * 
     * @param int $id Resource ID
     * @return array Response data
     */
    #[ApiDoc(
        summary: 'Get {{ControllerName}} by ID',
        description: 'Returns the {{ControllerName}} resource by its ID',
        version: 'v1',
        tags: ['{{Module}}'],
        category: '{{Module}}'
    )]
    public function get(int $id): array
    {
        // Implementation
        return $this->success(['id' => $id]);
    }
    
    /**
     * List resources
     * 
     * @param int $page Page number
     * @param int $limit Items per page
     * @return array Response data
     */
    #[ApiDoc(
        summary: 'List {{ControllerName}} resources',
        description: 'Returns a paginated list of {{ControllerName}} resources',
        version: 'v1',
        tags: ['{{Module}}'],
        category: '{{Module}}'
    )]
    public function getList(int $page = 1, int $limit = 20): array
    {
        // Implementation
        return $this->success(['items' => [], 'total' => 0]);
    }
    
    /**
     * Create resource
     * 
     * @return array Response data
     */
    #[ApiDoc(
        summary: 'Create {{ControllerName}}',
        description: 'Creates a new {{ControllerName}} resource',
        version: 'v1',
        tags: ['{{Module}}'],
        category: '{{Module}}'
    )]
    public function post(): array
    {
        // Implementation
        return $this->success(['id' => 1]);
    }
}
PHP;
    }
    
    private function getObserverTemplate(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace {{Vendor}}\{{Module}}\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class {{ObserverName}} implements ObserverInterface
{
    /**
     * Execute observer
     * 
     * @param Event $event
     */
    public function execute(Event &$event): void
    {
        $data = $event->getData('data');
        
        // Your observer logic here
    }
}
PHP;
    }
    
    private function getInstallTemplate(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace {{Vendor}}\{{Module}}\Setup;

use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\SetupInterface;

class Install implements SetupInterface
{
    /**
     * @inheritDoc
     */
    public function setup(Setup $setup, Context $context): void
    {
        // Installation logic here
    }
}
PHP;
    }
    
    private function getEventXmlTemplate(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <event name="{{event_name}}">
        <observer name="{{observer_name}}" class="{{Vendor}}\{{Module}}\Observer\{{ObserverName}}" />
    </event>
</config>
XML;
    }
    
    private function getMenuXmlTemplate(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<menu xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <add 
        id="{{Vendor}}_{{Module}}::menu_item" 
        title="{{Menu Title}}" 
        module="{{Vendor}}_{{Module}}" 
        action="backend/index" 
        resource="{{Vendor}}_{{Module}}::menu_item"
        sortOrder="10"
    />
</menu>
XML;
    }
}
