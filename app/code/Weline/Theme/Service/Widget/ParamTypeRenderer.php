<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Service\Widget;

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Ui\Widget\ParamType\ArrayType;
use Weline\Theme\Ui\Widget\ParamType\BoolType;
use Weline\Theme\Ui\Widget\ParamType\ColorType;
use Weline\Theme\Ui\Widget\ParamType\DatetimeType;
use Weline\Theme\Ui\Widget\ParamType\IconType;
use Weline\Theme\Ui\Widget\ParamType\ImageType;
use Weline\Theme\Ui\Widget\ParamType\NumberType;
use Weline\Theme\Ui\Widget\ParamType\RangeType;
use Weline\Theme\Ui\Widget\ParamType\SelectType;
use Weline\Theme\Ui\Widget\ParamType\StringType;
use Weline\Theme\Ui\Widget\ParamType\TextareaType;
use Weline\Theme\Ui\Widget\ParamType\UrlType;
use Weline\Theme\Ui\Widget\ParamType\WidgetParamTypeInterface;

/**
 * Widget 参数类型渲染服务
 * 
 * 统一管理所有参数类型的渲染，提供表单生成功能
 */
class ParamTypeRenderer
{
    /**
     * 已注册的类型渲染器映射
     */
    private array $typeRenderers = [];

    /**
     * 默认类型渲染器类映射
     */
    private const DEFAULT_TYPE_CLASSES = [
        'string'   => StringType::class,
        'text'     => StringType::class,
        'number'   => NumberType::class,
        'int'      => NumberType::class,
        'integer'  => NumberType::class,
        'float'    => NumberType::class,
        'bool'     => BoolType::class,
        'boolean'  => BoolType::class,
        'select'   => SelectType::class,
        'dropdown' => SelectType::class,
        'color'    => ColorType::class,
        'url'      => UrlType::class,
        'link'     => UrlType::class,
        'image'    => ImageType::class,
        'file'     => ImageType::class,
        'array'    => ArrayType::class,
        'list'     => ArrayType::class,
        'textarea' => TextareaType::class,
        'html'     => TextareaType::class,
        'richtext' => TextareaType::class,
        'datetime' => DatetimeType::class,
        'date'     => DatetimeType::class,
        'time'     => DatetimeType::class,
        'range'    => RangeType::class,
        'slider'   => RangeType::class,
        'icon'     => IconType::class,
    ];

    /**
     * 获取类型渲染器
     *
     * @param string $type 类型代码
     * @return WidgetParamTypeInterface
     */
    public function getRenderer(string $type): WidgetParamTypeInterface
    {
        $type = strtolower($type);
        
        // 检查缓存
        if (isset($this->typeRenderers[$type])) {
            return $this->typeRenderers[$type];
        }
        
        // 获取渲染器类
        $rendererClass = self::DEFAULT_TYPE_CLASSES[$type] ?? StringType::class;
        
        // 创建实例并缓存
        $this->typeRenderers[$type] = ObjectManager::getInstance($rendererClass);
        
        return $this->typeRenderers[$type];
    }

    /**
     * 注册自定义类型渲染器
     *
     * @param string $type 类型代码
     * @param WidgetParamTypeInterface $renderer 渲染器实例
     * @return self
     */
    public function registerRenderer(string $type, WidgetParamTypeInterface $renderer): self
    {
        $this->typeRenderers[strtolower($type)] = $renderer;
        return $this;
    }

    /**
     * 渲染单个参数字段
     *
     * @param string $key 参数键名
     * @param array $param 参数定义
     * @param mixed $value 当前值
     * @param int|string $layoutId 布局ID
     * @param array $attrs 附加属性
     * @return string HTML
     */
    public function renderField(string $key, array $param, mixed $value, int|string $layoutId = '', array $attrs = []): string
    {
        $type = $param['type'] ?? 'string';
        $renderer = $this->getRenderer($type);
        
        return $renderer->getHtml($key, $param, $value, $layoutId, $attrs);
    }

    /**
     * 渲染完整的配置表单
     *
     * @param int|string $layoutId 布局ID
     * @param array $params 参数定义列表
     * @param array $config 当前配置值
     * @return string HTML
     */
    public function renderForm(int|string $layoutId, array $params, array $config = []): string
    {
        if (empty($params)) {
            return $this->renderEmptyState();
        }
        
        // 按分组组织字段
        $groups = $this->groupFields($params);
        
        // 生成分组 HTML
        $groupsHtml = '';
        
        foreach ($groups as $groupKey => $groupData) {
            $fieldsHtml = '';
            foreach ($groupData['fields'] as $key => $param) {
                $value = $config[$key] ?? null;
                $fieldsHtml .= $this->renderField($key, $param, $value, $layoutId);
            }
            
            $collapsed = $groupData['collapsed'] ?? false;
            $groupClass = 'config-group' . ($collapsed ? ' collapsed' : '');
            
            $groupsHtml .= '
                <div class="' . $groupClass . '">
                    <h5 class="config-group-title">
                        <i class="' . htmlspecialchars($groupData['icon']) . '"></i>
                        ' . htmlspecialchars($groupData['label']) . '
                        <i class="ri-arrow-down-s-line toggle-icon"></i>
                    </h5>
                    <div class="config-fields">' . $fieldsHtml . '</div>
                </div>
            ';
        }
        
        // 生成完整表单
        return '
            <form class="widget-accordion-config-form" data-layout-id="' . htmlspecialchars((string)$layoutId) . '">
                ' . $groupsHtml . '
                <div class="config-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="ri-save-line"></i> ' . __('保存配置') . '
                    </button>
                    <button type="button" class="btn btn-outline-danger btn-delete-widget" data-layout-id="' . htmlspecialchars((string)$layoutId) . '">
                        <i class="ri-delete-bin-line"></i> ' . __('删除') . '
                    </button>
                </div>
            </form>
        ';
    }

    /**
     * 将参数按分组组织
     */
    private function groupFields(array $params): array
    {
        $groups = [
            'basic' => [
                'label' => __('基本信息'),
                'icon' => 'ri-information-line',
                'collapsed' => false,
                'fields' => [],
            ],
            'style' => [
                'label' => __('样式设置'),
                'icon' => 'ri-palette-line',
                'collapsed' => false,
                'fields' => [],
            ],
            'link' => [
                'label' => __('链接配置'),
                'icon' => 'ri-links-line',
                'collapsed' => true,
                'fields' => [],
            ],
            'advanced' => [
                'label' => __('高级设置'),
                'icon' => 'ri-settings-4-line',
                'collapsed' => true,
                'fields' => [],
            ],
        ];
        
        // 社交媒体关键字
        $socialKeys = ['facebook', 'twitter', 'instagram', 'youtube', 'linkedin', 'pinterest', 'tiktok', 'weibo', 'wechat', 'github', 'telegram', 'whatsapp', 'discord', 'reddit', 'snapchat'];
        
        foreach ($params as $key => $param) {
            // 首先检查是否显式指定了分组
            if (isset($param['group']) && isset($groups[$param['group']])) {
                $groups[$param['group']]['fields'][$key] = $param;
                continue;
            }
            
            // 根据键名自动分组
            $keyLower = strtolower($key);
            
            if (in_array($keyLower, $socialKeys) || str_contains($keyLower, 'url') || str_contains($keyLower, 'link') || str_contains($keyLower, 'http')) {
                $groups['link']['fields'][$key] = $param;
            } elseif (str_contains($keyLower, 'style') || str_contains($keyLower, 'size') || str_contains($keyLower, 'color') || str_contains($keyLower, 'align') || str_contains($keyLower, 'gap') || str_contains($keyLower, 'margin') || str_contains($keyLower, 'padding')) {
                $groups['style']['fields'][$key] = $param;
            } else {
                $groups['basic']['fields'][$key] = $param;
            }
        }
        
        // 移除空分组
        return array_filter($groups, fn($group) => !empty($group['fields']));
    }

    /**
     * 渲染空状态
     */
    private function renderEmptyState(): string
    {
        return '<div class="config-empty-state">
            <i class="ri-settings-3-line"></i>
            <p>' . __('该部件无可配置项') . '</p>
        </div>';
    }

    /**
     * 验证配置值
     *
     * @param array $params 参数定义列表
     * @param array $values 提交的值
     * @return array ['valid' => bool, 'errors' => [...]]
     */
    public function validateConfig(array $params, array $values): array
    {
        $errors = [];
        
        foreach ($params as $key => $param) {
            $value = $values[$key] ?? null;
            $type = $param['type'] ?? 'string';
            $renderer = $this->getRenderer($type);
            
            if (!$renderer->validate($value, $param)) {
                $errors[$key] = sprintf(__('字段 "%s" 的值无效'), $param['label'] ?? $key);
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * 处理配置值
     *
     * @param array $params 参数定义列表
     * @param array $values 提交的值
     * @return array 处理后的值
     */
    public function processConfig(array $params, array $values): array
    {
        $processed = [];
        
        foreach ($params as $key => $param) {
            $value = $values[$key] ?? null;
            $type = $param['type'] ?? 'string';
            $renderer = $this->getRenderer($type);
            
            // 如果值为空，使用默认值
            if ($value === null || $value === '') {
                $processed[$key] = $renderer->getDefaultValue($param);
            } else {
                $processed[$key] = $renderer->processValue($value, $param);
            }
        }
        
        return $processed;
    }

    /**
     * 获取所有已注册的类型
     *
     * @return array
     */
    public function getRegisteredTypes(): array
    {
        return array_keys(self::DEFAULT_TYPE_CLASSES);
    }
}
