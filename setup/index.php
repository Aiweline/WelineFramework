<?php
/**
 * 文件信息
 * 作者：邹万才
 * 网名：秋枫雁飞(可以百度看看)
 * 网站：www.aiweline.com/bbs.aiweline.com
 * 工具：PhpStorm
 * 日期：2020/8/3
 * 时间：20:42
 * 描述：此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
 */
require 'bootstrap.php';
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>安装-欢迎使用MFramework框架！</title>
    <link rel="icon" href="/setup/static/favicon.png" type="image/x-icon">
    <link rel="stylesheet" href="/setup/static/css/swiper-bundle.min.css">
    <link rel="stylesheet" href="/setup/static/css/install.css">
    <link rel="stylesheet" href="/setup/static/css/form.css">
    <script type="text/javascript" src="/setup/static/js/jquery-3.5.1/jquery-3.5.1.min.js"></script>
</head>
<body>
<?php
$step = $_GET['step'] ?? '';
switch ($step) {
    case 'step-2':
        include 'step/step-2.html';

        break;
    case 'step-3':
        include 'step/step-3.html';

        break;
    case 'step-4':
        include 'step/step-4.html';

        break;
    case 'step-5':
        include 'step/step-5.html';

        break;
    case 'step-1':
    default:
        include 'step/step-1.html';

        break;
}
?>
<script type="text/javascript" src="/setup/static/js/Installer.js"></script>
</body>
</html>
