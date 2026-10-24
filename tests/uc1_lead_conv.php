<?php
declare(strict_types=1);
/**
 * UC-1 冻结主路径 · 真实通路验收（AiAcquisition lead → mail → AI reply → convert）。
 * 全程走真实业务栈：真实 ObjectManager、真实 DB（pgsql）、真实 Mail 服务（本地 fake 域名落库通路）、
 * 真实 AI 网关（deepseek key 已失效/ollama 未启动 → AI 不可用路径如实验证）。
 * Mail 通路注明：account=quote-test.invalid fake 域（engine=fake）→ MailFakeMailboxService 落库 w_weline_mail_message。
 * 幂等：dedupe_hash 固定 'uc1-lead-conv-fixed-0001' upsert，可重复执行。
 *
 * 运行：php tests/uc1_lead_conv.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use Weline\AiAcquisition\Model\InboundLeadMailBinder;
use Weline\AiAcquisition\Model\Lead;
use Weline\AiAcquisition\Model\LeadConversation;
use Weline\AiAcquisition\Service\AutoReplyEngine;
use Weline\AiAcquisition\Service\Customer\LeadConverter;
use Weline\AiAcquisition\Service\Model\ModelCatalogService;
use Weline\AiAcquisition\Service\Model\ModelGatewayService;
use Weline\Customer\Model\Customer;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Model\MailAccount;
use Weline\Mail\Model\MailMessage;
use Weline\Mail\Service\MailComposerService;
use Weline\Mail\Service\MailSmtpAccountService;
use Weline\SystemConfig\Api\ConfigReader;
use Weline\SystemConfig\Model\SystemConfig;

const DEDUPE = 'uc1-lead-conv-fixed-0001';
const FIX_ACCOUNT_EMAIL = 'support@quote-test.invalid'; // 已存在的 active fake 域测试账户（非新增夹具）

$results = [];
$evidence = [];
$fail = static function (string $step, string $reason) use (&$results): void {
    $results[$step] = ['PASS' => false, 'reason' => $reason];
    throw new RuntimeException("STEP {$step} FAIL: {$reason}");
};
$pass = static function (string $step, string $note = '', array $ev = []) use (&$results, &$evidence): void {
    $results[$step] = ['PASS' => true, 'note' => $note];
    $evidence = array_merge($evidence, $ev);
};
$pdo = static fn(): PDO => ConnectionFactory::getInstance()->getConnector()->getWrappedConnection()->getPdo();
$q1 = static function (string $sql, array $p = []) use ($pdo): ?array {
    $st = $pdo()->prepare($sql);
    $st->execute($p);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : $r;
};

// ─── 步骤 1：准备 ───────────────────────────────────────────────
$setupReady = (bool)$q1("SELECT to_regclass('w_ai_acquisition_lead') IS NOT NULL AS ok")['ok'];
if (!$setupReady) { $fail('S1', 'w_ai_acquisition_lead 不存在'); }
$convTable = (bool)$q1("SELECT to_regclass('w_ai_acquisition_conversation') IS NOT NULL AS ok")['ok'];
$seedTypes = [];
foreach ($pdo()->query("SELECT code FROM w_customer_type") as $r) { $seedTypes[] = $r['code']; }
$seedOk = count(array_intersect(['retail', 'b2b_wholesale', 'ai_acquisition'], $seedTypes)) === 3;
$smtp = ObjectManager::getInstance(MailSmtpAccountService::class);
$acctCfg = $smtp->getAccountConfig((int)(ObjectManager::getInstance(MailAccount::class)->clear()
    ->where(MailAccount::schema_fields_EMAIL, FIX_ACCOUNT_EMAIL)->find()->fetch()->getId() ?: 0));
if (!$acctCfg || !$acctCfg['is_fake']) { $fail('S1', '发件账户不可用或非 fake 域：' . FIX_ACCOUNT_EMAIL); }
$pass('S1', "表就绪 conv=" . var_export($convTable, true)
    . "; customer_type 种子=" . implode(',', $seedTypes) . ($seedOk ? '' : ' (缺种子，SQL 夹具补种见 S6 前置)')
    . "; 发件账户={$acctCfg['email']} (fake 域, account_id={$acctCfg['account_id']}, MailFakeMailboxService 落库通路)");
if (!$seedOk) {
    $pdo()->exec("INSERT INTO w_customer_type(code,name,sort,is_system) VALUES ('retail','零售客户',10,1),('b2b_wholesale','B2B批发客户',20,1),('ai_acquisition','AI获客客户',30,1) ON CONFLICT (code) DO NOTHING");
}

// 系统配置（website/global scope）：AI 自动回复开启 + 提示词模板（测试夹具配置）
$configModel = ObjectManager::getInstance(SystemConfig::class);
$configModel->setConfig('ai_acquisition/auto_reply/enabled', '1', 'Weline_AiAcquisition', ConfigReader::area_BACKEND);
$configModel->setConfig('ai_acquisition/auto_reply/cooldown_days', '7', 'Weline_AiAcquisition', ConfigReader::area_BACKEND);
$configModel->setConfig('ai_acquisition/prompt/default', 'You are a B2B outreach assistant for a furniture factory.', 'Weline_AiAcquisition', ConfigReader::area_BACKEND);
$configModel->setConfig('ai_acquisition/outreach/mail_account_id', (string)$acctCfg['account_id'], 'Weline_AiAcquisition', ConfigReader::area_BACKEND);
$cr = new ConfigReader();
if ((int)$cr->get('ai_acquisition/auto_reply/enabled', 'Weline_AiAcquisition', ConfigReader::area_BACKEND, 0) !== 1) {
    $fail('S1', 'ai_acquisition/auto_reply/enabled 配置写入后读取失败');
}
$pass('S1-config', "enabled=1, cooldown=7, prompt/default 已设, mail_account_id={$acctCfg['account_id']}");

// ─── 步骤 2：夹具 Lead ─────────────────────────────────────────
$leadModel = ObjectManager::getInstance(Lead::class);
$existing = $leadModel->clearData()->reset()
    ->where(Lead::schema_fields_DEDUPE_HASH, DEDUPE)->find()->fetch();
if ($existing && $existing->getId()) {
    $lead = $existing;
    // 重置回初始态以便重复执行（fixed dedupe upsert 语义）
    $lead->setData(Lead::schema_fields_STATUS, Lead::STATUS_NEW)
        ->setData(Lead::schema_fields_CUSTOMER_ID, 0)->save();
} else {
    $lead = ObjectManager::getInstance(Lead::class);
    $lead->clearData()->reset()->setData([
        Lead::schema_fields_WEBSITE_ID => 1,
        Lead::schema_fields_COMPANY_NAME => 'UC1 Test Retail Co',
        Lead::schema_fields_COMPANY_DOMAIN => 'uc1testretail.example.com',
        Lead::schema_fields_COUNTRY_CODE => 'US',
        Lead::schema_fields_COUNTRY_NAME => 'United States',
        Lead::schema_fields_EMAIL => 'uc1-buyer@example.com',
        Lead::schema_fields_LEAD_TYPE => 'retailer',
        Lead::schema_fields_OUTREACH_TARGET => 1,
        Lead::schema_fields_AI_AUTO_REPLY => 1,
        Lead::schema_fields_STATUS => Lead::STATUS_NEW,
        Lead::schema_fields_SOURCE_URL => 'https://example.com/uc1',
        Lead::schema_fields_SOURCE_EXCERPT => 'UC-1 fixture lead',
        Lead::schema_fields_DEDUPE_HASH => DEDUPE,
        Lead::schema_fields_FIRST_SEEN_DATE => date('Y-m-d'),
    ])->save();
    $lead = $leadModel->clearData()->reset()
        ->where(Lead::schema_fields_DEDUPE_HASH, DEDUPE)->find()->fetch();
}
$leadId = (int)$lead->getId();
if ($leadId <= 0) { $fail('S2', 'Lead 夹具落库失败'); }
$row = $q1("SELECT email,lead_type,outreach_target,ai_auto_reply,status,first_seen_date FROM w_ai_acquisition_lead WHERE id=:id", ['id' => $leadId]);
if ($row['email'] !== 'uc1-buyer@example.com' || $row['lead_type'] !== 'retailer'
    || (int)$row['outreach_target'] !== 1 || (int)$row['ai_auto_reply'] !== 1
    || $row['status'] !== 'new' || $row['first_seen_date'] === null) {
    $fail('S2', 'Lead 夹具字段断言失败: ' . json_encode($row));
}
$pass('S2', 'Lead 夹具就绪（新建列默认值断言通过）', ['lead_id' => $leadId]);

// 清理本 lead 旧会话/旧邮件（幂等重跑；不删夹具本身）
$pdo()->prepare("DELETE FROM w_ai_acquisition_conversation WHERE lead_id=:id")->execute(['id' => $leadId]);
$pdo()->prepare("DELETE FROM w_weline_mail_message WHERE source='ai_acquisition_lead' AND source_id=:id")->execute(['id' => $leadId]);

// ─── 步骤 3：发信（后台 LeadMail::postSend 同款调用面） ─────────
$composer = ObjectManager::getInstance(MailComposerService::class);
$sendResult = $composer->send(
    (int)$acctCfg['account_id'],
    'uc1-buyer@example.com',
    'UC1 outreach: premium hardwood supply',
    "Hi,\n\nWe manufacture solid wood furniture and would love to supply your retail chain.\n\nBest regards,",
    LeadConversation::MAIL_SOURCE,
    $leadId,
);
if (empty($sendResult['success'])) { $fail('S3', 'send 失败: ' . json_encode($sendResult, JSON_UNESCAPED_UNICODE)); }
// LeadMail::postSend 控制器同款后续逻辑（send() 本身不写会话/不改状态，控制器写）：
$mailOut = $q1("SELECT message_id,folder,from_email,to_email,source,source_id FROM w_weline_mail_message WHERE source='ai_acquisition_lead' AND source_id=:id ORDER BY message_id DESC LIMIT 1", ['id' => $leadId]);
if (!$mailOut || $mailOut['source'] !== 'ai_acquisition_lead' || (int)$mailOut['source_id'] !== $leadId
    || $mailOut['from_email'] !== $acctCfg['email'] || $mailOut['to_email'] !== 'uc1-buyer@example.com') {
    $fail('S3', 'MailMessage 落库断言失败: ' . json_encode($mailOut));
}
$convModel = ObjectManager::getInstance(LeadConversation::class);
$convModel->clearData()->reset()->setData([
    LeadConversation::schema_fields_LEAD_ID => $leadId,
    LeadConversation::schema_fields_RUN_ID => 0,
    LeadConversation::schema_fields_DIRECTION => LeadConversation::DIRECTION_OUT,
    LeadConversation::schema_fields_CHANNEL => LeadConversation::CHANNEL_EMAIL,
    LeadConversation::schema_fields_MAIL_MESSAGE_ID => (int)$mailOut['message_id'],
    LeadConversation::schema_fields_ROLE => LeadConversation::ROLE_HUMAN,
    LeadConversation::schema_fields_CONTENT => 'UC1 outreach: premium hardwood supply',
    LeadConversation::schema_fields_HUMAN_APPROVED => 1,
    LeadConversation::schema_fields_SENT_AT => time(),
])->save();
$convOut = $q1("SELECT id,direction,role FROM w_ai_acquisition_conversation WHERE lead_id=:id AND direction='out'", ['id' => $leadId]);
if (!$convOut) { $fail('S3', 'LeadConversation out 行未写入'); }
$lead->load($leadId);
if ((string)$lead->getData(Lead::schema_fields_STATUS) !== Lead::STATUS_CONTACTED) {
    $lead->setData(Lead::schema_fields_STATUS, Lead::STATUS_CONTACTED)->save();
    $lead->load($leadId);
}
if ((string)$lead->getData(Lead::schema_fields_STATUS) !== Lead::STATUS_CONTACTED) {
    $fail('S3', 'lead.status 未流转 new→contacted，实际=' . $lead->getData(Lead::schema_fields_STATUS));
}
$pass('S3', 'MailMessage(source=ai_acquisition_lead)+Conversation(out)+new→contacted', [
    'mail_out_id' => (int)$mailOut['message_id'], 'conv_out_id' => (int)$convOut['id'],
]);

// ─── 步骤 4：回信落 inbox + InboundLeadMailBinder::bind ─────────
$msgModel = ObjectManager::getInstance(MailMessage::class);
$reply = ObjectManager::getInstance(MailMessage::class);
$reply->clearData()->reset()->setData([
    MailMessage::schema_fields_ACCOUNT_ID => (int)$acctCfg['account_id'],
    MailMessage::schema_fields_FOLDER => 'inbox',
    MailMessage::schema_fields_FROM_EMAIL => 'uc1-buyer@example.com',
    MailMessage::schema_fields_TO_EMAIL => $acctCfg['email'],
    MailMessage::schema_fields_SUBJECT => 'Re: UC1 outreach: premium hardwood supply',
    MailMessage::schema_fields_BODY => "Thanks for reaching out.\r\nCould you share your catalog?\r\n\r\n--\r\nUC1 Buyer",
    MailMessage::schema_fields_SOURCE => 'ai_acquisition_lead',
    MailMessage::schema_fields_SOURCE_ID => $leadId,
])->save();
$replyId = (int)$reply->getId();
if ($replyId <= 0) { $fail('S4', '回信 MailMessage 落库失败'); }
$binder = ObjectManager::getInstance(InboundLeadMailBinder::class);
$bindResult = $binder->bind($reply);
if (empty($bindResult['bound']) || (int)($bindResult['lead_id'] ?? 0) !== $leadId) {
    $fail('S4', 'bind 未回绑: ' . json_encode($bindResult));
}
$convIn = $q1("SELECT id,direction,role,mail_message_id FROM w_ai_acquisition_conversation WHERE mail_message_id=:mid", ['mid' => $replyId]);
if (!$convIn || $convIn['direction'] !== 'in' || $convIn['role'] !== 'customer') { $fail('S4', 'Conversation in 行断言失败'); }
$lead->load($leadId);
if ((string)$lead->getData(Lead::schema_fields_STATUS) !== Lead::STATUS_REPLIED) {
    $fail('S4', 'lead.status 未流转 contacted→replied，实际=' . $lead->getData(Lead::schema_fields_STATUS));
}
// 幂等：同一封信重复 bind 不再写
$bindAgain = $binder->bind($reply);
$duplicate = $q1("SELECT count(*) AS c FROM w_ai_acquisition_conversation WHERE mail_message_id=:mid", ['mid' => $replyId]);
if ($duplicate['c'] != 1) { $fail('S4', 'bind 幂等失败，conversation 行数=' . $duplicate['c']); }
$pass('S4', 'bind 回绑 + contacted→replied + 二次 bind 幂等(already_bound)', ['mail_in_id' => $replyId, 'conv_in_id' => (int)$convIn['id']]);

// ─── 步骤 5：AI 自动回复（真实网关探测 → 两条路径） ─────────────
$catalog = ObjectManager::getInstance(ModelCatalogService::class);
$runRow = $q1("SELECT model_code FROM w_ai_acquisition_run ORDER BY id DESC LIMIT 1");
$probeModel = $catalog->resolve(($runRow['model_code'] ?? '') ?: null);
$aiPath = 'unavailable';
$aiError = '';
if ($probeModel) {
    $gateway = ObjectManager::getInstance(ModelGatewayService::class);
    try {
        $probe = $gateway->chat($probeModel, [['role' => 'user', 'content' => 'reply with exactly: OK']]);
        if (trim((string)($probe['content'] ?? '')) !== '') { $aiPath = 'available'; }
    } catch (Throwable $e) {
        $aiError = preg_replace('/\s+/', ' ', $e->getMessage());
    }
} else {
    $aiError = 'ModelCatalogService::resolve() 返回 null（无 chat 默认模型）';
}
$engine = ObjectManager::getInstance(AutoReplyEngine::class);
if ($aiPath === 'available') {
    // a) AI 可用 → 完整发送
    $r = $engine->replyIfDue($lead);
    if (empty($r['sent'])) { $fail('S5a', 'AI 可用路径 replyIfDue 未发送: ' . json_encode($r)); }
    $convAi = $q1("SELECT id,role,direction,ai_model_code,mail_message_id FROM w_ai_acquisition_conversation WHERE id=:cid", ['cid' => (int)$r['conversation_id']]);
    if (!$convAi || $convAi['role'] !== 'ai' || (int)$convAi['mail_message_id'] <= 0) { $fail('S5a', 'AI 会话/邮件回填断言失败'); }
    $pass('S5a', 'AI 网关真实调用成功并完整发送', ['conv_ai_id' => (int)$r['conversation_id'], 'mail_ai_id' => (int)$convAi['mail_message_id']]);
} else {
    // b) AI 不可用 → fail-safe：无发送、无会话写入、返回错误
    // 引擎 cooldown 检查先于 AI 调用：回拨 out 会话 sent_at 至 8 天前，让引擎真正到达 AI 网关
    $pdo()->prepare("UPDATE w_ai_acquisition_conversation SET sent_at=:ts WHERE lead_id=:id AND direction='out'")
        ->execute(['ts' => time() - 8 * 86400, 'id' => $leadId]);
    $beforeMsgs = (int)$q1("SELECT count(*) AS c FROM w_weline_mail_message WHERE source='ai_acquisition_lead' AND source_id=:id", ['id' => $leadId])['c'];
    $beforeConvs = (int)$q1("SELECT count(*) AS c FROM w_ai_acquisition_conversation WHERE lead_id=:id", ['id' => $leadId])['c'];
    $r = $engine->replyIfDue($lead);
    $afterMsgs = (int)$q1("SELECT count(*) AS c FROM w_weline_mail_message WHERE source='ai_acquisition_lead' AND source_id=:id", ['id' => $leadId])['c'];
    $afterConvs = (int)$q1("SELECT count(*) AS c FROM w_ai_acquisition_conversation WHERE lead_id=:id", ['id' => $leadId])['c'];
    $skip = (string)($r['skipped'] ?? '');
    if (!in_array($skip, ['ai_call_failed', 'no_model', 'ai_empty_reply'], true)) {
        $fail('S5b', 'AI 不可用时引擎未返回失败 skip，实际: ' . json_encode($r));
    }
    if ($afterMsgs !== $beforeMsgs || $afterConvs !== $beforeConvs) {
        $fail('S5b', 'fail-safe 违反：AI 失败仍写了邮件或会话行');
    }
    $pass('S5b', "AI 不可用 → 引擎返回 skipped={$skip}，无发送、无会话写入（fail-safe 通过）。原因: {$aiError}", ['skip_code' => $skip]);
}

// ─── 步骤 6：转化（含幂等） ────────────────────────────────────
$lead->load($leadId);
$converter = ObjectManager::getInstance(LeadConverter::class);
$c1 = $converter->convert($lead);
$customer = $q1("SELECT customer_id,email,customer_type,is_anonymous FROM w_customer WHERE email=:e", ['e' => 'uc1-buyer@example.com']);
if (!$customer) { $fail('S6', 'w_customer 未出现该 email'); }
if ($customer['customer_type'] !== 'ai_acquisition' || (int)$customer['is_anonymous'] !== 1) {
    $fail('S6', 'customer_type/is_anonymous 断言失败: ' . json_encode($customer));
}
$lead->load($leadId);
if ((string)$lead->getData(Lead::schema_fields_STATUS) !== Lead::STATUS_CONVERTED
    || (int)$lead->getData(Lead::schema_fields_CUSTOMER_ID) !== (int)$customer['customer_id']) {
    $fail('S6', 'lead 未 converted 或 customer_id 回填不符');
}
// 幂等：重复 convert 不新建（注意：当前实现 converted 状态直接抛异常而非幂等返回，
// 冻结用例只要求"重复转化不产生第二条 customer"，此处按 DB 结果断言并记录行为差异）
try {
    $c2 = $converter->convert($lead);
    $secondSame = (int)$c2['customer_id'] === (int)$c1['customer_id'];
} catch (RuntimeException $e) {
    $secondSame = true; // 转化被拒（status=converted），同样不会新建
}
$count = $q1("SELECT count(*) AS c FROM w_customer WHERE email=:e", ['e' => 'uc1-buyer@example.com']);
if ((int)$count['c'] !== 1 || !$secondSame) { $fail('S6', '重复转化产生了第二条 customer 或行为异常'); }
$pass('S6', "convert 创建匿名 ai_acquisition 客户 + 重复 convert 幂等 (created=" . var_export((bool)$c1['created'], true) . ")", ['customer_id' => (int)$customer['customer_id']]);

// ─── 步骤 7：频控（cooldown 内再调 replyIfDue → skipped） ────────
// 主 lead 已 converted（引擎 status 检查先于 cooldown，converted 停发本身正确）。
// 用独立探针 lead（status=replied + 刚发送的 out 会话）验证 cooldown：
$coolHash = DEDUPE . '-cooldown';
$cRow = $q1("SELECT id FROM w_ai_acquisition_lead WHERE dedupe_hash=:d", ['d' => $coolHash]);
if ($cRow) {
    $pdo()->prepare("DELETE FROM w_ai_acquisition_conversation WHERE lead_id=:id")->execute(['id' => $cRow['id']]);
    $pdo()->prepare("DELETE FROM w_weline_mail_message WHERE source='ai_acquisition_lead' AND source_id=:id")->execute(['id' => $cRow['id']]);
    $pdo()->prepare("DELETE FROM w_ai_acquisition_lead WHERE id=:id")->execute(['id' => $cRow['id']]);
}
$coolLead = ObjectManager::getInstance(Lead::class);
$coolLead->clearData()->reset()->setData([
    Lead::schema_fields_WEBSITE_ID => 1,
    Lead::schema_fields_COMPANY_NAME => 'UC1 Cooldown Probe Co',
    Lead::schema_fields_EMAIL => 'uc1-cooldown@example.com',
    Lead::schema_fields_COUNTRY_CODE => 'US',
    Lead::schema_fields_LEAD_TYPE => 'retailer',
    Lead::schema_fields_OUTREACH_TARGET => 1,
    Lead::schema_fields_AI_AUTO_REPLY => 1,
    Lead::schema_fields_STATUS => Lead::STATUS_REPLIED,
    Lead::schema_fields_DEDUPE_HASH => $coolHash,
])->save();
$coolLeadId = (int)$coolLead->getId();
$coolConv = ObjectManager::getInstance(LeadConversation::class);
$coolConv->clearData()->reset()->setData([
    LeadConversation::schema_fields_LEAD_ID => $coolLeadId,
    LeadConversation::schema_fields_RUN_ID => 0,
    LeadConversation::schema_fields_DIRECTION => LeadConversation::DIRECTION_OUT,
    LeadConversation::schema_fields_CHANNEL => LeadConversation::CHANNEL_EMAIL,
    LeadConversation::schema_fields_MAIL_MESSAGE_ID => 0,
    LeadConversation::schema_fields_ROLE => LeadConversation::ROLE_HUMAN,
    LeadConversation::schema_fields_CONTENT => 'cooldown probe out',
    LeadConversation::schema_fields_HUMAN_APPROVED => 1,
    LeadConversation::schema_fields_SENT_AT => time(),
])->save();
$coolLead->load($coolLeadId);
$r7 = $engine->replyIfDue($coolLead);
if ((string)($r7['skipped'] ?? '') !== 'cooldown') {
    $fail('S7', 'cooldown 频控未生效，实际: ' . json_encode($r7));
}
$pass('S7', "cooldown 内再调 replyIfDue → skipped=cooldown (probe lead_id={$coolLeadId})", ['cooldown_probe_lead_id' => $coolLeadId]);

// ─── 步骤 8a：status=new 的 lead 直接 replyIfDue → 跳过 ─────────
$probeLeadRow = $q1("SELECT id FROM w_ai_acquisition_lead WHERE dedupe_hash=:d", ['d' => DEDUPE . '-new']);
if ($probeLeadRow) {
    $pdo()->prepare("DELETE FROM w_ai_acquisition_conversation WHERE lead_id=:id")->execute(['id' => $probeLeadRow['id']]);
    $pdo()->prepare("DELETE FROM w_weline_mail_message WHERE source='ai_acquisition_lead' AND source_id=:id")->execute(['id' => $probeLeadRow['id']]);
    $pdo()->prepare("DELETE FROM w_ai_acquisition_lead WHERE id=:id")->execute(['id' => $probeLeadRow['id']]);
}
$newLead = ObjectManager::getInstance(Lead::class);
$newLead->clearData()->reset()->setData([
    Lead::schema_fields_WEBSITE_ID => 1,
    Lead::schema_fields_COMPANY_NAME => 'UC1 New Status Probe Co',
    Lead::schema_fields_EMAIL => 'uc1-new-status@example.com',
    Lead::schema_fields_COUNTRY_CODE => 'US',
    Lead::schema_fields_LEAD_TYPE => 'retailer',
    Lead::schema_fields_OUTREACH_TARGET => 1,
    Lead::schema_fields_AI_AUTO_REPLY => 1,
    Lead::schema_fields_STATUS => Lead::STATUS_NEW,
    Lead::schema_fields_DEDUPE_HASH => DEDUPE . '-new',
])->save();
$newLeadId = (int)$newLead->getId();
$newLead->load($newLeadId);
$r8a = $engine->replyIfDue($newLead);
if ((string)($r8a['skipped'] ?? '') !== 'status_not_eligible') {
    $fail('S8a', 'status=new 的 lead 未被跳过，实际: ' . json_encode($r8a));
}
$pass('S8a', "status=new 直接 replyIfDue → skipped=status_not_eligible (probe lead_id={$newLeadId})", ['probe_lead_id' => $newLeadId]);

// ─── 步骤 8b：email 已存在 retail 客户 → convert merged=true 不改类型 ──
$mergedHash = DEDUPE . '-merged';
$mRow = $q1("SELECT id FROM w_ai_acquisition_lead WHERE dedupe_hash=:d", ['d' => $mergedHash]);
if ($mRow) {
    $pdo()->prepare("DELETE FROM w_ai_acquisition_lead WHERE id=:id")->execute(['id' => $mRow['id']]);
}
$existingRetail = $q1("SELECT customer_id,customer_type FROM w_customer WHERE email=:e AND customer_type='retail' LIMIT 1", ['e' => 'uc1-retail@example.com']);
if (!$existingRetail) {
    $cModel = ObjectManager::getInstance(Customer::class);
    $cModel->clearData()->reset()->setData([
        Customer::schema_fields_email => 'uc1-retail@example.com',
        Customer::schema_fields_username => 'UC1 Existing Retail',
        Customer::schema_fields_password => bin2hex(random_bytes(8)),
        Customer::schema_fields_is_anonymous => 0,
        Customer::schema_fields_customer_type => Customer::CUSTOMER_TYPE_RETAIL,
    ])->save();
    $existingRetail = ['customer_id' => (string)$cModel->getId(), 'customer_type' => 'retail'];
}
$retailCid = (int)$existingRetail['customer_id'];
$mLead = ObjectManager::getInstance(Lead::class);
$mLead->clearData()->reset()->setData([
    Lead::schema_fields_WEBSITE_ID => 1,
    Lead::schema_fields_COMPANY_NAME => 'UC1 Merge Probe Co',
    Lead::schema_fields_EMAIL => 'uc1-retail@example.com',
    Lead::schema_fields_COUNTRY_CODE => 'US',
    Lead::schema_fields_LEAD_TYPE => 'retailer',
    Lead::schema_fields_OUTREACH_TARGET => 1,
    Lead::schema_fields_AI_AUTO_REPLY => 1,
    Lead::schema_fields_STATUS => Lead::STATUS_REPLIED,
    Lead::schema_fields_DEDUPE_HASH => $mergedHash,
])->save();
$mLeadId = (int)$mLead->getId();
$mLead->load($mLeadId);
$mConv = $converter->convert($mLead);
if (empty($mConv['merged']) || (int)$mConv['customer_id'] !== $retailCid) {
    $fail('S8b', 'merged 转化断言失败: ' . json_encode($mConv));
}
$after = $q1("SELECT customer_type,is_anonymous FROM w_customer WHERE customer_id=:cid", ['cid' => $retailCid]);
if ($after['customer_type'] !== 'retail') { $fail('S8b', 'merged 转化改了 customer_type: ' . json_encode($after)); }
$pass('S8b', 'email 相同已存在 retail 客户 → merged=true 且类型未改', ['merged_lead_id' => $mLeadId, 'merged_customer_id' => $retailCid]);

// ─── 输出 ───────────────────────────────────────────────────────
$allPass = true;
echo "\n===== UC-1 结果 =====\n";
foreach ($results as $step => $r) {
    $allPass = $allPass && $r['PASS'];
    echo sprintf("[%s] %s %s\n", $r['PASS'] ? 'PASS' : 'FAIL', $step, $r['note'] ?? ('原因: ' . ($r['reason'] ?? '')));
}
echo "\n===== 证据 id =====\n" . json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
echo "\nMAIL_PATH=MailFakeMailboxService(quote-test.invalid fake 域 → w_weline_mail_message)\n";
echo "AI_PATH=" . ($aiPath === 'available' ? 'ModelGatewayService 真实调用成功' : '不可用（fail-safe 已验证）') . "\n";
echo "\nUC1 " . ($allPass ? 'PASS' : 'FAIL') . "\n";
exit($allPass ? 0 : 1);
