<?php

declare(strict_types=1);

namespace Weline\EditorManager\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\EditorManager\EditorManagerInterface;

#[Acl('Weline_EditorManager::main', '编辑器管理器', 'mdi mdi-content-save-edit', '内容管理')]
class Index extends BackendController
{
    /**
     * 编辑器管理器列表页面
     */
    #[Acl('Weline_EditorManager::list', '编辑器列表', 'mdi mdi-view-list', '查看编辑器列表')]
    public function index(): string
    {
        // 获取所有已注册的编辑器管理器
        $editors = $this->getRegisteredEditors();
        
        $this->assign('title', __('编辑器管理器'));
        $this->assign('editors', $editors);
        
        return $this->fetch();
    }
    
    /**
     * 获取所有已注册的编辑器管理器
     * 
     * @return array
     */
    private function getRegisteredEditors(): array
    {
        $editors = [];
        
        // 获取实现 EditorManagerInterface 的类
        $classes = $this->findEditorManagerClasses();
        
        foreach ($classes as $class) {
            try {
                if (class_exists($class) && is_subclass_of($class, EditorManagerInterface::class)) {
                    $editor = ObjectManager::getInstance($class);
                    $editors[] = [
                        'name' => $class::name(),
                        'class' => $class,
                        'description' => $editor->render(),
                    ];
                }
            } catch (\Exception $e) {
                // 忽略无法实例化的类
            }
        }
        
        return $editors;
    }
    
    /**
     * 查找所有编辑器管理器类
     * 
     * @return array
     */
    private function findEditorManagerClasses(): array
    {
        $classes = [];
        
        // 默认的 Local 编辑器
        $classes[] = \Weline\EditorManager\EditorManager\Local::class;
        
        // 检查是否有 CKEditor 模块
        if (class_exists(\Weline\CkeditorEditorManager\EditorManager\CKEditor::class)) {
            $classes[] = \Weline\CkeditorEditorManager\EditorManager\CKEditor::class;
        }
        
        return $classes;
    }
}
