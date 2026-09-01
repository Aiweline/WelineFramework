<?php
declare(strict_types=1);

namespace Weline\Visitor\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Model\PixelErrorIncident;
use Weline\Visitor\Service\PixelErrorIncidentClassifier;

#[Acl('Weline_Visitor::pixel_error_monitor', '错误监控', 'warning', '像素错误监控', 'Weline_Visitor::pixel_dashboard')]
class PixelErrorMonitor extends BackendController
{
    #[Acl('Weline_Visitor::pixel_error_monitor_index', '查看错误监控列表', 'list', '查看错误监控列表')]
    public function index(): string
    {
        $filters = $this->collectFilters();
        $rows = [];
        $loadError = '';
        try {
            /** @var PixelErrorIncident $model */
            $model = ObjectManager::getInstance(PixelErrorIncident::class);
            $model->reset();
            if ($filters['website_id'] > 0) {
                $model->where(PixelErrorIncident::schema_fields_WEBSITE_ID, $filters['website_id']);
            }
            if ($filters['error_type'] !== '') {
                $model->where(PixelErrorIncident::schema_fields_ERROR_TYPE, $filters['error_type']);
            }
            if ($filters['disposition'] !== '') {
                $model->where(PixelErrorIncident::schema_fields_DISPOSITION, $filters['disposition']);
            } else {
                $model->where(PixelErrorIncident::schema_fields_DISPOSITION, PixelErrorIncident::DISPOSITION_OPEN);
            }
            if ($filters['stale'] === '1') {
                $model->where(PixelErrorIncident::schema_fields_STALE_CLIENT, 1);
            } elseif ($filters['stale'] === '0') {
                $model->where(PixelErrorIncident::schema_fields_STALE_CLIENT, 0);
            }
            if ($filters['has_email'] === '1') {
                $model->where(PixelErrorIncident::schema_fields_IDENTITY_EMAIL, '', 'neq');
            } elseif ($filters['has_email'] === '0') {
                $model->where(PixelErrorIncident::schema_fields_IDENTITY_KIND, PixelErrorIncident::IDENTITY_VISITOR);
            }
            if ($filters['theme_empty'] === '1') {
                $model->where(PixelErrorIncident::schema_fields_THEME_PUBLISHED_VERSION_ID, '');
            }
            $model->order(PixelErrorIncident::schema_fields_CREATED_AT, 'DESC')
                ->limit(100)
                ->select()
                ->fetch();
            $rows = $model->getItems() ?: [];
        } catch (\Throwable $e) {
            $loadError = $e->getMessage();
            MessageManager::warning((string)__('加载错误监控失败：%{1}', [$loadError]));
        }

        $this->assign('incidents', $rows);
        $this->assign('filters', $filters);
        $this->assign('load_error', $loadError);
        $this->assign('error_types', PixelErrorIncidentClassifier::ALL_TYPES);
        $this->assign('open_incident_id', max(0, (int)($this->request->getGet('open') ?? 0)));
        $this->assign('page_title', (string)__('错误监控'));

        return $this->fetch();
    }

    #[Acl('Weline_Visitor::pixel_error_monitor_detail', '查看错误详情', 'eye', '查看错误监控详情')]
    public function detail(): string
    {
        $id = (int)($this->request->getGet('id') ?? $this->request->getParam('id') ?? 0);
        $wantsJson = $this->wantsJson();

        if ($id <= 0) {
            if ($wantsJson) {
                return $this->fetchJson(['success' => false, 'message' => (string)__('缺少事故 ID')]);
            }
            MessageManager::error((string)__('缺少事故 ID'));
            return $this->redirect('*/pixel-error-monitor/index');
        }

        /** @var PixelErrorIncident $model */
        $model = ObjectManager::getInstance(PixelErrorIncident::class);
        $model->load($id);
        if (!(int)$model->getId()) {
            if ($wantsJson) {
                return $this->fetchJson(['success' => false, 'message' => (string)__('事故不存在')]);
            }
            MessageManager::error((string)__('事故不存在'));
            return $this->redirect('*/pixel-error-monitor/index');
        }

        $payload = $this->buildIncidentPayload($model->getData());
        if ($wantsJson) {
            return $this->fetchJson([
                'success' => true,
                'data' => $payload,
            ]);
        }

        // 独立详情页已改为列表弹层；旧链接落到列表并自动打开。
        return $this->redirect('*/pixel-error-monitor/index', ['open' => $id]);
    }

    #[Acl('Weline_Visitor::pixel_error_monitor_dispose', '处置错误事故', 'check', '标记错误事故处置状态')]
    public function postDispose(): string
    {
        $id = (int)($this->request->getPost('id') ?? 0);
        $disposition = \trim((string)($this->request->getPost('disposition') ?? ''));
        $wantsJson = $this->wantsJson();

        if ($id <= 0 || !\in_array($disposition, PixelErrorIncident::DISPOSITIONS, true)) {
            if ($wantsJson) {
                return $this->fetchJson(['success' => false, 'message' => (string)__('处置参数无效')]);
            }
            MessageManager::error((string)__('处置参数无效'));
            return $this->redirect('*/pixel-error-monitor/index');
        }

        /** @var PixelErrorIncident $model */
        $model = ObjectManager::getInstance(PixelErrorIncident::class);
        $model->load($id);
        if (!(int)$model->getId()) {
            if ($wantsJson) {
                return $this->fetchJson(['success' => false, 'message' => (string)__('事故不存在')]);
            }
            MessageManager::error((string)__('事故不存在'));
            return $this->redirect('*/pixel-error-monitor/index');
        }

        $model->setData(PixelErrorIncident::schema_fields_DISPOSITION, $disposition)
            ->setData(PixelErrorIncident::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();

        if ($wantsJson) {
            return $this->fetchJson([
                'success' => true,
                'message' => (string)__('已更新处置状态'),
                'data' => ['id' => $id, 'disposition' => $disposition],
            ]);
        }

        MessageManager::success((string)__('已更新处置状态'));
        return $this->redirect('*/pixel-error-monitor/index');
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function buildIncidentPayload(array $data): array
    {
        $classifier = new PixelErrorIncidentClassifier();
        $severity = \strtolower(\trim((string)($data[PixelErrorIncident::schema_fields_SEVERITY] ?? '')));
        if ($severity === '') {
            $severity = $classifier->severityForType((string)($data[PixelErrorIncident::schema_fields_ERROR_TYPE] ?? ''));
        }
        $stepPath = json_decode((string)($data[PixelErrorIncident::schema_fields_STEP_PATH_JSON] ?? '[]'), true);
        $formSnapshot = json_decode((string)($data[PixelErrorIncident::schema_fields_FORM_SNAPSHOT_JSON] ?? '{}'), true);

        $identity = (string)($data[PixelErrorIncident::schema_fields_IDENTITY_KIND] ?? 'visitor');
        if (!empty($data[PixelErrorIncident::schema_fields_IDENTITY_EMAIL])) {
            $identityLabel = (string)$data[PixelErrorIncident::schema_fields_IDENTITY_EMAIL];
        } elseif ($identity === 'user') {
            $identityLabel = 'user#' . (int)($data[PixelErrorIncident::schema_fields_USER_ID] ?? 0);
        } else {
            $identityLabel = (string)__('访客');
        }

        $themePublished = (string)(($data[PixelErrorIncident::schema_fields_THEME_PUBLISHED_VERSION] ?? '') !== ''
            ? $data[PixelErrorIncident::schema_fields_THEME_PUBLISHED_VERSION]
            : ($data[PixelErrorIncident::schema_fields_THEME_PUBLISHED_VERSION_ID] ?? ''));

        $errorType = (string)($data[PixelErrorIncident::schema_fields_ERROR_TYPE] ?? '');
        $disposition = (string)($data[PixelErrorIncident::schema_fields_DISPOSITION] ?? '');

        return [
            'incident_id' => (int)($data[PixelErrorIncident::schema_fields_ID] ?? 0),
            'error_type' => $errorType,
            'error_type_label' => (string)__($classifier->typeLabel($errorType)),
            'error_code' => (string)($data[PixelErrorIncident::schema_fields_ERROR_CODE] ?? ''),
            'error_message' => (string)($data[PixelErrorIncident::schema_fields_ERROR_MESSAGE] ?? ''),
            'error_stack' => (string)($data[PixelErrorIncident::schema_fields_ERROR_STACK] ?? ''),
            'page_url' => (string)($data[PixelErrorIncident::schema_fields_PAGE_URL] ?? ''),
            'session_id' => (string)($data[PixelErrorIncident::schema_fields_SESSION_ID] ?? ''),
            'pixel_id' => (int)($data[PixelErrorIncident::schema_fields_PIXEL_ID] ?? 0),
            'identity_label' => $identityLabel,
            'client_deploy_version' => (string)($data[PixelErrorIncident::schema_fields_CLIENT_DEPLOY_VERSION] ?? ''),
            'server_deploy_version' => (string)($data[PixelErrorIncident::schema_fields_SERVER_DEPLOY_VERSION] ?? ''),
            'worker_build_id' => (string)($data[PixelErrorIncident::schema_fields_WORKER_BUILD_ID] ?? ''),
            'theme_published_version' => $themePublished,
            'pixel_script_version' => (string)($data[PixelErrorIncident::schema_fields_PIXEL_SCRIPT_VERSION] ?? ''),
            'stale_client' => !empty($data[PixelErrorIncident::schema_fields_STALE_CLIENT]),
            'disposition' => $disposition,
            'disposition_label' => (string)__($classifier->dispositionLabel($disposition)),
            'created_at' => (string)($data[PixelErrorIncident::schema_fields_CREATED_AT] ?? ''),
            'severity' => $severity,
            'severity_tone' => $classifier->severityBadgeTone($severity),
            'severity_label' => (string)__($classifier->severityLabel($severity)),
            'step_path' => \is_array($stepPath) ? $stepPath : [],
            'form_snapshot' => \is_array($formSnapshot) ? $formSnapshot : [],
        ];
    }

    private function wantsJson(): bool
    {
        $format = \strtolower(\trim((string)($this->request->getGet('format') ?? $this->request->getPost('format') ?? '')));
        if ($format === 'json') {
            return true;
        }
        $acceptRaw = $this->request->getHeader('Accept');
        $accept = \strtolower(\is_array($acceptRaw) ? \implode(',', $acceptRaw) : (string)($acceptRaw ?? ''));
        if (\str_contains($accept, 'application/json')) {
            return true;
        }
        $xhrRaw = $this->request->getHeader('X-Requested-With');
        $xhr = \strtolower(\is_array($xhrRaw) ? \implode(',', $xhrRaw) : (string)($xhrRaw ?? ''));
        return $xhr === 'xmlhttprequest';
    }

    /**
     * @return array{website_id:int,error_type:string,disposition:string,stale:string,has_email:string,theme_empty:string}
     */
    private function collectFilters(): array
    {
        return [
            'website_id' => max(0, (int)($this->request->getGet('website_id') ?? 0)),
            'error_type' => \trim((string)($this->request->getGet('error_type') ?? '')),
            'disposition' => \trim((string)($this->request->getGet('disposition') ?? PixelErrorIncident::DISPOSITION_OPEN)),
            'stale' => \trim((string)($this->request->getGet('stale') ?? '')),
            'has_email' => \trim((string)($this->request->getGet('has_email') ?? '')),
            'theme_empty' => \trim((string)($this->request->getGet('theme_empty') ?? '')),
        ];
    }
}
