<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/7/1 13:12:38
 */

namespace Weline\I18n\Taglib;

use TheSeer\Tokenizer\Exception;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\StateManager;
use Weline\Framework\View\Data\DataInterface;
use Weline\Framework\View\Taglib;
use Weline\Framework\View\Template;

class Local implements \Weline\Framework\Taglib\TaglibInterface
{
    private static array $ids = [];
    private static bool $stateRegistered = false;
    private static bool $bulkModalRendered = false;
    private static string $runtimeRecordId = '';

    /**
     * 运行期 tag-start：在循环内渲染前绑定当前记录 ID（避免把 <?= ?> 原样塞进 HTML）。
     */
    public static function bindRuntimeRecordId(int|string $recordId): void
    {
        self::$runtimeRecordId = trim((string)$recordId);
    }

    private static function ensureStateRegistered(): void
    {
        if (!self::$stateRegistered) {
            StateManager::registerStaticResets(self::class, [
                'ids' => [],
                'bulkModalRendered' => false,
                'runtimeRecordId' => '',
            ]);
            self::$stateRegistered = true;
        }
    }

    /**
     * @inheritDoc
     */
    static public function name(): string
    {
        return 'local';
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
        return [
            'model' => true,
            'id' => true,
            'field' => false,
            'mode' => false,
            'bulk-fields' => false,
            'bulk-input' => false,
            'bulk-label' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    static function tag_start(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    static function tag_end(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    static function callback(): callable
    {
        self::ensureStateRegistered();
        $ids = &self::$ids;
        return function ($tag_key, $config, $tag_data, $attributes) use (&$ids) {
            # 这里可以做任何处理，然后返回对应处理后的内容
            $model = $attributes['model'];
            $mode = trim((string)($attributes['mode'] ?? ''));
            $field = trim((string)($attributes['field'] ?? ''));
            /**@var Taglib $Taglib */
            $Taglib = ObjectManager::getInstance(Taglib::class);
            $origin_id = $attributes['id'];
            $isRuntimePair = in_array($tag_key, ['tag-start', 'tag-end'], true);
            if ($isRuntimePair && self::$runtimeRecordId !== '') {
                $parserId = htmlspecialchars(self::$runtimeRecordId, ENT_QUOTES);
            } elseif ($isRuntimePair && ctype_digit(trim((string)$origin_id))) {
                $parserId = htmlspecialchars(trim((string)$origin_id), ENT_QUOTES);
            } else {
                $parserId = '<?=(' . $Taglib->varParser($origin_id) . '?:\'' . str_replace('.', '-', $origin_id) . '\')?>';
            }
            /**@var Request $request */
            $request = ObjectManager::getInstance(Request::class);
            $isBackend = $request->isBackend();

            if ($mode === 'bulk') {
                if (!$isBackend) {
                    return '';
                }
                if (!self::isBulkRenderTagKey($tag_key)) {
                    return '';
                }
                $bulkFieldsAttr = htmlspecialchars(trim((string)($attributes['bulk-fields'] ?? '')), ENT_QUOTES);
                $cssUrl = htmlspecialchars(self::resolveModuleStaticUrl('Weline_I18n::css/local-bulk-translation.css?v=20260827-bulk-auth1'), ENT_QUOTES);
                $jsUrl = htmlspecialchars(self::resolveModuleStaticUrl('Weline_I18n::js/local-bulk-translation.js?v=20260827-bulk-auth1'), ENT_QUOTES);
                $buttonText = htmlspecialchars((string)__('一键 AI 翻译'), ENT_QUOTES);
                $dialogTitle = htmlspecialchars((string)__('一键 AI 批量翻译'), ENT_QUOTES);
                $progressLabel = htmlspecialchars((string)__('翻译进度'), ENT_QUOTES);
                $closeText = htmlspecialchars((string)__('关闭'), ENT_QUOTES);
                $msgSaveFirst = htmlspecialchars((string)__('请先保存后再翻译'), ENT_QUOTES);
                $msgNoFields = htmlspecialchars((string)__('没有可翻译的字段，请先填写文案'), ENT_QUOTES);
                $msgReady = htmlspecialchars((string)__('准备开始…'), ENT_QUOTES);
                $msgRunning = htmlspecialchars((string)__('翻译进行中'), ENT_QUOTES);
                $modelEsc = htmlspecialchars($model, ENT_QUOTES);
                $bulkModal = '';
                if (!self::$bulkModalRendered) {
                    self::$bulkModalRendered = true;
                    $bulkModal = <<<MODAL
<dialog class="w-dialog w-local-bulk-dialog" data-w-component="dialog" data-w-local-bulk-dialog data-size="md" data-w-closable="false" data-w-backdrop="static" aria-labelledby="w-local-bulk-dialog-title">
  <header class="w-dialog__header">
    <div class="w-cluster" data-align="center" data-gap="sm">
      <w-icon name="sparkles" size="sm"></w-icon>
      <h2 class="w-dialog__title" id="w-local-bulk-dialog-title">{$dialogTitle}</h2>
    </div>
    <button type="button" class="w-button" data-w-local-bulk-close data-tone="quiet" data-size="sm" data-icon-only="true" hidden aria-label="{$closeText}">
      <w-icon name="close" size="sm"></w-icon>
    </button>
  </header>
  <div class="w-dialog__body">
    <div class="w-local-bulk-dialog__summary">
      <div class="w-cluster" data-align="center" data-justify="between">
        <span class="w-local-bulk-dialog__progress-text" data-w-local-bulk-progress-text>{$msgReady}</span>
        <span class="w-badge" data-tone="info" data-w-local-bulk-status>{$msgRunning}</span>
      </div>
      <div class="w-local-bulk-dialog__progress-track" aria-hidden="true">
        <div class="w-local-bulk-dialog__progress-bar" data-w-local-bulk-progress-bar></div>
      </div>
    </div>
    <div class="w-local-bulk-dialog__steps" data-w-local-bulk-steps aria-label="{$progressLabel}"></div>
    <div class="w-local-bulk-dialog__log" data-w-local-bulk-log aria-live="polite"></div>
  </div>
  <footer class="w-dialog__footer w-local-bulk-dialog__footer" data-w-local-bulk-footer hidden>
    <button type="button" class="w-button" data-w-local-bulk-close data-tone="primary">
      <span>{$closeText}</span>
    </button>
  </footer>
</dialog>
<script src="{$jsUrl}" defer></script>
MODAL;
                }
                return <<<BULK
<link rel="stylesheet" href="{$cssUrl}">
<div class="w-local-bulk-root" data-w-local-bulk-root data-model="{$modelEsc}" data-record-id="{$parserId}" data-bulk-fields="{$bulkFieldsAttr}" data-msg-save-first="{$msgSaveFirst}" data-msg-no-fields="{$msgNoFields}">
  <button type="button" class="w-button" data-w-local-bulk-trigger data-tone="primary" data-variant="outline">
    <w-icon name="sparkles" size="sm"></w-icon>
    <span>{$buttonText}</span>
  </button>
</div>
{$bulkModal}
BULK;
            }

            if ($field === '') {
                throw new Exception(__('请选择一个字段！'));
            }

            // 成对标签会派发 tag-start + tag-end；仅开标签占用唯一 ID。
            if ($tag_key === 'tag-end') {
                return '';
            }

            $idName = 'local-off-canvas-' . $parserId . '-' . $field . '-' . trim((string)($attributes['name'] ?? ''));
            if (in_array($idName, $ids)) {
                throw new Exception('local标签ID不允许重复！');
            }
            $ids[] = $idName;
            $name = trim($tag_data[2] ?? '');
            $bulkInput = trim((string)($attributes['bulk-input'] ?? ''));
            $bulkLabel = trim((string)($attributes['bulk-label'] ?? $field));
            $bulkParticipantAttrs = '';
            if ($isBackend && $bulkInput !== '') {
                $bulkParticipantAttrs = ' data-w-local-bulk-participant'
                    . ' data-model="' . htmlspecialchars($model, ENT_QUOTES) . '"'
                    . ' data-record-id="' . $parserId . '"'
                    . ' data-field="' . htmlspecialchars($field, ENT_QUOTES) . '"'
                    . ' data-bulk-input="' . htmlspecialchars($bulkInput, ENT_QUOTES) . '"'
                    . ' data-bulk-label="' . htmlspecialchars($bulkLabel, ENT_QUOTES) . '"';
            }
            if ($isBackend) {
                $action = $request->getUrlBuilder()->getBackendUrl('i18n/backend/taglib/local', ['model' => $model, 'field' => $field]);
            } else {
                $action = $request->getUrlBuilder()->getUrl('i18n/frontend/taglib/local', ['model' => $model, 'field' => $field]);
            }

            $cssUrl = htmlspecialchars(self::resolveModuleStaticUrl('Weline_I18n::css/local-translation.css?v=20260916-trigger-bg2'), ENT_QUOTES);
            $closeText = __('关闭');
            $titileText = __('多语言翻译');
            $refreshText = __('刷新');
            $submitText = __('保存');
            $cancelText = __('取消');
            $searchPlaceholder = __('搜索语言、语言码或翻译');
            $loadingText = __('正在加载语言…');
            $progressTemplate = __('已译 %{filled} / 共 %{total} 种语言');
            $sourceHint = __('原文');
            $colLanguage = __('语言');
            $colTranslation = __('译文');
            $colStatus = __('状态');
            $i18nEmpty = htmlspecialchars(__('没有找到已启用的语言'), ENT_QUOTES);
            $i18nNoMatch = htmlspecialchars(__('没有匹配的语言'), ENT_QUOTES);
            $i18nProgress = htmlspecialchars(__('已译 %{filled} / 共 %{total} 种语言'), ENT_QUOTES);
            $i18nFilled = htmlspecialchars(__('已填写'), ENT_QUOTES);
            $i18nPending = htmlspecialchars(__('待填写'), ENT_QUOTES);
            $i18nBase = htmlspecialchars(__('母本语言'), ENT_QUOTES);
            $i18nSourceFallback = htmlspecialchars(__('母本为空，当前以 %{locale} 为翻译源'), ENT_QUOTES);
            $aiText = htmlspecialchars(__('AI翻译'), ENT_QUOTES);
            $i18nAiDone = htmlspecialchars(__('AI 翻译完成'), ENT_QUOTES);
            $i18nAiRetranslate = htmlspecialchars(__('已有翻译内容，是否重新翻译？'), ENT_QUOTES);
            $i18nAiRetranslateTitle = htmlspecialchars(__('重新翻译'), ENT_QUOTES);
            return match ($tag_key) {
                'tag', 'tag-start' => <<<TAG
                    <link rel="stylesheet" href="{$cssUrl}">
                    <div class="w-local-translation__wrap"{$bulkParticipantAttrs}>
                    <button type="button" class="w-button w-local-translation__trigger" aria-controls='{$idName}' data-w-target='#{$idName}' data-w-action="drawer.open" data-tone="quiet" data-size="sm">
                        <div class="w-local-translation__trigger-text">{$name}</div>
                        <w-icon name="language" size="sm"></w-icon>
                    </button>
                    <div class="w-drawer w-drawer--dock w-local-translation-drawer" tabindex='-1' id='{$idName}' aria-labelledby='{$idName}Label'
                         data-w-component="drawer local-translation" data-w-source="{$action}&value={$name}&id={$parserId}"
                         data-state="closed" hidden aria-hidden="true">
                        <div class="w-drawer__header">
                            <h5 class="w-drawer__title" id='{$idName}Label'>
                                <w-icon name="language" size="sm" aria-hidden="true"></w-icon>
                                <span>{$titileText}</span>
                            </h5>
                            <button type='button' class="w-button" aria-label='{$closeText}' data-w-action="drawer.close" data-w-close data-tone="quiet" data-size="sm" data-icon-only="true"><w:icon name="close" size="sm"></w:icon></button>
                        </div>
                        <div class="w-drawer__body">
                            <div class="w-local-translation" data-w-local-panel
                                 data-i18n-empty="{$i18nEmpty}"
                                 data-i18n-no-match="{$i18nNoMatch}"
                                 data-i18n-progress="{$i18nProgress}"
                                 data-i18n-filled="{$i18nFilled}"
                                 data-i18n-pending="{$i18nPending}"
                                 data-i18n-base="{$i18nBase}"
                                 data-i18n-source-fallback="{$i18nSourceFallback}"
                                 data-i18n-ai-done="{$i18nAiDone}"
                                 data-i18n-ai-retranslate="{$i18nAiRetranslate}"
                                 data-i18n-ai-retranslate-title="{$i18nAiRetranslateTitle}">
                                <div class="w-local-translation__toolbar">
                                    <div class="w-local-translation__source">
                                        <p class="w-local-translation__source-label">{$sourceHint}</p>
                                        <p class="w-local-translation__source-value" data-w-local-source>{$name}</p>
                                        <p class="w-local-translation__source-note" data-w-local-source-note hidden></p>
                                    </div>
                                    <div class="w-local-translation__progress-wrap">
                                        <div class="w-local-translation__progress-track" aria-hidden="true">
                                            <div class="w-local-translation__progress-bar" data-w-local-progress-bar></div>
                                        </div>
                                        <span class="w-local-translation__progress-text" data-w-local-progress aria-live="polite"></span>
                                    </div>
                                    <label class="w-local-translation__search">
                                        <w-icon name="search" size="sm" aria-hidden="true"></w-icon>
                                        <input class="w-input" type="search" data-w-local-search placeholder="{$searchPlaceholder}" autocomplete="off" spellcheck="false">
                                    </label>
                                </div>
                                <div class="w-local-translation__list-head" data-w-local-list-head hidden>
                                    <span></span>
                                    <span>{$colLanguage}</span>
                                    <span>{$colTranslation}</span>
                                    <span>{$colStatus}</span>
                                </div>
                                <div class="w-local-translation__list" data-w-local-list role="list"></div>
                                <div class="w-local-translation__state" data-w-local-loading hidden>
                                    <span class="w-spinner" data-size="sm" aria-hidden="true"></span>
                                    <span>{$loadingText}</span>
                                </div>
                                <div class="w-local-translation__state" data-w-local-empty hidden>
                                    <w-icon name="language" size="lg" aria-hidden="true"></w-icon>
                                    <p data-w-local-empty-text></p>
                                </div>
                            </div>
                        </div>
                        <div class="w-drawer__footer w-cluster" data-align="center" data-justify="between" data-gap="sm">
                            <div class="w-cluster" data-gap="sm">
                                <button type="button" class="w-button" data-variant="outline" data-w-local-refresh aria-label='{$refreshText}'>
                                    <w-icon name="refresh" size="sm"></w-icon>
                                    <span>{$refreshText}</span>
                                </button>
                                <button type="button" class="w-button" data-variant="outline" data-tone="primary" data-w-local-ai>
                                    <w-icon name="sparkles" size="sm"></w-icon>
                                    <span>{$aiText}</span>
                                </button>
                            </div>
                            <div class="w-cluster" data-gap="sm">
                                <button type="button" class="w-button" data-variant="outline" data-w-action="drawer.close" data-w-close>
                                    <span>{$cancelText}</span>
                                </button>
                                <button type='button' class="w-button" data-w-local-submit data-tone="primary">
                                    <span>{$submitText}</span>
                                </button>
                            </div>
                        </div>
                    </div>
                    </div>
TAG,
                'tag-end' => '',
            };
        };
    }

    private static function isBulkRenderTagKey(string $tag_key): bool
    {
        return in_array($tag_key, ['tag', 'tag-start', 'tag-self-close-with-attrs', 'tag-self-close', '@tag()', '@tag{}'], true);
    }

    private static function resolveModuleStaticUrl(string $source): string
    {
        /** @var Template $template */
        $template = ObjectManager::getInstance(Template::class);

        return (string)$template->fetchTagSource(DataInterface::dir_type_STATICS, $source);
    }

    /**
     * @inheritDoc
     */
    static function tag_self_close(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    static function tag_self_close_with_attrs(): bool
    {
        return false;
    }

    /**
     * 指定父标签，用于依赖管理
     * @return string|null 父标签名称
     */
    static function parent(): ?string
    {
        return null; // Local标签没有依赖
    }

    static function document(): string
    {
        return '翻译标签，使用Model继承 Weline\I18n\LocalModel.然后使用。示例：' . htmlentities('<local model="Weline\Store\Model\StoreDescription" field="name" id="store.store_id" name="store-name"></local>') . ' 其中 Weline\Store\Model\Store 继承 Weline\I18n\LocalModel。
<pre>
class StoreDescription extends \Weline\I18n\LocalModel
{
    public const indexer = \'store_local_description\';
    public const fields_ID = \'store_id\';
    public const fields_NAME = Store::schema_fields_NAME;
    public const fields_DESCRIPTION = Store::schema_fields_DESCRIPTION;
}
</pre>示例中，我们设置店铺的name字段可以翻译。还可以添加多个字段，比如店铺详情等，使用时指定字段即可。';
    }
}
