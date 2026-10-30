<?php
/**
 * 回归验收：后台通知页（/system/backend/notification）的模型对象化契约
 *
 * 背景（2026-10-30）：多行 select()->fetch() 返回 RowCollection（每行是关联数组），
 * 消费方若仍对行调用模型方法（getData()/getId()）会崩溃（Call to a member function
 * getData() on array）或静默拿到空列表。
 *
 * 运行：php tests/accept_backend_notification_fetch.php
 * 退出码：0 = 全部通过；1 = 有失败
 */

require_once __DIR__ . '/../app/bootstrap.php';

use Weline\Backend\Model\Menu;
use Weline\Backend\Model\UserNotificationStatus;
use Weline\Backend\Service\NotificationService;
use Weline\Backend\Service\TopicCollector;
use Weline\Framework\Database\Model;
use Weline\Framework\Manager\ObjectManager;

$pass = 0;
$fail = 0;
function verdict(string $id, bool $ok, string $detail): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "[PASS] {$id}  {$detail}\n";
    } else {
        $fail++;
        echo "[FAIL] {$id}  {$detail}\n";
    }
}

echo "=== A1 getParentPaths：parents 必须是模型对象（面包屑 / Taglib Breadcrumb 依赖 instanceof） ===\n";
$target = ObjectManager::getInstance(Menu::class)
    ->reset()
    ->where(Menu::schema_fields_ACTION, 'system/backend/notification')
    ->find()
    ->fetch();
verdict('A1a', (int)$target->getId() > 0, '通知中心菜单存在 menu_id=' . $target->getId());
try {
    ObjectManager::getInstance(Menu::class)->getParentPaths(
        $target,
        Menu::schema_fields_ID,
        Menu::schema_fields_PID,
        Menu::schema_fields_ORDER,
        'ASC'
    );
    $parents = (array)$target->getData('parents');
    $bad = 0;
    foreach ($parents as $p) {
        if (!$p instanceof Model) {
            $bad++;
        }
    }
    verdict('A1b', $bad === 0, 'parents 行数=' . count($parents) . ' 非模型行数=' . $bad);
} catch (\Error $e) {
    verdict('A1b', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

echo "\n=== A2 getUserNotifications：列表不能恒为空 ===\n";
$probe = ObjectManager::getInstance(UserNotificationStatus::class);
$firstRow = $probe->reset()->limit(1)->select()->fetch()->first();
$userId = (int)($firstRow['user_id'] ?? 0);
verdict('A2a', $userId > 0, '状态表首行 user_id=' . $userId);
$service = ObjectManager::getInstance(NotificationService::class);
$result = $service->getUserNotifications($userId, 1, 15, false, []);
verdict('A2b', count($result['items']) > 0, 'total=' . $result['total'] . ' items=' . count($result['items']) . '（修复前 items=0）');
verdict('A2c', !empty($result['items'][0]['title']), '首条 title=' . ($result['items'][0]['title'] ?? '(空)'));

echo "\n=== A3 getTopicByCode：单行语义必须返回模型数据 ===\n";
$collector = ObjectManager::getInstance(TopicCollector::class);
$topic = $collector->getTopicByCode('seo_sitemap');
verdict('A3a', is_array($topic) && ($topic['topic_name'] ?? '') !== '', 'topic_name=' . ($topic['topic_name'] ?? '(null)'));
verdict('A3b', $collector->getTopicByCode('__no_such_topic__') === null, '未知 topic 返回 null');

echo "\n=== A4 getAdjacentNotifications：prev/next 行取法不再挂在 RowCollection 上 ===\n";
$notificationId = (int)($result['items'][0]['notification_id'] ?? 0);
$adjacent = $service->getAdjacentNotifications($userId, $notificationId);
verdict('A4a', $adjacent['prev_id'] !== null || $adjacent['next_id'] !== null,
    'prev_id=' . var_export($adjacent['prev_id'], true) . ' next_id=' . var_export($adjacent['next_id'], true));

echo "\n结果: PASS={$pass} FAIL={$fail}\n";
exit($fail === 0 ? 0 : 1);
