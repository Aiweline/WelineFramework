<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Console\Console\Deploy\Content;

use Weline\Framework\App\Env;
use Weline\Framework\Output\Cli\Printing;

class Set implements \Weline\Framework\Console\CommandInterface
{
    private Printing $printing;

    public function __construct(
        Printing $printing
    )
    {
        $this->printing = $printing;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        if (!isset($args[1])) {
            $this->printing->error(__('请指定设置类型:'));
            foreach ($this->getTypes() as $key => $detail) {
                $this->printing->note($detail, $key);
            }
            exit();
        }
        if (empty($type = $args[1])) {
            $this->printing->error(__('请指定设置类型：%1', implode(',', $this->getTypes())));
            foreach ($this->getTypes() as $key => $detail) {
                $this->printing->note($detail, $key);
            }
            exit();
        }
        if (!array_key_exists($type, $this->getTypes())) {
            $this->printing->error(__('错误的设置类型：%1', implode(',', $this->getTypes())));
            foreach ($this->getTypes() as $key => $detail) {
                $this->printing->note($detail, $key);
            }
            exit();
        }
        if (!isset($args[2])) {
            $this->printing->error(__('请设置类型 %1 的值', $type));
            $this->printing->note($this->getTypes()[$type], $type);
            exit();
        }
        $value = $args[2];
        if ($value !== '0' && $value !== '1') {
            $this->printing->error(__('类型 %1 的值仅接受：0或1', $type));
            $this->printing->note($this->getTypes()[$type], $type);
            exit();
        }

        Env::getInstance()->setConfig($type, $value);
        $this->printing->success(__('类型 %1 设置为：%1', [$type, $type]));
    }

    public function getTypes(): array
    {
        return [
            'static_file_rand_version' => __('设置静态文件是否末尾添加随机字符，以保证浏览器不缓存文件而得到实时响应修改。接受值：0或1')
        ];
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '设置静态文件状态';
    }
}
