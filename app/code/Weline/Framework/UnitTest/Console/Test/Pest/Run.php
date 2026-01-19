<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\UnitTest\Console\Test\Pest;

use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\UnitTest\Pest\Pest as PestTest;

class Run implements CommandInterface
{
    function __construct(
        private Printing $printer
    )
    {
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        // 检查 Pest 是否可用
        if (!PestTest::isAvailable()) {
            $this->printer->error(__('Pest 测试框架未安装！'));
            $this->printer->note(__('请运行以下命令安装:'));
            $this->printer->print(__('composer require --dev pestphp/pest'));
            return;
        }

        // 解析参数
        $options = [];
        
        if (isset($args['filter']) || isset($args['f'])) {
            $options['filter'] = $args['filter'] ?? $args['f'];
        }
        
        if (isset($args['group']) || isset($args['g'])) {
            $options['group'] = $args['group'] ?? $args['g'];
        }
        
        if (isset($args['parallel']) || isset($args['p'])) {
            $options['parallel'] = true;
        }
        
        if (isset($args['coverage']) || isset($args['c'])) {
            $options['coverage'] = true;
        }
        
        if (isset($args['min'])) {
            $options['min'] = $args['min'];
        }
        
        if (isset($args['testsuite']) || isset($args['s'])) {
            $options['testsuite'] = $args['testsuite'] ?? $args['s'];
        }
        
        if (isset($args['path'])) {
            $options['path'] = $args['path'];
        }

        $this->printer->success(__('开始运行 Pest 测试...'));
        $this->printer->note(__('测试框架: Pest PHP'));
        echo "\n";

        // 运行测试
        $exitCode = PestTest::run($options);

        echo "\n";
        if ($exitCode === 0) {
            $this->printer->success(__('所有测试通过！'));
        } else {
            $this->printer->error(__('测试失败，退出代码: %{1}', [$exitCode]));
        }

        return $exitCode;
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('运行 Pest PHP 测试');
    }

    /**
     * @inheritDoc
     */
    public function help(): array|string
    {
        return <<<HELP
════════════════════════════════════════════════════════════════════════════════
🎯 Pest PHP 测试框架运行命令
════════════════════════════════════════════════════════════════════════════════

📋 基本语法：
    php bin/w test:pest:run [选项]

🔧 常用选项：
    -f, --filter=<名称>       过滤测试名称
    -g, --group=<组名>        运行指定组的测试
    -p, --parallel            并行运行测试
    -c, --coverage            生成代码覆盖率报告
    --min=<百分比>            最小覆盖率要求（配合 --coverage 使用）
    -s, --testsuite=<套件>    运行指定测试套件
    --path=<路径>             指定测试路径（默认：tests）

📋 使用示例：

1️⃣ 运行所有测试：
    php bin/w test:pest:run

2️⃣ 运行指定目录的测试：
    php bin/w test:pest:run --path=tests/Unit

3️⃣ 运行过滤的测试：
    php bin/w test:pest:run --filter=ExampleTest

4️⃣ 运行指定组的测试：
    php bin/w test:pest:run --group=unit

5️⃣ 并行运行测试：
    php bin/w test:pest:run --parallel

6️⃣ 生成代码覆盖率报告：
    php bin/w test:pest:run --coverage

7️⃣ 组合使用：
    php bin/w test:pest:run --filter=ExampleTest --coverage --min=80

💡 提示：
    - 测试文件应放在 tests/ 目录下
    - 使用 --test 或 -t 参数启动服务时自动加载测试框架
    - 更多信息请查看: app/code/Weline/Framework/UnitTest/README.md

════════════════════════════════════════════════════════════════════════════════
HELP;
    }
}
