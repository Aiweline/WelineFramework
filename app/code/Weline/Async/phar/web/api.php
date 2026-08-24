<?php

/**
 * phar独立包Web API
 */

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'status':
        echo json_encode([
            'success' => true,
            'data' => [
                'message' => 'phar独立包API（需要配置数据库连接）'
            ]
        ]);
        break;
    default:
        echo json_encode([
            'success' => false,
            'message' => '未知操作'
        ]);
        break;
}
