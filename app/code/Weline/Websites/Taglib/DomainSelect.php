<?php

declare(strict_types=1);

/**
 * Weline Websites - 域名选择器标签
 *
 * 提供可搜索的域名池选择器组件
 * 支持主题变量，兼容暗色/亮色模式
 *
 * 【硬性】本标签为自定义（非 HTML）标签：属性上禁止 PHP，须用静态标签（@lang/@var/{{}} 等）或字面量；
 * 勿写 <?php、<?=。编译期 taglib 会抽取/还原占位符，属性内嵌 PHP 易 ParseError（如 unexpected identifier "true"）。
 * 动态值：在标签前的 <?php ?> 块赋给普通变量，属性只写变量名（Weline_Taglib_resolve，如 value="domainSelectValueEscaped"）；
 * 常量可用字面量或 var|默认值 语法。详见 dev/ai/skills/theme-development/SKILL.md。
 */

namespace Weline\Websites\Taglib;

use Weline\Taglib\TaglibInterface;
use Weline\Framework\Http\Url;

/**
 * 域名选择标签
 *
 * 使用示例（属性中勿嵌套 <?= ... ?>）：
 * <w:websites:domain:select
 *     id="domain_select"
 *     name="domain"
 *     value="selectedDomain|''"
 *     display="displayText|'请选择域名'"
 *     class="w-100"
 *     on-select="handleDomainSelect"
 * />
 */
class DomainSelect implements TaglibInterface
{
    public static function name(): string
    {
        return 'websites:domain:select';
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
        return [
            'id' => true,
            'name' => true,
            'value' => true,
            'display' => true,
            'class' => false,
            'style' => false,
            'limit' => false,
            'url' => false,
            'multiple' => false,  // 是否支持多选，默认 false（单选）
            'on-select' => false,  // 选择后的回调函数名
            'auto-fill-code' => false,  // 自动填充代码的目标输入框 ID
            'auto-fill-url' => false,   // 自动填充 URL 的目标输入框 ID
            'auto-fill-name' => false,  // 自动填充名称的目标输入框 ID
            'auto-fill-address' => false,  // 自动填充地址的目标 textarea ID（多选时填充多行）
            'site-ready-only' => true,    // v1.6.0: 是否只显示可建站且未已建站（site_ready=1 且 site_created=0），默认 true
            'value-type' => false,         // v1.6.0: 值类型 "domain"（默认）或 "pool_id"
            'website-id' => false,        // 编辑站点时传入当前 website_id，列表中包含本站已绑定域名便于取消绑定
            'bind-root-www' => false,     // 多选时：点选 apex 与 www.{apex} 其一则自动勾选另一（列表中存在时）；移除标签时成对移除
        ];
    }

    public static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            if (empty($attributes['id'])) {
                throw new \Exception(__('id属性不能为空'));
            }
            
            $placeholder = $attributes['placeholder'] ?? __('搜索域名...');
            $class = $attributes['class'] ?? '';
            $style = $attributes['style'] ?? '';
            $limit = (int)($attributes['limit'] ?? 50);
            $urlPath = $attributes['url'] ?? 'websites/backend/api/domain-pool';
            $onSelect = $attributes['on-select'] ?? '';
            $autoFillCode = $attributes['auto-fill-code'] ?? '';
            $autoFillUrl = $attributes['auto-fill-url'] ?? '';
            $autoFillName = $attributes['auto-fill-name'] ?? '';
            $autoFillAddress = $attributes['auto-fill-address'] ?? '';
            
            // 多选模式：支持 "true"、"1"、true
            $multipleRaw = $attributes['multiple'] ?? 'false';
            $isMultiple = in_array(strtolower(trim((string)$multipleRaw)), ['true', '1', 'yes'], true);
            
            // v1.6.0: site-ready-only 默认为 true，只显示可建站的域名
            $siteReadyOnlyRaw = $attributes['site-ready-only'] ?? 'true';
            $siteReadyOnly = !in_array(strtolower(trim((string)$siteReadyOnlyRaw)), ['false', '0', 'no'], true);
            
            // v1.6.0: 值类型 "domain"（默认）或 "pool_id"
            $valueType = strtolower(trim($attributes['value-type'] ?? 'domain'));
            $websiteIdAttr = trim((string) ($attributes['website-id'] ?? ''));
            $websiteId = str_contains($websiteIdAttr, '<?=') ? 0 : (int) $websiteIdAttr;

            // bind-root-www：属性为字面量 true/false（及 1/0/yes/no）时编译期直接定值；否则为变量名，渲染期用 $Taglib__bind_root_www（见 $__wds_brw_*）
            $bindRootWwwRaw = \trim((string) ($attributes['bind-root-www'] ?? 'false'));
            $bindRootWwwLower = \strtolower($bindRootWwwRaw);
            $bindRootWwwLiteralTrue = \in_array($bindRootWwwLower, ['true', '1', 'yes'], true);
            $bindRootWwwLiteralFalse = \in_array($bindRootWwwLower, ['false', '0', 'no'], true);
            $bindRootWwwIsLiteral = $bindRootWwwLiteralTrue || $bindRootWwwLiteralFalse;
            
            /** @var Url $url */
            $url = w_obj(Url::class);
            $epPath = $url->getBackendUrlPath($urlPath);
            $manualCreatePath = $url->getBackendUrlPath('websites/admin/domain/create-manual-domain');
            $dnsAccountsPath = $url->getBackendUrlPath('websites/admin/domain/get-dns-accounts');

            $attributes['url'] = $epPath;
            $attributes['limit'] = $limit;
            
            // 解析所有属性
            $code = \Weline\Taglib\Taglib::attributes($attributes);
            $idAttr = (string) ($attributes['id'] ?? 'domain_select');
            $brwSuffix = \preg_replace('/[^a-zA-Z0-9_]/', '_', $idAttr) ?: 'domain_select';
            if ($brwSuffix !== '' && \preg_match('/^[0-9]/', $brwSuffix)) {
                $brwSuffix = 'ds_' . $brwSuffix;
            }
            $imPhp = $isMultiple ? 'true' : 'false';
            $html = [];
            if ($bindRootWwwIsLiteral) {
                $effectiveBrw = $isMultiple && $bindRootWwwLiteralTrue;
                $html[] = '<?php ' . $code . ' $__wds_brw_' . $brwSuffix . ' = ' . ($effectiveBrw ? 'true' : 'false') . '; ?>';
            } else {
                $html[] = '<?php ' . $code . ' $__wds_brw_' . $brwSuffix . ' = ' . $imPhp . ' && in_array(strtolower(trim((string)($Taglib__bind_root_www ?? \'false\'))), [\'true\', \'1\', \'yes\'], true); ?>';
            }
            
            $html[] = '<style>';
            $html[] = '.weline-domain-select {';
            $html[] = '  position: relative;';
            $html[] = '}';
            $html[] = '.weline-domain-select-trigger {';
            $html[] = '  display: flex;';
            $html[] = '  align-items: center;';
            $html[] = '  justify-content: space-between;';
            $html[] = '  width: 100%;';
            $html[] = '  min-height: 38px;';
            $html[] = '  padding: 0.375rem 0.75rem;';
            $html[] = '  background-color: var(--backend-color-card-bg, #fff);';
            $html[] = '  border: 1px solid var(--backend-color-border-default, #ced4da);';
            $html[] = '  border-radius: var(--backend-border-radius-sm, 0.25rem);';
            $html[] = '  color: var(--backend-color-text-primary, #212529);';
            $html[] = '  cursor: pointer;';
            $html[] = '  transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;';
            $html[] = '}';
            $html[] = '.weline-domain-select-trigger:hover {';
            $html[] = '  border-color: var(--backend-color-primary, #556ee6);';
            $html[] = '}';
            $html[] = '.weline-domain-select-trigger:focus {';
            $html[] = '  border-color: var(--backend-color-primary, #556ee6);';
            $html[] = '  box-shadow: 0 0 0 0.2rem rgba(85, 110, 230, 0.25);';
            $html[] = '  outline: none;';
            $html[] = '}';
            $html[] = '.weline-domain-select-tags {';
            $html[] = '  display: flex;';
            $html[] = '  flex-wrap: wrap;';
            $html[] = '  gap: 0.25rem;';
            $html[] = '  flex: 1;';
            $html[] = '  align-items: center;';
            $html[] = '}';
            $html[] = '.weline-domain-select-tag {';
            $html[] = '  display: inline-flex;';
            $html[] = '  align-items: center;';
            $html[] = '  padding: 0.125rem 0.5rem;';
            $html[] = '  font-size: 0.75rem;';
            $html[] = '  background-color: var(--backend-color-primary-bg-subtle, rgba(85, 110, 230, 0.1));';
            $html[] = '  color: var(--backend-color-primary, #556ee6);';
            $html[] = '  border-radius: var(--backend-border-radius-sm, 0.25rem);';
            $html[] = '  white-space: nowrap;';
            $html[] = '}';
            $html[] = '.weline-domain-select-tag-remove {';
            $html[] = '  margin-left: 0.25rem;';
            $html[] = '  cursor: pointer;';
            $html[] = '  opacity: 0.7;';
            $html[] = '  font-size: 0.875rem;';
            $html[] = '}';
            $html[] = '.weline-domain-select-tag-remove:hover {';
            $html[] = '  opacity: 1;';
            $html[] = '}';
            $html[] = '.weline-domain-select-dropdown {';
            $html[] = '  position: absolute;';
            $html[] = '  left: 0;';
            $html[] = '  right: 0;';
            $html[] = '  z-index: 1060;';
            $html[] = '  padding: 0.75rem;';
            $html[] = '  background-color: var(--backend-color-card-bg, #fff);';
            $html[] = '  border: 1px solid var(--backend-color-border-default, #dee2e6);';
            $html[] = '  border-radius: var(--backend-border-radius-md, 0.375rem);';
            $html[] = '  box-shadow: var(--backend-shadow-lg, 0 0.5rem 1rem rgba(0, 0, 0, 0.15));';
            $html[] = '}';
            $html[] = '.weline-domain-select-search {';
            $html[] = '  width: 100%;';
            $html[] = '  padding: 0.5rem 0.75rem;';
            $html[] = '  margin-bottom: 0.5rem;';
            $html[] = '  background-color: var(--backend-color-card-bg);';
            $html[] = '  border: 1px solid var(--backend-color-border-default);';
            $html[] = '  border-radius: var(--backend-border-radius-sm, 0.25rem);';
            $html[] = '  color: var(--backend-color-text-primary);';
            $html[] = '}';
            $html[] = '.weline-domain-select-search:focus {';
            $html[] = '  border-color: var(--backend-color-primary, #556ee6);';
            $html[] = '  outline: none;';
            $html[] = '}';
            $html[] = '.weline-domain-select-list {';
            $html[] = '  max-height: 300px;';
            $html[] = '  overflow-y: auto;';
            $html[] = '  border: 1px solid var(--backend-color-border-default, #dee2e6);';
            $html[] = '  border-radius: var(--backend-border-radius-sm, 0.25rem);';
            $html[] = '  background-color: var(--backend-color-card-bg, #fff);';
            $html[] = '}';
            $html[] = '.weline-domain-select-group-label {';
            $html[] = '  padding: 0.5rem 0.75rem;';
            $html[] = '  font-weight: 600;';
            $html[] = '  font-size: 0.75rem;';
            $html[] = '  text-transform: uppercase;';
            $html[] = '  color: var(--backend-color-text-secondary, #6c757d);';
            $html[] = '  background-color: var(--backend-color-bg-secondary, #f8f9fa);';
            $html[] = '  border-bottom: 1px solid var(--backend-color-border-default, #dee2e6);';
            $html[] = '}';
            $html[] = '.weline-domain-select-item {';
            $html[] = '  padding: 0.5rem 0.75rem;';
            $html[] = '  cursor: pointer;';
            $html[] = '  border-bottom: 1px solid var(--backend-color-border-light, #e9ecef);';
            $html[] = '  color: var(--backend-color-text-primary, #212529);';
            $html[] = '  transition: background-color 0.15s ease;';
            $html[] = '}';
            $html[] = '.weline-domain-select-item:last-child {';
            $html[] = '  border-bottom: none;';
            $html[] = '}';
            $html[] = '.weline-domain-select-item:hover {';
            $html[] = '  background-color: var(--backend-color-bg-tertiary);';
            $html[] = '}';
            $html[] = '.weline-domain-select-item.active {';
            $html[] = '  background-color: var(--backend-color-bg-secondary);';
            $html[] = '  color: var(--backend-color-primary);';
            $html[] = '}';
            $html[] = '.weline-domain-select-item.selected {';
            $html[] = '  background-color: var(--backend-color-primary-bg-subtle, rgba(85, 110, 230, 0.1));';
            $html[] = '}';
            $html[] = '.weline-domain-select-checkbox {';
            $html[] = '  margin-right: 0.5rem;';
            $html[] = '  accent-color: var(--backend-color-primary, #556ee6);';
            $html[] = '}';
            $html[] = '.weline-domain-select-actions {';
            $html[] = '  display: flex;';
            $html[] = '  gap: 0.5rem;';
            $html[] = '  padding: 0.5rem;';
            $html[] = '  border-top: 1px solid var(--backend-color-border-default, #dee2e6);';
            $html[] = '  margin-top: 0.5rem;';
            $html[] = '}';
            $html[] = '.weline-domain-select-btn {';
            $html[] = '  flex: 1;';
            $html[] = '  padding: 0.375rem 0.75rem;';
            $html[] = '  font-size: 0.875rem;';
            $html[] = '  border: none;';
            $html[] = '  border-radius: var(--backend-border-radius-sm, 0.25rem);';
            $html[] = '  cursor: pointer;';
            $html[] = '  transition: background-color 0.15s ease;';
            $html[] = '}';
            $html[] = '.weline-domain-select-btn-primary {';
            $html[] = '  background-color: var(--backend-color-primary, #556ee6);';
            $html[] = '  color: #fff;';
            $html[] = '}';
            $html[] = '.weline-domain-select-btn-primary:hover {';
            $html[] = '  background-color: var(--backend-color-primary-hover, #4857d4);';
            $html[] = '}';
            $html[] = '.weline-domain-select-btn-secondary {';
            $html[] = '  background-color: var(--backend-color-bg-secondary, #f8f9fa);';
            $html[] = '  color: var(--backend-color-text-primary, #212529);';
            $html[] = '}';
            $html[] = '.weline-domain-select-btn-secondary:hover {';
            $html[] = '  background-color: var(--backend-color-bg-tertiary, #e9ecef);';
            $html[] = '}';
            $html[] = '.weline-domain-select-item-desc {';
            $html[] = '  font-size: 0.75rem;';
            $html[] = '  color: var(--backend-color-text-muted, #adb5bd);';
            $html[] = '}';
            $html[] = '.weline-domain-select-loading,';
            $html[] = '.weline-domain-select-empty {';
            $html[] = '  padding: 1rem;';
            $html[] = '  text-align: center;';
            $html[] = '  color: var(--backend-color-text-secondary, #6c757d);';
            $html[] = '}';
            $html[] = '.weline-domain-select-hint {';
            $html[] = '  margin-top: 0.25rem;';
            $html[] = '  font-size: 0.75rem;';
            $html[] = '  color: var(--backend-color-text-muted, #adb5bd);';
            $html[] = '}';
            $html[] = '</style>';

            // HTML 结构（data-website-id 由页面渲染时写入，编辑时供 loadData 读取以包含本站已绑定域名）
            $multipleAttr = $isMultiple ? 'true' : 'false';
            $dataWebsiteId = ($websiteIdAttr !== '' && !str_contains($websiteIdAttr, '<?='))
                ? ' data-website-id="' . (int) $websiteIdAttr . '"'
                : ' data-website-id="' . $websiteIdAttr . '"';
            $html[] = '<div class="weline-domain-select ' . htmlspecialchars($class) . '" style="' . htmlspecialchars($style) . '" id="<?= htmlspecialchars($Taglib__id) ?>_wrapper" data-multiple="' . $multipleAttr . '"' . $dataWebsiteId . '>';
            $html[] = '  <button type="button" class="weline-domain-select-trigger" id="<?= htmlspecialchars($Taglib__id) ?>_trigger">';
            if ($isMultiple) {
                $html[] = '    <div class="weline-domain-select-tags" id="<?= htmlspecialchars($Taglib__id) ?>_tags">';
                $html[] = '      <span class="weline-domain-select-placeholder" id="<?= htmlspecialchars($Taglib__id) ?>_placeholder">';
                $html[] = '        <i class="mdi mdi-domain me-1"></i>';
                $html[] = '        <span><?php $_display = trim($Taglib__display, "\'\""); if($_display !== ""): echo htmlspecialchars($_display); else: ?>' . htmlspecialchars(__('点击选择域名（可多选）')) . '<?php endif; ?></span>';
                $html[] = '      </span>';
                $html[] = '    </div>';
            } else {
                $html[] = '    <span>';
                $html[] = '      <i class="mdi mdi-domain me-1"></i>';
                $html[] = '      <span id="<?= htmlspecialchars($Taglib__id) ?>_display"><?php $_display = trim($Taglib__display, "\'\""); if($_display !== ""): echo htmlspecialchars($_display); else: ?>' . htmlspecialchars(__('请选择域名')) . '<?php endif; ?></span>';
                $html[] = '    </span>';
            }
            $html[] = '    <i class="mdi mdi-chevron-down"></i>';
            $html[] = '  </button>';
            $html[] = '  <input type="hidden" id="<?= htmlspecialchars($Taglib__id) ?>_value" name="<?= htmlspecialchars($Taglib__name) ?>" value="<?= htmlspecialchars($Taglib__value) ?>">';
            $html[] = '  <div id="<?= htmlspecialchars($Taglib__id) ?>_dropdown" class="weline-domain-select-dropdown" style="display:none;">';
            $html[] = '    <input type="text" class="weline-domain-select-search" id="<?= htmlspecialchars($Taglib__id) ?>_search" placeholder="' . htmlspecialchars($placeholder) . '" autocomplete="off">';
            $html[] = '    <div class="weline-domain-select-list">';
            $html[] = '      <div id="<?= htmlspecialchars($Taglib__id) ?>_loading" class="weline-domain-select-loading" style="display:none;">' . __('加载中...') . '</div>';
            $html[] = '      <div id="<?= htmlspecialchars($Taglib__id) ?>_list"></div>';
            $html[] = '    </div>';
            if ($isMultiple) {
                $html[] = '    <div class="weline-domain-select-actions">';
                $html[] = '      <button type="button" class="weline-domain-select-btn weline-domain-select-btn-secondary" id="<?= htmlspecialchars($Taglib__id) ?>_clear">' . __('清空') . '</button>';
                $html[] = '      <button type="button" class="weline-domain-select-btn weline-domain-select-btn-primary" id="<?= htmlspecialchars($Taglib__id) ?>_confirm">' . __('确定') . '</button>';
                $html[] = '    </div>';
            }
            $html[] = '    <div class="weline-domain-select-actions">';
            $html[] = '      <button type="button" class="weline-domain-select-btn weline-domain-select-btn-secondary" id="<?= htmlspecialchars($Taglib__id) ?>_manual_create_btn"><i class="mdi mdi-plus-circle-outline me-1"></i>' . __('新建域名') . '</button>';
            $html[] = '    </div>';
            $html[] = '  </div>';
            if ($isMultiple) {
                $hintLong = \htmlspecialchars(__('点击选择多个域名，选中后点击确定。根域与 www 子域成对绑定：选其一将自动勾选另一（若列表中存在）。'), ENT_QUOTES, 'UTF-8');
                $hintShort = \htmlspecialchars(__('点击选择多个域名，选中后点击确定'), ENT_QUOTES, 'UTF-8');
                $html[] = '  <small class="weline-domain-select-hint"><?php if ($__wds_brw_' . $brwSuffix . '): ?>' . $hintLong . '<?php else: ?>' . $hintShort . '<?php endif; ?></small>';
            } else {
                $html[] = '  <small class="weline-domain-select-hint">' . __('点击选择域名，选择后将自动填充相关字段') . '</small>';
            }
            $html[] = '</div>';
            $html[] = '<div class="offcanvas offcanvas-end" tabindex="-1" id="<?= htmlspecialchars($Taglib__id) ?>_manual_create_offcanvas">';
            $html[] = '  <div class="offcanvas-header">';
            $html[] = '    <h5 class="offcanvas-title"><lang>新建域名</lang></h5>';
            $html[] = '    <button type="button" class="btn-close text-reset" data-bs-dismiss="offcanvas" aria-label="Close"></button>';
            $html[] = '  </div>';
            $html[] = '  <div class="offcanvas-body">';
            $html[] = '    <div class="mb-3">';
            $html[] = '      <label class="form-label"><lang>域名</lang></label>';
            $html[] = '      <input type="text" class="form-control" id="<?= htmlspecialchars($Taglib__id) ?>_manual_domain" placeholder="' . __('如：www.example.com') . '">';
            $html[] = '    </div>';
            $html[] = '    <div class="mb-3">';
            $html[] = '      <label class="form-label"><lang>描述</lang></label>';
            $html[] = '      <input type="text" class="form-control" id="<?= htmlspecialchars($Taglib__id) ?>_manual_description" placeholder="' . __('可选') . '">';
            $html[] = '    </div>';
            $html[] = '    <div class="row">';
            $html[] = '      <div class="col-6 mb-3">';
            $html[] = '        <label class="form-label"><lang>DNS 供应商/账户</lang></label>';
            $html[] = '        <select class="form-select" id="<?= htmlspecialchars($Taglib__id) ?>_manual_dns_account"><option value="0">' . __('默认不设置') . '</option></select>';
            $html[] = '        <div class="form-text"><lang>选择 DNS 服务商及账户，用于解析与证书</lang></div>';
            $html[] = '      </div>';
            $html[] = '      <div class="col-6 mb-3">';
            $html[] = '        <label class="form-label"><lang>CDN 供应商/账户</lang></label>';
            $html[] = '        <select class="form-select" id="<?= htmlspecialchars($Taglib__id) ?>_manual_cdn_account"><option value="0">' . __('默认不设置') . '</option></select>';
            $html[] = '        <div class="form-text"><lang>可选，如 Cloudflare 等</lang></div>';
            $html[] = '      </div>';
            $html[] = '    </div>';
            $html[] = '    <div class="mb-3">';
            $html[] = '      <label class="form-label"><lang>HTTPS处理方式</lang></label>';
            $html[] = '      <select class="form-select" id="<?= htmlspecialchars($Taglib__id) ?>_manual_https_mode">';
            $html[] = '        <option value="none">' . __('暂不处理') . '</option>';
            $html[] = '        <option value="auto">' . __('自动申请证书') . '</option>';
            $html[] = '        <option value="manual">' . __('手动导入证书') . '</option>';
            $html[] = '      </select>';
            $html[] = '    </div>';
            $html[] = '    <div id="<?= htmlspecialchars($Taglib__id) ?>_manual_https_auto_box" style="display:none;" class="mb-3">';
            $html[] = '      <label class="form-label"><lang>联系邮箱</lang></label>';
            $html[] = '      <input type="email" class="form-control" id="<?= htmlspecialchars($Taglib__id) ?>_manual_https_email" placeholder="' . __('可选，默认 admin@根域') . '">';
            $html[] = '    </div>';
            $html[] = '    <div id="<?= htmlspecialchars($Taglib__id) ?>_manual_https_manual_box" style="display:none;">';
            $html[] = '      <div class="mb-2"><small class="text-muted"><lang>支持 PEM/CRT/KEY/PFX/P12 上传，或直接粘贴文本</lang></small></div>';
            $html[] = '      <div class="mb-2"><input type="file" class="form-control" id="<?= htmlspecialchars($Taglib__id) ?>_manual_cert_file" accept=".pem,.crt"></div>';
            $html[] = '      <div class="mb-2"><input type="file" class="form-control" id="<?= htmlspecialchars($Taglib__id) ?>_manual_key_file" accept=".key,.pem"></div>';
            $html[] = '      <div class="mb-2"><input type="file" class="form-control" id="<?= htmlspecialchars($Taglib__id) ?>_manual_chain_file" accept=".pem,.crt"></div>';
            $html[] = '      <div class="mb-2"><input type="file" class="form-control" id="<?= htmlspecialchars($Taglib__id) ?>_manual_pfx_file" accept=".pfx,.p12"></div>';
            $html[] = '      <div class="mb-2"><input type="password" class="form-control" id="<?= htmlspecialchars($Taglib__id) ?>_manual_pfx_password" placeholder="' . __('PFX/P12 密码（可选）') . '"></div>';
            $html[] = '      <div class="mb-2"><textarea class="form-control" rows="3" id="<?= htmlspecialchars($Taglib__id) ?>_manual_cert_text" placeholder="-----BEGIN CERTIFICATE-----"></textarea></div>';
            $html[] = '      <div class="mb-2"><textarea class="form-control" rows="3" id="<?= htmlspecialchars($Taglib__id) ?>_manual_key_text" placeholder="-----BEGIN PRIVATE KEY-----"></textarea></div>';
            $html[] = '      <div class="mb-2"><textarea class="form-control" rows="2" id="<?= htmlspecialchars($Taglib__id) ?>_manual_chain_text" placeholder="' . __('可选：中间证书链') . '"></textarea></div>';
            $html[] = '    </div>';
            $html[] = '    <div class="d-flex justify-content-end mt-3">';
            $html[] = '      <button type="button" class="btn btn-primary" id="<?= htmlspecialchars($Taglib__id) ?>_manual_submit"><lang>创建域名</lang></button>';
            $html[] = '    </div>';
            $html[] = '  </div>';
            $html[] = '</div>';

            // JavaScript
            $t_no_match = addslashes(__('未找到匹配的域名'));
            $t_load_fail = addslashes(__('加载失败'));
            $t_no_domain = addslashes(__('暂无可用域名，请先添加'));
            $t_default = addslashes(__('请选择域名'));
            $t_selected = addslashes(__('已选择 %s 个域名'));
            
            $html[] = '<script>(function(){';
            $html[] = '"use strict";';
            $html[] = 'var ep = ' . json_encode($epPath) . ';';
            $html[] = 'var manualCreateApi = ' . json_encode($manualCreatePath) . ';';
            $html[] = 'var dnsAccountsApi = ' . json_encode($dnsAccountsPath) . ';';
            $html[] = 'var id = <?= json_encode($Taglib__id) ?>;';
            $html[] = 'var limit = ' . $limit . ';';
            $html[] = 'var isMultiple = ' . ($isMultiple ? 'true' : 'false') . ';';
            $html[] = 'var bindRootWww = <?php echo ($__wds_brw_' . $brwSuffix . ') ? \'true\' : \'false\'; ?>;';
            $html[] = 'var siteReadyOnly = ' . ($siteReadyOnly ? 'true' : 'false') . ';';
            $html[] = 'var valueType = ' . json_encode($valueType) . ';';
            $html[] = 'var websiteId = ' . (str_contains($websiteIdAttr, '<?=')
                ? $websiteIdAttr
                : (string) $websiteId) . ';';
            $html[] = 'var autoFillCode = ' . json_encode($autoFillCode) . ';';
            $html[] = 'var autoFillUrl = ' . json_encode($autoFillUrl) . ';';
            $html[] = 'var autoFillName = ' . json_encode($autoFillName) . ';';
            $html[] = 'var autoFillAddress = ' . json_encode($autoFillAddress) . ';';
            $html[] = 'var onSelectFn = ' . json_encode($onSelect) . ';';
            $html[] = '';
            $html[] = 'var trigger = document.getElementById(id + "_trigger");';
            $html[] = 'var dropdown = document.getElementById(id + "_dropdown");';
            $html[] = 'var list = document.getElementById(id + "_list");';
            $html[] = 'var loading = document.getElementById(id + "_loading");';
            $html[] = 'var search = document.getElementById(id + "_search");';
            $html[] = 'var hidden = document.getElementById(id + "_value");';
            $html[] = 'var display = isMultiple ? null : document.getElementById(id + "_display");';
            $html[] = 'var tagsContainer = isMultiple ? document.getElementById(id + "_tags") : null;';
            $html[] = 'var placeholder = isMultiple ? document.getElementById(id + "_placeholder") : null;';
            $html[] = 'var confirmBtn = isMultiple ? document.getElementById(id + "_confirm") : null;';
            $html[] = 'var clearBtn = isMultiple ? document.getElementById(id + "_clear") : null;';
            $html[] = 'var manualCreateBtn = document.getElementById(id + "_manual_create_btn");';
            $html[] = 'var manualOffcanvasEl = document.getElementById(id + "_manual_create_offcanvas");';
            $html[] = 'var manualDomainInput = document.getElementById(id + "_manual_domain");';
            $html[] = 'var manualDescInput = document.getElementById(id + "_manual_description");';
            $html[] = 'var manualDnsSelect = document.getElementById(id + "_manual_dns_account");';
            $html[] = 'var manualCdnSelect = document.getElementById(id + "_manual_cdn_account");';
            $html[] = 'var manualHttpsMode = document.getElementById(id + "_manual_https_mode");';
            $html[] = 'var manualHttpsAutoBox = document.getElementById(id + "_manual_https_auto_box");';
            $html[] = 'var manualHttpsManualBox = document.getElementById(id + "_manual_https_manual_box");';
            $html[] = 'var manualHttpsEmail = document.getElementById(id + "_manual_https_email");';
            $html[] = 'var manualSubmitBtn = document.getElementById(id + "_manual_submit");';
            $html[] = '';
            $html[] = 'var cache = null;';
            $html[] = 'var selectedDomains = [];';
            $html[] = 'var floatingOpened = false;';
            $html[] = 'if (!window.WelineSmartDropdown) {';
            $html[] = '  window.WelineSmartDropdown = (function(){';
            $html[] = '    function compute(anchorRect, panelRect, cfg){';
            $html[] = '      var margin = cfg.margin || 8, gap = cfg.gap || 4;';
            $html[] = '      var vw = window.innerWidth || document.documentElement.clientWidth || 1200;';
            $html[] = '      var vh = window.innerHeight || document.documentElement.clientHeight || 900;';
            $html[] = '      var width = Math.max(cfg.minWidth || 0, panelRect.width || anchorRect.width);';
            $html[] = '      var height = panelRect.height || 0;';
            $html[] = '      var bottomSpace = vh - anchorRect.bottom - margin;';
            $html[] = '      var topSpace = anchorRect.top - margin;';
            $html[] = '      var openUp = bottomSpace < Math.min(cfg.preferredHeight || 320, height) && topSpace > bottomSpace;';
            $html[] = '      var top = openUp ? (anchorRect.top - height - gap) : (anchorRect.bottom + gap);';
            $html[] = '      if (top < margin) top = margin;';
            $html[] = '      if (top + height > vh - margin) top = Math.max(margin, vh - margin - height);';
            $html[] = '      var left = anchorRect.left;';
            $html[] = '      if (left + width > vw - margin) left = Math.max(margin, vw - margin - width);';
            $html[] = '      if (left < margin) left = margin;';
            $html[] = '      return { left:left, top:top, width:width, maxHeight:Math.max(180, vh - margin * 2), openUp:openUp };';
            $html[] = '    }';
            $html[] = '    return {';
            $html[] = '      mount:function(anchor,panel,cfg){';
            $html[] = '        cfg = cfg || {}; if (!anchor || !panel) return null;';
            $html[] = '        if (!panel.__welineOriginalParent) { panel.__welineOriginalParent = panel.parentNode || null; panel.__welineOriginalNext = panel.nextSibling || null; }';
            $html[] = '        panel.style.position = "fixed"; panel.style.zIndex = String(cfg.zIndex || 4000); panel.style.left = "0px"; panel.style.top = "0px";';
            $html[] = '        panel.style.minWidth = Math.max(cfg.minWidth || 0, Math.round(anchor.getBoundingClientRect().width)) + "px"; panel.style.maxWidth = "calc(100vw - 16px)";';
            $html[] = '        document.body.appendChild(panel); panel.style.display = "block";';
            $html[] = '        var rect = anchor.getBoundingClientRect(); var pr = panel.getBoundingClientRect(); var next = compute(rect, pr, cfg);';
            $html[] = '        panel.style.left = Math.round(next.left) + "px"; panel.style.top = Math.round(next.top) + "px"; panel.style.width = Math.round(next.width) + "px"; panel.style.maxHeight = Math.round(next.maxHeight) + "px";';
            $html[] = '        return next;';
            $html[] = '      },';
            $html[] = '      unmount:function(panel){';
            $html[] = '        if (!panel) return; panel.style.display = "none";';
            $html[] = '        if (panel.__welineOriginalParent) {';
            $html[] = '          if (panel.__welineOriginalNext && panel.__welineOriginalNext.parentNode === panel.__welineOriginalParent) { panel.__welineOriginalParent.insertBefore(panel, panel.__welineOriginalNext); }';
            $html[] = '          else { panel.__welineOriginalParent.appendChild(panel); }';
            $html[] = '        }';
            $html[] = '      }';
            $html[] = '    };';
            $html[] = '  })();';
            $html[] = '}';
            $html[] = '';
            $html[] = 'function notify(msg, type) {';
            $html[] = '  if (window.BackendToast && typeof BackendToast[type || "info"] === "function") {';
            $html[] = '    BackendToast[type || "info"](msg);';
            $html[] = '    return;';
            $html[] = '  }';
            $html[] = '  if (window.AdminToast && typeof AdminToast[type || "info"] === "function") {';
            $html[] = '    AdminToast[type || "info"](msg);';
            $html[] = '  }';
            $html[] = '}';
            $html[] = '';
            $html[] = 'function toggleManualHttpsFields() {';
            $html[] = '  if (!manualHttpsMode) return;';
            $html[] = '  var mode = (manualHttpsMode.value || "none").toLowerCase();';
            $html[] = '  if (manualHttpsAutoBox) manualHttpsAutoBox.style.display = (mode === "auto") ? "" : "none";';
            $html[] = '  if (manualHttpsManualBox) manualHttpsManualBox.style.display = (mode === "manual") ? "" : "none";';
            $html[] = '}';
            $html[] = '';
            $html[] = 'function loadManualAccounts() {';
            $html[] = '  if (!manualDnsSelect || !manualCdnSelect) return;';
            $html[] = '  fetch(dnsAccountsApi).then(function(r){ return r.json(); }).then(function(data){';
            $html[] = '    if (!data || data.code !== 200 || !data.data) return;';
            $html[] = '    var dnsAccounts = data.data.dns_accounts || [];';
            $html[] = '    var cdnAccounts = data.data.cdn_accounts || [];';
            $html[] = '    manualDnsSelect.innerHTML = \'<option value="0">' . addslashes(__('默认不设置')) . '</option>\';';
            $html[] = '    manualCdnSelect.innerHTML = \'<option value="0">' . addslashes(__('默认不设置')) . '</option>\';';
            $html[] = '    dnsAccounts.forEach(function(acc){ var opt=document.createElement("option"); opt.value=acc.account_id; opt.textContent=(acc.registrar_name||acc.registrar_code||"") + " - " + (acc.name||acc.account_name||acc.account_id); manualDnsSelect.appendChild(opt); });';
            $html[] = '    cdnAccounts.forEach(function(acc){ var opt=document.createElement("option"); opt.value=acc.account_id; opt.textContent=(acc.registrar_name||acc.registrar_code||"") + " - " + (acc.name||acc.account_name||acc.account_id); manualCdnSelect.appendChild(opt); });';
            $html[] = '  }).catch(function(){});';
            $html[] = '}';
            $html[] = '';
            
            // 域名处理函数
            $html[] = 'function domainToCode(domain) {';
            $html[] = '  return domain.toLowerCase().replace(/\\./g, "_");';
            $html[] = '}';
            $html[] = '';
            $html[] = 'function isSubdomain(domain) {';
            $html[] = '  var parts = domain.split(".");';
            $html[] = '  if (parts.length <= 2) return false;';
            $html[] = '  if (parts.length === 3 && parts[0].toLowerCase() === "www") return false;';
            $html[] = '  return true;';
            $html[] = '}';
            $html[] = '';
            $html[] = 'function domainToUrl(domain) {';
            $html[] = '  domain = domain.toLowerCase().trim();';
            $html[] = '  var port = window.location.port;';
            $html[] = '  var url = "https://" + domain;';
            $html[] = '  if (port && port !== "443" && port !== "80") {';
            $html[] = '    url += ":" + port;';
            $html[] = '  }';
            $html[] = '  return url;';
            $html[] = '}';
            $html[] = '';
            $html[] = 'function domainToName(domain) {';
            $html[] = '  var parts = domain.split(".");';
            $html[] = '  if (parts.length >= 2) {';
            $html[] = '    var baseName = parts.slice(-2, -1)[0];';
            $html[] = '    return baseName.charAt(0).toUpperCase() + baseName.slice(1);';
            $html[] = '  }';
            $html[] = '  return domain;';
            $html[] = '}';
            $html[] = '';
            $html[] = 'function getPairedRootWwwFqdn(domain) {';
            $html[] = '  var d = (domain || "").toLowerCase().trim();';
            $html[] = '  if (!d) return "";';
            $html[] = '  if (d.indexOf("www.") === 0) return d.substring(4);';
            $html[] = '  return "www." + d;';
            $html[] = '}';
            $html[] = '';
            $html[] = 'function findDomainInCacheFqdn(domain) {';
            $html[] = '  var want = (domain || "").toLowerCase();';
            $html[] = '  if (!want) return null;';
            $html[] = '  var found = null;';
            $html[] = '  (cache || []).forEach(function(group) {';
            $html[] = '    (group.options || []).forEach(function(opt) {';
            $html[] = '      if (opt.domain && String(opt.domain).toLowerCase() === want) found = opt.domain;';
            $html[] = '    });';
            $html[] = '  });';
            $html[] = '  return found;';
            $html[] = '}';
            $html[] = '';
            $html[] = 'function syncPairedRootWww(anchorDomain, added) {';
            $html[] = '  if (!bindRootWww || !isMultiple) return;';
            $html[] = '  var pairKey = getPairedRootWwwFqdn(anchorDomain);';
            $html[] = '  if (!pairKey) return;';
            $html[] = '  var pairCanon = findDomainInCacheFqdn(pairKey);';
            $html[] = '  if (!pairCanon) return;';
            $html[] = '  if (String(pairCanon).toLowerCase() === String(anchorDomain || "").toLowerCase()) return;';
            $html[] = '  var idx = -1;';
            $html[] = '  for (var si = 0; si < selectedDomains.length; si++) {';
            $html[] = '    if (String(selectedDomains[si]).toLowerCase() === String(pairCanon).toLowerCase()) { idx = si; break; }';
            $html[] = '  }';
            $html[] = '  if (added) {';
            $html[] = '    if (idx === -1) selectedDomains.push(pairCanon);';
            $html[] = '  } else if (idx > -1) {';
            $html[] = '    selectedDomains.splice(idx, 1);';
            $html[] = '  }';
            $html[] = '}';
            $html[] = '';
            $html[] = 'function expandRootWwwPairsInSelection() {';
            $html[] = '  if (!bindRootWww || !isMultiple || !cache) return;';
            $html[] = '  var rounds = 0;';
            $html[] = '  while (rounds < 3) {';
            $html[] = '    rounds++;';
            $html[] = '    var before = selectedDomains.length;';
            $html[] = '    selectedDomains.slice().forEach(function(d) {';
            $html[] = '      syncPairedRootWww(d, true);';
            $html[] = '    });';
            $html[] = '    if (selectedDomains.length === before) break;';
            $html[] = '  }';
            $html[] = '}';
            $html[] = '';
            $html[] = 'function isDomainInSelection(d) {';
            $html[] = '  var dl = String(d || "").toLowerCase();';
            $html[] = '  if (!dl) return false;';
            $html[] = '  for (var i = 0; i < selectedDomains.length; i++) {';
            $html[] = '    if (String(selectedDomains[i]).toLowerCase() === dl) return true;';
            $html[] = '  }';
            $html[] = '  return false;';
            $html[] = '}';
            $html[] = '';
            $html[] = 'function syncListSelectionFromState() {';
            $html[] = '  if (!isMultiple || !list) return;';
            $html[] = '  list.querySelectorAll(".weline-domain-select-item").forEach(function(el) {';
            $html[] = '    var d = el.dataset.domain;';
            $html[] = '    if (!d) return;';
            $html[] = '    var sel = isDomainInSelection(d);';
            $html[] = '    if (sel) el.classList.add("selected"); else el.classList.remove("selected");';
            $html[] = '    var cb = el.querySelector(".weline-domain-select-checkbox");';
            $html[] = '    if (cb) cb.checked = sel;';
            $html[] = '  });';
            $html[] = '}';
            $html[] = '';
            
            // 更新显示（多选模式下更新标签）
            $html[] = 'function updateDisplay() {';
            $html[] = '  if (!isMultiple) return;';
            $html[] = '  tagsContainer.innerHTML = "";';
            $html[] = '  if (selectedDomains.length === 0) {';
            $html[] = '    tagsContainer.innerHTML = \'<span class="weline-domain-select-placeholder"><i class="mdi mdi-domain me-1"></i><span>' . addslashes(__('点击选择域名（可多选）')) . '</span></span>\';';
            $html[] = '    return;';
            $html[] = '  }';
            $html[] = '  selectedDomains.forEach(function(domain) {';
            $html[] = '    var tag = document.createElement("span");';
            $html[] = '    tag.className = "weline-domain-select-tag";';
            $html[] = '    tag.innerHTML = \'<i class="mdi mdi-web me-1"></i>\' + domain + \'<span class="weline-domain-select-tag-remove" data-domain="\' + domain + \'">&times;</span>\';';
            $html[] = '    tagsContainer.appendChild(tag);';
            $html[] = '  });';
            $html[] = '  bindTagRemove();';
            $html[] = '}';
            $html[] = '';
            $html[] = 'function bindTagRemove() {';
            $html[] = '  tagsContainer.querySelectorAll(".weline-domain-select-tag-remove").forEach(function(el) {';
            $html[] = '    el.addEventListener("click", function(e) {';
            $html[] = '      e.stopPropagation();';
            $html[] = '      var domain = this.dataset.domain;';
            $html[] = '      var idx = selectedDomains.indexOf(domain);';
            $html[] = '      if (idx === -1) {';
            $html[] = '        for (var ti = 0; ti < selectedDomains.length; ti++) {';
            $html[] = '          if (String(selectedDomains[ti]).toLowerCase() === String(domain).toLowerCase()) { idx = ti; break; }';
            $html[] = '        }';
            $html[] = '      }';
            $html[] = '      if (idx > -1) selectedDomains.splice(idx, 1);';
            $html[] = '      syncPairedRootWww(domain, false);';
            $html[] = '      updateHiddenValue();';
            $html[] = '      updateDisplay();';
            $html[] = '      doAutoFill();';
            $html[] = '      syncListSelectionFromState();';
            $html[] = '    });';
            $html[] = '  });';
            $html[] = '}';
            $html[] = '';
            $html[] = 'function updateHiddenValue() {';
            $html[] = '  if (isMultiple) {';
            $html[] = '    if (valueType === "pool_id") {';
            $html[] = '      // 根据 selectedDomains 查找对应的 pool_id';
            $html[] = '      var poolIds = [];';
            $html[] = '      (cache || []).forEach(function(group) {';
            $html[] = '        (group.options || []).forEach(function(opt) {';
            $html[] = '          if (selectedDomains.indexOf(opt.domain) > -1 && opt.pool_id) {';
            $html[] = '            poolIds.push(String(opt.pool_id));';
            $html[] = '          }';
            $html[] = '        });';
            $html[] = '      });';
            $html[] = '      hidden.value = poolIds.join(",");';
            $html[] = '    } else {';
            $html[] = '      hidden.value = selectedDomains.join(",");';
            $html[] = '    }';
            $html[] = '  }';
            $html[] = '  try { hidden.dispatchEvent(new Event("change")); } catch(e) {}';
            $html[] = '}';
            $html[] = '';
            $html[] = 'function getPreferredDomainForUrl(domains) {';
            $html[] = '  if (!domains || domains.length === 0) return "";';
            $html[] = '  if (domains.length === 1) return domains[0];';
            $html[] = '  for (var i = 0; i < domains.length; i++) {';
            $html[] = '    var d = domains[i];';
            $html[] = '    if (d.startsWith("www.")) {';
            $html[] = '      var root = d.substring(4);';
            $html[] = '      if (domains.indexOf(root) > -1) return d;';
            $html[] = '    }';
            $html[] = '  }';
            $html[] = '  return domains[0];';
            $html[] = '}';
            $html[] = '';
            $html[] = 'function doAutoFill() {';
            $html[] = '  var domains = isMultiple ? selectedDomains : [hidden.value];';
            $html[] = '  if (domains.length === 0) return;';
            $html[] = '  var preferredDomain = getPreferredDomainForUrl(domains);';
            $html[] = '  var firstDomain = domains[0];';
            $html[] = '  if (autoFillCode) {';
            $html[] = '    var codeEl = document.getElementById(autoFillCode);';
            $html[] = '    if (codeEl) codeEl.value = domainToCode(firstDomain);';
            $html[] = '  }';
            $html[] = '  if (autoFillUrl) {';
            $html[] = '    var urlEl = document.getElementById(autoFillUrl);';
            $html[] = '    if (urlEl) urlEl.value = domainToUrl(preferredDomain);';
            $html[] = '  }';
            $html[] = '  if (autoFillName) {';
            $html[] = '    var nameEl = document.getElementById(autoFillName);';
            $html[] = '    if (nameEl) nameEl.value = domainToName(firstDomain);';
            $html[] = '  }';
            $html[] = '  if (autoFillAddress) {';
            $html[] = '    var addrEl = document.getElementById(autoFillAddress);';
            $html[] = '    if (addrEl) {';
            $html[] = '      var port = window.location.port;';
            $html[] = '      var portSuffix = (port && port !== "443" && port !== "80") ? ":" + port : "";';
            $html[] = '      var addressList = domains.map(function(d) { return "https://" + d + portSuffix; });';
            $html[] = '      addrEl.value = addressList.join("\\n");';
            $html[] = '    }';
            $html[] = '  }';
            $html[] = '  if (onSelectFn && typeof window[onSelectFn] === "function") {';
            $html[] = '    window[onSelectFn](isMultiple ? domains : firstDomain, { multiple: isMultiple });';
            $html[] = '  }';
            $html[] = '}';
            $html[] = '';
            
            // 渲染函数
            $html[] = 'function render(groups) {';
            $html[] = '  if (!groups || !groups.length) {';
            $html[] = '    list.innerHTML = \'<div class="weline-domain-select-empty">' . $t_no_match . '</div>\';';
            $html[] = '    return;';
            $html[] = '  }';
            $html[] = '  var html = "";';
            $html[] = '  groups.forEach(function(group) {';
            $html[] = '    html += \'<div class="weline-domain-select-group-label">\' + (group.label || "") + \'</div>\';';
            $html[] = '    if (group.options && group.options.length) {';
            $html[] = '      group.options.forEach(function(opt) {';
            $html[] = '        var descParts = [];';
            $html[] = '        if (opt.https_status === "valid") { descParts.push(\'<span style="color:var(--backend-color-success)">HTTPS</span>\'); }';
            $html[] = '        if (opt.site_ready) { descParts.push(\'<span style="color:var(--backend-color-success)">' . addslashes(__('可建站')) . '</span>\'); }';
            $html[] = '        if (opt.https_expires_at) { descParts.push(\'' . addslashes(__('证书到期')) . ': \' + opt.https_expires_at); }';
            $html[] = '        var desc = descParts.length > 0 ? \'<div class="weline-domain-select-item-desc">\' + descParts.join(" | ") + \'</div>\' : "";';
            $html[] = '        var isSelected = isDomainInSelection(opt.domain);';
            $html[] = '        var selectedClass = isSelected ? " selected" : "";';
            $html[] = '        var poolId = opt.pool_id || "";';
            $html[] = '        html += \'<div class="weline-domain-select-item\' + selectedClass + \'" data-domain="\' + opt.domain + \'" data-poolid="\' + poolId + \'">\';';
            $html[] = '        if (isMultiple) {';
            $html[] = '          html += \'<input type="checkbox" class="weline-domain-select-checkbox"\' + (isSelected ? " checked" : "") + \'>\';';
            $html[] = '        }';
            $html[] = '        html += \'<i class="mdi mdi-web me-1"></i>\' + opt.domain + desc;';
            $html[] = '        html += \'</div>\';';
            $html[] = '      });';
            $html[] = '    }';
            $html[] = '  });';
            $html[] = '  list.innerHTML = html;';
            $html[] = '  bindItems();';
            $html[] = '}';
            $html[] = '';
            
            // 绑定点击事件
            $html[] = 'function bindItems() {';
            $html[] = '  list.querySelectorAll(".weline-domain-select-item").forEach(function(el) {';
            $html[] = '    el.addEventListener("click", function() {';
            $html[] = '      var domain = this.dataset.domain;';
            $html[] = '      var poolId = this.dataset.poolid || "";';
            $html[] = '      var itemValue = (valueType === "pool_id" && poolId) ? poolId : domain;';
            $html[] = '      if (isMultiple) {';
            $html[] = '        var idx = selectedDomains.indexOf(domain);';
            $html[] = '        if (idx > -1) {';
            $html[] = '          selectedDomains.splice(idx, 1);';
            $html[] = '          syncPairedRootWww(domain, false);';
            $html[] = '        } else {';
            $html[] = '          selectedDomains.push(domain);';
            $html[] = '          syncPairedRootWww(domain, true);';
            $html[] = '        }';
            $html[] = '        syncListSelectionFromState();';
            $html[] = '      } else {';
            $html[] = '        hidden.value = itemValue;';
            $html[] = '        hidden.dataset.domain = domain;';
            $html[] = '        hidden.dataset.poolid = poolId;';
            $html[] = '        display.textContent = domain;';
            $html[] = '        closeDropdownPanel();';
            $html[] = '        list.querySelectorAll(".weline-domain-select-item").forEach(function(li) {';
            $html[] = '          li.classList.remove("active");';
            $html[] = '        });';
            $html[] = '        this.classList.add("active");';
            $html[] = '        doAutoFill();';
            $html[] = '        try { hidden.dispatchEvent(new Event("change")); } catch(e) {}';
            $html[] = '      }';
            $html[] = '    });';
            $html[] = '  });';
            $html[] = '}';
            $html[] = '';
            
            // 多选确认按钮
            $html[] = 'if (isMultiple && confirmBtn) {';
            $html[] = '  confirmBtn.addEventListener("click", function() {';
            $html[] = '    if (bindRootWww) expandRootWwwPairsInSelection();';
            $html[] = '    updateHiddenValue();';
            $html[] = '    updateDisplay();';
            $html[] = '    doAutoFill();';
            $html[] = '    closeDropdownPanel();';
            $html[] = '  });';
            $html[] = '}';
            $html[] = '';
            $html[] = 'if (isMultiple && clearBtn) {';
            $html[] = '  clearBtn.addEventListener("click", function() {';
            $html[] = '    selectedDomains = [];';
            $html[] = '    updateHiddenValue();';
            $html[] = '    updateDisplay();';
            $html[] = '    render(cache || []);';
            $html[] = '  });';
            $html[] = '}';
            $html[] = '';
            
            // 加载数据
            $html[] = 'function loadData() {';
            $html[] = '  loading.style.display = "block";';
            $html[] = '  list.innerHTML = "";';
            $html[] = '  var wrapper = document.getElementById(id + "_wrapper");';
            $html[] = '  var wid = parseInt(wrapper ? (wrapper.getAttribute("data-website-id") || "0") : "0", 10);';
            $html[] = '  var curPoolIds = (hidden.value || "").trim().split(",").map(function(v){ return v.trim(); }).filter(function(v){ return v !== ""; });';
            $html[] = '  var poolIdsParam = curPoolIds.length ? "&pool_ids=" + encodeURIComponent(curPoolIds.join(",")) : "";';
            $html[] = '  var apiUrl = ep + "?limit=" + limit + "&grouped=true&site_ready=" + (siteReadyOnly ? "true" : "false") + (wid > 0 ? "&website_id=" + wid : "") + poolIdsParam;';
            $html[] = '  fetch(apiUrl)';
            $html[] = '    .then(function(r) { return r.json(); })';
            $html[] = '    .then(function(res) {';
            $html[] = '      loading.style.display = "none";';
            $html[] = '      cache = (res && res.success) ? (res.data || []) : [];';
            $html[] = '      if (cache.length === 0) {';
            $html[] = '        list.innerHTML = \'<div class="weline-domain-select-empty">' . $t_no_domain . '</div>\';';
            $html[] = '        return;';
            $html[] = '      }';
            $html[] = '      // 初始化选中状态：根据 hidden.value 中的 pool_id 列表查找对应的 domain';
            $html[] = '      if (isMultiple && valueType === "pool_id" && hidden.value) {';
            $html[] = '        var initPoolIds = hidden.value.split(",").map(function(v) { return String(v).trim(); }).filter(function(v) { return v !== ""; });';
            $html[] = '        cache.forEach(function(group) {';
            $html[] = '          (group.options || []).forEach(function(opt) {';
            $html[] = '            if (initPoolIds.indexOf(String(opt.pool_id)) > -1 && selectedDomains.indexOf(opt.domain) === -1) {';
            $html[] = '              selectedDomains.push(opt.domain);';
            $html[] = '            }';
            $html[] = '          });';
            $html[] = '        });';
            $html[] = '        expandRootWwwPairsInSelection();';
            $html[] = '        updateHiddenValue();';
            $html[] = '        updateDisplay();';
            $html[] = '      }';
            $html[] = '      render(cache);';
            $html[] = '    })';
            $html[] = '    .catch(function() {';
            $html[] = '      loading.style.display = "none";';
            $html[] = '      list.innerHTML = \'<div class="weline-domain-select-empty" style="color:var(--backend-color-danger,#dc3545)">' . $t_load_fail . '</div>\';';
            $html[] = '    });';
            $html[] = '}';
            $html[] = '';
            
            // 搜索过滤
            $html[] = 'var filterTimeout = null;';
            $html[] = 'function doFilter(kw) {';
            $html[] = '  kw = (kw || "").toLowerCase().trim();';
            $html[] = '  if (!kw) {';
            $html[] = '    render(cache || []);';
            $html[] = '    return;';
            $html[] = '  }';
            $html[] = '  var filtered = [];';
            $html[] = '  (cache || []).forEach(function(group) {';
            $html[] = '    var opts = (group.options || []).filter(function(o) {';
            $html[] = '      return o.domain.toLowerCase().indexOf(kw) !== -1;';
            $html[] = '    });';
            $html[] = '    if (opts.length > 0) {';
            $html[] = '      filtered.push({ label: group.label, options: opts });';
            $html[] = '    }';
            $html[] = '  });';
            $html[] = '  render(filtered);';
            $html[] = '}';
            $html[] = '';
            
            // 事件绑定
            $html[] = 'function positionDropdown(){ window.WelineSmartDropdown.mount(trigger, dropdown, { minWidth: trigger ? trigger.offsetWidth : 0, preferredHeight: 420, zIndex: 4300, gap: 4 }); }';
            $html[] = 'function openDropdown(){ positionDropdown(); floatingOpened = true; loadData(); setTimeout(function() { if (search) search.focus(); }, 50); window.addEventListener("resize", handleViewportChange); window.addEventListener("scroll", handleViewportChange, true); }';
            $html[] = 'function closeDropdownPanel(){ floatingOpened = false; window.WelineSmartDropdown.unmount(dropdown); document.removeEventListener("click", closeDropdown); document.removeEventListener("keydown", escClose); window.removeEventListener("resize", handleViewportChange); window.removeEventListener("scroll", handleViewportChange, true); }';
            $html[] = 'function handleViewportChange(){ if (!floatingOpened) return; positionDropdown(); }';
            $html[] = 'trigger.addEventListener("click", function(e) {';
            $html[] = '  e.stopPropagation();';
            $html[] = '  if (floatingOpened) { closeDropdownPanel(); return; }';
            $html[] = '  openDropdown();';
            $html[] = '});';
            $html[] = '';
            $html[] = 'search.addEventListener("input", function() {';
            $html[] = '  clearTimeout(filterTimeout);';
            $html[] = '  var val = this.value;';
            $html[] = '  filterTimeout = setTimeout(function() { doFilter(val); }, 200);';
            $html[] = '});';
            $html[] = '';
            $html[] = 'function closeDropdown(ev) {';
            $html[] = '  if (!dropdown.contains(ev.target) && !trigger.contains(ev.target)) {';
            $html[] = '    closeDropdownPanel();';
            $html[] = '  }';
            $html[] = '}';
            $html[] = 'function escClose(e) {';
            $html[] = '  if (e.key === "Escape") {';
            $html[] = '    closeDropdownPanel();';
            $html[] = '  }';
            $html[] = '}';
            $html[] = 'toggleManualHttpsFields();';
            $html[] = 'if (manualHttpsMode) {';
            $html[] = '  manualHttpsMode.addEventListener("change", toggleManualHttpsFields);';
            $html[] = '}';
            $html[] = 'var bs = (typeof window !== "undefined" && window.bootstrap) || (typeof window.parent !== "undefined" && window.parent.bootstrap);';
            $html[] = 'var manualCanvas = (manualOffcanvasEl && bs && bs.Offcanvas) ? new bs.Offcanvas(manualOffcanvasEl) : null;';
            $html[] = 'function showManualOffcanvas() {';
            $html[] = '  if (manualCanvas) { manualCanvas.show(); return; }';
            $html[] = '  if (manualOffcanvasEl) { manualOffcanvasEl.classList.add("show"); document.body.classList.add("offcanvas-backdrop"); }';
            $html[] = '}';
            $html[] = 'function hideManualOffcanvas() {';
            $html[] = '  if (manualCanvas) { manualCanvas.hide(); return; }';
            $html[] = '  if (manualOffcanvasEl) { manualOffcanvasEl.classList.remove("show"); document.body.classList.remove("offcanvas-backdrop"); }';
            $html[] = '}';
            $html[] = 'if (manualCreateBtn && manualOffcanvasEl) {';
            $html[] = '  manualCreateBtn.addEventListener("click", function(e) {';
            $html[] = '    e.stopPropagation();';
            $html[] = '    e.preventDefault();';
            $html[] = '    loadManualAccounts();';
            $html[] = '    showManualOffcanvas();';
            $html[] = '  });';
            $html[] = '  var manualCloseBtn = manualOffcanvasEl.querySelector(\'.btn-close[data-bs-dismiss="offcanvas"], .btn-close\');';
            $html[] = '  if (manualCloseBtn) manualCloseBtn.addEventListener("click", function() { hideManualOffcanvas(); });';
            $html[] = '  if (manualSubmitBtn) {';
            $html[] = '    manualSubmitBtn.addEventListener("click", function() {';
            $html[] = '      var domainVal = (manualDomainInput && manualDomainInput.value ? manualDomainInput.value : "").trim();';
            $html[] = '      if (!domainVal) { notify("' . addslashes(__('请输入域名')) . '", "warning"); return; }';
            $html[] = '      var fd = new FormData();';
            $html[] = '      fd.append("domain", domainVal);';
            $html[] = '      fd.append("description", manualDescInput && manualDescInput.value ? manualDescInput.value : "");';
            $html[] = '      fd.append("dns_account_id", manualDnsSelect && manualDnsSelect.value ? manualDnsSelect.value : "0");';
            $html[] = '      fd.append("cdn_account_id", manualCdnSelect && manualCdnSelect.value ? manualCdnSelect.value : "0");';
            $html[] = '      fd.append("https_mode", manualHttpsMode && manualHttpsMode.value ? manualHttpsMode.value : "none");';
            $html[] = '      fd.append("https_email", manualHttpsEmail && manualHttpsEmail.value ? manualHttpsEmail.value : "");';
            $html[] = '      var certText = document.getElementById(id + "_manual_cert_text");';
            $html[] = '      var keyText = document.getElementById(id + "_manual_key_text");';
            $html[] = '      var chainText = document.getElementById(id + "_manual_chain_text");';
            $html[] = '      var certFile = document.getElementById(id + "_manual_cert_file");';
            $html[] = '      var keyFile = document.getElementById(id + "_manual_key_file");';
            $html[] = '      var chainFile = document.getElementById(id + "_manual_chain_file");';
            $html[] = '      var pfxFile = document.getElementById(id + "_manual_pfx_file");';
            $html[] = '      var pfxPassword = document.getElementById(id + "_manual_pfx_password");';
            $html[] = '      fd.append("cert_fullchain_text", certText && certText.value ? certText.value : "");';
            $html[] = '      fd.append("cert_private_key_text", keyText && keyText.value ? keyText.value : "");';
            $html[] = '      fd.append("cert_chain_text", chainText && chainText.value ? chainText.value : "");';
            $html[] = '      fd.append("cert_pfx_password", pfxPassword && pfxPassword.value ? pfxPassword.value : "");';
            $html[] = '      if (certFile && certFile.files && certFile.files[0]) fd.append("cert_file", certFile.files[0]);';
            $html[] = '      if (keyFile && keyFile.files && keyFile.files[0]) fd.append("key_file", keyFile.files[0]);';
            $html[] = '      if (chainFile && chainFile.files && chainFile.files[0]) fd.append("chain_file", chainFile.files[0]);';
            $html[] = '      if (pfxFile && pfxFile.files && pfxFile.files[0]) fd.append("pfx_file", pfxFile.files[0]);';
            $html[] = '      manualSubmitBtn.disabled = true;';
            $html[] = '      fetch(manualCreateApi, { method: "POST", body: fd }).then(function(r){ return r.json(); }).then(function(res){';
            $html[] = '        manualSubmitBtn.disabled = false;';
            $html[] = '        if (!res || res.code !== 200) { notify((res && res.msg) ? res.msg : "' . addslashes(__('创建失败')) . '", "error"); return; }';
            $html[] = '        var data = (res.data || {});';
            $html[] = '        var newDomain = data.domain || "";';
            $html[] = '        var newPoolId = data.pool_id ? String(data.pool_id) : "";';
            $html[] = '        var rootDomain = data.root_domain || newDomain;';
            $html[] = '        if (newDomain && selectedDomains.indexOf(newDomain) === -1) {';
            $html[] = '          selectedDomains.push(newDomain);';
            $html[] = '          if (valueType === "pool_id" && newPoolId) {';
            $html[] = '            var cur = (hidden.value || "").trim();';
            $html[] = '            hidden.value = cur ? cur + "," + newPoolId : newPoolId;';
            $html[] = '          } else { updateHiddenValue(); }';
            $html[] = '          if (!cache) cache = [];';
            $html[] = '          var grp = cache.find(function(g) { return (g.label || "") === rootDomain; });';
            $html[] = '          if (!grp) { grp = { label: rootDomain, options: [] }; cache.push(grp); }';
            $html[] = '          if (!grp.options.some(function(o) { return o.domain === newDomain; })) {';
            $html[] = '            grp.options.push({ domain: newDomain, pool_id: newPoolId, site_ready: 0, root_domain: rootDomain });';
            $html[] = '          }';
            $html[] = '          if (bindRootWww) expandRootWwwPairsInSelection();';
            $html[] = '          if (valueType === "pool_id") updateHiddenValue();';
            $html[] = '          updateDisplay();';
            $html[] = '          doAutoFill();';
            $html[] = '        }';
            $html[] = '        notify((res.msg || "' . addslashes(__('域名已添加并已选')) . '") + " ' . addslashes(__('系统将自动完成解析与证书，可建站后网站即可访问。')) . '", "success");';
            $html[] = '        hideManualOffcanvas();';
            $html[] = '      }).catch(function(){ manualSubmitBtn.disabled = false; notify("' . addslashes(__('请求失败')) . '", "error"); });';
            $html[] = '    });';
            $html[] = '  }';
            $html[] = '}';
            $html[] = 'trigger.addEventListener("click", function() {';
            $html[] = '  setTimeout(function() {';
            $html[] = '    document.addEventListener("click", closeDropdown);';
            $html[] = '    document.addEventListener("keydown", escClose);';
            $html[] = '  }, 0);';
            $html[] = '});';
            $html[] = '';
            $html[] = '})();</script>';

            return implode("\n", $html);
        };
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
        $doc = <<<DOC
<h3><code>&lt;w:websites:domain:select&gt;</code> 使用文档</h3>
<p><strong>作用</strong>：渲染"可搜索 + 点选"的域名选择器，用于从域名池（DomainPool）选择域名。支持单选和多选模式。</p>
<p><strong>v1.6.0 更新</strong>：数据源改为 DomainPool 模型，支持 site_ready 筛选和 pool_id 值类型。</p>

<h4>属性</h4>
<ul>
  <li><code>id</code>：组件唯一 ID（必需）</li>
  <li><code>name</code>：隐藏域表单名（必需）</li>
  <li><code>value</code>：隐藏域值，域名或 pool_id（支持变量/默认）</li>
  <li><code>display</code>：按钮显示文本（支持变量/默认）</li>
  <li><code>class</code>/<code>style</code>：外层样式</li>
  <li><code>limit</code>：加载数量限制（默认50）</li>
  <li><code>url</code>：API地址（默认 websites/backend/api/domain-pool）</li>
  <li><code>multiple</code>：是否多选（默认 false，支持 "true"/"1"/"yes"）</li>
  <li><code>on-select</code>：选择后的回调函数名</li>
  <li><code>auto-fill-code</code>：自动填充网站代码的输入框 ID</li>
  <li><code>auto-fill-url</code>：自动填充网站 URL 的输入框 ID</li>
  <li><code>auto-fill-name</code>：自动填充网站名称的输入框 ID</li>
  <li><code>auto-fill-address</code>：自动填充地址的 textarea ID（多选时填充多行）</li>
  <li><code>site-ready-only</code>：<strong>[v1.6.0]</strong> 是否只显示 site_ready=1 的域名（默认 true）</li>
  <li><code>value-type</code>：<strong>[v1.6.0]</strong> 值类型 "domain"（默认）或 "pool_id"</li>
  <li><code>bind-root-www</code>：多选时若为 true，apex 与 <code>www.</code>apex 在列表中同时存在则成对勾选/取消（移除标签亦成对）</li>
</ul>

<h4>使用示例</h4>
<pre>
&lt;!-- 单选模式（默认，只显示可建站域名） --&gt;
&lt;w:websites:domain:select
    id="domain_select"
    name="domain"
    value="''"
    display="'请选择域名'"
    class="w-100"
    auto-fill-code="code"
    auto-fill-url="url"
    auto-fill-name="name"
/&gt;

&lt;!-- 多选模式，使用 pool_id 作为值 --&gt;
&lt;w:websites:domain:select
    id="domain_select"
    name="pool_ids"
    value="''"
    display="'点击选择域名（可多选）'"
    class="w-100"
    multiple="true"
    value-type="pool_id"
    auto-fill-address="address_lines"
/&gt;

&lt;!-- 显示所有域名（包括未就绪的） --&gt;
&lt;w:websites:domain:select
    id="all_domain_select"
    name="domain"
    value="''"
    display="'选择域名'"
    site-ready-only="false"
/&gt;
</pre>
DOC;

        return htmlspecialchars($doc, ENT_NOQUOTES);
    }
}
