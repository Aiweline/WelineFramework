<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Administrator
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：23/4/2024 17:51:03
 */

namespace Weline\Queue\Console\Queue\Type;

use Weline\Framework\Console\CommandInterface;
use Weline\Queue\Model\Queue;

class Listing implements CommandInterface
{

    private \Weline\Framework\Output\Cli\Printing $printing;
    private Queue\Type $type;

    function __construct(Queue\Type $type, \Weline\Framework\Output\Cli\Printing $printing)
    {
        $this->printing = $printing;
        $this->type = $type;
    }

    /**
     * @inheritDoc
     * @throws \Exception
     */
    public function execute(array $args = [], array $data = [])
    {
        array_shift($args);
        if ($args) {
            $this->printing->setup(implode(',', $args), '搜索');
            foreach ($args as $arg) {
                $this->type->where('concat(name,module_name,class)', '%' . $arg . '%', 'like', 'or');
            }
        }
        $queueTypes         = $this->type->select()->fetchArray();
        $modulesQueueTypes = [];
        foreach ($queueTypes as $queueType) {
            $modulesQueueTypes[$queueType['module_name']][] = $queueType;
        }
        foreach ($modulesQueueTypes as $module => $moduleQueueTypes) {
            $this->printing->warning('#' . $module, '');
            foreach ($moduleQueueTypes as $moduleQueueType) {
                $this->printing->note(str_pad($moduleQueueType['class'], 58) . str_pad($moduleQueueType['name'], 50) . __('说明：') . $moduleQueueType['tip'], '');
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '列出所有队列类型数据，示例：php bin/w queue:type:listing [可选：搜索队列名称]';
    }
}