<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Hook;

defined('DS') || define('DS', DIRECTORY_SEPARATOR);
defined('BP') || define('BP', dirname(__DIR__, 7) . DS);
defined('APP_PATH') || define('APP_PATH', BP . 'app' . DS);
defined('APP_CODE_PATH') || define('APP_CODE_PATH', APP_PATH . 'code' . DS);
defined('APP_ETC_PATH') || define('APP_ETC_PATH', APP_PATH . 'etc' . DS);
defined('DEV_PATH') || define('DEV_PATH', BP . 'dev' . DS);
defined('PUB') || define('PUB', BP . 'pub' . DS);
require_once BP . 'app/bootstrap_phpunit.php';

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;

class HookerTest extends TestCase
{
    public function testGetHook(): void
    {
        /**@var Hooker $hooker */
        $hooker = ObjectManager::getInstance(Hooker::class);
        self::assertIsArray($hooker->getHook('title'));
    }
}
