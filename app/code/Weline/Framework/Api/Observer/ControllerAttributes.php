<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Api\Observer;

use Weline\Framework\Api\Validator\ApiSpecValidator;
use Weline\Framework\App\Exception;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * 控制器属性Observer
 * 
 * 在路由注册时验证API规范
 */
class ControllerAttributes implements ObserverInterface
{
    private ApiSpecValidator $validator;
    
    public function __construct()
    {
        $this->validator = ObjectManager::getInstance(ApiSpecValidator::class);
    }
    
    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        
        // 只处理API路由
        if (($data['type'] ?? '') !== 'api') {
            return;
        }
        
        $className = $data['class'] ?? '';
        $methodName = $data['method'] ?? '';
        $isBackend = (bool)($data['is_backend'] ?? false);
        
        if (empty($className) || empty($methodName)) {
            return;
        }
        
        // 检查类是否存在
        if (!class_exists($className)) {
            return;
        }
        
        // 只验证继承 AbstractRestController 的控制器方法
        // Observer、Helper 等非 API 控制器不需要验证
        $reflection = new \ReflectionClass($className);
        $isApiController = false;
        
        // 检查是否继承 AbstractRestController
        $parentClass = $reflection->getParentClass();
        while ($parentClass) {
            if ($parentClass->getName() === 'Weline\Framework\Controller\AbstractRestController') {
                $isApiController = true;
                break;
            }
            $parentClass = $parentClass->getParentClass();
        }
        
        // 如果不是 API 控制器，跳过验证
        if (!$isApiController) {
            return;
        }
        
        // 验证API规范
        $result = $this->validator->validateMethod($className, $methodName, $isBackend);
        
        if (!$result['valid']) {
            // 不符合规范，抛出异常并停止编译
            $errorMessage = "API规范验证失败：\n";
            $errorMessage .= "控制器: {$className}\n";
            $errorMessage .= "方法: {$methodName}\n";
            $errorMessage .= "错误列表:\n";
            foreach ($result['errors'] as $error) {
                $errorMessage .= "  - {$error}\n";
            }
            $errorMessage .= "\n请参考文档: app/code/Weline/Framework/doc/3-开发/API接口开发规范.md";
            
            throw new Exception($errorMessage);
        }
        
        // 验证通过，继续注册路由
    }
}

