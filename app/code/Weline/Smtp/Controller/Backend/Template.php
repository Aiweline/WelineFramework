<?php

declare(strict_types=1);

namespace Weline\Smtp\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Smtp\Model\SmtpMailTemplate;
use Weline\Smtp\Service\MailChannelCollector;
use Weline\Smtp\Service\MailTemplateRenderer;
use Weline\Smtp\Service\MailTemplateResolver;
use Weline\Smtp\Service\MailTemplateSeeder;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\SystemConfig\Service\SystemConfigTargetScopeService;

#[Acl('Weline_Smtp::system_smtp_template', 'SMTP 渠道管理', 'mail', '按发信渠道与范围维护各语言邮件模板', 'Weline_Smtp::system_smtp')]
class Template extends BackendController
{
    #[Acl('Weline_Smtp::smtp_template_listing', '渠道管理', 'list', '查看发信渠道与各语言模板', 'Weline_Smtp::system_smtp_template')]
    public function listing(): string
    {
        return $this->index();
    }

    #[Acl('Weline_Smtp::smtp_template_index', '渠道管理', 'list', '查看发信渠道与各语言模板', 'Weline_Smtp::system_smtp_template')]
    public function index(): string
    {
        $workScope = $this->resolveWorkScope(true);
        $storageScope = (string)$workScope['storage_scope'];

        /** @var MailTemplateSeeder $seeder */
        $seeder = ObjectManager::getInstance(MailTemplateSeeder::class);
        $seeder->syncAll(SystemConfig::SCOPE_GLOBAL);
        if ($storageScope !== SystemConfig::SCOPE_GLOBAL) {
            $seeder->syncAll($storageScope);
        }

        /** @var MailChannelCollector $collector */
        $collector = ObjectManager::getInstance(MailChannelCollector::class);
        $channels = $collector->collect();

        /** @var SmtpMailTemplate $model */
        $model = ObjectManager::getInstance(SmtpMailTemplate::class);
        $rows = $model->clear()
            ->where(SmtpMailTemplate::schema_fields_STORAGE_SCOPE, $storageScope)
            ->order(SmtpMailTemplate::schema_fields_CHANNEL_CODE, 'ASC')
            ->order(SmtpMailTemplate::schema_fields_LOCALE, 'ASC')
            ->select()
            ->fetch()
            ->getItems();

        /** @var \Weline\I18n\Service\ActiveLocaleCodeProvider $localeProvider */
        $localeProvider = ObjectManager::getInstance(\Weline\I18n\Service\ActiveLocaleCodeProvider::class);
        $installedLocales = $localeProvider->getInstalledActiveCodes();
        if ($installedLocales === []) {
            $installedLocales = ['zh_Hans_CN', 'en_US'];
        }

        /** @var MailTemplateResolver $resolver */
        $resolver = ObjectManager::getInstance(MailTemplateResolver::class);
        $byChannelLocale = [];
        foreach ($rows as $row) {
            $code = (string)$row->getData(SmtpMailTemplate::schema_fields_CHANNEL_CODE);
            $loc = (string)$row->getData(SmtpMailTemplate::schema_fields_LOCALE);
            if ($code === '' || $loc === '' || $loc === 'default') {
                continue;
            }
            $byChannelLocale[$code][$loc] = $row;
        }

        $list = [];
        foreach ($channels as $ch) {
            $code = (string)$ch['code'];
            $localeCodes = [];
            foreach ($installedLocales as $loc) {
                $localeCodes[$loc] = true;
            }
            foreach (array_keys($byChannelLocale[$code] ?? []) as $loc) {
                $localeCodes[(string)$loc] = true;
            }
            foreach (($ch['default_templates'] ?? []) as $seed) {
                if (!is_array($seed)) {
                    continue;
                }
                $seedLocale = trim((string)($seed['locale'] ?? ''));
                if ($seedLocale !== '' && $seedLocale !== 'default') {
                    $localeCodes[$seedLocale] = true;
                }
            }
            $sortedLocales = array_keys($localeCodes);
            sort($sortedLocales, SORT_STRING);

            $localeItems = [];
            $presentCount = 0;
            foreach ($sortedLocales as $loc) {
                $row = $byChannelLocale[$code][$loc] ?? null;
                $inherit = null;
                if ($row === null) {
                    $inherit = $resolver->findInheritFrom($code, $storageScope, $loc);
                } else {
                    $presentCount++;
                }
                $localeItems[] = [
                    'locale' => $loc,
                    'template' => $row,
                    'inherit' => $inherit,
                ];
            }

            $list[] = [
                'channel' => $ch,
                'locales' => $localeItems,
                'locale_count' => count($localeItems),
                'present_count' => $presentCount,
            ];
        }

        $returnState = $this->listingReturnState(false);
        $this->assign('items', $list);
        $this->assign('open_channel', trim((string)$this->request->getGet('channel', '')));
        $this->assign('filter_q', $returnState['q']);
        $this->assign('filter_locales', $returnState['locales']);
        $this->assign('focus_locale', $returnState['focus_locale']);
        $this->assignScopeVars($workScope);
        return $this->fetch('Weline_Smtp::Backend/Template/listing');
    }

    #[Acl('Weline_Smtp::smtp_template_edit', '编辑模板', 'edit', '编辑邮件模板', 'Weline_Smtp::system_smtp_template')]
    public function getEdit(): string
    {
        return $this->edit();
    }

    #[Acl('Weline_Smtp::smtp_template_edit', '编辑模板', 'edit', '编辑邮件模板', 'Weline_Smtp::system_smtp_template')]
    public function edit(): string
    {
        $workScope = $this->resolveWorkScope(false);
        $storageScope = (string)$workScope['storage_scope'];
        $locale = trim((string)$this->request->getGet('locale', 'zh_Hans_CN'));
        $channel = trim((string)$this->request->getGet('channel', ''));
        $templateId = (int)$this->request->getGet('template_id', 0);

        /** @var SmtpMailTemplate $model */
        $model = ObjectManager::getInstance(SmtpMailTemplate::class);
        if ($templateId > 0) {
            $model->load($templateId);
        } elseif ($channel !== '') {
            $found = $model->clear()
                ->where(SmtpMailTemplate::schema_fields_CHANNEL_CODE, $channel)
                ->where(SmtpMailTemplate::schema_fields_STORAGE_SCOPE, $storageScope)
                ->where(SmtpMailTemplate::schema_fields_LOCALE, $locale)
                ->find()
                ->fetch();
            if ($found && $found->getId()) {
                $model = $found;
            } else {
                $model->clearData()
                    ->setData(SmtpMailTemplate::schema_fields_CHANNEL_CODE, $channel)
                    ->setData(SmtpMailTemplate::schema_fields_STORAGE_SCOPE, $storageScope)
                    ->setData(SmtpMailTemplate::schema_fields_LOCALE, $locale)
                    ->setData(SmtpMailTemplate::schema_fields_SUBJECT, '')
                    ->setData(SmtpMailTemplate::schema_fields_BODY_HTML, '')
                    ->setData(SmtpMailTemplate::schema_fields_BODY_TEXT, '')
                    ->setData(SmtpMailTemplate::schema_fields_SOURCE, SmtpMailTemplate::SOURCE_CUSTOM)
                    ->setData(SmtpMailTemplate::schema_fields_USE_DEFAULT, 0);
            }
        }

        $channelCode = (string)$model->getData(SmtpMailTemplate::schema_fields_CHANNEL_CODE);
        /** @var MailChannelCollector $collector */
        $collector = ObjectManager::getInstance(MailChannelCollector::class);
        $channelMeta = $collector->getByCode($channelCode);
        $variables = array_values(array_merge(
            $channelMeta['variables'] ?? [],
            \Weline\Smtp\Service\MailBrandContextService::variableDefinitions()
        ));
        // 去重：渠道变量优先
        $seen = [];
        $mergedVars = [];
        foreach ($variables as $var) {
            $code = trim((string)($var['code'] ?? ''));
            if ($code === '' || isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            $mergedVars[] = $var;
        }

        /** @var \Weline\Smtp\Service\MailTemplateShellComposer $shell */
        $shell = ObjectManager::getInstance(\Weline\Smtp\Service\MailTemplateShellComposer::class);
        $model->setData(
            SmtpMailTemplate::schema_fields_BODY_HTML,
            $shell->extractBodyFragment((string)$model->getData(SmtpMailTemplate::schema_fields_BODY_HTML))
        );

        // URL workScope 驱动切换器与品牌预览，避免被旧 template 行锁死
        $editLocale = $locale !== '' && $locale !== 'default'
            ? $locale
            : ((string)$model->getData(SmtpMailTemplate::schema_fields_LOCALE) ?: 'zh_Hans_CN');
        if ($editLocale === '' || $editLocale === 'default') {
            $editLocale = 'zh_Hans_CN';
        }
        $editScope = $storageScope;
        /** @var \Weline\Smtp\Service\MailBrandContextService $brandCtx */
        $brandCtx = ObjectManager::getInstance(\Weline\Smtp\Service\MailBrandContextService::class);
        $previewSamples = $brandCtx->buildPreviewSamples($mergedVars, $editScope);

        $returnState = $this->listingReturnState(false);
        if ($returnState['focus_locale'] === '') {
            $returnState['focus_locale'] = $editLocale;
        }
        $this->assign('template', $model);
        $this->assign('channel_meta', $channelMeta);
        $this->assign('variables', $mergedVars);
        $this->assign('locale', $editLocale);
        $this->assign('listing_return', $returnState);
        $this->assign('preview_shell_html', $shell->loadShell($editLocale));
        $this->assign('preview_samples', $previewSamples);
        $this->assignScopeVars([
            'storage_scope' => $editScope,
            'website_code' => (string)$workScope['website_code'],
            'store_code' => (string)$workScope['store_code'],
            'channel_code' => (string)$workScope['channel_code'],
        ]);
        return $this->fetch('Weline_Smtp::Backend/Template/edit');
    }

    #[Acl('Weline_Smtp::smtp_template_save', '保存模板', 'save', '保存邮件模板', 'Weline_Smtp::system_smtp_template')]
    public function postSave(): string
    {
        $workScope = $this->resolveWorkScope(false);
        $templateId = (int)$this->request->getPost('template_id', 0);
        $channel = trim((string)$this->request->getPost('channel_code', ''));
        $locale = trim((string)$this->request->getPost('locale', 'zh_Hans_CN'));
        $storageScope = trim((string)$this->request->getPost('storage_scope', $workScope['storage_scope']));
        $subject = (string)$this->request->getPost('subject', '');
        $bodyHtml = (string)$this->request->getPost('body_html', '');
        $bodyText = (string)$this->request->getPost('body_text', '');

        /** @var MailTemplateRenderer $renderer */
        $renderer = ObjectManager::getInstance(MailTemplateRenderer::class);
        $bodyHtml = $renderer->normalizeEmailHtml($bodyHtml);
        /** @var \Weline\Smtp\Service\MailTemplateShellComposer $shell */
        $shell = ObjectManager::getInstance(\Weline\Smtp\Service\MailTemplateShellComposer::class);
        $bodyHtml = $shell->extractBodyFragment($bodyHtml);
        if (trim($bodyText) === '') {
            $bodyText = $renderer->htmlToText($bodyHtml);
        }

        /** @var SmtpMailTemplate $model */
        $model = ObjectManager::getInstance(SmtpMailTemplate::class);
        if ($templateId > 0) {
            $model->load($templateId);
        } else {
            $existing = $model->clear()
                ->where(SmtpMailTemplate::schema_fields_CHANNEL_CODE, $channel)
                ->where(SmtpMailTemplate::schema_fields_STORAGE_SCOPE, $storageScope)
                ->where(SmtpMailTemplate::schema_fields_LOCALE, $locale)
                ->find()
                ->fetch();
            if ($existing && $existing->getId()) {
                $model = $existing;
            }
        }

        $model->setData(SmtpMailTemplate::schema_fields_CHANNEL_CODE, $channel)
            ->setData(SmtpMailTemplate::schema_fields_STORAGE_SCOPE, $storageScope)
            ->setData(SmtpMailTemplate::schema_fields_LOCALE, $locale)
            ->setData(SmtpMailTemplate::schema_fields_SUBJECT, $subject)
            ->setData(SmtpMailTemplate::schema_fields_BODY_HTML, $bodyHtml)
            ->setData(SmtpMailTemplate::schema_fields_BODY_TEXT, $bodyText)
            ->setData(SmtpMailTemplate::schema_fields_SOURCE, SmtpMailTemplate::SOURCE_CUSTOM)
            ->setData(SmtpMailTemplate::schema_fields_USE_DEFAULT, 0)
            ->setData(SmtpMailTemplate::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();

        $this->getMessageManager()->addSuccess(__('模板已保存'));
        $returnState = $this->listingReturnState(true);
        if ($returnState['focus_locale'] === '') {
            $returnState['focus_locale'] = $locale;
        }
        $this->redirect($this->scopedUrl(
            'smtp/backend/template/edit',
            $workScope,
            $this->withListingReturn([
                'template_id' => (int)$model->getId(),
                'channel' => $channel,
                'locale' => $locale,
            ], $returnState)
        ));
        return '';
    }

    #[Acl('Weline_Smtp::smtp_template_reset', '重置默认', 'refresh', '重置为 Extends 默认模板', 'Weline_Smtp::system_smtp_template')]
    public function postReset(): string
    {
        $workScope = $this->resolveWorkScope(false);
        $channel = trim((string)$this->request->getPost('channel_code', ''));
        $locale = trim((string)$this->request->getPost('locale', 'zh_Hans_CN'));
        $storageScope = trim((string)$this->request->getPost('storage_scope', SystemConfig::SCOPE_GLOBAL));

        /** @var SmtpMailTemplate $model */
        $model = ObjectManager::getInstance(SmtpMailTemplate::class);
        $row = $model->clear()
            ->where(SmtpMailTemplate::schema_fields_CHANNEL_CODE, $channel)
            ->where(SmtpMailTemplate::schema_fields_STORAGE_SCOPE, $storageScope)
            ->where(SmtpMailTemplate::schema_fields_LOCALE, $locale)
            ->find()
            ->fetch();
        if ($row && $row->getId()) {
            $row->setData(SmtpMailTemplate::schema_fields_USE_DEFAULT, 1)
                ->setData(SmtpMailTemplate::schema_fields_SOURCE, SmtpMailTemplate::SOURCE_MODULE)
                ->setData(SmtpMailTemplate::schema_fields_SEED_HASH, '')
                ->save();
        }

        /** @var MailChannelCollector $collector */
        $collector = ObjectManager::getInstance(MailChannelCollector::class);
        $meta = $collector->getByCode($channel);
        if ($meta) {
            /** @var MailTemplateSeeder $seeder */
            $seeder = ObjectManager::getInstance(MailTemplateSeeder::class);
            $seeder->syncChannel($meta, $storageScope);
        }

        $this->getMessageManager()->addSuccess(__('已重置为默认模板'));
        $returnState = $this->listingReturnState(true);
        if ($returnState['focus_locale'] === '') {
            $returnState['focus_locale'] = $locale;
        }
        $this->redirect($this->scopedUrl(
            'smtp/backend/template/edit',
            $workScope,
            $this->withListingReturn([
                'channel' => $channel,
                'locale' => $locale,
            ], $returnState)
        ));
        return '';
    }

    #[Acl('Weline_Smtp::smtp_template_copy', '复制模板', 'copy', '复制到其它范围或语言', 'Weline_Smtp::system_smtp_template')]
    public function postCopy(): string
    {
        $workScope = $this->resolveWorkScope(false);
        $templateId = (int)$this->request->getPost('template_id', 0);
        $targetScope = trim((string)$this->request->getPost('target_storage_scope', ''));
        $targetLocale = trim((string)$this->request->getPost('target_locale', ''));
        if ($targetScope === '') {
            $targetScope = (string)$workScope['storage_scope'];
        }
        if ($targetLocale === '') {
            $targetLocale = 'zh_Hans_CN';
        }

        /** @var SmtpMailTemplate $model */
        $model = ObjectManager::getInstance(SmtpMailTemplate::class);
        $src = $model->load($templateId);
        if (!$src->getId()) {
            $this->getMessageManager()->addError(__('源模板不存在'));
            $this->redirect($this->scopedUrl('smtp/backend/template/listing', $workScope));
            return '';
        }

        $channel = (string)$src->getData(SmtpMailTemplate::schema_fields_CHANNEL_CODE);
        /** @var SmtpMailTemplate $dest */
        $dest = ObjectManager::getInstance(SmtpMailTemplate::class);
        $existing = $dest->clear()
            ->where(SmtpMailTemplate::schema_fields_CHANNEL_CODE, $channel)
            ->where(SmtpMailTemplate::schema_fields_STORAGE_SCOPE, $targetScope)
            ->where(SmtpMailTemplate::schema_fields_LOCALE, $targetLocale)
            ->find()
            ->fetch();
        if ($existing && $existing->getId()) {
            $dest = $existing;
        } else {
            $dest->clearData();
        }
        $dest->setData(SmtpMailTemplate::schema_fields_CHANNEL_CODE, $channel)
            ->setData(SmtpMailTemplate::schema_fields_STORAGE_SCOPE, $targetScope)
            ->setData(SmtpMailTemplate::schema_fields_LOCALE, $targetLocale)
            ->setData(SmtpMailTemplate::schema_fields_SUBJECT, (string)$src->getData(SmtpMailTemplate::schema_fields_SUBJECT))
            ->setData(SmtpMailTemplate::schema_fields_BODY_HTML, (string)$src->getData(SmtpMailTemplate::schema_fields_BODY_HTML))
            ->setData(SmtpMailTemplate::schema_fields_BODY_TEXT, (string)$src->getData(SmtpMailTemplate::schema_fields_BODY_TEXT))
            ->setData(SmtpMailTemplate::schema_fields_SOURCE, SmtpMailTemplate::SOURCE_CUSTOM)
            ->setData(SmtpMailTemplate::schema_fields_USE_DEFAULT, 0)
            ->setData(SmtpMailTemplate::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();

        $this->getMessageManager()->addSuccess(__('已复制模板'));
        $this->redirect($this->scopedUrl('smtp/backend/template/edit', [
            'storage_scope' => $targetScope,
            'website_code' => '',
            'store_code' => '',
            'channel_code' => '',
        ], [
            'template_id' => (int)$dest->getId(),
            'channel' => $channel,
            'locale' => $targetLocale,
            'target_scope' => $targetScope,
        ]));
        return '';
    }

    /**
     * 渠道列表筛选位置：搜索词 / 语言多选 / 焦点语言行（编辑往返保持）。
     *
     * @return array{q:string,locales:string,focus_locale:string}
     */
    private function listingReturnState(bool $fromPost): array
    {
        $bag = $fromPost ? $this->request->getPost() : $this->request->getGet();
        $q = trim((string)($bag['q'] ?? $bag['return_q'] ?? ''));
        if (strlen($q) > 200) {
            $q = substr($q, 0, 200);
        }
        $localesRaw = trim((string)($bag['locales'] ?? $bag['return_locales'] ?? ''));
        $parts = preg_split('/\s*,\s*/', $localesRaw) ?: [];
        $clean = [];
        foreach ($parts as $part) {
            $code = trim((string)$part);
            if ($code !== '' && preg_match('/^[A-Za-z0-9_]+$/', $code) === 1) {
                $clean[$code] = true;
            }
        }
        $locales = implode(',', array_keys($clean));
        $focus = trim((string)($bag['focus_locale'] ?? $bag['return_focus_locale'] ?? ''));
        if ($focus !== '' && preg_match('/^[A-Za-z0-9_]+$/', $focus) !== 1) {
            $focus = '';
        }
        return [
            'q' => $q,
            'locales' => $locales,
            'focus_locale' => $focus,
        ];
    }

    /**
     * @param array<string, scalar> $extra
     * @param array{q:string,locales:string,focus_locale:string} $returnState
     * @return array<string, scalar>
     */
    private function withListingReturn(array $extra, array $returnState): array
    {
        if ($returnState['q'] !== '') {
            $extra['q'] = $returnState['q'];
        }
        if ($returnState['locales'] !== '') {
            $extra['locales'] = $returnState['locales'];
        }
        if ($returnState['focus_locale'] !== '') {
            $extra['focus_locale'] = $returnState['focus_locale'];
        }
        return $extra;
    }

    /**
     * @return array{kind:string,website_code:string,store_code:string,channel_code:string,storage_scope:string}
     */
    private function resolveWorkScope(bool $normalizeUrl): array
    {
        /** @var SystemConfigTargetScopeService $targetScopeService */
        $targetScopeService = ObjectManager::getInstance(SystemConfigTargetScopeService::class);
        $get = $this->request->getGet();
        $post = $this->request->isPost() ? $this->request->getPost() : [];
        $input = [
            'target_scope' => (string)($post['target_scope'] ?? $get['target_scope'] ?? ''),
            'scope' => (string)($post['scope'] ?? $get['scope'] ?? ''),
        ];
        // 仅当请求显式携带分段键时再传入，避免空 website_code 覆盖 target_scope
        foreach (['website_code', 'store_code', 'channel_code', 'scope_kind', 'store_mode'] as $key) {
            if (\array_key_exists($key, $post) || \array_key_exists($key, $get)) {
                $input[$key] = (string)($post[$key] ?? $get[$key] ?? '');
            }
        }
        $resolved = $targetScopeService->resolveFromInput($input, false);
        $storageScope = (string)($resolved['storage_scope'] ?? SystemConfig::SCOPE_GLOBAL);
        $hasExplicit = trim((string)($get['target_scope'] ?? '')) !== ''
            || trim((string)($get['scope'] ?? '')) !== ''
            || array_key_exists('website_code', $get)
            || trim((string)($post['target_scope'] ?? '')) !== '';
        if ($normalizeUrl && !$hasExplicit && !$this->request->isPost()) {
            $this->redirect($this->scopedUrl('smtp/backend/template/listing', $resolved));
        }
        return [
            'kind' => (string)($resolved['kind'] ?? 'global'),
            'website_code' => (string)($resolved['website_code'] ?? ''),
            'store_code' => (string)($resolved['store_code'] ?? ''),
            'channel_code' => (string)($resolved['channel_code'] ?? ''),
            'storage_scope' => $storageScope,
        ];
    }

    /** @param array{storage_scope?:string,website_code?:string,store_code?:string,channel_code?:string} $workScope */
    private function assignScopeVars(array $workScope): void
    {
        $storageScope = (string)($workScope['storage_scope'] ?? SystemConfig::SCOPE_GLOBAL);
        $this->assign('selected_scope', $storageScope);
        $this->assign('target_scope', $storageScope);
        $this->assign('scope_website_code', (string)($workScope['website_code'] ?? ''));
        $this->assign('scope_store_code', (string)($workScope['store_code'] ?? ''));
        $this->assign('scope_channel_code', (string)($workScope['channel_code'] ?? ''));
    }

    /**
     * @param array{storage_scope?:string,website_code?:string,store_code?:string,channel_code?:string} $workScope
     * @param array<string, scalar> $extra
     */
    private function scopedUrl(string $path, array $workScope, array $extra = []): string
    {
        $params = array_merge([
            'target_scope' => (string)($workScope['storage_scope'] ?? SystemConfig::SCOPE_GLOBAL),
            'website_code' => (string)($workScope['website_code'] ?? ''),
            'store_code' => (string)($workScope['store_code'] ?? ''),
            'channel_code' => (string)($workScope['channel_code'] ?? ''),
        ], $extra);
        return $this->_url->getBackendUrl($path, $params);
    }
}
