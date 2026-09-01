<?php

declare(strict_types=1);

namespace Weline\Catalog\Controller\Backend;

use Weline\Catalog\Service\GoogleTaxonomyService;
use Weline\Catalog\Service\GoogleTaxonomyTranslationQueueService;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Ui\FormKey;

final class GoogleTaxonomy extends BackendController
{
    public function __construct(
        private readonly GoogleTaxonomyService $taxonomy,
        private readonly GoogleTaxonomyTranslationQueueService $translationQueue,
    ) {
    }

    protected function csrf(): string
    {
        return FormKey::key_name;
    }

    #[Acl(
        'Weline_Catalog::commerce:universal-catalog:google-taxonomy',
        'Google 产品分类',
        'tree',
        '只读 Google 分类参照与 AI 译名',
        'Weline_Backend::commerce:catalog:group',
    )]
    public function index(): string
    {
        $q = trim((string)$this->request->getGet('q', ''));
        $parentId = trim((string)$this->request->getGet('parent_id', ''));
        $selectedId = trim((string)$this->request->getGet('id', ''));
        $locale = trim((string)$this->request->getGet('locale', 'zh_Hans_CN'));

        if ($q !== '') {
            $rows = $this->taxonomy->search($q, 100);
            $children = $rows;
        } elseif ($parentId !== '') {
            $rows = $this->taxonomy->listChildren($parentId);
            $children = $rows;
        } else {
            $rows = [];
            $children = $this->taxonomy->listRoots();
        }

        $selected = $selectedId !== '' ? $this->taxonomy->find($selectedId) : null;
        if ($selected === null && $children !== [] && $q === '') {
            $selectedId = (string)($children[0]['google_id'] ?? '');
            $selected = $this->taxonomy->find($selectedId);
        }

        $this->assign('title', (string)__('Google 产品分类'));
        $this->assign('layoutShowPageHeader', false);
        $meta = is_array($this->getData('meta')) ? $this->getData('meta') : [];
        $meta['showPageHeader'] = false;
        $this->assign('meta', $meta);
        $this->assign('rows', $rows);
        $this->assign('children', $children);
        $this->assign('selected', $selected);
        $this->assign('selected_id', $selectedId);
        $this->assign('parent_id', $parentId);
        $this->assign('breadcrumb', $parentId !== '' ? $this->taxonomy->breadcrumb($parentId) : []);
        $this->assign('q', $q);
        $this->assign('locale', $locale);
        $this->assign('total_count', $this->taxonomy->countRows());
        $this->assign('taxonomy_version', $this->readBundledVersion());

        return (string)$this->fetch('Weline_Catalog::templates/backend/google-taxonomy/index.phtml');
    }

    #[Acl(
        'Weline_Catalog::commerce:universal-catalog:google-taxonomy',
        'Google 分类 AI 翻译',
        'language',
        '入队 Google 分类 AI 翻译',
    )]
    public function postEnqueueAi(): string
    {
        try {
            $locale = trim((string)$this->request->getPost('locale', 'zh_Hans_CN'));
            $ids = $this->request->getPost('google_ids', []);
            if (!is_array($ids)) {
                $ids = array_filter(array_map('trim', explode(',', (string)$ids)));
            }
            $queueId = $this->translationQueue->enqueue($locale, $ids);
            if ($this->request->isAjax()) {
                return $this->fetchJson([
                    'success' => true,
                    'msg' => (string)__('已入队 AI 翻译'),
                    'data' => ['queue_id' => $queueId],
                ]);
            }
            $this->getMessageManager()->addSuccess(__('已入队 AI 翻译（队列 #%{1}）', [$queueId]));
        } catch (\Throwable $exception) {
            if ($this->request->isAjax()) {
                return $this->fetchJson(['success' => false, 'msg' => $exception->getMessage()]);
            }
            $this->getMessageManager()->addError($exception->getMessage());
        }

        return (string)$this->redirect('weline_catalog/backend/google-taxonomy/index');
    }

    private function readBundledVersion(): string
    {
        $path = $this->taxonomy->defaultOfficialFilePath();
        if (!is_readable($path)) {
            return '';
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }
        $firstLine = (string)fgets($handle);
        fclose($handle);
        if (preg_match('/Google_Product_Taxonomy_Version:\s*(.+)$/i', $firstLine, $matches)) {
            return trim((string)$matches[1]);
        }

        return '';
    }
}
