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
use Weline\Framework\UnitTest\TestCore;
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
            'action' => $request->getUrlBuilder()->getUrl('test/test/test'),
            'save_form' => true
        ]);
        $data = $block->setTemplate('Weline_Component::off-canvas.phtml')->render();
        if (str_contains($data, 'off-canvas.phtml')) {
            $this->assertTrue(true, '模板渲染成功');
        } else {
            $this->assertTrue(false, '模板渲染失败');
        }
    }
}
