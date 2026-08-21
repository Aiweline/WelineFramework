<?php

declare(strict_types=1);

namespace Weline\CacheManager\Taglib;

use Weline\CacheManager\Service\CacheAdminService;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Taglib\TaglibInterface;

/**
 * <w:cache:clear /> 后台顶部「设置」面板里的清理缓存入口。
 *
 * - 仅后台区域渲染；
 * - 仅拥有清理缓存控制器 ACL（Weline_CacheManager::system_cache_clear）的用户可见；
 * - 弹窗支持搜索、多选清理指定缓存池，或一键清理全部非持久缓存
 *   （全部清理按钮额外要求 Weline_CacheManager::system_cache_clear_all）。
 */
class CacheClear implements TaglibInterface
{
    public const ACL_CLEAR = 'Weline_CacheManager::system_cache_clear';
    public const ACL_CLEAR_ALL = 'Weline_CacheManager::system_cache_clear_all';

    public static function name(): string
    {
        return 'cache:clear';
    }

    public static function tag(): bool
    {
        return false;
    }

    public static function tag_start(): bool
    {
        return false;
    }

    public static function tag_end(): bool
    {
        return false;
    }

    public static function attr(): array
    {
        return [];
    }

    public static function callback(): callable
    {
        // 编译期只输出运行时调用：可见性取决于当前用户 ACL，不能把结果烘焙进模板。
        return static function ($tag_key, $config, $tag_data, $attributes): string {
            return '<?php echo \\Weline\\CacheManager\\Taglib\\CacheClear::render(); ?>';
        };
    }

    /**
     * Runtime render entry used by compiled templates.
     */
    public static function render(): string
    {
        if (!self::resolveIsBackendArea()) {
            return '';
        }
        if (!self::hasAcl(self::ACL_CLEAR)) {
            return '<!-- cache:clear hidden: no acl -->';
        }

        $canClearAll = self::hasAcl(self::ACL_CLEAR_ALL);

        $pools = [];
        try {
            /** @var CacheAdminService $service */
            $service = ObjectManager::getInstance(CacheAdminService::class);
            $pools = $service->listPoolOptions();
        } catch (\Throwable) {
        }

        $config = [
            'can_clear_all' => $canClearAll,
            'strings' => [
                'select_empty' => (string)__('请先选择要清理的缓存'),
                'clearing' => (string)__('清理中...'),
                'clear_done' => (string)__('所选缓存已清理完成'),
                'clear_partial' => (string)__('部分缓存清理失败，请查看提示后重试'),
                'clear_all_done' => (string)__('已清理所有非持久缓存池'),
                'request_failed' => (string)__('请求失败'),
                'api_unavailable' => (string)__('bin-query 接口不可用，请刷新页面后重试'),
                'confirm_clear_selected' => (string)__('确定要清理所选缓存池吗？'),
                'confirm_clear_selected_force' => (string)__('所选缓存里包含持久缓存，将以强制模式清理。是否继续？'),
                'confirm_clear_all' => (string)__('确定要清理全部非持久缓存吗？这会保留系统关键持久缓存。'),
                'confirm_continue' => (string)__('继续'),
                'confirm_cancel' => (string)__('取消'),
                'no_match' => (string)__('无匹配缓存'),
            ],
        ];
        $configJson = \json_encode(
            $config,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) ?: '{}';

        $e = static fn(string $text): string => \htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        $html = [];
        $html[] = '<div class="weline-cache-clear-entry" data-weline-cache-clear>';
        $html[] = '<hr style="--w-mt:0;" />';
        $html[] = '<h6 class="w-text" data-align="center" style="--w-mb:0;">' . $e((string)__('系统维护')) . '</h6>';
        $html[] = '<div style="--w-pt:var(--weline-space-6);--w-pe:var(--weline-space-6);--w-pb:var(--weline-space-6);--w-ps:var(--weline-space-6);">';
        $html[] = '<button type="button" class="w-button" data-role="open" data-w-width="full" data-tone="danger"><w-icon name="circle" size="sm"></w-icon>' . $e((string)__('清理缓存')) . '</button>';
        $html[] = '<small class="w-field__hint w-text" data-w-display="block" data-tone="muted" style="--w-mt:var(--weline-space-2);">' . $e((string)__('弹窗内可搜索选择缓存类型，或一键清理全部非持久缓存。')) . '</small>';
        $html[] = '</div>';

        // 弹窗（初次打开时由 JS 移动到 body，避开右侧栏层叠上下文）
        $html[] = '<div class="weline-cache-clear-modal" data-role="modal" hidden>';
        $html[] = '<div class="weline-cache-clear-modal__backdrop" data-role="close"></div>';
        $html[] = '<div class="weline-cache-clear-modal__dialog" role="dialog" aria-modal="true" aria-label="' . $e((string)__('清理缓存')) . '">';
        $html[] = '<div class="weline-cache-clear-modal__header">';
        $html[] = '<h5 style="--w-mt:0;--w-me:0;--w-mb:0;--w-ms:0;"><w-icon name="circle" size="sm"></w-icon>' . $e((string)__('清理缓存')) . '</h5>';
        $html[] = '<button type="button" class="w-button" data-role="close" aria-label="' . $e((string)__('关闭')) . '" data-w-close="" data-tone="quiet" data-size="sm"></button>';
        $html[] = '</div>';
        $html[] = '<div class="weline-cache-clear-modal__body">';
        $html[] = '<input type="text" class="w-input" data-role="search" placeholder="' . $e((string)__('搜索缓存类型（identity / 名称 / 模块）')) . '" autocomplete="off" style="--w-mb:var(--weline-space-2);">';
        $html[] = '<div class="w-cluster" data-align="center" data-justify="between" style="--w-mb:var(--weline-space-2);">';
        $html[] = '<label class="w-check" style="--w-mt:0;--w-me:0;--w-mb:0;--w-ms:0;"><input type="checkbox" data-role="select-all"><span class="ms-1">' . $e((string)__('全选当前结果')) . '</span></label>';
        $html[] = '<span class="w-text" data-tone="muted" data-size="sm"><span data-role="selected-count">0</span> ' . $e((string)__('个已选')) . '</span>';
        $html[] = '</div>';
        $html[] = '<div class="weline-cache-clear-modal__list" data-role="list">';

        foreach ($pools as $pool) {
            $identity = $e((string)$pool['identity']);
            $name = $e((string)$pool['name']);
            $module = $e((string)$pool['module']);
            $permanent = !empty($pool['permanent']);
            $searchText = $e(\strtolower((string)$pool['identity'] . ' ' . (string)$pool['name'] . ' ' . (string)$pool['module']));
            $html[] = '<label class="weline-cache-clear-option" data-search="' . $searchText . '" data-permanent="' . ($permanent ? '1' : '0') . '">';
            $html[] = '<input type="checkbox" value="' . $identity . '">';
            $html[] = '<span class="weline-cache-clear-option__text"><strong>' . $name . '</strong><small class="w-text" data-w-display="block" data-tone="muted">' . $identity . ($module !== '' ? ' · ' . $module : '') . '</small></span>';
            if ($permanent) {
                $html[] = '<span class="w-badge w-text" data-w-background="warning" data-tone="muted">' . $e((string)__('持久')) . '</span>';
            }
            $html[] = '</label>';
        }

        $html[] = '<div class="w-text" data-role="empty" hidden data-tone="muted" data-size="sm" style="--w-pt:var(--weline-space-2);--w-pe:var(--weline-space-2);--w-pb:var(--weline-space-2);--w-ps:var(--weline-space-2);">' . $e((string)__('无匹配缓存')) . '</div>';
        $html[] = '</div>';
        $html[] = '</div>';
        $html[] = '<div class="weline-cache-clear-modal__footer">';
        if ($canClearAll) {
            $html[] = '<button type="button" class="w-button" data-role="clear-all" data-tone="danger" data-variant="outline"><w-icon name="trash" size="sm"></w-icon>' . $e((string)__('全部清理（非持久）')) . '</button>';
        }
        $html[] = '<div class="ms-auto w-cluster" style="--w-gap:var(--weline-space-2);">';
        $html[] = '<button type="button" class="w-button" data-role="close" data-tone="neutral">' . $e((string)__('取消')) . '</button>';
        $html[] = '<button type="button" class="w-button" data-role="clear-selected" disabled data-tone="danger"><w-icon name="trash" size="sm"></w-icon>' . $e((string)__('清理所选')) . '</button>';
        $html[] = '</div>';
        $html[] = '</div>';
        $html[] = '</div>';
        $html[] = '</div>';

        $html[] = '<script type="application/json" data-role="config">' . $configJson . '</script>';
        $html[] = self::styles();
        $html[] = self::script();
        $html[] = '</div>';

        return \implode("\n", $html);
    }

    /**
     * 静默 ACL 检查：无 Weline_Acl 模块时不渲染（该入口必须受 ACL 管控）。
     */
    private static function hasAcl(string $source): bool
    {
        try {
            if (!\class_exists(\Weline\Acl\Taglib\Acl::class)) {
                return false;
            }

            return \Weline\Acl\Taglib\Acl::hasPermissionQuiet($source);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function resolveIsBackendArea(): bool
    {
        try {
            /** @var Request $request */
            $request = ObjectManager::getInstance(Request::class);
            if ((bool)$request->isBackend()
                || (\method_exists($request, 'isApiBackend') && (bool)$request->isApiBackend())
            ) {
                return true;
            }
        } catch (\Throwable) {
        }

        try {
            if (\class_exists(\Weline\Theme\Helper\ThemeData::class)) {
                $area = \strtolower((string)(\Weline\Theme\Helper\ThemeData::getCurrentArea() ?? ''));
                if ($area === 'backend') {
                    return true;
                }
            }
        } catch (\Throwable) {
        }

        return false;
    }

    private static function styles(): string
    {
        return <<<'HTML'
<style>
.weline-cache-clear-modal{position:fixed;inset:0;z-index:11000;display:flex;align-items:center;justify-content:center;}
.weline-cache-clear-modal[hidden]{display:none;}
.weline-cache-clear-modal__backdrop{position:absolute;inset:0;background:rgba(0,0,0,.45);}
.weline-cache-clear-modal__dialog{position:relative;width:min(560px,calc(100vw - 32px));max-height:min(78vh,720px);display:flex;flex-direction:column;background:var(--backend-color-card-bg,#fff);border-radius:var(--backend-border-radius-xl,1rem);box-shadow:var(--backend-dropdown-shadow,0 10px 30px rgba(0,0,0,.2));overflow:hidden;}
.weline-cache-clear-modal__header{display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;border-bottom:1px solid var(--backend-color-border-light,#e9ecef);}
.weline-cache-clear-modal__body{padding:1rem 1.25rem;overflow:hidden;display:flex;flex-direction:column;min-height:0;}
.weline-cache-clear-modal__list{overflow-y:auto;min-height:120px;max-height:44vh;border:1px solid var(--backend-color-border-light,#e9ecef);border-radius:var(--backend-border-radius-md,.5rem);padding:.25rem;}
.weline-cache-clear-option{display:flex;align-items:center;gap:.6rem;width:100%;padding:.5rem .6rem;margin:0;border-radius:var(--backend-border-radius-md,.5rem);cursor:pointer;}
.weline-cache-clear-option:hover{background:var(--backend-color-bg-secondary,#f8f9fa);}
.weline-cache-clear-option__text{flex:1 1 auto;min-width:0;}
.weline-cache-clear-option__text small{word-break:break-all;}
.weline-cache-clear-modal__footer{display:flex;align-items:center;gap:.5rem;padding:1rem 1.25rem;border-top:1px solid var(--backend-color-border-light,#e9ecef);}
</style>
HTML;
    }

    private static function script(): string
    {
        return <<<'HTML'
<script>
(function () {
    'use strict';
    var script = document.currentScript;
    var root = script ? script.closest('[data-weline-cache-clear]') : null;
    if (!root || root.dataset.welineCacheClearBound === '1') { return; }
    root.dataset.welineCacheClearBound = '1';

    var config = {};
    try { config = JSON.parse((root.querySelector('[data-role="config"]') || {}).textContent || '{}'); } catch (e) {}
    var strings = config.strings || {};
    var modal = root.querySelector('[data-role="modal"]');
    if (!modal) { return; }

    function text(key, fallback) { var v = strings[key]; return v == null || v === '' ? fallback : String(v); }
    function notify(type, message) {
        if (window.Weline.UI.toast && typeof window.Weline.UI.toast[type] === 'function') { window.Weline.UI.toast[type](message); return; }
        console[type === 'error' ? 'error' : 'log'](message);
    }
    function confirmAction(message) {
        return Weline.UI.dialog.confirm(message, {
            confirmLabel: text('confirm_continue', 'OK'),
            cancelLabel: text('confirm_cancel', 'Cancel'),
            tone: 'warning'
        });
    }
    function loadApi() {
        if (window.Weline && typeof window.Weline.load === 'function') {
            return window.Weline.load('api');
        }
        if (window.Weline && window.Weline.Api && typeof window.Weline.Api.resource === 'function') {
            return Promise.resolve(window.Weline.Api);
        }
        return Promise.reject(new Error(text('api_unavailable', 'bin-query unavailable')));
    }
    function unwrapPayload(payload) {
        var body = payload;
        if (payload && typeof payload === 'object' && Object.prototype.hasOwnProperty.call(payload, 'data')
            && payload.data && typeof payload.data === 'object'
            && (Object.prototype.hasOwnProperty.call(payload.data, 'success')
                || Object.prototype.hasOwnProperty.call(payload.data, 'code')
                || Object.prototype.hasOwnProperty.call(payload.data, 'msg')
                || Object.prototype.hasOwnProperty.call(payload.data, 'message'))
        ) {
            body = payload.data;
        }
        if (!body || typeof body !== 'object') {
            return { success: true, data: body };
        }
        if (body.success === false || (typeof body.code !== 'undefined' && Number(body.code) >= 400)) {
            throw new Error(body.message || body.msg || text('request_failed', 'Request failed'));
        }
        return body;
    }
    function callCache(operation, params) {
        return loadApi().then(function (api) {
            if (!api || typeof api.resource !== 'function') {
                throw new Error(text('api_unavailable', 'bin-query unavailable'));
            }
            var resource = api.resource('cache_manager');
            if (!resource || typeof resource[operation] !== 'function') {
                throw new Error(text('api_unavailable', 'bin-query unavailable'));
            }
            return resource[operation](params || {});
        }).then(unwrapPayload);
    }

    var openButton = root.querySelector('[data-role="open"]');
    var searchInput = modal.querySelector('[data-role="search"]');
    var selectAll = modal.querySelector('[data-role="select-all"]');
    var selectedCountNode = modal.querySelector('[data-role="selected-count"]');
    var clearSelectedButton = modal.querySelector('[data-role="clear-selected"]');
    var clearAllButton = modal.querySelector('[data-role="clear-all"]');
    var emptyNode = modal.querySelector('[data-role="empty"]');

    function options() { return Array.prototype.slice.call(modal.querySelectorAll('.weline-cache-clear-option')); }
    function visibleOptions() { return options().filter(function (opt) { return !opt.hidden; }); }
    function selectedOptions() {
        return options().filter(function (opt) {
            var checkbox = opt.querySelector('input[type="checkbox"]');
            return checkbox && checkbox.checked;
        });
    }
    function syncState() {
        var selected = selectedOptions();
        if (selectedCountNode) { selectedCountNode.textContent = String(selected.length); }
        if (clearSelectedButton) { clearSelectedButton.disabled = selected.length === 0; }
        if (selectAll) {
            var visible = visibleOptions();
            var visibleChecked = visible.filter(function (opt) { return opt.querySelector('input[type="checkbox"]').checked; });
            selectAll.checked = visible.length > 0 && visibleChecked.length === visible.length;
        }
    }
    function filter() {
        var keyword = String((searchInput && searchInput.value) || '').trim().toLowerCase();
        var visible = 0;
        options().forEach(function (opt) {
            var ok = keyword === '' || (opt.getAttribute('data-search') || '').indexOf(keyword) !== -1;
            opt.hidden = !ok;
            if (ok) { visible++; }
        });
        if (emptyNode) { emptyNode.hidden = visible !== 0; }
        syncState();
    }
    function openModal() {
        if (modal.parentElement !== document.body) { document.body.appendChild(modal); }
        modal.hidden = false;
        if (searchInput) { searchInput.value = ''; }
        filter();
        setTimeout(function () { try { searchInput && searchInput.focus(); } catch (e) {} }, 0);
    }
    function closeModal() { modal.hidden = true; }

    if (openButton) { openButton.addEventListener('click', openModal); }
    modal.querySelectorAll('[data-role="close"]').forEach(function (node) { node.addEventListener('click', closeModal); });
    document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && !modal.hidden) { closeModal(); } });
    if (searchInput) { searchInput.addEventListener('input', filter); }
    if (selectAll) {
        selectAll.addEventListener('change', function () {
            var checked = selectAll.checked;
            visibleOptions().forEach(function (opt) { opt.querySelector('input[type="checkbox"]').checked = checked; });
            syncState();
        });
    }
    modal.addEventListener('change', function (event) {
        if (event.target && event.target.matches('.weline-cache-clear-option input[type="checkbox"]')) { syncState(); }
    });

    function setBusy(button, busy) {
        if (!button) { return; }
        if (busy) {
            button.dataset.originalHtml = button.innerHTML;
            button.disabled = true;
            button.innerHTML = text('clearing', 'Clearing...');
        } else {
            button.disabled = false;
            if (button.dataset.originalHtml) { button.innerHTML = button.dataset.originalHtml; }
        }
    }

    if (clearSelectedButton) {
        clearSelectedButton.addEventListener('click', function () {
            var selected = selectedOptions();
            if (selected.length === 0) { notify('warning', text('select_empty', 'Select cache first')); return; }
            var hasPermanent = selected.some(function (opt) { return opt.getAttribute('data-permanent') === '1'; });
            var message = hasPermanent ? text('confirm_clear_selected_force', '') : text('confirm_clear_selected', '');
            confirmAction(message).then(function (ok) {
                if (!ok) { return; }
                setBusy(clearSelectedButton, true);
                var failed = [];
                var chain = Promise.resolve();
                selected.forEach(function (opt) {
                    var identity = opt.querySelector('input[type="checkbox"]').value;
                    var force = opt.getAttribute('data-permanent') === '1';
                    chain = chain.then(function () {
                        return callCache('clearPool', { identity: identity, force: force }).catch(function (error) {
                            failed.push(identity + ': ' + (error && error.message ? error.message : ''));
                        });
                    });
                });
                chain.then(function () {
                    setBusy(clearSelectedButton, false);
                    if (failed.length === 0) {
                        notify('success', text('clear_done', 'Cleared'));
                        closeModal();
                    } else {
                        notify('error', text('clear_partial', 'Partially failed') + ' (' + failed.join('; ') + ')');
                    }
                });
            });
        });
    }

    if (clearAllButton) {
        clearAllButton.addEventListener('click', function () {
            confirmAction(text('confirm_clear_all', '')).then(function (ok) {
                if (!ok) { return; }
                setBusy(clearAllButton, true);
                callCache('clearAll', { force: false }).then(function (payload) {
                    setBusy(clearAllButton, false);
                    notify('success', (payload && (payload.msg || payload.message)) || text('clear_all_done', 'Cleared'));
                    closeModal();
                }).catch(function (error) {
                    setBusy(clearAllButton, false);
                    notify('error', (error && error.message) || text('request_failed', 'Request failed'));
                });
            });
        });
    }

    filter();
})();
</script>
HTML;
    }

    public static function tag_self_close(): bool
    {
        return true;
    }

    public static function tag_self_close_with_attrs(): bool
    {
        return true;
    }

    public static function parent(): ?string
    {
        return null;
    }

    public static function document(): string
    {
        return '<p><code>&lt;w:cache:clear /&gt;</code> '
            . __('后台设置面板的清理缓存入口：弹窗支持搜索多选缓存池清理或全部清理，仅拥有 %{1} ACL 的用户可见。', self::ACL_CLEAR)
            . '</p>';
    }
}
