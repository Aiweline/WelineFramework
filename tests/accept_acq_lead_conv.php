<?php
/**
 * AiAcq lead-conv-customer 批次静态验收：
 * T1-T7 所有落点文件存在 + 关键声明存在。 Prints "ACCEPT PASS" / "ACCEPT FAIL: <reason>", exit 0/1.
 */
declare(strict_types=1);

$base = dirname(__DIR__) . '/app/code/Weline';
$fail = static function (string $r): void { echo "ACCEPT FAIL: $r\n"; exit(1); };
$has = static function (string $file, string ...$needles) use ($base, $fail): void {
    $path = $base . '/' . $file;
    if (!is_file($path)) { $fail("missing file: $file"); }
    $src = (string)file_get_contents($path);
    foreach ($needles as $n) {
        if (!str_contains($src, $n)) { $fail("missing `$n` in $file"); }
    }
};

// T1 Lead
$has('AiAcquisition/Model/Lead.php',
    "const schema_fields_LEAD_TYPE = 'lead_type'",
    "const schema_fields_OUTREACH_TARGET = 'outreach_target'",
    "const schema_fields_AI_AUTO_REPLY = 'ai_auto_reply'",
    "const schema_fields_CUSTOMER_ID = 'customer_id'",
    "const schema_fields_FIRST_SEEN_DATE = 'first_seen_date'",
    "'retailer'", "'rental_studio'", "'supplier_factory'", "'marketplace'",
    "'media_guide'", "'directory'", "'social'",
    'LEAD_TYPES', 'getLeadTypeLabel',
    "idx_ai_acquisition_lead_type", "idx_ai_acquisition_lead_customer", "idx_ai_acquisition_lead_first_seen",
);

// T2 LeadConversation
$has('AiAcquisition/Model/LeadConversation.php',
    "const schema_table = 'ai_acquisition_conversation'",
    "const DIRECTION_OUT = 'out'", "const DIRECTION_IN  = 'in'",
    "const ROLE_CUSTOMER = 'customer'", "ROLE_HUMAN", "ROLE_AI = 'ai'",
    'MAIL_SOURCE', "'ai_acquisition_lead'",
    'mail_message_id', 'tokens_in', 'tokens_out', 'human_approved', 'sent_at',
    'UNIQUE', 'save_before',
);

// T3 Customer
$has('Customer/Model/Customer.php',
    "const schema_fields_customer_type = 'customer_type'",
    "CUSTOMER_TYPE_RETAIL = 'retail'", "CUSTOMER_TYPE_B2B_WHOLESALE = 'b2b_wholesale'",
    "CUSTOMER_TYPE_AI_ACQUISITION = 'ai_acquisition'",
);
$has('Customer/Model/Type.php',
    "const schema_table = 'customer_type'",
    "const schema_primary_key = 'type_id'",
    'schema_fields_CODE', 'schema_fields_IS_SYSTEM',
);
$has('Customer/Setup/Upgrade.php',
    'retail', 'b2b_wholesale', 'ai_acquisition', 'columnExist', 'addColumn',
);

// T4 LeadConverter
$has('AiAcquisition/Service/Customer/LeadConverter.php',
    'function convert(Lead $lead): array',
    "Lead::STATUS_REPLIED", "Lead::STATUS_QUALIFIED", "Lead::STATUS_CONTACTED",
    "Lead::STATUS_CONVERTED", // 幂等：converted 再次 convert 只关联不重建
    "'merged' => true", "'created'", "'customer_id'", 'already_converted',
    'is_anonymous', 'CUSTOMER_TYPE_AI_ACQUISITION',
    'TransactionCoordinatorInterface', '->run(',
);

// T5 AutoReplyEngine
$has('AiAcquisition/Service/AutoReplyEngine.php',
    'function replyIfDue(Lead $lead): array',
    "'max_rounds'", "'cooldown'", "'daily_limit'",
    'ai_acquisition/auto_reply/enabled', 'ai_acquisition/auto_reply/cooldown_days',
    'ai_acquisition/auto_reply/daily_limit', 'ai_acquisition/outreach/mail_account_id',
    'ai_acquisition/prompt/default', 'HARD_CONSTRAINTS',
    'MailComposerService', 'MOQ', 'contact@changanhanfu.com',
);

// T6 InboundLeadMailBinder
$has('AiAcquisition/Model/InboundLeadMailBinder.php',
    'function bind(MailMessage $msg): array',
    'ROLE_CUSTOMER', 'DIRECTION_IN',
    'STATUS_REPLIED', 'In-Reply-To',
);

// T7 AiAcquisition Upgrade
$has('AiAcquisition/Setup/Upgrade.php',
    'lead_type', 'outreach_target', 'ai_auto_reply', 'customer_id', 'first_seen_date',
    'ensureConversationTable', 'columnExist',
);

echo "ACCEPT PASS\n";
