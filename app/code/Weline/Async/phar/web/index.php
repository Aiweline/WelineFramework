<?php

/**
 * phar独立包Web UI入口
 */

// 简单的Web界面
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Weline Async 同步管理</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            background: #007bff;
            color: white;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .status-card {
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 15px;
        }
        .status-running {
            color: green;
        }
        .status-stopped {
            color: red;
        }
        button {
            padding: 8px 16px;
            margin: 5px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .btn-primary {
            background: #007bff;
            color: white;
        }
        .btn-danger {
            background: #dc3545;
            color: white;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Weline Async 同步管理</h1>
        <p>文件同步工具 Web 管理界面</p>
    </div>
    
    <div id="status-container">
        <p>正在加载状态...</p>
    </div>

    <script>
        // 这里可以添加AJAX请求来获取状态和控制watcher
        // 由于phar独立运行，需要简化实现
        console.log('Web UI loaded');
    </script>
</body>
</html>
