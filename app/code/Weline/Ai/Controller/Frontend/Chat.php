<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2025/10/09
 */

namespace Weline\Ai\Controller\Frontend;

use Weline\Ai\Model\AiModel;
use Weline\Ai\Service\AdapterScanner;
use Weline\Framework\App\Controller\FrontendController;

/**
 * AI聊天界面控制器
 * 
 * 功能：
 * - 提供聊天界面
 * - 支持文本、图片、音频、视频等多媒体聊天
 * - 通过可恢复后台任务提供流式响应
 * - 聊天历史记录
 */
class Chat extends FrontendController
{
    /**
     * @var AiModel
     */
    private AiModel $aiModel;

    /**
     * @var AdapterScanner
     */
    private AdapterScanner $adapterScanner;

    /**
     * 构造函数
     * 
     * @param AiModel $aiModel
     * @param AdapterScanner $adapterScanner
     */
    public function __construct(
        AiModel $aiModel,
        AdapterScanner $adapterScanner
    ) {
        $this->aiModel = $aiModel;
        $this->adapterScanner = $adapterScanner;
    }

    /**
     * 聊天界面
     * 
     * @return string
     */
    public function index(): string
    {
        // 允许未登录用户访问（演示模式）
        $isLoggedIn = $this->isLoggedIn();

        // 获取可用的AI模型
        $models = $this->aiModel->reset()
            ->where(AiModel::schema_fields_IS_ACTIVE, 1)
            ->where(AiModel::schema_fields_PRIMARY_MODALITY, AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT)
            ->order(AiModel::schema_fields_IS_DEFAULT, 'DESC')
            ->order(AiModel::schema_fields_ID, 'ASC')
            ->select()
            ->fetch();
        $modelItems = $models->getItems();
        $fallbackModelCode = '';
        if ($modelItems !== []) {
            $firstModel = $modelItems[0];
            $fallbackModelCode = (string)(is_array($firstModel)
                ? ($firstModel[AiModel::schema_fields_MODEL_CODE] ?? '')
                : $firstModel->getData(AiModel::schema_fields_MODEL_CODE));
        }

        // 获取可用的场景适配器
        $adapters = $this->adapterScanner->getAllActiveAdapters();

        $this->assign('page_title', __('AI聊天'));
        $this->assign('models', $modelItems);
        $this->assign('fallback_model_code', $fallbackModelCode);
        $this->assign('adapters', $adapters);
        $this->assign('is_logged_in', $isLoggedIn);

        return $this->fetch();
    }

    /**
     * 获取聊天历史
     * 
     * @return string
     */
    public function history(): string
    {
        if (!$this->isLoggedIn()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('请先登录')
            ]);
        }

        // TODO: 实现聊天历史记录功能
        $history = [];

        return $this->fetchJson([
            'success' => true,
            'data' => $history
        ]);
    }

    /**
     * 清空聊天历史
     * 
     * @return string
     */
    public function clearHistory(): string
    {
        if (!$this->isLoggedIn()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('请先登录')
            ]);
        }

        // TODO: 实现清空聊天历史功能

        return $this->fetchJson([
            'success' => true,
            'message' => __('聊天历史已清空')
        ]);
    }

}
