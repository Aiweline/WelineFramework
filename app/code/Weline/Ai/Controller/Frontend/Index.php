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
 * AI工具介绍页面控制器
 * 
 * 功能：
 * - 展示AI助手工具功能介绍
 * - 显示可用的AI模型列表
 * - 显示场景适配器说明
 * - 提供API使用指南
 */
class Index extends FrontendController
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
     * 工具介绍首页
     * 
     * @return string
     */
    public function index(): string
    {
        // 获取激活的AI模型列表
        $models = $this->aiModel->reset()
            ->where(AiModel::fields_IS_ACTIVE, 1)
            ->order(AiModel::fields_CREATED_AT, 'DESC')
            ->limit(10)
            ->select()
            ->fetch();

        // 获取可用的场景适配器
        $adapters = $this->adapterScanner->getAllActiveAdapters();

        $this->assign('page_title', __('AI助手工具'));
        $this->assign('models', $models->getItems());
        $this->assign('adapters', $adapters);
        $this->assign('models_count', $models->count());
        $this->assign('adapters_count', count($adapters));

        return $this->fetch();
    }

    /**
     * 功能特性页面
     * 
     * @return string
     */
    public function features(): string
    {
        $features = [
            [
                'icon' => 'mdi-robot',
                'title' => __('多种AI模型'),
                'description' => __('支持OpenAI GPT、Claude等多种主流AI模型，满足不同场景需求'),
            ],
            [
                'icon' => 'mdi-palette',
                'title' => __('场景适配器'),
                'description' => __('提供翻译、代码生成、内容创作等专业场景适配器，优化生成效果'),
            ],
            [
                'icon' => 'mdi-api',
                'title' => __('API接口'),
                'description' => __('提供RESTful API和流式接口，支持实时响应和批量处理'),
            ],
            [
                'icon' => 'mdi-shield-check',
                'title' => __('安全可靠'),
                'description' => __('完善的API密钥管理、访问控制和内容安全检测机制'),
            ],
            [
                'icon' => 'mdi-lightning-bolt',
                'title' => __('高性能'),
                'description' => __('智能缓存、并发控制和负载均衡，确保快速响应'),
            ],
            [
                'icon' => 'mdi-earth',
                'title' => __('国际化'),
                'description' => __('支持多语言界面和内容，自动语言检测和转换'),
            ],
        ];

        $this->assign('page_title', __('功能特性'));
        $this->assign('features', $features);

        return $this->fetch();
    }

    /**
     * API使用指南页面
     * 
     * @return string
     */
    public function guide(): string
    {
        $this->assign('page_title', __('使用指南'));
        
        // 示例代码
        $examples = [
            'basic' => [
                'title' => __('基础文本生成'),
                'code' => '// PHP静态方法调用
$result = \Weline\Ai\Service\AiService::generateText(
    $prompt = "请介绍一下人工智能",
    $modelCode = null, // 使用默认模型
    $scenarioCode = null,
    $locale = null
);',
            ],
            'translation' => [
                'title' => __('翻译场景'),
                'code' => '// 使用翻译适配器
$result = \Weline\Ai\Service\AiService::generateText(
    $prompt = "Hello, how are you?",
    $modelCode = "gpt-3.5-turbo",
    $scenarioCode = "translation",
    $locale = "zh-CN",
    $params = [
        "target_language" => "中文",
        "source_language" => "英文",
        "strategy" => "standard"
    ]
);',
            ],
            'stream' => [
                'title' => __('流式响应'),
                'code' => '// 流式生成
\Weline\Ai\Service\AiService::generateTextStream(
    $prompt = "写一篇关于AI的文章",
    $callback = function($chunk) {
        echo $chunk;
        flush();
    },
    $modelCode = "gpt-4"
);',
            ],
        ];

        $this->assign('examples', $examples);

        return $this->fetch();
    }

    /**
     * 定价页面
     * 
     * @return string
     */
    public function pricing(): string
    {
        // 获取模型及其价格信息
        $models = $this->aiModel->reset()
            ->where(AiModel::fields_IS_ACTIVE, 1)
            ->select()
            ->fetch();

        $pricingData = [];
        foreach ($models->getItems() as $model) {
            $pricingData[] = [
                'name' => $model->getData(AiModel::fields_NAME),
                'vendor' => $model->getData(AiModel::fields_SUPPLIER),
                'input_price' => $model->getData(AiModel::fields_TOKEN_PRICE_INPUT),
                'output_price' => $model->getData(AiModel::fields_TOKEN_PRICE_OUTPUT),
            ];
        }

        $this->assign('page_title', __('定价信息'));
        $this->assign('pricing_data', $pricingData);

        return $this->fetch();
    }
}

