<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Console\Command;

use Weline\Cdn\Model\Domain;
use Weline\Cdn\Service\RuleManager;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * 默认规则推送命令
 *
 * 把 `app/code/Weline/Cdn/etc/default-rules.json` 中的全局默认规则推送到指定域名（或所有已配置域名）。
 * 与 `cdn:rules:import` 是反向操作：本命令把"我们认为最佳的防御规则"下发到 CDN，
 * 包括 Weline 标准头部识别（X-Weline-Idempotent / Cache-Bypass / Url-Guard）等。
 *
 * 用法：
 *   php bin/w cdn:rules:push:defaults --domain=example.com
 *   php bin/w cdn:rules:push:defaults --all
 *   php bin/w cdn:rules:push:defaults --dry-run --domain=example.com
 *
 * @package Weline_Cdn
 */
class RulesPushDefaults extends CommandAbstract implements CommandInterface
{
    private RuleManager $ruleManager;

    public function __construct()
    {
        $this->ruleManager = ObjectManager::getInstance(RuleManager::class);
    }

    public function tip(): string
    {
        return __('推送 Weline 标准默认 CDN 规则到目标域名');
    }

    /**
     * @return array<int, string>
     */
    public function aliases(): array
    {
        return [
            'cdn:rules:push:defaults',
            'cdn:rules:push-defaults',
            'cdn:push:defaults',
        ];
    }

    /**
     * @return array|string
     */
    public function help(): array|string
    {
        return [
            __('用法: php bin/w cdn:rules:push:defaults [选项]'),
            __(''),
            __('选项:'),
            __('  --domain=域名ID或名称   指定要推送的单个域名'),
            __('  --all                  推送到所有启用的域名'),
            __('  --dry-run              只预览将要推送的规则数量，不实际下发'),
            __(''),
            __('示例:'),
            __('  php bin/w cdn:rules:push:defaults --domain=example.com'),
            __('  php bin/w cdn:rules:push:defaults --all'),
            __('  php bin/w cdn:rules:push:defaults --domain=example.com --dry-run'),
        ];
    }

    /**
     * @param array $args
     * @param array $data
     * @return mixed|void
     */
    public function execute(array $args = [], array $data = [])
    {
        $domainArg = $data['domain'] ?? '';
        $all = isset($data['all']) || \in_array('--all', $args, true);
        $dryRun = isset($data['dry-run']) || \in_array('--dry-run', $args, true);

        $defaultRules = $this->ruleManager->getDefaultRules();
        $rulesCount = \count($defaultRules);

        if ($rulesCount === 0) {
            $this->printer->warning(__('未发现任何默认规则（app/code/Weline/Cdn/etc/default-rules.json 为空或不存在）'));
            return;
        }

        $this->printer->note(__('共发现 %{1} 条默认规则', [$rulesCount]));

        if ($dryRun) {
            $this->renderRulesSummary($defaultRules);
            $this->printer->success(__('Dry-run 模式：未执行实际推送'));
            return;
        }

        try {
            /** @var Domain $domainModel */
            $domainModel = ObjectManager::getInstance(Domain::class);

            $targets = $this->resolveTargets($domainModel, (string)$domainArg, (bool)$all);
            if ($targets === []) {
                $this->printer->error(__('未匹配到任何要推送的域名（请检查 --domain 或 --all）'));
                return;
            }

            $okCount = 0;
            $failCount = 0;
            foreach ($targets as $domainObj) {
                $name = (string)$domainObj->getData(Domain::schema_fields_DOMAIN_NAME);
                $this->printer->note(__('正在推送到域名：%{1}', [$name]));
                try {
                    $result = $this->ruleManager->pushRules($domainObj);
                    if (!empty($result['success'])) {
                        $this->printer->success(__('  -> 成功（%{1}）', [$result['message'] ?? __('已推送')]));
                        $okCount++;
                    } else {
                        $this->printer->error(__('  -> 失败：%{1}', [$result['message'] ?? '未知错误']));
                        $failCount++;
                    }
                } catch (\Throwable $e) {
                    $this->printer->error(__('  -> 异常：%{1}', [$e->getMessage()]));
                    $failCount++;
                }
            }

            $this->printer->note(__('推送完成：成功 %{1} 个，失败 %{2} 个', [$okCount, $failCount]));
        } catch (\Throwable $e) {
            $this->printer->error(__('执行失败：%{1}', [$e->getMessage()]));
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     */
    private function renderRulesSummary(array $rules): void
    {
        foreach ($rules as $index => $rule) {
            $description = (string)($rule['description'] ?? '');
            $expression = (string)($rule['expression'] ?? '');
            $action = $rule['action'] ?? null;
            $actionStr = \is_array($action) ? \json_encode($action, \JSON_UNESCAPED_UNICODE) : (string)$action;

            $this->printer->note(\sprintf(
                "  [%d] %s\n      expr   : %s\n      action : %s",
                $index + 1,
                $description !== '' ? $description : '-',
                $expression !== '' ? $expression : '-',
                (string)$actionStr
            ));
        }
    }

    /**
     * @return array<int, Domain>
     */
    private function resolveTargets(Domain $model, string $domainArg, bool $all): array
    {
        if ($all) {
            $rows = $model->reset()->select()->fetch();
            $items = $this->extractItems($rows);
            return \array_values(\array_filter($items, static fn ($d) => $d instanceof Domain && (bool)$d->getData(Domain::schema_fields_DOMAIN_ID)));
        }

        if ($domainArg === '') {
            return [];
        }

        if (\is_numeric($domainArg)) {
            $obj = $model->reset()->load((int)$domainArg);
        } else {
            $obj = $model->reset()
                ->where(Domain::schema_fields_DOMAIN_NAME, $domainArg)
                ->find()
                ->fetch();
        }

        if (!$obj instanceof Domain || !(bool)$obj->getData(Domain::schema_fields_DOMAIN_ID)) {
            return [];
        }
        return [$obj];
    }

    /**
     * @return array<int, mixed>
     */
    private function extractItems(mixed $rows): array
    {
        if (\is_object($rows) && \method_exists($rows, 'getItems')) {
            return $rows->getItems();
        }
        if ($rows instanceof \Traversable) {
            return \iterator_to_array($rows, false);
        }
        if (\is_array($rows)) {
            return $rows;
        }
        if (\is_object($rows)) {
            return [$rows];
        }
        return [];
    }
}
