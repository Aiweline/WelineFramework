<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Helper\CssVariableScanner;
use Weline\Theme\Model\WelineTheme;

/**
 * CSS变量Meta注册Observer
 * 
 * 监听系统升级事件，自动扫描并注册所有CSS变量到Meta系统
 */
class VariableMetaRegister implements ObserverInterface
{
    /**
     * 执行观察者逻辑
     * 
     * @param Event $event
     * @return void
     */
    public function execute(Event &$event): void
    {
        try {
            /** @var CssVariableScanner $scanner */
            $scanner = ObjectManager::getInstance(CssVariableScanner::class);
            
            // 获取所有主题
            /** @var WelineTheme $themeModel */
            $themeModel = ObjectManager::getInstance(WelineTheme::class);
            $themes = $themeModel->select()->fetch()->getItems();
            
            $totalScanned = 0;
            
            // 扫描每个主题的变量
            foreach ($themes as $theme) {
                if (!$theme instanceof WelineTheme || !$theme->getId()) {
                    continue;
                }
                
                // 扫描前端变量
                $frontendVars = $scanner->scanVariables('frontend', $theme);
                $totalScanned += count($frontendVars);
                
                // 扫描后端变量
                $backendVars = $scanner->scanVariables('backend', $theme);
                $totalScanned += count($backendVars);
            }
            
            // 如果没有主题，扫描默认主题（Weline_Theme模块）
            if (empty($themes)) {
                $frontendVars = $scanner->scanVariables('frontend');
                $totalScanned += count($frontendVars);
                
                $backendVars = $scanner->scanVariables('backend');
                $totalScanned += count($backendVars);
            }
            
        } catch (\Exception $e) {
            // 记录错误但不阻止系统升级
            if (defined('DEV') && DEV) {
                w_log_error('CSS变量Meta注册失败: ' . $e->getMessage());
            }
        }
    }
    
    /**
     * 手动触发扫描（供命令行工具调用）
     * 
     * @param string $area 区域（frontend/backend），如果为空则扫描所有区域
     * @param int|null $themeId 主题ID，如果为null则扫描所有主题
     * @param bool $force 是否强制重新注册
     * @return array 扫描结果
     */
    public static function scanManually(string $area = '', ?int $themeId = null, bool $force = false): array
    {
        /** @var CssVariableScanner $scanner */
        $scanner = ObjectManager::getInstance(CssVariableScanner::class);
        
        $results = [];
        $areas = $area ? [$area] : ['frontend', 'backend'];
        
        if ($themeId) {
            /** @var WelineTheme $theme */
            $theme = ObjectManager::getInstance(WelineTheme::class);
            $theme->load($themeId);
            
            if ($theme->getId()) {
                foreach ($areas as $areaItem) {
                    $vars = $scanner->scanVariables($areaItem, $theme);
                    $results[$areaItem] = $vars;
                }
            }
        } else {
            // 扫描所有主题
            /** @var WelineTheme $themeModel */
            $themeModel = ObjectManager::getInstance(WelineTheme::class);
            $themes = $themeModel->select()->fetch()->getItems();
            
            foreach ($themes as $theme) {
                if (!$theme instanceof WelineTheme || !$theme->getId()) {
                    continue;
                }
                
                foreach ($areas as $areaItem) {
                    $vars = $scanner->scanVariables($areaItem, $theme);
                    if (!isset($results[$areaItem])) {
                        $results[$areaItem] = [];
                    }
                    $results[$areaItem] = array_merge($results[$areaItem], $vars);
                }
            }
            
            // 如果没有主题，扫描默认主题
            if (empty($themes)) {
                foreach ($areas as $areaItem) {
                    $vars = $scanner->scanVariables($areaItem);
                    $results[$areaItem] = $vars;
                }
            }
        }
        
        return $results;
    }
}

