<?php
declare(strict_types=1);

/**
 * Weline Framework
 * 
 * @DESC         | RuntimeTag 单元测试
 * @Author       | Weline Framework
 * @Package      | Weline\Framework\View\Taglib\Test
 */

namespace Weline\Framework\View\Taglib\Test;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\Taglib;
use Weline\Framework\View\Template;
use Weline\Framework\View\Taglib\Runtime\RuntimeTag;
use Weline\Framework\Manager\ObjectManager;

/**
 * RuntimeTag 测试
 * 
 * 覆盖运行期标签渲染功能
 */
class RuntimeTagTest extends TestCase
{
    private Taglib $taglib;
    private Template $template;

    protected function setUp(): void
    {
        $this->taglib = ObjectManager::getInstance(Taglib::class);
        $this->template = ObjectManager::getInstance(Template::class);
    }

    /**
     * 测试 RuntimeTag 回调注册
     */
    public function testRuntimeTagCallbackRegistration(): void
    {
        $runtimeTag = new RuntimeTag();
        
        $runtimeTag->registerCallback('test-tag', function(array $params) {
            return 'Test output: ' . ($params['value'] ?? 'default');
        });

        self::assertTrue($runtimeTag->hasCallback('test-tag'));
    }

    /**
     * 测试 RuntimeTag 渲染
     */
    public function testRuntimeTagRender(): void
    {
        $runtimeTag = new RuntimeTag();
        
        $runtimeTag->registerCallback('custom', function(array $params) {
            return 'Custom: ' . ($params['name'] ?? 'unknown');
        });

        $result = $runtimeTag->render('custom', ['name' => 'Test']);
        self::assertEquals('Custom: Test', $result);
    }

    /**
     * 测试 RuntimeTag 子内容
     */
    public function testRuntimeTagWithContent(): void
    {
        $runtimeTag = new RuntimeTag();
        
        $runtimeTag->registerCallback('wrapper', function(array $params) {
            return '<div>' . ($params['__content'] ?? '') . '</div>';
        });

        $result = $runtimeTag->render('wrapper', [], 'Inner content');
        self::assertEquals('<div>Inner content</div>', $result);
    }

    /**
     * 测试 Taglib registerRuntimeTag API
     */
    public function testTaglibRegisterRuntimeTag(): void
    {
        $this->taglib->registerRuntimeTag('my-runtime-tag', function(array $params) {
            return 'My runtime tag: ' . ($params['__content'] ?? '');
        });

        // 验证通过 stats 检查
        $stats = $this->taglib->stats();
        self::assertArrayHasKey('runtimeTag', $stats);
    }

    /**
     * 测试命名空间标签解析
     */
    public function testNamespacedTagResolution(): void
    {
        $runtimeTag = new RuntimeTag();
        
        // 注册一个命名空间标签
        $runtimeTag->registerCallback('w:custom:tag', function(array $params) {
            return 'Namespaced tag output';
        });

        $result = $runtimeTag->render('w:custom:tag', []);
        self::assertEquals('Namespaced tag output', $result);
    }

    /**
     * 测试未注册标签返回内容
     */
    public function testUnregisteredTagReturnsContent(): void
    {
        $runtimeTag = new RuntimeTag();
        
        // 渲染未注册的标签
        $result = $runtimeTag->render('unregistered-tag', [], 'Default content');
        self::assertEquals('Default content', $result);
    }

    /**
     * 测试 RuntimeTag 批量注册
     */
    public function testRuntimeTagBatchRegister(): void
    {
        $runtimeTag = new RuntimeTag();
        
        $runtimeTag->registerCallbacks([
            'tag1' => fn($p) => 'Tag1',
            'tag2' => fn($p) => 'Tag2',
            'tag3' => fn($p) => 'Tag3',
        ]);

        self::assertTrue($runtimeTag->hasCallback('tag1'));
        self::assertTrue($runtimeTag->hasCallback('tag2'));
        self::assertTrue($runtimeTag->hasCallback('tag3'));
    }

    /**
     * 测试 RuntimeTag 统计信息
     */
    public function testRuntimeTagStats(): void
    {
        $runtimeTag = new RuntimeTag();
        
        $runtimeTag->registerCallback('test', fn($p) => 'Test');
        
        $stats = $runtimeTag->stats();
        self::assertArrayHasKey('callbackCount', $stats);
        self::assertEquals(1, $stats['callbackCount']);
    }

    /**
     * 测试 RuntimeTag 设置模板
     */
    public function testRuntimeTagSetTemplate(): void
    {
        $runtimeTag = new RuntimeTag();
        $template = ObjectManager::getInstance(Template::class);
        
        $runtimeTag->setTemplate($template);
        
        self::assertSame($template, $runtimeTag->getTemplate());
    }
}
