<?php

declare(strict_types=1);

namespace Weline\Widget\Service;

/**
 * 部件配置服务：表单/字段渲染、校验、处理、参数定义
 */
class WidgetConfigService
{
    public function __construct(
        private readonly ParamTypeRenderer $paramTypeRenderer,
        private readonly WidgetRegistry $widgetRegistry,
        private readonly ParamSchemaRegistry $paramSchemaRegistry
    ) {
    }

    /**
     * 获取部件参数定义（来自 WidgetRegistry widget['params']）
     */
    public function getParamDefinitions(string $widgetModule, string $widgetCode, string $area = 'frontend'): array
    {
        $registry = $this->widgetRegistry->getRegistry();
        foreach ($registry as $type => $widgets) {
            if (!is_array($widgets)) {
                continue;
            }
            foreach ($widgets as $code => $widget) {
                if (!is_array($widget)) {
                    continue;
                }
                if (($widget['module'] ?? '') === $widgetModule && ($widget['code'] ?? '') === $widgetCode) {
                    $widgetArea = (string)($widget['area'] ?? 'frontend');
                    if ($widgetArea !== '' && $widgetArea !== $area) {
                        continue;
                    }
                    $params = $widget['params'] ?? [];
                    foreach ($params as $key => $param) {
                        if (!is_array($param)) {
                            continue;
                        }
                        if (!isset($param['label']) && isset($param['name'])) {
                            $params[$key]['label'] = $param['name'];
                        }
                        if (isset($param['option']) && !isset($param['options'])) {
                            $params[$key]['options'] = $param['option'];
                        }
                    }
                    return $this->paramSchemaRegistry->expandParams($params);
                }
            }
        }
        return [];
    }

    public function renderForm(int|string $layoutId, array $params, array $config = []): string
    {
        return $this->paramTypeRenderer->renderForm($layoutId, $params, $config);
    }

    public function renderField(string $key, array $param, mixed $value, int|string $layoutId = '', array $attrs = []): string
    {
        return $this->paramTypeRenderer->renderField($key, $param, $value, $layoutId, $attrs);
    }

    /**
     * @return array{valid: bool, errors: array<string, string>}
     */
    public function validateConfig(array $params, array $values): array
    {
        return $this->paramTypeRenderer->validateConfig($params, $values);
    }

    public function processConfig(array $params, array $values): array
    {
        return $this->paramTypeRenderer->processConfig($params, $values);
    }

    public function getRegisteredTypes(): array
    {
        $builtinTypes = $this->paramTypeRenderer->getRegisteredTypes();
        $schemaTypes = array_keys($this->paramSchemaRegistry->getRegistry());
        return array_unique(array_merge($builtinTypes, $schemaTypes));
    }
}
