<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Sticker\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl as AclAttribute;
use Weline\Framework\Manager\ObjectManager;
use Weline\Sticker\Service\RuleParser;
use Weline\Sticker\Service\RuleScanner;
use Weline\Sticker\Service\StickerRegistry;

/**
 * Sticker 管理后台控制器
 * 
 * @package Weline_Sticker
 */
#[AclAttribute('Weline_Sticker::sticker_manager', 'Sticker管理', 'mdi-sticker', 'Sticker管理', '')]
class Sticker extends BackendController
{
    /**
     * 获取规则扫描器
     */
    private function getRuleScanner(): RuleScanner
    {
        return ObjectManager::getInstance(RuleScanner::class);
    }

    /**
     * 获取规则解析器
     */
    private function getRuleParser(): RuleParser
    {
        return ObjectManager::getInstance(RuleParser::class);
    }

    /**
     * 获取注册表
     */
    private function getStickerRegistry(): StickerRegistry
    {
        return ObjectManager::getInstance(StickerRegistry::class);
    }

    /**
     * Sticker 列表页面
     * 
     * @return string
     */
    #[AclAttribute('Weline_Sticker::sticker_list', '查看Sticker列表', 'mdi-view-list', '查看Sticker列表')]
    public function index(): string
    {
        try {
            $search = trim($this->request->getGet('search', ''));
            $targetModule = trim($this->request->getGet('target_module', ''));
            $sourceModule = trim($this->request->getGet('source_module', ''));

            // 获取注册表
            $registry = $this->getStickerRegistry()->getRegistry(true); // 强制重新加载

            // 构建列表数据
            $stickers = [];
            foreach ($registry as $targetModuleName => $files) {
                foreach ($files as $targetFile => $stickerInfos) {
                    foreach ($stickerInfos as $stickerInfo) {
                        $sourceModuleName = $stickerInfo['source_module'] ?? '';
                        $stickerFile = $stickerInfo['sticker_file'] ?? '';
                        $actions = $stickerInfo['actions'] ?? [];

                        // 过滤
                        if (!empty($search)) {
                            $match = false;
                            if (stripos($targetModuleName, $search) !== false ||
                                stripos($targetFile, $search) !== false ||
                                stripos($sourceModuleName, $search) !== false ||
                                stripos($stickerFile, $search) !== false) {
                                $match = true;
                            }
                            if (!$match) {
                                continue;
                            }
                        }

                        if (!empty($targetModule) && $targetModuleName !== $targetModule) {
                            continue;
                        }

                        if (!empty($sourceModule) && $sourceModuleName !== $sourceModule) {
                            continue;
                        }

                        $stickers[] = [
                            'target_module' => $targetModuleName,
                            'target_file' => $targetFile,
                            'source_module' => $sourceModuleName,
                            'sticker_file' => $stickerFile,
                            'sticker_relative_path' => $stickerInfo['sticker_relative_path'] ?? '',
                            'actions_count' => count($actions),
                            'actions' => $actions
                        ];
                    }
                }
            }

            // 获取所有模块列表（用于过滤）
            $modules = \Weline\Framework\App\Env::getInstance()->getModuleList();
            $moduleList = array_keys($modules);

            $this->assign('stickers', $stickers);
            $this->assign('total', count($stickers));
            $this->assign('search', $search);
            $this->assign('target_module', $targetModule);
            $this->assign('source_module', $sourceModule);
            $this->assign('modules', $moduleList);

            return $this->fetch();
        } catch (\Exception $e) {
            $this->getMessageManager()->addError(__('加载Sticker列表失败：%{1}', [$e->getMessage()]));
            return $this->fetch();
        }
    }

    /**
     * Sticker 详情页面
     * 
     * @return string
     */
    #[AclAttribute('Weline_Sticker::sticker_detail', '查看Sticker详情', 'mdi-eye', '查看Sticker详情')]
    public function detail(): string
    {
        try {
            $targetModule = trim($this->request->getGet('target_module', ''));
            $targetFile = trim($this->request->getGet('target_file', ''));
            $sourceModule = trim($this->request->getGet('source_module', ''));

            if (empty($targetModule) || empty($targetFile) || empty($sourceModule)) {
                $this->getMessageManager()->addError(__('参数不完整'));
                return $this->redirect('*/backend/sticker/index');
            }

            // 获取注册表
            $registry = $this->getStickerRegistry()->getRegistry(true);
            
            if (!isset($registry[$targetModule][$targetFile])) {
                $this->getMessageManager()->addError(__('Sticker不存在'));
                return $this->redirect('*/backend/sticker/index');
            }

            // 查找对应的 Sticker 信息
            $stickerInfo = null;
            foreach ($registry[$targetModule][$targetFile] as $info) {
                if (($info['source_module'] ?? '') === $sourceModule) {
                    $stickerInfo = $info;
                    break;
                }
            }

            if (!$stickerInfo) {
                $this->getMessageManager()->addError(__('Sticker不存在'));
                return $this->redirect('*/backend/sticker/index');
            }

            $stickerFile = $stickerInfo['sticker_file'] ?? '';
            $actions = $stickerInfo['actions'] ?? [];

            // 读取 Sticker 文件内容
            $stickerContent = '';
            if (file_exists($stickerFile)) {
                $stickerContent = file_get_contents($stickerFile);
            }

            // 读取源文件内容
            $modules = \Weline\Framework\App\Env::getInstance()->getModuleList();
            $sourceContent = '';
            $sourceFilePath = '';
            if (isset($modules[$targetModule])) {
                $basePath = $modules[$targetModule]['base_path'] ?? '';
                $sourceFilePath = $basePath . str_replace('/', DIRECTORY_SEPARATOR, $targetFile);
                if (file_exists($sourceFilePath)) {
                    $sourceContent = file_get_contents($sourceFilePath);
                }
            }

            $this->assign('target_module', $targetModule);
            $this->assign('target_file', $targetFile);
            $this->assign('source_module', $sourceModule);
            $this->assign('sticker_file', $stickerFile);
            $this->assign('sticker_relative_path', $stickerInfo['sticker_relative_path'] ?? '');
            $this->assign('actions', $actions);
            $this->assign('sticker_content', $stickerContent);
            $this->assign('source_content', $sourceContent);
            $this->assign('source_file_path', $sourceFilePath);

            return $this->fetch();
        } catch (\Exception $e) {
            $this->getMessageManager()->addError(__('加载Sticker详情失败：%{1}', [$e->getMessage()]));
            return $this->redirect('*/backend/sticker/index');
        }
    }
}

