<?php
declare(strict_types=1);

namespace Weline\Widget\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Theme\Service\ThemePlaceableRegistry;
use Weline\Widget\Service\AiWidgetGenerationService;
use Weline\Widget\Service\WidgetConfigService;
use Weline\Widget\Service\WidgetListService;
use Weline\Widget\Service\WidgetPreviewService;

class WidgetQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly WidgetListService $listService,
        private readonly WidgetConfigService $configService,
        private readonly WidgetPreviewService $previewService,
        private readonly ThemePlaceableRegistry $placeableRegistry,
        private readonly AiWidgetGenerationService $aiWidgetGenerationService,
    ) {
    }

    public function getProviderName(): string
    {
        return 'widget';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'getAvailableList' => $this->placeableRegistry->getAvailableList(
                $params['page_type'] ?? null,
                $params['filter_options'] ?? null,
                null,
                (string)(($params['filter_options']['area'] ?? null) ?: ($params['area'] ?? 'frontend'))
            ),
            'getParamDefinitions' => $this->getParamDefinitions($params),
            'getConfigForm' => $this->configService->renderForm(
                $params['layout_id'] ?? '',
                $params['params'] ?? [],
                $params['config'] ?? []
            ),
            'renderField' => $this->configService->renderField(
                (string)($params['key'] ?? ''),
                $params['param'] ?? [],
                $params['value'] ?? null,
                $params['layout_id'] ?? '',
                $params['attrs'] ?? []
            ),
            'validateConfig' => $this->configService->validateConfig(
                $params['params'] ?? [],
                $params['values'] ?? []
            ),
            'processConfig' => $this->configService->processConfig(
                $params['params'] ?? [],
                $params['values'] ?? []
            ),
            'preview' => $this->renderPreview($params),
            'getRegisteredTypes' => $this->configService->getRegisteredTypes(),
            'generateAiWidget' => $this->aiWidgetGenerationService->generate($params),
            default => throw new \InvalidArgumentException((string)__('Widget 查询器不支持的 operation：%{1}', $operation)),
        };
    }

    private function getParamDefinitions(array $params): array
    {
        $module = (string)($params['widget_module'] ?? '');
        $code = (string)($params['widget_code'] ?? '');
        $area = (string)($params['area'] ?? 'frontend');

        if ($module === 'Weline_Theme' && str_contains($code, '/')) {
            return $this->placeableRegistry->getParamDefinitions($module, 'theme_component', $code, null, $area);
        }

        return $this->configService->getParamDefinitions($module, $code, $area);
    }

    private function renderPreview(array $params): string
    {
        $module = (string)($params['widget_module'] ?? '');
        $code = (string)($params['widget_code'] ?? '');
        $config = $params['config'] ?? [];
        $area = (string)($params['area'] ?? 'frontend');

        if ($module === 'Weline_Theme' && str_contains($code, '/')) {
            return $this->placeableRegistry->renderPreview($module, 'theme_component', $code, is_array($config) ? $config : [], null, $area);
        }

        return $this->previewService->render($module, $code, is_array($config) ? $config : [], $area);
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'widget',
            'name' => __('Widget 部件查询'),
            'description' => __('提供部件列表、参数定义、配置表单、字段渲染、配置校验、预览等查询能力'),
            'module' => 'Weline_Widget',
            'operations' => [
                [
                    'name' => 'getAvailableList',
                    'description' => __('获取可用部件列表（分组、过滤、i18n）'),
                    'frontend' => true,
                    'mode' => 'read',
                    'params' => [
                        ['name' => 'page_type', 'type' => 'string|null', 'required' => false, 'description' => __('页面类型，null 表示不过滤')],
                        ['name' => 'filter_options', 'type' => 'array|null', 'required' => false, 'description' => __('过滤选项，如 slot_id、area、show_exclusive_only')],
                    ],
                ],
                [
                    'name' => 'getParamDefinitions',
                    'description' => __('获取部件的参数定义（schema）'),
                    'params' => [
                        ['name' => 'widget_module', 'type' => 'string', 'required' => true, 'description' => __('部件模块名')],
                        ['name' => 'widget_code', 'type' => 'string', 'required' => true, 'description' => __('部件代码')],
                        ['name' => 'area', 'type' => 'string', 'required' => false, 'description' => __('区域，默认 frontend')],
                    ],
                ],
                [
                    'name' => 'getConfigForm',
                    'description' => __('获取配置表单 HTML'),
                    'params' => [
                        ['name' => 'layout_id', 'type' => 'int|string', 'required' => true, 'description' => __('布局实例 ID')],
                        ['name' => 'params', 'type' => 'array', 'required' => true, 'description' => __('参数定义')],
                        ['name' => 'config', 'type' => 'array', 'required' => false, 'description' => __('当前配置值')],
                    ],
                ],
                [
                    'name' => 'renderField',
                    'description' => __('渲染单个配置字段 HTML'),
                    'params' => [
                        ['name' => 'key', 'type' => 'string', 'required' => true, 'description' => __('字段键名')],
                        ['name' => 'param', 'type' => 'array', 'required' => true, 'description' => __('字段定义')],
                        ['name' => 'value', 'type' => 'mixed', 'required' => false, 'description' => __('当前值')],
                        ['name' => 'layout_id', 'type' => 'int|string', 'required' => false, 'description' => __('布局 ID')],
                        ['name' => 'attrs', 'type' => 'array', 'required' => false, 'description' => __('额外属性')],
                    ],
                ],
                [
                    'name' => 'validateConfig',
                    'description' => __('校验配置值'),
                    'params' => [
                        ['name' => 'params', 'type' => 'array', 'required' => true, 'description' => __('参数定义')],
                        ['name' => 'values', 'type' => 'array', 'required' => true, 'description' => __('待校验的提交值')],
                    ],
                ],
                [
                    'name' => 'processConfig',
                    'description' => __('保存前处理配置'),
                    'params' => [
                        ['name' => 'params', 'type' => 'array', 'required' => true, 'description' => __('参数定义')],
                        ['name' => 'values', 'type' => 'array', 'required' => true, 'description' => __('原始提交值')],
                    ],
                ],
                [
                    'name' => 'preview',
                    'description' => __('获取部件预览 HTML'),
                    'params' => [
                        ['name' => 'widget_module', 'type' => 'string', 'required' => true, 'description' => __('部件模块名')],
                        ['name' => 'widget_code', 'type' => 'string', 'required' => true, 'description' => __('部件代码')],
                        ['name' => 'config', 'type' => 'array', 'required' => false, 'description' => __('部件配置')],
                        ['name' => 'area', 'type' => 'string', 'required' => false, 'description' => __('区域，默认 frontend')],
                    ],
                ],
                [
                    'name' => 'getRegisteredTypes',
                    'description' => __('获取已注册的 ParamType 类型名列表'),
                    'params' => [],
                ],
                [
                    'name' => 'generateAiWidget',
                    'frontend' => true,
                    'mode' => 'write',
                    'description' => __('根据目标 slot 协议生成 AI Widget 并保存为普通 Widget'),
                    'params' => [
                        ['name' => 'prompt', 'type' => 'string', 'required' => true, 'description' => __('生成要求')],
                        ['name' => 'generation_context', 'type' => 'array', 'required' => true, 'description' => __('生成上下文')],
                        ['name' => 'placement_target', 'type' => 'array', 'required' => true, 'description' => __('放置目标')],
                        ['name' => 'desired_type', 'type' => 'string', 'required' => false, 'description' => __('期望部件类型')],
                        ['name' => 'model_code', 'type' => 'string|null', 'required' => false, 'description' => __('模型代码')],
                    ],
                ],
            ],
        ];
    }
}
