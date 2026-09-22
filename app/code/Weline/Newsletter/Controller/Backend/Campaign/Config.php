<?php

declare(strict_types=1);

namespace Weline\Newsletter\Controller\Backend\Campaign;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Newsletter\Service\SubscribeGiftCampaignSyncService;
use Weline\Newsletter\Service\SubscribeGiftConfig;

#[Acl('Weline_Newsletter::campaign_config', '邮件订阅有奖配置', 'mdi-gift-outline', '配置邮件订阅欢迎礼并同步 Marketing', 'Weline_Backend::marketing_group')]
final class Config extends BackendController
{
    #[Acl('Weline_Newsletter::campaign_config_index', '查看邮件订阅有奖配置', 'mdi-cog', '打开有奖配置页')]
    public function index(): string
    {
        /** @var SubscribeGiftConfig $config */
        $config = ObjectManager::getInstance(SubscribeGiftConfig::class);
        $this->assign('config', $config->read());
        $this->assign('save_url', $this->getUrl('newsletter/backend/campaign/config/save'));

        return $this->fetch('Weline_Newsletter::templates/backend/campaign/config.phtml');
    }

    #[Acl('Weline_Newsletter::campaign_config_save', '保存邮件订阅有奖配置', 'mdi-content-save', '保存有奖参数并同步 Marketing')]
    public function postSave()
    {
        $enabled = (string)$this->request->getPost('enabled', '0') === '1'
            || (string)$this->request->getPost('enabled', '') === 'on';
        $payload = [
            'enabled' => $enabled,
            'discount_type' => (string)$this->request->getPost('discount_type', 'percentage'),
            'discount_value' => (float)$this->request->getPost('discount_value', 10),
            'valid_days' => (int)$this->request->getPost('valid_days', 14),
            'popup_cookie_days' => (int)$this->request->getPost('popup_cookie_days', 14),
        ];

        /** @var SubscribeGiftConfig $config */
        $config = ObjectManager::getInstance(SubscribeGiftConfig::class);
        $current = $config->read();
        $payload['marketing_rule_id'] = (int)($current['marketing_rule_id'] ?? 0);
        $config->write($payload);

        /** @var SubscribeGiftCampaignSyncService $sync */
        $sync = ObjectManager::getInstance(SubscribeGiftCampaignSyncService::class);
        $synced = $sync->sync($payload);

        $this->getMessageManager()->addSuccess((string)\__(
            '有奖配置已保存（rule_id=%{1}）。',
            (string)($synced['rule_id'] ?? 0)
        ));

        return $this->redirect($this->getUrl('newsletter/backend/campaign/config'));
    }
}
