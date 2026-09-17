<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/11/5 01:23:06
 */

namespace Weline\Smtp\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Manager\ObjectManager;
use Weline\Smtp\Model\SmtpSendLog;
use Weline\Smtp\Service\MailChannelCollector;
use Weline\Smtp\Service\SmtpSendLogListPresenter;
use Weline\SystemConfig\Service\SystemConfigTargetScopeService;

#[Acl('Weline_Smtp::system_smtp_log', 'SMTP 发件记录', 'check', '查看 SMTP 发送日志', 'Weline_Smtp::system_smtp')]
class Log extends BackendController
{
    /**
     * @var \Weline\Smtp\Model\SmtpSendLog
     */
    private SmtpSendLog $smtpSendLog;

    function __construct(SmtpSendLog $smtpSendLog)
    {
        $this->smtpSendLog = $smtpSendLog;
    }

    function listing()
    {
        $workScope = $this->resolveWorkScope(true);
        $storageScope = (string)$workScope['storage_scope'];
        $scopeKind = (string)$workScope['kind'];

        /** @var MailChannelCollector $channelCollector */
        $channelCollector = ObjectManager::getInstance(MailChannelCollector::class);
        $channels = $channelCollector->collect();
        $channelFilter = trim((string)$this->request->getGet('channel', ''));
        if ($channelFilter === 'all') {
            $channelFilter = '';
        }

        $query = $this->smtpSendLog->clear()->order('create_time', 'DESC');
        if ($channelFilter === '__none__') {
            $query->where(SmtpSendLog::schema_fields_CHANNEL, '', '=');
        } elseif ($channelFilter !== '') {
            $query->where(SmtpSendLog::schema_fields_CHANNEL, $channelFilter, '=');
        }

        // Global：看全部；具体网站/店铺/渠道：精确匹配 storage_scope
        if ($scopeKind !== 'global' && $storageScope !== '' && $storageScope !== 'default.default.default') {
            $query->where(SmtpSendLog::schema_fields_STORAGE_SCOPE, $storageScope, '=');
        }

        $listings = $query->pagination()->select()->fetch();
        $logs = $listings->getOriginData();
        $channelNameMap = [];
        foreach ($channels as $ch) {
            $code = (string)($ch['code'] ?? '');
            if ($code !== '') {
                $channelNameMap[$code] = (string)($ch['name'] ?? $code);
            }
        }
        /** @var SmtpSendLogListPresenter $presenter */
        $presenter = ObjectManager::getInstance(SmtpSendLogListPresenter::class);
        foreach ($logs as &$log) {
            if (!is_array($log)) {
                continue;
            }
            $log = $presenter->presentRow($log, $channelNameMap);
            $logId = (int)($log['id'] ?? 0);
            $log['preview_url'] = $logId > 0
                ? $this->_url->getBackendUrl('smtp/backend/log', [
                    'log_id' => $logId,
                    'embed' => '1',
                ])
                : '';
            // 列表不传正文，避免模板误渲染 HTML / 减小 payload
            unset($log['content'], $log['alt']);
        }
        unset($log);

        $this->assign('logs', $logs);
        $this->assign('pagination', $listings->getPagination());
        $this->assign('total', $listings->getPaginationData()['totalSize']);
        $this->assign('mail_channels', $channels);
        $this->assign('channel_filter', $channelFilter);
        $this->assign('channel_name_map', $channelNameMap);
        $this->assignScopeVars($workScope);
        return $this->fetch();
    }

    /**
     * embed 预览页：直出独立 HTML，绕过 Theme fetch_file_* 布局包装。
     */
    private function respondEmbedDocument(): never
    {
        $this->layoutType = null;
        $html = (string)$this->getTemplate()->fetch('Weline_Smtp::Backend/Log/embed');
        $response = $this->request->getResponse();
        $response->setHeader('Content-Type', 'text/html; charset=UTF-8');
        $response->setBody($html);
        throw new ResponseTerminateException($response);
    }

    function get()
    {
        $logId = (int)$this->request->getGet('log_id', 0);
        $embed = trim((string)$this->request->getGet('embed', '')) === '1';
        $log = $this->smtpSendLog->load($logId);

        if (!$log->getId()) {
            if ($embed) {
                $this->assign('embed_error', (string)__('发件记录不存在'));
                $this->assign('log_view', []);
                $this->respondEmbedDocument();
            }
            $this->getMessageManager()->addError(__('发件记录不存在'));

            return $this->redirect($this->scopedListingUrl($this->resolveWorkScope(false), ''));
        }

        /** @var MailChannelCollector $channelCollector */
        $channelCollector = ObjectManager::getInstance(MailChannelCollector::class);
        $channelNameMap = [];
        foreach ($channelCollector->collect() as $ch) {
            $code = (string)($ch['code'] ?? '');
            if ($code !== '') {
                $channelNameMap[$code] = (string)($ch['name'] ?? $code);
            }
        }
        /** @var SmtpSendLogListPresenter $presenter */
        $presenter = ObjectManager::getInstance(SmtpSendLogListPresenter::class);
        $row = $presenter->presentRow($log->getData(), $channelNameMap);

        $this->assign('log', $log);
        $this->assign('log_view', $row);
        $this->assign('embed_error', '');
        $this->assign('back_url', $this->scopedListingUrl(
            $this->resolveWorkScope(false),
            trim((string)($row['channel'] ?? ''))
        ));

        // embed=1：独立 HTML 文档供弹层 iframe（不包后台壳）
        if ($embed) {
            $this->respondEmbedDocument();
        }

        // get() 默认解析为 templates/Backend/Log，须显式指向阅读模板
        return $this->fetch('Weline_Smtp::Backend/Log/get');
    }

    function postDelete()
    {
        $log = $this->smtpSendLog->load($this->request->getPost('log_id', 0));
        if ($log->getId()) {
            $log->delete();
            $this->getMessageManager()->addSuccess(__('删除成功！'));
        } else {
            $this->getMessageManager()->addSuccess(__('你要删除的记录已不存在！'));
        }
        $workScope = $this->resolveWorkScope(false);
        $this->redirect($this->scopedListingUrl($workScope, trim((string)$this->request->getPost('channel', ''))));
    }

    /**
     * @param bool $normalizeUrl 为 true 时若缺少显式范围则 302 规范化到含 target_scope 的 URL
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
            'website_code' => (string)($post['website_code'] ?? $get['website_code'] ?? ''),
            'store_code' => (string)($post['store_code'] ?? $get['store_code'] ?? ''),
            'channel_code' => (string)($post['channel_code'] ?? $get['channel_code'] ?? ''),
        ];

        $resolved = $targetScopeService->resolveFromInput($input, false);
        $storageScope = (string)($resolved['storage_scope'] ?? 'default.default.default');

        $hasExplicit = trim((string)($get['target_scope'] ?? '')) !== ''
            || trim((string)($get['scope'] ?? '')) !== ''
            || array_key_exists('website_code', $get)
            || trim((string)($post['target_scope'] ?? '')) !== '';

        if ($normalizeUrl && !$hasExplicit && !$this->request->isPost()) {
            $channel = trim((string)$this->request->getGet('channel', ''));
            $this->redirect($this->scopedListingUrl($resolved, $channel));
        }

        return [
            'kind' => (string)($resolved['kind'] ?? 'global'),
            'website_code' => (string)($resolved['website_code'] ?? ''),
            'store_code' => (string)($resolved['store_code'] ?? ''),
            'channel_code' => (string)($resolved['channel_code'] ?? ''),
            'storage_scope' => $storageScope,
        ];
    }

    /**
     * @param array{storage_scope?:string,website_code?:string,store_code?:string,channel_code?:string,kind?:string} $workScope
     */
    private function assignScopeVars(array $workScope): void
    {
        $storageScope = (string)($workScope['storage_scope'] ?? 'default.default.default');
        $this->assign('selected_scope', $storageScope);
        $this->assign('target_scope', $storageScope);
        $this->assign('scope_website_code', (string)($workScope['website_code'] ?? ''));
        $this->assign('scope_store_code', (string)($workScope['store_code'] ?? ''));
        $this->assign('scope_channel_code', (string)($workScope['channel_code'] ?? ''));
        $this->assign('scope_kind', (string)($workScope['kind'] ?? 'global'));
    }

    /**
     * @param array{storage_scope?:string,website_code?:string,store_code?:string,channel_code?:string} $workScope
     */
    private function scopedListingUrl(array $workScope, string $channel = ''): string
    {
        $params = [
            'target_scope' => (string)($workScope['storage_scope'] ?? 'default.default.default'),
            'website_code' => (string)($workScope['website_code'] ?? ''),
            'store_code' => (string)($workScope['store_code'] ?? ''),
            'channel_code' => (string)($workScope['channel_code'] ?? ''),
        ];
        if ($channel !== '') {
            $params['channel'] = $channel;
        }
        return $this->_url->getBackendUrl('smtp/backend/log/listing', $params);
    }
}
