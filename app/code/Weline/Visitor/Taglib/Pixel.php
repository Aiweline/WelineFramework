<?php

namespace Weline\Visitor\Taglib;

use Weline\Framework\App\Debug;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\View\Taglib;
use Weline\Framework\View\Template;
use Weline\Taglib\TaglibInterface;

class Pixel implements TaglibInterface
{
    private static function escapeScriptBreakout(mixed $pixelCode): string
    {
        return '';
    }

    public static function trackingConfigJson(): string
    {
        try {
            $config = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Visitor\Service\VisitorTrackingConfig::class)
                ->getRuntimeConfig();
        } catch (\Throwable $throwable) {
            if (defined('DEV') && DEV) {
                w_log_error('读取 Visitor 统计配置失败: ' . $throwable->getMessage());
            }
            $config = [
                'module' => 'Weline_Visitor',
                'pixel' => ['enabled' => true],
                'ga4' => [
                    'enabled' => false,
                    'configured' => false,
                    'measurementId' => '',
                    'enableInDev' => false,
                    'autoTrackVisitorEvents' => true,
                    'ctaEventName' => 'cta_click',
                    'debugMode' => false,
                    'source' => 'Weline_Visitor default',
                ],
            ];
        }

        $json = json_encode(
            $config,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
        );

        return $json === false ? '{}' : $json;
    }

    /**
     * @inheritDoc
     */
    static public function name(): string
    {
        return 'pixel';
    }

    /**
     * @inheritDoc
     */
    static function tag(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    static function attr(): array
    {
        return ['name' => 1, 'enabled' => 0]; // name 必填，enabled 可选
    }

    /**
     * @inheritDoc
     */
    static function tag_start(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    static function tag_end(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            // 检查 name 属性是否存在
            if (!isset($attributes['name']) || empty($attributes['name'])) {
                return ''; // 如果 name 属性不存在或为空，直接返回空字符串
            }
            // Taglib 已提前解析 PHP 代码，如果原始值是 PHP 代码，attributes 中会包含解析后的表达式
            // 同时保留原始 PHP 代码在 __php_ 前缀的键中
            $nameAttr = $attributes['name'];
            Debug::target('pixel_name', $nameAttr);
            $enabledAttr = $attributes['enabled'] ?? 'yes';
            
            // 检查原始值是否是 PHP 代码（Taglib 会在 __php_ 前缀的键中保留原始值）
            $nameIsPhpCode = isset($attributes['__php_name']);
            $enabledIsPhpCode = isset($attributes['__php_enabled']);
            
            // 如果原始值是 PHP 代码，使用已解析的表达式；否则使用原值
            $nameExpr = $nameIsPhpCode ? $nameAttr : null;
            $enabledExpr = $enabledIsPhpCode ? $enabledAttr : null;
            
            // 如果 enabled 是 PHP 代码，需要生成条件判断代码
            if ($enabledIsPhpCode) {
                // 如果表达式是简单的变量名（如 $pixel_enabled），尝试从模板对象获取
                // 这样可以避免未定义变量错误
                // 匹配格式：$variable 或 $variable??'default' 或 $variable??""
                if (preg_match('/^\$([a-zA-Z_][a-zA-Z0-9_]*)(\s*\?\?\s*([\'"]?)([^\'"]*)\3)?$/', $enabledExpr, $varMatches)) {
                    $varName = $varMatches[1];
                    $defaultValue = isset($varMatches[4]) && $varMatches[4] !== '' ? $varMatches[4] : 'yes';
                    // 从模板对象获取变量，如果不存在则使用默认值
                    $enabledExpr = '($this->getData(\'' . $varName . '\') ?? \'' . addslashes($defaultValue) . '\')';
                }
                
                // 获取 name 值表达式
                if ($nameIsPhpCode) {
                    // 如果 name 表达式也是简单变量，也从模板对象获取
                    if (preg_match('/^\$([a-zA-Z_][a-zA-Z0-9_]*)(\s*\?\?\s*([\'"]?)([^\'"]*)\3)?$/', $nameExpr, $nameVarMatches)) {
                        $nameVarName = $nameVarMatches[1];
                        $nameDefaultValue = isset($nameVarMatches[4]) && $nameVarMatches[4] !== '' ? $nameVarMatches[4] : '';
                        $nameExpr = '($this->getData(\'' . $nameVarName . '\') ?? \'' . addslashes($nameDefaultValue) . '\')';
                    }
                } else {
                    $nameExpr = var_export($nameAttr, true);
                }
                
                // 使用字符串拼接生成 PHP 代码，确保表达式正确插入
                // 使用单引号字符串拼接，避免表达式中的双引号导致解析错误
                $output = '<?php' . "\n";
                $output .= '$__pixel_enabled_value = (' . $enabledExpr . ' ?? \'yes\');' . "\n";
                $output .= '$__pixel_enabled = !in_array(strtolower((string)$__pixel_enabled_value), [\'no\', \'0\', \'false\', \'off\', \'disabled\'], true);' . "\n";
                $output .= '$__pixel_name = (' . $nameExpr . ' ?? \'\');' . "\n";
                $output .= 'if (empty($__pixel_name)) { return \'\'; }' . "\n";
                $output .= '$__tp = \\Weline\\Framework\\Manager\\ObjectManager::getInstance(\\Weline\\Framework\\View\\Template::class);' . "\n";
                $output .= '$__data = new \\Weline\\Framework\\DataObject\\DataObject([\'pixel_code\' => \'\', \'name\' => $__pixel_name, \'enable\' => $__pixel_enabled ? 1 : 0]);' . "\n";
                $output .= '$__event = \\Weline\\Framework\\Manager\\ObjectManager::getInstance(\\Weline\\Framework\\Event\\EventsManager::class);' . "\n";
                $output .= '$__event->dispatch(\'Weline_Visitor::taglib_pixel\', $__data);' . "\n";
                $output .= 'if (!$__pixel_enabled || empty($__data->getData(\'enable\'))) { return \'\'; }' . "\n";
                $output .= '$__tp->assign(\'pixel_code\', \\Weline\\Visitor\\Taglib\\Pixel::escapeScriptBreakout($__data->getData(\'pixel_code\')));' . "\n";
                $output .= '$__tp->assign(\'visitor_tracking_config_json\', \\Weline\\Visitor\\Taglib\\Pixel::trackingConfigJson());' . "\n";
                $output .= '$__js = $__tp->fetch(\'Weline_Visitor::taglib/js/pixel.phtml\');' . "\n";
                $output .= 'echo str_replace(\'{:name}\', htmlspecialchars($__pixel_name, ENT_QUOTES, \'UTF-8\'), $__js);' . "\n";
                $output .= '?>';
                
                return $output;
            }
            
            // enabled 不是 PHP 代码，使用原来的逻辑
            $name = $nameIsPhpCode ? '' : $nameAttr; // 如果是 PHP 代码，稍后处理
            $enabled = true;
            if (in_array(strtolower($enabledAttr), ['no', '0', 'false', 'off', 'disabled'], true)) {
                $enabled = false;
            }
            
            // 如果 name 是 PHP 代码，需要生成动态代码
            if ($nameIsPhpCode) {
                // 如果 name 表达式是简单变量，也从模板对象获取
                if (preg_match('/^\$([a-zA-Z_][a-zA-Z0-9_]*)(\s*\?\?\s*([\'"]?)([^\'"]*)\3)?$/', $nameExpr, $nameVarMatches)) {
                    $nameVarName = $nameVarMatches[1];
                    $nameDefaultValue = isset($nameVarMatches[4]) && $nameVarMatches[4] !== '' ? $nameVarMatches[4] : '';
                    $nameExpr = '($this->getData(\'' . $nameVarName . '\') ?? \'' . addslashes($nameDefaultValue) . '\')';
                }
                // 使用字符串拼接生成 PHP 代码
                $enabledValue = $enabled ? '1' : '0';
                $enabledCheck = $enabled ? 'true' : 'false';
                
                $output = '<?php' . "\n";
                $output .= '$__pixel_name = (' . $nameExpr . ' ?? \'\');' . "\n";
                $output .= 'if (empty($__pixel_name)) { return \'\'; }' . "\n";
                $output .= '$__tp = \\Weline\\Framework\\Manager\\ObjectManager::getInstance(\\Weline\\Framework\\View\\Template::class);' . "\n";
                $output .= '$__data = new \\Weline\\Framework\\DataObject\\DataObject([\'pixel_code\' => \'\', \'name\' => $__pixel_name, \'enable\' => ' . $enabledValue . ']);' . "\n";
                $output .= '$__event = \\Weline\\Framework\\Manager\\ObjectManager::getInstance(\\Weline\\Framework\\Event\\EventsManager::class);' . "\n";
                $output .= '$__event->dispatch(\'Weline_Visitor::taglib_pixel\', $__data);' . "\n";
                $output .= 'if (!' . $enabledCheck . ' || empty($__data->getData(\'enable\'))) { return \'\'; }' . "\n";
                $output .= '$__tp->assign(\'pixel_code\', \\Weline\\Visitor\\Taglib\\Pixel::escapeScriptBreakout($__data->getData(\'pixel_code\')));' . "\n";
                $output .= '$__tp->assign(\'visitor_tracking_config_json\', \\Weline\\Visitor\\Taglib\\Pixel::trackingConfigJson());' . "\n";
                $output .= '$__js = $__tp->fetch(\'Weline_Visitor::taglib/js/pixel.phtml\');' . "\n";
                $output .= 'echo str_replace(\'{:name}\', htmlspecialchars($__pixel_name, ENT_QUOTES, \'UTF-8\'), $__js);' . "\n";
                $output .= '?>';
                
                return $output;
            }
            
            // 都是普通字符串，使用原来的逻辑
            /**@var Template $tp */
            $tp = w_obj(Template::class);
            $data = new DataObject(['pixel_code' => '', 'name' => $name, 'enable' => $enabled ? 1 : 0]);
            /**@var EventsManager $event */
            $event = w_obj(EventsManager::class);
            $event->dispatch('Weline_Visitor::taglib_pixel', $data);
            
            // 如果标签属性禁用或事件返回禁用，则不输出
            if (!$enabled || empty($data->getData('enable'))) {
                return '';
            }
            
            $tp->assign('pixel_code', self::escapeScriptBreakout($data->getData('pixel_code')));
            $tp->assign('visitor_tracking_config_json', self::trackingConfigJson());
            $js = $tp->fetch('Weline_Visitor::taglib/js/pixel.phtml');
            // 确保 $name 不为 null，避免 str_replace 的弃用警告
            $name = $name ?? '';
            return str_replace('{:name}', htmlspecialchars($name, ENT_QUOTES, 'UTF-8'), $js);
        };
    }

    /**
     * @inheritDoc
     */
    static function tag_self_close(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    static function tag_self_close_with_attrs(): bool
    {
        return true;
    }

    /**
     * 指定父标签，用于依赖管理
     * @return string|null 父标签名称
     */
    static function parent(): ?string
    {
        return null; // Pixel标签没有依赖
    }

    static function document(): string
    {
        return "统计网站流量。使用方法:在想要统计的页面引入<pixel name=\"default\"/>。 name为自定义统计名称，用于区分统计来源，默认使用sys。<br>
    可在后台自定义像素以及查看访问概览时，作为区分。
    自定义事件：在你想要统计的标签上设置类名开头为weline-pixel::name类，冒号后面的名字将作为事件名。
    只有设置了weline-pixel::name类的标签才会被统计，例如：weline-pixel::place-order,weline-pixel::add-to-cart等。
    place-order,add-to-cart将自动解析为事件名字。
    自定义事件请使用标准 weline-pixel::event_name 标记或监听 Weline_Visitor::taglib_pixel 控制像素启停。
    出于安全原因，pixel_code 不再作为前台 JavaScript 执行通道。
    <br><br>
    控制属性：
    - name（必填）：像素名称，用于区分统计来源
    - enabled（可选）：是否启用统计，支持 yes/no, 1/0, true/false。默认值为 yes（启用）
    示例：<pixel name=\"page_landing\" enabled=\"yes\" /> 或 <pixel name=\"page_landing\" enabled=\"no\" />
    ";
    }
}
