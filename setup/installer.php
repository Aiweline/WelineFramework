<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

use Weline\Installer\Runner;

require 'bootstrap.php';
$runner = new Runner();
switch ($_GET['action']) {
    case 'env':
        $data = $runner->checkEnv();
        echo json_encode(coverData($data));

        break;
    case 'db':
        $data = $runner->installDb();
        echo json_encode(coverData($data));

        break;
    case 'init_env':
        // 配置生成
        $data = $runner->systemInit();
        echo json_encode(coverData($data));

        break;
    case 'install_ok':
        // 命令清理
        $data = $runner->systemCommands();
        echo json_encode(coverData($data));
        $file = new \Weline\Framework\System\File\Io\File();
        $file->open(BP . 'setup/install.lock', $file::mode_a_add);
        $file->close();

        break;
    default:
        return http_response_code(404);
}
function coverData(array $data): array
{
    $tmpD        = [];
    $tmp['data'] = [];
    foreach ($data['data'] as $key => $datum) {
        $tmpD['name']  = $key;
        $tmpD['value'] = $datum;
        $tmp['data'][] = $tmpD;
    }
    $tmp['hasErr'] = $data['hasErr'];
    $tmp['msg']    = $data['msg'] ?? '';

    return $tmp;
}
