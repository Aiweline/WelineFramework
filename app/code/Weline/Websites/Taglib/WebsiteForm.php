<?php

declare(strict_types=1);

/**
 * Weline Websites - 统一网站表单标签
 * 
 * 提供统一的网站添加/编辑表单组件，包含：
 * - 域名选择（多选，支持 pool_id）
 * - 基本信息（网站名称、代码、URL）
 * - 高级选项（货币、语言、时区、SEO账户）手风琴折叠
 * 
 * 使用示例：
 * <w:websites:website:form
 *     id="website_form"
 *     website="website|[]"
 *     locales="locales|[]"
 *     currencies="currencies|[]"
 *     selected_currencies="selected_currencies|[]"
 *     selected_languages="selected_languages|[]"
 *     selected_pool_ids="selected_pool_ids|[]"
 * />
 */

namespace Weline\Websites\Taglib;

use Weline\Taglib\TaglibInterface;

class WebsiteForm implements TaglibInterface
{
    public static function name(): string
    {
        return 'websites:website:form';
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
            'website' => false,
            'locales' => false,
            'currencies' => false,
            'timezones' => false,
            'selected_currencies' => false,
            'selected_languages' => false,
            'selected_pool_ids' => false,
            'form_action' => false,
            'show_save_btn' => false,
            'save_btn_text' => false,
            'cancel_url' => false,
        ];
    }

    public static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            if (empty($attributes['id'])) {
                throw new \Exception(__('id属性不能为空'));
            }

            $id = $attributes['id'];
            $website = $attributes['website'] ?? '[]';
            $locales = $attributes['locales'] ?? '[]';
            $currencies = $attributes['currencies'] ?? '[]';
            $timezones = $attributes['timezones'] ?? '[]';
            $selectedCurrencies = $attributes['selected_currencies'] ?? '[]';
            $selectedLanguages = $attributes['selected_languages'] ?? '[]';
            $formAction = $attributes['form_action'] ?? 'null';
            $showSaveBtn = $attributes['show_save_btn'] ?? 'true';
            $saveBtnText = $attributes['save_btn_text'] ?? "'保存'";
            $cancelUrl = $attributes['cancel_url'] ?? "'javascript:history.back()'";

            // 解析所有属性
            $code = \Weline\Taglib\Taglib::attributes($attributes);
            $html = [];
            $html[] = '<?php ' . $code . ' ?>';

            $html[] = '<style>';
            $html[] = '.website-form-accordion .accordion-item {';
            $html[] = '  border: 1px solid var(--backend-color-border-default);';
            $html[] = '  border-radius: var(--backend-border-radius-md);';
            $html[] = '  margin-bottom: 1rem;';
            $html[] = '  background-color: var(--backend-color-card-bg);';
            $html[] = '}';
            $html[] = '.website-form-accordion .accordion-header {';
            $html[] = '  padding: 1rem 1.25rem;';
            $html[] = '  cursor: pointer;';
            $html[] = '  display: flex;';
            $html[] = '  align-items: center;';
            $html[] = '  justify-content: space-between;';
            $html[] = '  background-color: var(--backend-color-bg-tertiary);';
            $html[] = '  border-radius: var(--backend-border-radius-md);';
            $html[] = '  transition: background-color 0.2s;';
            $html[] = '}';
            $html[] = '.website-form-accordion .accordion-header:hover {';
            $html[] = '  background-color: var(--backend-color-bg-secondary);';
            $html[] = '}';
            $html[] = '.website-form-accordion .accordion-header i {';
            $html[] = '  color: var(--backend-color-primary);';
            $html[] = '  margin-right: 0.5rem;';
            $html[] = '}';
            $html[] = '.website-form-accordion .accordion-body {';
            $html[] = '  padding: 1.25rem;';
            $html[] = '  border-top: 1px solid var(--backend-color-border-default);';
            $html[] = '  background-color: var(--backend-color-card-bg);';
            $html[] = '}';
            $html[] = '.website-form-accordion .accordion-button {';
            $html[] = '  background: none;';
            $html[] = '  border: none;';
            $html[] = '  padding: 0;';
            $html[] = '  cursor: pointer;';
            $html[] = '  display: flex;';
            $html[] = '  align-items: center;';
            $html[] = '  gap: 0.5rem;';
            $html[] = '  color: var(--backend-color-text-primary);';
            $html[] = '  font-weight: 600;';
            $html[] = '  font-size: 1rem;';
            $html[] = '}';
            $html[] = '.website-form-accordion .accordion-button .mdi {';
            $html[] = '  transition: transform 0.3s ease;';
            $html[] = '}';
            $html[] = '.website-form-accordion .accordion-button[aria-expanded="true"] .mdi {';
            $html[] = '  transform: rotate(180deg);';
            $html[] = '}';
            $html[] = '</style>';

            // HTML 结构
            $html[] = '<div class="website-form-accordion" id="<?= htmlspecialchars($Taglib__id) ?>_wrapper">';
            
            // 基本信息（默认展开）
            $html[] = '  <div class="accordion-item">';
            $html[] = '    <h2 class="accordion-header" id="<?= htmlspecialchars($Taglib__id) ?>_basic_header">';
            $html[] = '      <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#<?= htmlspecialchars($Taglib__id) ?>_basic_body" aria-expanded="true" aria-controls="<?= htmlspecialchars($Taglib__id) ?>_basic_body">';
            $html[] = '        <i class="mdi mdiInformation-outline"></i>';
            $html[] = '        <span><lang>基本信息</lang></span>';
            $html[] = '      </button>';
            $html[] = '    </h2>';
            $html[] = '    <div id="<?= htmlspecialchars($Taglib__id) ?>_basic_body" class="accordion-collapse collapse show" aria-labelledby="<?= htmlspecialchars($Taglib__id) ?>_basic_header" data-bs-parent="#<?= htmlspecialchars($Taglib__id) ?>_wrapper">';
            $html[] = '      <div class="accordion-body">';
            $html[] = '        <?php $this->dispatchHook("website_form_basic", ["id" => $Taglib__id, "website" => $website]); ?>';
            $html[] = '      </div>';
            $html[] = '    </div>';
            $html[] = '  </div>';

            // 域名选择（默认展开）
            $html[] = '  <div class="accordion-item">';
            $html[] = '    <h2 class="accordion-header" id="<?= htmlspecialchars($Taglib__id) ?>_domains_header">';
            $html[] = '      <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#<?= htmlspecialchars($Taglib__id) ?>_domains_body" aria-expanded="true" aria-controls="<?= htmlspecialchars($Taglib__id) ?>_domains_body">';
            $html[] = '        <i class="mdi mdi-domain"></i>';
            $html[] = '        <span><lang>域名管理</lang></span>';
            $html[] = '      </button>';
            $html[] = '    </h2>';
            $html[] = '    <div id="<?= htmlspecialchars($Taglib__id) ?>_domains_body" class="accordion-collapse collapse show" aria-labelledby="<?= htmlspecialchars($Taglib__id) ?>_domains_header" data-bs-parent="#<?= htmlspecialchars($Taglib__id) ?>_wrapper">';
            $html[] = '      <div class="accordion-body">';
            $wfsDsDisplay = __('点击选择域名（可多选）');
            $html[] = '        <?php';
            $html[] = '            $__wfs_ds_id = (string)($Taglib__id ?? \'\');';
            $html[] = '            $__wfs_pools = $Taglib__selected_pool_ids ?? [];';
            $html[] = '            $__wfs_pool_str = is_array($__wfs_pools) ? implode(\',\', array_map(\'strval\', $__wfs_pools)) : (string)$__wfs_pools;';
            $html[] = '            $__wfs_domain_select_id = htmlspecialchars($__wfs_ds_id . \'_domain_select\', ENT_QUOTES, \'UTF-8\');';
            $html[] = '            $__wfs_domain_select_value = htmlspecialchars($__wfs_pool_str, ENT_QUOTES, \'UTF-8\');';
            $html[] = '            $__wfs_display = htmlspecialchars(' . var_export($wfsDsDisplay, true) . ', ENT_QUOTES, \'UTF-8\');';
            $html[] = '            $__wfs_website_row = $Taglib__website ?? [];';
            $html[] = '            $__wfs_domain_select_website_id = isset($__wfs_website_row[\'website_id\']) ? (int)$__wfs_website_row[\'website_id\'] : 0;';
            $html[] = '            $__wfs_auto_code = htmlspecialchars($__wfs_ds_id . \'_code\', ENT_QUOTES, \'UTF-8\');';
            $html[] = '            $__wfs_auto_name = htmlspecialchars($__wfs_ds_id . \'_name\', ENT_QUOTES, \'UTF-8\');';
            $html[] = '        ?>';
            $html[] = '        <w:websites:domain:select';
            $html[] = '            id="__wfs_domain_select_id"';
            $html[] = '            name="pool_ids"';
            $html[] = '            value="__wfs_domain_select_value"';
            $html[] = '            display="__wfs_display"';
            $html[] = '            class="w-100"';
            $html[] = '            multiple="true"';
            $html[] = '            value-type="pool_id"';
            $html[] = '            site-ready-only="true"';
            $html[] = '            website-id="__wfs_domain_select_website_id"';
            $html[] = '            auto-fill-code="__wfs_auto_code"';
            $html[] = '            auto-fill-name="__wfs_auto_name"';
            $html[] = '        />';
            $html[] = '        <input type="hidden" name="domain_values" value="">';
            $html[] = '        <div class="form-text-hint"><lang>从域名池选择已就绪的域名（可多选）；选择后网站代码与网站名称将自动填充</lang></div>';
            $html[] = '        <?php $this->dispatchHook("website_form_domains", ["id" => $Taglib__id, "website" => $website]); ?>';
            $html[] = '      </div>';
            $html[] = '    </div>';
            $html[] = '  </div>';

            // 高级选项（默认折叠）
            $html[] = '  <div class="accordion-item">';
            $html[] = '    <h2 class="accordion-header" id="<?= htmlspecialchars($Taglib__id) ?>_advanced_header">';
            $html[] = '      <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#<?= htmlspecialchars($Taglib__id) ?>_advanced_body" aria-expanded="false" aria-controls="<?= htmlspecialchars($Taglib__id) ?>_advanced_body">';
            $html[] = '        <i class="mdi mdi-settings"></i>';
            $html[] = '        <span><lang>高级选项</lang></span>';
            $html[] = '      </button>';
            $html[] = '    </h2>';
            $html[] = '    <div id="<?= htmlspecialchars($Taglib__id) ?>_advanced_body" class="accordion-collapse collapse" aria-labelledby="<?= htmlspecialchars($Taglib__id) ?>_advanced_header" data-bs-parent="#<?= htmlspecialchars($Taglib__id) ?>_wrapper">';
            $html[] = '      <div class="accordion-body">';
            $html[] = '        <div class="row">';
            $html[] = '          <div class="col-4 mb-3">';
            $html[] = '            <label for="<?= htmlspecialchars($Taglib__id) ?>_code" class="form-label"><lang>网站代码</lang></label>';
            $html[] = '            <input type="text" class="form-control" id="<?= htmlspecialchars($Taglib__id) ?>_code" name="code"';
            $html[] = '                   value="<?= isset($website[\'website_id\']) ? htmlspecialchars($website[\'code\'] ?? \'\') : \'\'; ?>" required>';
            $html[] = '            <div class="invalid-feedback"><lang>请输入网站代码</lang></div>';
            $html[] = '          </div>';
            $html[] = '          <div class="col-4 mb-3">';
            $html[] = '            <label for="<?= htmlspecialchars($Taglib__id) ?>_name" class="form-label"><lang>网站名称</lang></label>';
            $html[] = '            <input type="text" class="form-control" id="<?= htmlspecialchars($Taglib__id) ?>_name" name="name"';
            $html[] = '                   value="<?= isset($website[\'website_id\']) ? htmlspecialchars($website[\'name\'] ?? \'\') : \'\'; ?>" required>';
            $html[] = '            <div class="invalid-feedback"><lang>请输入网站名称</lang></div>';
            $html[] = '          </div>';
            $html[] = '          <div class="col-4 mb-3">';
            $html[] = '            <label for="<?= htmlspecialchars($Taglib__id) ?>_url" class="form-label"><lang>网站URL</lang></label>';
            $html[] = '            <input type="url" class="form-control" id="<?= htmlspecialchars($Taglib__id) ?>_url" name="url"';
            $html[] = '                   value="<?= isset($website[\'website_id\']) ? htmlspecialchars($website[\'url\'] ?? \'\') : \'\'; ?>" required>';
            $html[] = '            <div class="invalid-feedback"><lang>请输入有效的URL</lang></div>';
            $html[] = '          </div>';
            $html[] = '          <div class="col-4 mb-3">';
            $html[] = '            <label for="<?= htmlspecialchars($Taglib__id) ?>_default_currency" class="form-label"><lang>默认货币</lang></label>';
            $html[] = '            <select class="form-select" name="default_currency" id="<?= htmlspecialchars($Taglib__id) ?>_default_currency">';
            $html[] = '              <option value="">-- <lang>请选择</lang> --</option>';
            $html[] = '              <?php foreach ($currencies as $currency): ?>';
            $html[] = '                <option value="<?= htmlspecialchars($currency[\'code\']) ?>"';
            $html[] = '                    <?= (isset($website[\'default_currency\']) && $website[\'default_currency\'] === $currency[\'code\']) ? \'selected\' : \'\'; ?>>';
            $html[] = '                  <?= htmlspecialchars($currency[\'code\']) ?> - <?= htmlspecialchars($currency[\'name\']) ?>';
            $html[] = '                </option>';
            $html[] = '              <?php endforeach; ?>';
            $html[] = '            </select>';
            $html[] = '            <div class="form-text-hint"><lang>不设置则从关联货币中选择第一个</lang></div>';
            $html[] = '          </div>';
            $html[] = '          <div class="col-4 mb-3">';
            $html[] = '            <label for="<?= htmlspecialchars($Taglib__id) ?>_default_language" class="form-label"><lang>默认语言</lang></label>';
            $html[] = '            <?php';
            $html[] = '            $__wfs_parse_language_values = static function ($raw): array {';
            $html[] = '              if (is_array($raw)) {';
            $html[] = '                $values = $raw;';
            $html[] = '              } elseif ($raw === null || $raw === \'\') {';
            $html[] = '                $values = [];';
            $html[] = '              } else {';
            $html[] = '                $decoded = json_decode((string)$raw, true);';
            $html[] = '                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {';
            $html[] = '                  $values = $decoded;';
            $html[] = '                } else {';
            $html[] = '                  $values = preg_split(\'/[\\s,]+/\', (string)$raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];';
            $html[] = '                }';
            $html[] = '              }';
            $html[] = '              $result = [];';
            $html[] = '              foreach ($values as $value) {';
            $html[] = '                if (is_array($value) && isset($value[\'code\'])) {';
            $html[] = '                  $value = $value[\'code\'];';
            $html[] = '                }';
            $html[] = '                if (!is_scalar($value)) {';
            $html[] = '                  continue;';
            $html[] = '                }';
            $html[] = '                $value = (string)$value;';
            $html[] = '                if ($value === \'\' || in_array($value, $result, true)) {';
            $html[] = '                  continue;';
            $html[] = '                }';
            $html[] = '                $result[] = $value;';
            $html[] = '              }';
            $html[] = '              return $result;';
            $html[] = '            };';
            $html[] = '            $__wfs_default_language_selector_id = (string)($Taglib__id . \'_default_language_selector\');';
            $html[] = '            $__wfs_language_codes_selector_id = (string)($Taglib__id . \'_language_codes_selector\');';
            $html[] = '            $__wfs_default_language_input_id = (string)($Taglib__id . \'_default_language\');';
            $html[] = '            $__wfs_language_codes_input_id = (string)($Taglib__id . \'_language_codes\');';
            $html[] = '            $__wfs_default_language_value = (string)($website[\'default_language\'] ?? \'\');';
            $html[] = '            $__wfs_selected_languages = $__wfs_parse_language_values($selectedLanguages ?? []);';
            $html[] = '            if ($__wfs_default_language_value !== \'\' && !in_array($__wfs_default_language_value, $__wfs_selected_languages, true)) {';
            $html[] = '              $__wfs_selected_languages[] = $__wfs_default_language_value;';
            $html[] = '            }';
            $html[] = '            $__wfs_readonly_languages = $__wfs_default_language_value !== \'\' ? [$__wfs_default_language_value] : [];';
            $html[] = '            ?>';
            $html[] = '            <w:i18n:language:select';
            $html[] = '                id="__wfs_default_language_selector_id"';
            $html[] = '                name="default_language"';
            $html[] = '                input-id="__wfs_default_language_input_id"';
            $html[] = '                value="__wfs_default_language_value"';
            $html[] = '                allow-empty="true"';
            $html[] = '                class="w-100"';
            $html[] = '            />';
            $html[] = '            <?php if (false): ?>';
            $html[] = '            <select class="form-select" name="default_language" id="<?= htmlspecialchars($Taglib__id) ?>_default_language">';
            $html[] = '              <option value="">-- <lang>请选择</lang> --</option>';
            $html[] = '              <?php foreach ($locales as $locale): ?>';
            $html[] = '                <option value="<?= htmlspecialchars($locale[\'code\']) ?>"';
            $html[] = '                    <?= (isset($website[\'default_language\']) && $website[\'default_language\'] === $locale[\'code\']) ? \'selected\' : \'\'; ?>>';
            $html[] = '                  <?= htmlspecialchars($locale[\'name\']) ?>';
            $html[] = '                </option>';
            $html[] = '              <?php endforeach; ?>';
            $html[] = '            </select>';
            $html[] = '            <?php endif; ?>';
            $html[] = '            <div class="form-text-hint"><lang>不设置则从关联语言中选择第一个</lang></div>';
            $html[] = '          </div>';
            $html[] = '          <div class="col-6 mb-3">';
            $html[] = '            <label for="<?= htmlspecialchars($Taglib__id) ?>_currency_codes" class="form-label"><lang>关联货币</lang></label>';
            $html[] = '            <select class="form-select" name="currency_codes[]" id="<?= htmlspecialchars($Taglib__id) ?>_currency_codes" multiple>';
            $html[] = '              <?php foreach ($currencies as $currency): ?>';
            $html[] = '                <option value="<?= htmlspecialchars($currency[\'code\']) ?>"';
            $html[] = '                    <?= in_array($currency[\'code\'], $selectedCurrencies) ? \'selected\' : \'\'; ?>>';
            $html[] = '                  <?= htmlspecialchars($currency[\'code\']) ?> - <?= htmlspecialchars($currency[\'name\']) ?>';
            $html[] = '                </option>';
            $html[] = '              <?php endforeach; ?>';
            $html[] = '            </select>';
            $html[] = '            <div class="form-text-hint"><lang>按住Ctrl或Cmd键可多选</lang></div>';
            $html[] = '          </div>';
            $html[] = '          <div class="col-6 mb-3">';
            $html[] = '            <label for="<?= htmlspecialchars($Taglib__id) ?>_language_codes" class="form-label"><lang>关联语言</lang></label>';
            $html[] = '            <w:i18n:language:select';
            $html[] = '                id="__wfs_language_codes_selector_id"';
            $html[] = '                name="language_codes[]"';
            $html[] = '                input-id="__wfs_language_codes_input_id"';
            $html[] = '                value="__wfs_selected_languages"';
            $html[] = '                readonly-values="__wfs_readonly_languages"';
            $html[] = '                multiple="true"';
            $html[] = '                class="w-100"';
            $html[] = '            />';
            $html[] = '            <?php if (false): ?>';
            $html[] = '            <select class="form-select" name="language_codes[]" id="<?= htmlspecialchars($Taglib__id) ?>_language_codes" multiple>';
            $html[] = '              <?php foreach ($locales as $locale): ?>';
            $html[] = '                <option value="<?= htmlspecialchars($locale[\'code\']) ?>"';
            $html[] = '                    <?= in_array($locale[\'code\'], $selectedLanguages) ? \'selected\' : \'\'; ?>>';
            $html[] = '                  <?= htmlspecialchars($locale[\'name\']) ?>';
            $html[] = '                </option>';
            $html[] = '              <?php endforeach; ?>';
            $html[] = '            </select>';
            $html[] = '            <?php endif; ?>';
            $html[] = '            <div class="form-text-hint"><lang>按住Ctrl或Cmd键可多选</lang></div>';
            $html[] = '          </div>';
            $html[] = '          <div class="col-12 mb-3">';
            $html[] = '            <label for="<?= htmlspecialchars($Taglib__id) ?>_seo_account" class="form-label"><lang>SEO 账户</lang></label>';
            $html[] = '            <?php';
            $html[] = '            $seoAccountId = \'\';';
            $html[] = '            $seoAccountName = __(\'未绑定\');';
            $html[] = '            if (!empty($website[\'website_id\'])) {';
            $html[] = '              try {';
            $html[] = '                $seoBinding = ObjectManager::getInstance(\\Weline\\Seo\\Model\\SeoWebsiteAccount::class);';
            $html[] = '                $binding = $seoBinding->getByWebsiteId((int)$website[\'website_id\']);';
            $html[] = '                if ($binding) {';
            $html[] = '                  if (is_array($binding) && isset($binding[0]) && !empty($binding[0][\'account_id\'])) {';
            $html[] = '                    $seoAccountId = $binding[0][\'account_id\'];';
            $html[] = '                  } elseif (is_array($binding) && !empty($binding[\'account_id\'])) {';
            $html[] = '                    $seoAccountId = $binding[\'account_id\'];';
            $html[] = '                  } elseif (is_object($binding) && method_exists($binding, \'getAccountId\')) {';
            $html[] = '                    $seoAccountId = $binding->getAccountId();';
            $html[] = '                  }';
            $html[] = '                  if ($seoAccountId) {';
            $html[] = '                    $seoAccount = ObjectManager::getInstance(\\Weline\\Seo\\Model\\SeoAccount::class);';
            $html[] = '                    $account = $seoAccount->load($seoAccountId);';
            $html[] = '                    if ($account->getId()) {';
            $html[] = '                      $seoAccountName = $account->getData(\'name\') . \' (\' . $account->getData(\'provider\') . \')\';';
            $html[] = '                    }';
            $html[] = '                  }';
            $html[] = '                }';
            $html[] = '              } catch (\\Exception $e) {';
            $html[] = '              }';
            $html[] = '            }';
            $html[] = '            ?>';
            $html[] = '            <w:seo:account:select';
            $html[] = '                id="<?= htmlspecialchars($Taglib__id) ?>_seo_account"';
            $html[] = '                name="seo_account_id"';
            $html[] = '                value="seoAccountId|\'\'"';
            $html[] = '                display="seoAccountName|\'请选择SEO账户\'"';
            $html[] = '                class="w-100"';
            $html[] = '            />';
            $html[] = '            <div class="form-text-hint"><lang>绑定后将自动提交sitemap到该SEO账户的搜索引擎</lang></div>';
            $html[] = '          </div>';
            $html[] = '        </div>';
            $html[] = '        <?php $this->dispatchHook("website_form_advanced", ["id" => $Taglib__id, "website" => $website]); ?>';
            $html[] = '      </div>';
            $html[] = '    </div>';
            $html[] = '  </div>';

            // 操作按钮
            if ($showSaveBtn === 'true' || $showSaveBtn === '1') {
                $html[] = '  <div class="d-flex justify-content-between mt-4 pt-2">';
                $html[] = '    <a href="<?= $cancelUrl ?>" class="btn btn-secondary"><lang>取消</lang></a>';
                $html[] = '    <button type="submit" class="btn btn-primary"><?= $saveBtnText ?></button>';
                $html[] = '  </div>';
            }

            $html[] = '</div>';

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
<h3><code>&lt;w:websites:website:form&gt;</code> 使用文档</h3>
<p><strong>作用</strong>：渲染统一的网站添加/编辑表单，包含域名选择、基本信息和高级选项（手风琴折叠）。</p>

<h4>属性</h4>
<ul>
  <li><code>id</code>：组件唯一 ID（必需）</li>
  <li><code>website</code>：网站数据（数组或变量）</li>
  <li><code>locales</code>：语言列表</li>
  <li><code>currencies</code>：货币列表</li>
  <li><code>timezones</code>：时区列表</li>
  <li><code>selected_currencies</code>：已选中的货币代码数组</li>
  <li><code>selected_languages</code>：已选中的语言代码数组</li>
  <li><code>selected_pool_ids</code>：已选中的域名池 ID 数组</li>
  <li><code>form_action</code>：表单提交地址</li>
  <li><code>show_save_btn</code>：是否显示保存按钮（默认 true）</li>
  <li><code>save_btn_text</code>：保存按钮文本（默认 '保存'）</li>
  <li><code>cancel_url</code>：取消按钮链接</li>
</ul>

<h4>使用示例</h4>
<pre>
&lt;!-- 基本用法 --&gt;
&lt;w:websites:website:form
    id="website_form"
    website="website|[]"
    locales="locales|[]"
    currencies="currencies|[]"
    selected_currencies="selected_currencies|[]"
    selected_languages="selected_languages|[]"
    selected_pool_ids="selected_pool_ids|[]"
    form_action="'*/admin/website/' . (isset(\$website['website_id']) ? 'edit' : 'add')"
    cancel_url="\$this->getUrl('*/admin/website')"
/&gt;
DOC;

        return htmlspecialchars($doc, ENT_NOQUOTES);
    }
}
