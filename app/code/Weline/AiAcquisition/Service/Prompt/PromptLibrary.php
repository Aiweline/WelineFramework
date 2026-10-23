<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Service\Prompt;

use Weline\AiAcquisition\Model\PromptProfile;
use Weline\Framework\Manager\ObjectManager;

/**
 * 获客提示词库。
 *
 * 默认提示词只描述通用的 B2B 买家发现方法论（意向词、排除规则、5 轴打分、证据与真实性约束），
 * 不包含任何站点名、域名或邮箱；站点差异一律由运行上下文注入，或由提示词档案覆盖。
 */
class PromptLibrary
{
    /** 内置通用档案代码，被保留为不可写入的代码 */
    public const DEFAULT_CODE = 'default_b2b_acquisition';

    /**
     * 内置通用档案；id=0、builtin=true，用于和数据库档案区分。
     *
     * @return array{id:int,code:string,name:string,body:string,is_default:int,builtin:bool}
     */
    public function defaultProfile(): array
    {
        return [
            'id' => 0,
            'code' => self::DEFAULT_CODE,
            'name' => (string)__('内置通用 B2B 获客提示词'),
            'body' => $this->defaultBody(),
            'is_default' => 0,
            'builtin' => true,
        ];
    }

    /**
     * 生成系统提示词：档案正文（可由 context['prompt_body'] 覆盖）+ 本次运行上下文。
     *
     * @param array<string,mixed> $context website/product_lines/target_markets/icp/excluded_domains/language/prompt_body
     */
    public function compose(array $context = []): string
    {
        $body = trim((string)($context['prompt_body'] ?? ''));
        if ($body === '') {
            $body = $this->defaultBody();
        }

        $labels = [
            'website' => '站点',
            'product_lines' => '产品线',
            'target_markets' => '目标市场',
            'icp' => '理想客户画像',
            'excluded_domains' => '排除域名',
            'language' => '输出语言',
        ];
        $lines = [];
        foreach ($labels as $key => $label) {
            $value = $this->contextValue($context[$key] ?? null);
            if ($value !== '') {
                $lines[] = '- ' . $label . '：' . $value;
            }
        }

        if ($lines === []) {
            return $body;
        }

        return $body . "\n\n【本次运行上下文】\n" . implode("\n", $lines);
    }

    /**
     * 列出提示词档案：内置通用档案 + 数据库档案（按默认、ID 倒序）。
     *
     * 数据库不可用（未安装/表缺失）时只返回内置档案，不抛出异常。
     *
     * @return list<array{id:int,code:string,name:string,body:string,is_default:int,builtin:bool}>
     */
    public function listProfiles(): array
    {
        $items = [];
        try {
            $rows = ObjectManager::getInstance(PromptProfile::class)
                ->reset()
                ->clearQuery()
                ->order(PromptProfile::schema_fields_IS_DEFAULT, 'DESC')
                ->order(PromptProfile::schema_fields_ID, 'DESC')
                ->select()
                ->fetchArray();
            foreach (is_array($rows) ? $rows : [] as $row) {
                if (is_array($row)) {
                    $items[] = $this->normalizeRow($row);
                }
            }
        } catch (\Throwable $e) {
            w_log_warning('提示词档案读取失败', ['error' => $e->getMessage()]);
        }

        return array_merge([$this->defaultProfile()], $items);
    }

    /**
     * 新增或更新档案。
     *
     * @param array<string,mixed> $data id/code/name/body/is_default
     * @return array{id:int,code:string,name:string,body:string,is_default:int,builtin:bool}
     * @throws \InvalidArgumentException 校验失败时抛出，便于调用方给出明确提示
     */
    public function saveProfile(array $data): array
    {
        $id = max(0, (int)($data['id'] ?? 0));
        $code = trim((string)($data['code'] ?? ''));
        $name = trim((string)($data['name'] ?? ''));
        $body = trim((string)($data['body'] ?? ''));
        $isDefault = (int)($data['is_default'] ?? 0) === 1 ? 1 : 0;

        if ($code === '' || $name === '' || $body === '') {
            throw new \InvalidArgumentException((string)__('提示词代码、名称和正文不能为空。'));
        }
        if (!preg_match('/^[a-z0-9_\-]{2,64}$/i', $code)) {
            throw new \InvalidArgumentException((string)__('提示词代码只能包含字母、数字、下划线和短横线。'));
        }
        if ($code === self::DEFAULT_CODE) {
            throw new \InvalidArgumentException((string)__('内置提示词代码为保留代码，请换一个代码。'));
        }

        $model = $id > 0
            ? ObjectManager::getInstance(PromptProfile::class)->reset()->clearQuery()->load($id)
            : ObjectManager::getInstance(PromptProfile::class);
        if ($id > 0 && !$model->getId()) {
            throw new \InvalidArgumentException((string)__('提示词档案不存在。'));
        }
        if ($id === 0) {
            $exists = ObjectManager::getInstance(PromptProfile::class)
                ->reset()
                ->clearQuery()
                ->where(PromptProfile::schema_fields_CODE, $code)
                ->find()
                ->fetch();
            if ($exists && $exists->getId()) {
                throw new \InvalidArgumentException((string)__('提示词代码已存在。'));
            }
        }

        if ($isDefault === 1) {
            ObjectManager::getInstance(PromptProfile::class)
                ->reset()
                ->clearQuery()
                ->update([PromptProfile::schema_fields_IS_DEFAULT => 0]);
        }

        $model->setData([
            PromptProfile::schema_fields_CODE => $code,
            PromptProfile::schema_fields_NAME => $name,
            PromptProfile::schema_fields_BODY => $body,
            PromptProfile::schema_fields_IS_DEFAULT => $isDefault,
        ])->save();

        return [
            'id' => (int)$model->getId(),
            'code' => $code,
            'name' => $name,
            'body' => $body,
            'is_default' => $isDefault,
            'builtin' => false,
        ];
    }

    /**
     * 删除档案；不存在或删除失败返回 false。
     */
    public function deleteProfile(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        try {
            $model = ObjectManager::getInstance(PromptProfile::class)->reset()->clearQuery()->load($id);
            if (!$model->getId()) {
                return false;
            }
            $model->delete();

            return true;
        } catch (\Throwable $e) {
            w_log_warning('提示词档案删除失败', ['id' => $id, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * 数据库行归一化，避免把多余字段透给调用方。
     *
     * @param array<string,mixed> $row
     * @return array{id:int,code:string,name:string,body:string,is_default:int,builtin:bool}
     */
    private function normalizeRow(array $row): array
    {
        return [
            'id' => (int)($row[PromptProfile::schema_fields_ID] ?? 0),
            'code' => (string)($row[PromptProfile::schema_fields_CODE] ?? ''),
            'name' => (string)($row[PromptProfile::schema_fields_NAME] ?? ''),
            'body' => (string)($row[PromptProfile::schema_fields_BODY] ?? ''),
            'is_default' => (int)($row[PromptProfile::schema_fields_IS_DEFAULT] ?? 0),
            'builtin' => false,
        ];
    }

    /**
     * 上下文值归一：数组用顿号连接，标量转字符串。
     */
    private function contextValue(mixed $value): string
    {
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                if (is_scalar($item)) {
                    $trimmed = trim((string)$item);
                    if ($trimmed !== '') {
                        $parts[] = $trimmed;
                    }
                }
            }

            return implode('、', $parts);
        }
        if (is_scalar($value)) {
            return trim((string)$value);
        }

        return '';
    }

    /**
     * 通用 B2B 获客方法论正文（不含站点名/域名/邮箱）。
     */
    private function defaultBody(): string
    {
        return <<<'PROMPT'
你是 B2B 获客研究员：在公开网络上寻找真实、可核实的潜在买家，并留下可回溯的证据。

【检索方法】
1. 先想买家会怎么表达需求，再用「买家意向词 + 品类词」组合检索，例如：
   looking for supplier / sourcing / request for quotation / RFQ / where to buy wholesale / need manufacturer / bulk order / import / distributor wanted。
2. 用引号锁定完整短语，用 site: 限定社区、行业论坛、B2B 市场、商会或展会名录等公开来源。
3. 排除供应商侧营销页、目录站/聚合站、比价页、招聘页和纯消费者零售页——它们不是买家线索。
4. 只读摘要判断不充分时，用 inspect_page 打开原文核实，再决定是否入库。

【资格打分】每条候选按 5 个维度各 0–2 分：
- 品类匹配：2=明确要目标品类；1=相邻品类；0=泛品类
- 采购意图：2=明确求购或找供应商；1=有采购倾向的讨论；0=仅兴趣
- B2B 属性：2=企业/店主/进口商/活动主办；1=个人但量大；0=纯消费者
- 数量或时间：2=给出数量或期限；1=模糊；0=无
- 渠道可用：2=公开商务邮箱或官网；1=仅平台私信；0=无公开渠道
总分 ≥6 才允许 save_lead；低于 6 分直接放弃，不要写入。

【证据与真实性】
- 每条线索必须附来源 URL、页面标题和原文摘录（1–2 句能证明需求的原文）。
- 禁止编造公司名、邮箱、电话、价格、MOQ、交期、库存或认证；页面上没有就留空。
- 只采集公开页面上公示的商务联系方式：不猜测邮箱格式，不绕过登录、付费墙或验证码。
- 国家/地区不确定时依据页面明示信息填写，宁可不填也不要推测。

【去重与节奏】
- 保存前先用 list_existing_leads 查重；已存在就更新证据，不要重复写入。
- 每轮最多保存 1 条新线索，宁少勿多；不要对同一线索反复触达。
- 检索结果为空时换同义词、换意向词或换来源，不得凭记忆编造线索；连续多轮无结果要如实说明并给出建议关键词。

【输出】
每轮先简述检索思路，再用工具取证；结束时汇总本轮命中、入库数量和被排除的主要原因。
PROMPT;
    }
}
