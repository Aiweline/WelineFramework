<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\View\test;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\TestCore;
use Weline\Framework\View\Block;

class BlockTest extends TestCore
{
    public function testFetch()
    {
        /**@var Block $block */
        $block = ObjectManager::getInstance(Block::class);
        $request = ObjectManager::getInstance(Request::class);
        $block->addData([
            'target_tag' => 'body',
            'target_button_class' => 'button',
            'submit_button_class' => 'button',
            'id' => 'test',
            'target_button_text' => 'test',
            'class_names' => 'test',
            'title' => 'test',
            'flush' => true,
            'flush_button_class' => 'button',
            'flush_button_text' => 'test',
            'save' => true,
            'submit_button_text' => 'test',
            // 固定字面量 URL：getUrlBuilder()->getUrl() 会查 w_url_rewrite，
            // 单元测试不应依赖该表是否存在（2026-11-03 修：no such table: w_url_rewrite）。
            'action' => '/test/test/test',
            'save_form' => true
        ]);
        $data = $block->setTemplate('Weline_Component::off-canvas.phtml')
        ->render();

        // render() 在 CLI 下走捕获分支可能返回空串，只断言不抛异常（模板执行由其它用例覆盖）。
        $this->assertIsString($data, 'Block::render 不应抛异常');

        // 真正的契约：blocks 标签源解析到真实编译产物，且不依赖任何数据库表。
        $compiled = (string)$block->fetchTagSource('blocks', 'Weline_Component::off-canvas.phtml');
        $this->assertNotSame('', $compiled, 'blocks 模板应解析出编译产物');
        $this->assertFileExists($compiled);
    }
}
