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
use Weline\Framework\View\Taglib;
use Weline\I18n\Model\Locale\Dictionary as LocaleDictionary;
use Weline\I18n\Model\Meta\LocalModel as MetaLocalModel;
use Weline\Taglib\TaglibInterface;

class WMeta implements TaglibInterface
{
    private static $ids = [];

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
        $ids = &self::$ids;
        return function ($tag_key, $config, $tag_data, $attributes) use (&$ids) {
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

            // 解析 meta 字段路径
            // 格式支持：
            // 1. @meta::theme.component.pagination.info.name（完整路径，使用I18n Dictionary）
            // 2. @meta.info.name（需要补全前缀，使用I18n Dictionary）
            // 3. info.name（需要补全前缀和 @meta::，需要提供 prefix 属性，使用I18n Dictionary）
            // 4. theme.frontend.layouts.default（meta_identify，使用LocalModel，省略Model）
            // 5. theme.frontend.layouts.default.name（meta_identify + 字段路径，使用LocalModel，省略Model）
            $metaKey = trim($content);
            $useLocalModel = false;
            $metaIdentify = null;
            $fieldPath = 'name'; // 默认字段路径
            
            // 检查是否是 meta_identify 格式（不包含 @meta:: 或 @meta.，且包含至少2个点号分隔的部分）
            // 例如：theme.frontend.layouts.default 或 theme.frontend.layouts.default.name
            if (!str_starts_with($metaKey, '@meta::') && !str_starts_with($metaKey, '@meta.')) {
                // 检查是否可能是 meta_identify 格式
                $parts = explode('.', $metaKey);
                if (count($parts) >= 2) {
                    // 尝试判断：如果最后一部分是常见字段名（name, description等），则可能是 meta_identify.field
                    $commonFields = ['name', 'description', 'title', 'label', 'value'];
                    $lastPart = end($parts);
                    
                    if (in_array($lastPart, $commonFields) && count($parts) >= 3) {
                        // 可能是 meta_identify.field 格式
                        $metaIdentify = implode('.', array_slice($parts, 0, -1));
                        $fieldPath = $lastPart;
                        $useLocalModel = true;
                    } elseif (count($parts) >= 2) {
                        // 可能是纯 meta_identify 格式（默认使用 name 字段）
                        $metaIdentify = $metaKey;
                        $fieldPath = 'name';
                        $useLocalModel = true;
                    }
                }
            }
            
            // 如果使用 LocalModel，直接获取翻译值
            if ($useLocalModel && $metaIdentify) {
                // 获取当前语言
                $locale = \Weline\Framework\Http\Cookie::getLangLocal() ?? 'zh_Hans_CN';
                
                // 使用 LocalModel 获取翻译值
                $translatedValue = MetaLocalModel::getTranslatedValue($metaIdentify, $fieldPath, $locale);
                
                // 如果没有翻译值，尝试从 Meta 表获取默认值
                if ($translatedValue === null) {
                    /** @var \Weline\Meta\Model\Meta $metaModel */
                    $metaModel = ObjectManager::getInstance(\Weline\Meta\Model\Meta::class);
                    $metaModel->where(\Weline\Meta\Model\Meta::schema_fields_META_IDENTIFY, $metaIdentify)->find()->fetch();
                    
                    if ($metaModel->getId()) {
                        $metaData = json_decode($metaModel->getData(\Weline\Meta\Model\Meta::schema_fields_META_DATA) ?? '{}', true);
                        
                        // 尝试从 meta_data 中获取字段值
                        if ($fieldPath === 'name') {
                            if (isset($metaData['name'])) {
                                $translatedValue = $metaData['name'];
                            } elseif (isset($metaData['meta']['name'])) {
                                $translatedValue = $metaData['meta']['name'];
                            }
                        } else {
                            // 尝试从嵌套结构中获取
                            $keys = explode('.', $fieldPath);
                            $value = $metaData;
                            foreach ($keys as $key) {
                                if (isset($value[$key])) {
                                    $value = $value[$key];
                                } else {
                                    $value = null;
                                    break;
                                }
                            }
                            $translatedValue = is_string($value) ? $value : null;
                        }
                    }
                }
                
                $defaultValue = $translatedValue ?? $content;
                
                // 如果 type 不是 translate，直接返回翻译值
                if ($type !== 'translate') {
                    return htmlspecialchars($defaultValue);
                }
                
                // type="translate" 时，显示翻译按钮和模态框
                /** @var \Weline\Framework\Http\Request $request */
                $request = ObjectManager::getInstance(\Weline\Framework\Http\Request::class);
                
                // 生成唯一ID
                $idName = 'meta-local-' . md5($metaIdentify . '.' . $fieldPath);
                if (in_array($idName, $ids)) {
                    $idName .= '-' . count($ids);
                }
                $ids[] = $idName;
                
                // 构建翻译URL（使用 LocalModel）
                $actionParams = [
                    'model' => MetaLocalModel::class,
                    'field' => $fieldPath,
                    'id' => $metaIdentify
                ];
                if ($request->isBackend()) {
                    $actionParams['isIframe'] = '1';
                    $action = $request->getUrlBuilder()->getBackendUrl('i18n/backend/taglib/local', $actionParams);
                } else {
                    $action = $request->getUrlBuilder()->getUrl('i18n/frontend/taglib/local', $actionParams);
                }
                
                $closeText = __('关闭');
                $titleText = __('翻译窗口');
                $refreshText = __('刷新');
                $submitText = __('提交');
                $displayValue = htmlspecialchars($defaultValue);
                
                return <<<TAG
                    <a class='d-flex align-items-center link-info gap-1' style='cursor: pointer'
                        data-bs-toggle='offcanvas'
                        data-bs-target='#{$idName}' 
                        aria-controls='{$idName}'
                        data-href='{$action}&value={$displayValue}'>
                        <span>{$displayValue}</span>
                        <i class='ri-translate'></i>
                    </a>
                    <!-- {$idName} -->
                    <div class='offcanvas  offcanvas-end w-75 h-100' tabindex='-1' id='{$idName}' 
                         aria-labelledby='{$idName}Label'>
                        <div class='offcanvas-header'>
                            <h5 id='{$idName}Label'>
                                <lang>{$titleText}</lang>
                            </h5>
                            <div class="d-flex gap-2 ms-auto">
                                <button id="{$idName}SubmitBtn" type='submit' class='btn btn-primary btn-sm'>
                                    <i class="ri-save-line me-1"></i>{$submitText}
                                </button>
                                <a id="{$idName}IframeRefreshBtn" class='btn btn-info btn-sm' 
                                   aria-label='{$refreshText}'>
                                    <i class="ri-refresh-line me-1"></i>{$refreshText}
                                </a>
                                <button type='button' class='btn-close btn-sm' data-bs-dismiss='offcanvas'
                                        aria-label='{$closeText}'></button>
                            </div>
                        </div>
                        <div class='offcanvas-body'>
                            <div class='position-relative w-100 h-100 '>
                                <iframe id='{$idName}Iframe' class='w-100 h-100'
                                        data-src="{$action}&value={$displayValue}"
                                        frameborder='0'></iframe>
                            </div>
                        </div>
                    </div>
                    <script>
                        $('#{$idName}').on('show.bs.offcanvas', function (e) {
                            let Iframe = $('#{$idName}Iframe')
                            Iframe.attr('src', Iframe.attr('data-src'))
                        })
                        $('#{$idName}IframeRefreshBtn').on('click', function (e) {
                            let Iframe = $('#{$idName}Iframe')
                            Iframe.attr('src', Iframe.attr('data-src'))
                        })
                        $('#{$idName}SubmitBtn').on('click', function (e) {
                            const btn = $(this);
                            const Iframe = $('#{$idName}Iframe');
                            const iframeDoc = Iframe[0].contentWindow?.document;
                            if (!iframeDoc) {
                                console.error('Iframe document not accessible');
                                return;
                            }
                            const form = iframeDoc.querySelector('form');
                            if (form) {
                                form.submit();
                            }
                        });
                    </script>
TAG;
            }
            
            // 原有的处理逻辑（使用 I18n Dictionary）
            // 如果已经是完整路径（以 @meta:: 开头），直接使用
            if (str_starts_with($metaKey, '@meta::')) {
                // 已经是完整路径，不需要处理
            } 
            // 如果以 @meta. 开头，替换为 @meta::，然后补全前缀
            elseif (str_starts_with($metaKey, '@meta.')) {
                $metaKey = str_replace('@meta.', '', $metaKey);
                // 补全路径
                if (!empty($prefix)) {
                    $metaKey = '@meta::' . $prefix . '.' . $metaKey;
                } else {
                    $metaKey = '@meta::' . $metaKey;
                }
            }
            // 如果都不匹配，说明是简写格式（如 info.name），需要补全
            else {
                // 补全路径
                if (!empty($prefix)) {
                    $metaKey = '@meta::' . $prefix . '.' . $metaKey;
                } else {
                    // 如果没有 prefix，直接添加 @meta:: 前缀
                    $metaKey = '@meta::' . $metaKey;
                }
            }

            // 获取当前语言
            $locale = \Weline\Framework\Http\Cookie::getLangLocal() ?? 'zh_Hans_CN';

            // 构建带 scope 的 meta key（用于存储和读取）
            // 格式：@meta::theme.component.pagination.info.name|scope:default
            // 如果 scope 是 PHP 变量，需要在运行时构建
            if ($isPhpScope) {
                // 在运行时构建带 scope 的 key
                $metaKeyWithScopePhp = '<?=$metaKeyWithScope = $metaKey . (($scope = ' . $scopePhpCode . ') !== \'default\' ? \'|scope:\' . $scope : \');?>';
            } else {
                $metaKeyWithScope = $metaKey;
                if ($scope !== 'default') {
                    $metaKeyWithScope = $metaKey . '|scope:' . $scope;
                }
            }

            // 从I18n Dictionary获取翻译（优先使用带 scope 的 key）
            // 如果 scope 是 PHP 变量，需要在运行时获取翻译
            if ($isPhpScope) {
                // 生成运行时获取翻译的 PHP 代码
                $translationPhpCode = <<<PHP
<?php
\$locale = \\Weline\\Framework\\Http\\Cookie::getLangLocal() ?? 'zh_Hans_CN';
\$scope = {$scopePhpCode};
\$metaKeyWithScope = \$metaKey . (\$scope !== 'default' ? '|scope:' . \$scope : '');
\$localeDict = \\Weline\\Framework\\Manager\\ObjectManager::getInstance()->get(\\Weline\\I18n\\Model\\Locale\\Dictionary::class);
\$md5 = \\Weline\\I18n\\Model\\Locale\\Dictionary::generateMd5(\$metaKeyWithScope, \$locale);
\$localeDict->load(\$md5, \\Weline\\I18n\\Model\\Locale\\Dictionary::schema_fields_MD5);
\$translation = '';
if (\$localeDict->getId()) {
    \$translation = \$localeDict->getData(\\Weline\\I18n\\Model\\Locale\\Dictionary::schema_fields_TRANSLATE);
}
if (empty(\$translation) && \$scope !== 'default') {
    \$md5Default = \\Weline\\I18n\\Model\\Locale\\Dictionary::generateMd5(\$metaKey, \$locale);
    \$localeDict->load(\$md5Default, \\Weline\\I18n\\Model\\Locale\\Dictionary::schema_fields_MD5);
    if (\$localeDict->getId()) {
        \$translation = \$localeDict->getData(\\Weline\\I18n\\Model\\Locale\\Dictionary::schema_fields_TRANSLATE);
    }
}
\$defaultValue = \$translation ?: '{$content}';
?>
PHP;
                $defaultValue = '<?=$defaultValue?>';
            } else {
                /** @var LocaleDictionary $localeDict */
                $localeDict = ObjectManager::getInstance(LocaleDictionary::class);
                
                // 先尝试带 scope 的 key
                $md5 = LocaleDictionary::generateMd5($metaKeyWithScope, $locale);
                $localeDict->load(LocaleDictionary::schema_fields_MD5, $md5);
                
                $translation = '';
                if ($localeDict->getId()) {
                    $translation = $localeDict->getData(LocaleDictionary::schema_fields_TRANSLATE);
                }
                
                // 如果没有找到，尝试不带 scope 的 key（使用默认值）
                if (empty($translation) && $scope !== 'default') {
                    $md5Default = LocaleDictionary::generateMd5($metaKey, $locale);
                    $localeDict->load(LocaleDictionary::schema_fields_MD5, $md5Default);
                    if ($localeDict->getId()) {
                        $translation = $localeDict->getData(LocaleDictionary::schema_fields_TRANSLATE);
                    }
                }

                // 如果没有翻译，使用默认值（从标签内容中提取，如果有的话）
                $defaultValue = $translation ?: $content;
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
            $idName = 'meta-translate-' . md5($metaKey);
            if (in_array($idName, $ids)) {
                $idName .= '-' . count($ids);
            }
            $ids[] = $idName;

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
                <a class='d-flex align-items-center link-info gap-1' style='cursor: pointer'
                    data-bs-toggle='offcanvas'
                    data-bs-target='#{$idName}' 
                    aria-controls='{$idName}'
                    data-href='{$action}'>
                    <span>{$displayValue}</span>
                    <i class='ri-translate'></i>
                </a>
                <!-- {$idName} -->
                <div class='offcanvas  offcanvas-end w-75 h-100' tabindex='-1' id='{$idName}' 
                     aria-labelledby='{$idName}Label'>
                    <div class='offcanvas-header'>
                        <h5 id='{$idName}Label'>
                            <lang>{$titleText}</lang>
                        </h5>
                        <div class="d-flex gap-2 ms-auto">
                            <button id="{$idName}SubmitBtn" type='submit' class='btn btn-primary btn-sm'>
                                <i class="ri-save-line me-1"></i>{$submitText}
                            </button>
                            <a id="{$idName}IframeRefreshBtn" class='btn btn-info btn-sm' 
                               aria-label='{$refreshText}'>
                                <i class="ri-refresh-line me-1"></i>{$refreshText}
                            </a>
                            <button type='button' class='btn-close btn-sm' data-bs-dismiss='offcanvas'
                                    aria-label='{$closeText}'></button>
                        </div>
                    </div>
                    <div class='offcanvas-body'>
                        <div class='position-relative w-100 h-100 '>
                            <iframe id='{$idName}Iframe' class='w-100 h-100'
                                    data-src="{$action}"
                                    frameborder='0'></iframe>
                        </div>
                    </div>
                </div>
                <script>
                    //show.bs.offcanvas
                    $('#{$idName}').on('show.bs.offcanvas', function (e) {
                        let Iframe = $('#{$idName}Iframe')
                        Iframe.attr('src', Iframe.attr('data-src'))
                    })
                    $('#{$idName}IframeRefreshBtn').on('click', function (e) {
                        let Iframe = $('#{$idName}Iframe')
                        Iframe.attr('src', Iframe.attr('data-src'))
                    })
                    // 提交按钮点击事件
                    $('#{$idName}SubmitBtn').on('click', function (e) {
                        const btn = $(this);
                        const Iframe = $('#{$idName}Iframe');
                        const iframeDoc = Iframe[0].contentWindow?.document;

                        if (!iframeDoc) {
                            console.error('Iframe document not accessible');
                            return;
                        }
                        // 获取id为metaTranslationForm的表单元素
                        const form = iframeDoc.getElementById('metaTranslationForm');
                        if (!form) {
                            console.error('Form not found in iframe');
                            return;
                        }
                        
                        // 防止重复提交
                        btn.prop('disabled', true);
                        btn.html(`<span class="spinner-border spinner-border-sm me-1" role="status"></span>\${btn.text()}`);

                        try {
                            form.submit();

                            // 监听iframe加载完成事件
                            Iframe.on('load', function() {
                                btn.prop('disabled', false);
                                btn.html(`<i class="ri-save-line me-1"></i>{$submitText}`);
                            });
                        } catch (error) {
                            console.error('Form submit error:', error);
                            btn.prop('disabled', false);
                            btn.html(`<i class="ri-save-line me-1"></i>{$submitText}`);
                        }
                    })
                </script>
TAG;
        };
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

