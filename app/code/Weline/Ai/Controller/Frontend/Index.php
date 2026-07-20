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
            ->where(AiModel::schema_fields_IS_ACTIVE, 1)
            ->order(AiModel::schema_fields_CREATED_AT, 'DESC')
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
                'title' => __('可恢复任务接口'),
                'description' => __('通过后台任务和可重连事件订阅提供实时响应，短暂断网不会中断生成'),
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
                'title' => __('内部服务调用（CLI / Runner）'),
                'code' => '// 仅用于受控 CLI、Runner 或模块内部服务；不得在 HTTP 请求或 SSE 控制器中直接执行。
$result = \Weline\Ai\Service\AiService::generateText(
    $prompt = "请介绍一下人工智能",
    $modelCode = null, // 使用默认模型
    $scenarioCode = null,
    $locale = null
);',
            ],
            'translation' => [
                'title' => __('内部翻译服务（CLI / Runner）'),
                'code' => '// 仅用于受控 CLI、Runner 或模块内部服务。
// 浏览器请求必须启动可恢复任务，而不是在连接内调用 AiService。
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
                'title' => __('浏览器可恢复任务订阅'),
                'code' => '// 浏览器业务请求统一通过 Weline.Api：先启动后台任务，再订阅可重连 StreamHandle。
const api = await Weline.load("api");
const task = await api.resource("runtime_task").start({
    type_code: "ai.chat_generation",
    input: { message: "写一篇关于 AI 的文章", request_id: crypto.randomUUID() }
}, { silent: true });

const stream = api.createStream(task.stream_channel, {
    task_id: task.task_id,
    lease_id: task.lease_id
});
stream.addEventListener("chunk", (event) => renderChunk(JSON.parse(event.data).chunk));
stream.addEventListener("completed", () => stream.close());

// 页面离开时只 stream.close() 退订；只有用户明确点击取消才调用 stream.cancel(reason)。',
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
            ->where(AiModel::schema_fields_IS_ACTIVE, 1)
            ->select()
            ->fetch();

        $pricingData = [];
        foreach ($models->getItems() as $model) {
            $pricingData[] = [
                'name' => $model->getData(AiModel::schema_fields_NAME),
                'vendor' => $model->getData(AiModel::schema_fields_SUPPLIER),
                'input_price' => $model->getData(AiModel::schema_fields_TOKEN_PRICE_INPUT),
                'output_price' => $model->getData(AiModel::schema_fields_TOKEN_PRICE_OUTPUT),
            ];
        }

        $this->assign('page_title', __('定价信息'));
        $this->assign('pricing_data', $pricingData);

        return $this->fetch();
    }
}
