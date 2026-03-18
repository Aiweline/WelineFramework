<?php

declare(strict_types=1);

namespace Weline\ModuleManager\Console\Module;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\ConsoleException;
use Weline\Framework\Output\Cli\Printing;
use Weline\ModuleManager\Service\ModuleTableHandoverService;

/**
 * 模块表归属划转：module:table-handover
 */
class TableHandover extends CommandAbstract
{
    public function __construct(
        Printing $printer,
        private readonly ModuleTableHandoverService $handoverService
    ) {
        $this->printer = $printer;
    }

    public function execute(array $args = [], array $data = []): void
    {
        array_shift($args);
        $from = '';
        $to = '';
        $maps = [];
        $markSuccessor = false;
        foreach ($args as $a) {
            if (str_starts_with($a, '--from=')) {
                $from = substr($a, 7);
            } elseif (str_starts_with($a, '--to=')) {
                $to = substr($a, 5);
            } elseif (str_starts_with($a, '--map=')) {
                $maps[] = substr($a, 6);
            } elseif ($a === '--mark-successor') {
                $markSuccessor = true;
            }
        }
        $from = trim($from);
        $to = trim($to);
        if ($from === '' || $to === '') {
            throw new ConsoleException(
                __('用法：module:table-handover --from=Weline_Saas --to=Weline_Websites --map=saas_provisioning_order=Weline\\Websites\\Model\\ProvisioningOrder [--map=...]')
            );
        }
        if ($maps === []) {
            throw new ConsoleException(__('至少一个 --map=逻辑表名=模型类FQCN'));
        }

        $logicalToModel = [];
        foreach ($maps as $m) {
            $p = explode('=', $m, 2);
            if (\count($p) !== 2) {
                throw new ConsoleException(__('map 格式错误：%{1}', [$m]));
            }
            $logicalToModel[trim($p[0])] = trim($p[1], " \t\n\r\0\x0B\"'");
        }

        if ($markSuccessor) {
            foreach ($logicalToModel as $logical => $_) {
                $r = $this->handoverService->markSuccessor($from, $logical, $to);
                $this->printer->note($r['message']);
            }
            $this->printer->success(__('已标记 successor，请对目标模块执行 setup:upgrade 完成模型登记接管'));

            return;
        }

        $r = $this->handoverService->handover($from, $to, $logicalToModel);
        if ($r['success']) {
            $this->printer->success($r['message']);
        } else {
            $this->printer->error($r['message']);
        }
    }

    public function tip(): string
    {
        return __('模块表登记划转（合并模块、消除 weline_module_table 冲突）');
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'module:table-handover',
            $this->tip(),
            [
                '--from=' => __('源模块名'),
                '--to=' => __('目标模块名'),
                '--map=' => __('逻辑表名=新模型类FQCN，可重复'),
                '--mark-successor' => __('仅标记 successor，不立即改 module_name（高级）'),
            ],
            [],
            [
                'php bin/w module:table-handover --from=Weline_Saas --to=Weline_Websites --map=saas_provisioning_order=Weline\\Websites\\Model\\ProvisioningOrder',
            ],
            'php bin/w module:table-handover --from=... --to=... --map=...'
        );
    }
}
