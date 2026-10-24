<?php
declare(strict_types=1);

namespace Weline\App\Controller\Backend;

use Weline\App\Model\AppBuild;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\Message;
use Weline\Websites\Api\Catalog\WebsiteCatalogInterface;

#[Acl('Weline_App::build', 'App 打包配置', 'circle', 'App 打包配置管理')]
class Build extends BackendController
{
    #[Acl('Weline_App::build_index', '查看App打包配置', 'circle', '查看App打包配置')]
    public function index(): string
    {
        $builds = ObjectManager::getInstance(AppBuild::class)
            ->clear()->find()->fetch();
        $builds = is_iterable($builds) ? $builds : [];
        $websites = ObjectManager::getInstance(WebsiteCatalogInterface::class)->all();

        $this->assign('builds', $builds);
        $this->assign('websites', $websites);
        $this->assign('save_url', $this->getUrl('*/backend/save'));
        $this->assign('title', __('App 打包配置'));
        $this->assign('page_title', __('App 打包配置'));

        return $this->fetch('Weline_App::templates/backend/Build/index.phtml');
    }

    #[Acl('Weline_App::build_save', '保存App打包配置', 'edit', '保存App打包配置')]
    public function postSave(): string
    {
        try {
            $websiteId = (int)$this->request->getPost('website_id', 0);
            $domain = trim((string)$this->request->getPost('domain', ''));
            $platforms = (array)$this->request->getPost('platforms', []);
            $enabled = (bool)$this->request->getPost('enabled');

            if ($websiteId < 0) {
                Message::error(__('网站ID无效'));
                $this->redirect('*/backend/build');
                return '';
            }
            $validPlatforms = ['android', 'web', 'ios'];
            $platforms = array_values(array_intersect($platforms, $validPlatforms));
            if (empty($platforms)) {
                Message::error(__('请至少选择一个目标平台'));
                $this->redirect('*/backend/build');
                return '';
            }

            $build = ObjectManager::getInstance(AppBuild::class)
                ->clear()->where('website_id', $websiteId)->find()->fetch();
            $data = [
                'website_id' => $websiteId,
                'domain' => $domain,
                'platforms' => json_encode($platforms, JSON_UNESCAPED_UNICODE),
                'enabled' => $enabled ? 1 : 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($build->getId()) {
                $build->setData($data);
            } else {
                $data['created_at'] = date('Y-m-d H:i:s');
                $build->setData($data);
            }
            $build->save();

            Message::success(__('App 打包配置已保存'));
        } catch (\Throwable $e) {
            Message::error(__('App 打包配置保存失败：%{1}', $e->getMessage()));
        }
        $this->redirect('*/backend/build');
        return '';
    }
}
