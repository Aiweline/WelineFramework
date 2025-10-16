<?php
declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Controller\Backend;

use GuoLaiRen\PageBuilder\Model\Page;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;

/**
 * 页面预览控制器
 * 用于实时预览头部、内容、页脚配置
 */
class Preview extends BackendController
{
    private Page $pageModel;

    public function __construct(
        Page $pageModel
    ) {
        $this->pageModel = $pageModel;
    }

    /**
     * 预览头部
     */
    public function header()
    {
        try {
            $pageId = (int)$this->request->getGet('page_id');
            if (!$pageId) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('页面ID不能为空')
                ]);
            }

            // 加载页面数据
            $page = clone $this->pageModel;
            $page->load($pageId);
            
            if (!$page->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('页面不存在')
                ]);
            }

            // 获取样式代码
            $styleCode = $page->getData('style') ?: 'default';
            $styleSettings = $page->getStyleSetting();

            // 构建头部模板路径
            $templatePath = 'GuoLaiRen_PageBuilder::Backend/Page/Preview/header.phtml';
            
            // 设置模板变量
            $this->assign('page', $page);
            $this->assign('style_code', $styleCode);
            $this->assign('style_settings', $styleSettings);
            
            // 渲染模板
            $html = $this->view->getTemplateEngine()->fetchTemplate($templatePath, $this->view->getData());
            
            return $this->fetchJson([
                'success' => true,
                'html' => $html,
                'style_code' => $styleCode
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * 预览内容
     */
    public function content()
    {
        try {
            $pageId = (int)$this->request->getGet('page_id');
            if (!$pageId) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('页面ID不能为空')
                ]);
            }

            // 加载页面数据
            $page = clone $this->pageModel;
            $page->load($pageId);
            
            if (!$page->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('页面不存在')
                ]);
            }

            // 获取样式代码
            $styleCode = $page->getData('style') ?: 'default';
            $styleSettings = $page->getStyleSetting();

            // 构建内容模板路径
            $templatePath = 'GuoLaiRen_PageBuilder::Backend/Page/Preview/content.phtml';
            
            // 设置模板变量
            $this->assign('page', $page);
            $this->assign('style_code', $styleCode);
            $this->assign('style_settings', $styleSettings);
            
            // 渲染模板
            $html = $this->view->getTemplateEngine()->fetchTemplate($templatePath, $this->view->getData());
            
            return $this->fetchJson([
                'success' => true,
                'html' => $html,
                'style_code' => $styleCode
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * 预览页脚
     */
    public function footer()
    {
        try {
            $pageId = (int)$this->request->getGet('page_id');
            if (!$pageId) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('页面ID不能为空')
                ]);
            }

            // 加载页面数据
            $page = clone $this->pageModel;
            $page->load($pageId);
            
            if (!$page->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('页面不存在')
                ]);
            }

            // 获取样式代码
            $styleCode = $page->getData('style') ?: 'default';
            $styleSettings = $page->getStyleSetting();

            // 构建页脚模板路径
            $templatePath = 'GuoLaiRen_PageBuilder::Backend/Page/Preview/footer.phtml';
            
            // 设置模板变量
            $this->assign('page', $page);
            $this->assign('style_code', $styleCode);
            $this->assign('style_settings', $styleSettings);
            
            // 渲染模板
            $html = $this->view->getTemplateEngine()->fetchTemplate($templatePath, $this->view->getData());
            
            return $this->fetchJson([
                'success' => true,
                'html' => $html,
                'style_code' => $styleCode
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * 完整预览（头部+内容+页脚）
     * 组合 style/{styleCode}/header.phtml、content.phtml、footer.phtml 三个模板
     */
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::page_builder_preview', '页面预览', '', '页面预览')]
    public function full()
    {
        $pageId = (int)$this->request->getGet('page_id');
        if (!$pageId) {
            echo '<div style="padding: 20px; color: red;">页面ID不能为空</div>';
            return;
        }

        // 加载页面数据
        $page = clone $this->pageModel;
        $page->load($pageId);
        
        if (!$page->getId()) {
            echo '<div style="padding: 20px; color: red;">页面不存在</div>';
            return;
        }

        // 获取样式代码和配置
        $styleCode = $page->getData('style') ?: 'default';
        $styleSettings = $page->getStyleSetting();

        // 设置模板变量
        $this->assign('page', $page);
        $this->assign('style_settings', $styleSettings);
        $this->assign('is_preview', true); // 标记为预览模式
        
        // 组合渲染 header、content、footer 三个模板
        // 使用相对路径（相对于当前控制器的模板目录）
        $stylePath = "../../style/{$styleCode}";
        
        // 渲染 header
        $headerHtml = $this->fetch("{$stylePath}/header.phtml");
        
        // 渲染 content
        $contentHtml = $this->fetch("{$stylePath}/content.phtml");
        
        // 渲染 footer
        $footerHtml = $this->fetch("{$stylePath}/footer.phtml");
        
        // 输出组合后的完整页面
        echo $headerHtml . $contentHtml . $footerHtml;
    }

    /**
     * 自动保存配置
     */
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_PageBuilder::page_builder_auto_save', '自动保存配置', '', '自动保存配置')]
    public function autoSave()
    {
        try {
            // 获取 JSON 请求体
            $rawBody = file_get_contents('php://input');
            $data = json_decode($rawBody, true);
            
            // 调试日志
            $this->log('📥 自动保存请求', [
                'rawBody' => $rawBody,
                'jsonData' => $data,
                'postData' => [
                    'page_id' => $this->request->getPost('page_id'),
                    'style_config' => $this->request->getPost('style_config')
                ]
            ]);
            
            // 如果 JSON 解析失败，尝试从 POST 获取
            if (!$data) {
                $data = [
                    'page_id' => $this->request->getPost('page_id'),
                    'style_config' => $this->request->getPost('style_config', [])
                ];
            }
            
            $pageId = (int)($data['page_id'] ?? 0);
            $locale = $data['locale'] ?? ''; // 获取语言参数
            $styleCode = $data['style_code'] ?? ''; // 获取样式代码
            
            $this->log('🔍 解析后的数据', [
                'pageId' => $pageId,
                'locale' => $locale,
                'styleCode' => $styleCode,
                'dataKeys' => array_keys($data),
                'styleConfigKeys' => isset($data['style_config']) ? array_keys($data['style_config']) : []
            ]);
            
            if (!$pageId) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('页面ID不能为空'),
                    'debug' => [
                        'received_data' => $data,
                        'page_id_value' => $data['page_id'] ?? null
                    ]
                ]);
            }

            // 加载页面
            $page = clone $this->pageModel;
            $page->load($pageId);
            
            if (!$page->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('页面不存在')
                ]);
            }

            // 获取样式配置
            $styleConfig = $data['style_config'] ?? [];
            
            // 如果没有指定样式代码，使用页面当前的样式
            if (!$styleCode) {
                $styleCode = $page->getData('style') ?: 'default';
            }
            
            // 获取现有的所有配置
            $currentSettings = $page->getStyleSetting();
            if (!is_array($currentSettings)) {
                $currentSettings = [];
            }
            
            // 确保样式代码的配置存在
            if (!isset($currentSettings[$styleCode])) {
                $currentSettings[$styleCode] = [];
            }
            
            // 根据是否有语言参数来决定如何保存
            if ($locale) {
                // 按样式 -> 语言保存：配置保存在 settings[styleCode][locale] 下
                if (!isset($currentSettings[$styleCode][$locale])) {
                    $currentSettings[$styleCode][$locale] = [];
                }
                
                // 合并新配置到该样式的该语言
                $currentSettings[$styleCode][$locale] = array_merge(
                    $currentSettings[$styleCode][$locale],
                    $styleConfig
                );
                
                $this->log('💾 按样式和语言保存配置', [
                    'styleCode' => $styleCode,
                    'locale' => $locale,
                    'configCount' => count($styleConfig),
                    'mergedCount' => count($currentSettings[$styleCode][$locale])
                ]);
            } else {
                // 不分语言保存：直接合并到该样式的根配置
                $currentSettings[$styleCode] = array_merge(
                    is_array($currentSettings[$styleCode]) ? $currentSettings[$styleCode] : [],
                    $styleConfig
                );
                
                $this->log('💾 保存样式通用配置', [
                    'styleCode' => $styleCode,
                    'configCount' => count($styleConfig),
                    'totalCount' => count($currentSettings[$styleCode])
                ]);
            }
            
            // 保存配置
            $page->setData('style_setting', json_encode($currentSettings));
            $page->save();

            return $this->fetchJson([
                'success' => true,
                'message' => __('配置已自动保存'),
                'locale' => $locale,
                'saved_at' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
}

