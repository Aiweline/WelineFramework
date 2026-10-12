<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Meta\Taglib;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Taglib\TaglibInterface;
use Weline\Framework\View\Taglib;
use Weline\Meta\Helper\MetaTranslation;

class WMeta implements TaglibInterface
{
    private const REQUEST_IDS_KEY = 'meta.taglib.translation_ids.v1';

    public static function resetRequestState(): void
    {
        RequestContext::remove(self::REQUEST_IDS_KEY);
    }

    static public function name(): string
    {
        return 'w:meta';
    }

    static function tag(): bool
    {
        return true; // 支持成对标签
    }

    static function attr(): array
    {
        return ['type' => false, 'prefix' => false, 'scope' => false]; // type、prefix 和 scope 属性可选
    }

    static function tag_start(): bool
    {
        return false;
    }

    static function tag_end(): bool
    {
        return false;
    }

    static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            // 只处理成对标签
            if ($tag_key !== 'tag') {
                return '';
            }

            // 获取标签内容（meta字段路径，如：@meta.info.name）
            $content = trim($tag_data[2] ?? '');
            if (empty($content)) {
                return '';
            }

            // 获取 type 属性，默认为空（直接显示翻译值）
            $type = $attributes['type'] ?? '';
            // 获取 prefix 属性，用于自动补全路径
            $prefix = $attributes['prefix'] ?? '';
            // 获取 scope 属性，用于区分不同范围的配置，默认为 "default"
            $scopeAttr = $attributes['scope'] ?? 'default';
            
            // 解析 scope（支持 PHP 变量）
            $isPhpScope = false;
            if (!empty($scopeAttr) && (strpos($scopeAttr, '$') !== false || strpos($scopeAttr, '{{') !== false)) {
                /** @var Taglib $Taglib */
                $Taglib = ObjectManager::getInstance(Taglib::class);
                // 如果包含 PHP 代码或模板变量，需要解析
                $scopeParser = $Taglib->varParser($scopeAttr);
                $scopePhpCode = '<?=(' . $scopeParser . '?:\'default\')?>';
                $isPhpScope = true;
                $scope = 'default'; // 默认值，实际值在运行时解析
            } else {
                $scope = $scopeAttr ?: 'default';
                $scopePhpCode = $scope;
            }

            // 解析 meta 字段路径 → MetaLocal（MetaTranslation）
            // 格式支持：
            // 1. @meta::theme.component.pagination.info.name（完整路径）
            // 2. @meta.info.name（需要补全前缀）
            // 3. info.name（需要补全前缀和 @meta::，需要提供 prefix 属性）
            // 4. theme.frontend.layouts.default.name（统一规范化为 @meta:: 键）
            $metaKey = trim($content);

            if (str_starts_with($metaKey, '@meta::')) {
                // 已经是完整路径，不需要处理
            } 
            elseif (str_starts_with($metaKey, '@meta.')) {
                $metaKey = str_replace('@meta.', '', $metaKey);
                if (!empty($prefix)) {
                    $metaKey = '@meta::' . $prefix . '.' . $metaKey;
                } else {
                    $metaKey = '@meta::' . $metaKey;
                }
            }
            else {
                if (!empty($prefix)) {
                    $metaKey = '@meta::' . $prefix . '.' . $metaKey;
                } else {
                    $metaKey = '@meta::' . $metaKey;
                }
            }

            // 获取当前语言
            $locale = \Weline\Framework\Http\Cookie::getLangLocal() ?? 'zh_Hans_CN';

            // 构建带 scope 的 meta key（用于存储和读取）
            if ($isPhpScope) {
                $metaKeyWithScopePhp = '<?=$metaKeyWithScope = $metaKey . (($scope = ' . $scopePhpCode . ') !== \'default\' ? \'|scope:\' . $scope : \');?>';
            } else {
                $metaKeyWithScope = $metaKey;
                if ($scope !== 'default') {
                    $metaKeyWithScope = $metaKey . '|scope:' . $scope;
                }
            }

            // 从 MetaLocal 获取翻译（优先使用带 scope 的 key）
            if ($isPhpScope) {
                $escapedContent = addcslashes($content, "\\'");
                $translationPhpCode = <<<PHP
<?php
\$locale = \\Weline\\Framework\\Http\\Cookie::getLangLocal() ?? 'zh_Hans_CN';
\$scope = {$scopePhpCode};
\$metaKeyWithScope = \$metaKey . (\$scope !== 'default' ? '|scope:' . \$scope : '');
\$translation = \\Weline\\Meta\\Helper\\MetaTranslation::getTranslatedValueWithScope(
    \$metaKeyWithScope,
    \$scope,
    \$locale,
    '',
);
if (\$translation === '' && \$scope !== 'default') {
    \$translation = \\Weline\\Meta\\Helper\\MetaTranslation::getTranslatedValueWithScope(
        \$metaKey,
        'default',
        \$locale,
        '',
    );
}
\$defaultValue = \$translation !== '' ? \$translation : '{$escapedContent}';
?>
PHP;
                $defaultValue = '<?=$defaultValue?>';
            } else {
                $translation = MetaTranslation::getTranslatedValueWithScope(
                    $metaKeyWithScope,
                    $scope,
                    $locale,
                    '',
                );
                
                if ($translation === '' && $scope !== 'default') {
                    $translation = MetaTranslation::getTranslatedValueWithScope(
                        $metaKey,
                        'default',
                        $locale,
                        '',
                    );
                }

                $defaultValue = $translation !== '' ? $translation : $content;
            }

            // 如果 type 不是 translate，直接返回翻译值或默认值
            if ($type !== 'translate') {
                if ($isPhpScope) {
                    // 如果是 PHP scope，需要先执行 PHP 代码
                    return $translationPhpCode . htmlspecialchars($defaultValue);
                }
                return htmlspecialchars($defaultValue);
            }

            // type="translate" 时，显示翻译按钮和模态框
            /** @var \Weline\Framework\Http\Request $request */
            $request = ObjectManager::getInstance(\Weline\Framework\Http\Request::class);
            
            // 生成唯一ID
            $idName = self::nextTranslationId($metaKey);

            // 构建翻译URL（包含 scope）
            if ($isPhpScope) {
                // 如果 scope 是 PHP 变量，需要在运行时构建 URL
                // 使用 PHP 代码在服务器端运行时构建
                $actionBase = $request->isBackend() 
                    ? $request->getUrlBuilder()->getBackendUrl('meta/backend/taglib/meta', [])
                    : $request->getUrlBuilder()->getUrl('meta/frontend/taglib/meta', []);
                // 生成运行时构建 URL 的 PHP 代码
                $action = "<?php\n" .
                    "\$scope = {$scopePhpCode};\n" .
                    "\$actionParams = ['key' => '{$metaKey}', 'value' => \$defaultValue];\n" .
                    "if (\$scope !== 'default') {\n" .
                    "    \$actionParams['scope'] = \$scope;\n" .
                    "}\n" .
                    "\$action = \$request->getUrlBuilder()->getBackendUrl('meta/backend/taglib/meta', \$actionParams);\n" .
                    "?>";
            } else {
                $actionParams = [
                    'key' => $metaKey,
                    'value' => $defaultValue
                ];
                if ($scope !== 'default') {
                    $actionParams['scope'] = $scope;
                }
                if ($request->isBackend()) {
                    $action = $request->getUrlBuilder()->getBackendUrl('meta/backend/taglib/meta', $actionParams);
                } else {
                    $action = $request->getUrlBuilder()->getUrl('meta/frontend/taglib/meta', $actionParams);
                }
            }

            $closeText = __('关闭');
            $titleText = __('翻译窗口');
            $refreshText = __('刷新');
            $submitText = __('提交');
            $displayValue = htmlspecialchars($defaultValue);

            return <<<TAG
                <button type="button"
                    class="w-button w-meta-translate-trigger"
                    data-tone="quiet"
                    data-w-action="drawer.open"
                    data-w-target="#{$idName}"
                    aria-controls="{$idName}">
                    <span>{$displayValue}</span>
                    <span aria-hidden="true">✎</span>
                </button>
                <aside class="w-drawer w-remote-drawer"
                       id="{$idName}"
                       data-w-component="drawer remote-drawer"
                       data-w-state="closed"
                       data-state="closed"
                       data-size="lg"
                       data-w-remote-save="true"
                       data-w-remote-form="#metaTranslationForm"
                       aria-labelledby="{$idName}Label"
                       aria-hidden="true"
                       tabindex="-1"
                       hidden>
                    <header class="w-drawer__header">
                        <h2 class="w-card__title" id="{$idName}Label">{$titleText}</h2>
                        <div class="w-cluster" data-justify="end">
                            <button type="button" class="w-button" data-size="sm" data-w-remote-action="submit">{$submitText}</button>
                            <button type="button" class="w-button" data-size="sm" data-tone="neutral" data-w-remote-action="reload">{$refreshText}</button>
                            <button type="button" class="w-button" data-size="sm" data-tone="quiet"
                                    data-w-action="drawer.close" data-w-target="#{$idName}" aria-label="{$closeText}">×</button>
                        </div>
                    </header>
                    <div class="w-drawer__body">
                        <iframe class="w-remote-drawer__frame"
                                data-w-remote-frame
                                data-src="{$action}"
                                title="{$titleText}"></iframe>
                    </div>
                </aside>
TAG;
        };
    }

    private static function nextTranslationId(string $metaKey): string
    {
        $ids = RequestContext::get(self::REQUEST_IDS_KEY, []);
        if (!is_array($ids)) {
            $ids = [];
        }

        $id = 'meta-translate-' . md5($metaKey);
        if (in_array($id, $ids, true)) {
            $id .= '-' . count($ids);
        }
        $ids[] = $id;
        RequestContext::set(self::REQUEST_IDS_KEY, $ids);

        return $id;
    }

    static function tag_self_close(): bool
    {
        return false;
    }

    static function tag_self_close_with_attrs(): bool
    {
        return false;
    }

    static function parent(): ?string
    {
        return null;
    }

    static function document(): string
    {
        return 'Meta翻译标签，用于显示和翻译meta信息。' . 
               '格式：' .
               '1. 完整路径：<w:meta type="translate">@meta::theme.component.pagination.info.name</w:meta>' .
               '2. 带前缀：<w:meta type="translate">@meta.info.name</w:meta>' .
               '3. 简写（推荐）：<w:meta type="translate" prefix="theme.component.pagination">info.name</w:meta>' .
               '4. 自动推断：<w:meta type="translate">info.name</w:meta>（如果模板中有component变量会自动推断）' .
               '当type="translate"时，显示翻译按钮和模态框；不写type时，直接显示当前语言的翻译值。' .
               '可以省略@meta前缀，标签会自动补全完整路径。';
    }
}
