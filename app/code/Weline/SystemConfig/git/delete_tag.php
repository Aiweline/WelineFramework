<?php

// 切换到当前目录
chdir(getcwd());
# 删除前先git fetch --all
echo "开始执行 git fetch --all \n";
exec("git fetch --all");
// 获取所有本地tag
exec("git tag", $tags, $return_var);

// 输入版本范围或指定版本
array_shift($argv);
$repositories = [];
foreach ($argv as $argv_key => $item) {
    if ($item == '-h' || $item == '--help') {
        echo "示例：php delete_tag.php v1.0.0~v1.1.0 v1.1.0 v1.2.0" . "\n";
        echo "范围：v1.0.0~v1.1.0 表示删除范围之内的版本。\n";
        echo "指定：v1.1.0 表示删除指定版本。可以连续指定多个！\n";
        echo "不支持：gtv1.2.0 或者 >=v1.0.0 类似的操作都不支持，可以配合范围灵活运用即可！\n";
        echo "指定仓库：-r=github 默认使用：origin 操作全部仓库：all \n";
        echo "[注意]：此工具删除tag只是协助，如果因为网络等原因无法全包完全正确地删除tag，如有未删除或者失败的情况请手动处理！\n";
        die;
    }
    if (str_starts_with($item, '-r=')) {
        $repositories[] = str_replace('-r=', '', $item);
        unset($argv[$argv_key]);
    }
}
if(empty($repositories)){
    $repositories[] = 'origin';
}
# 检查仓库是否存在
# 获取本地仓库
exec("git remote -v", $remotes, $return_var);
$local_repositories = [];
foreach ($remotes as $remote) {
    if (str_contains($remote, '(fetch)')) {
        $remote = explode('http', $remote);
        $local_repositories[] = trim($remote[0]);
    }
}
# 校验仓库
$use_all = in_array('all', $repositories);
if(!$use_all){
    foreach ($repositories as $repository_key => $repo) {
        if (!in_array($repo, $local_repositories)) {
            echo "指定仓库 $repo 不存在.\n";
            die;
        }
    }
}else{
    $repositories = $local_repositories;
}

if (empty($argv)) {
    echo "请输入版本范围:\n";
    $argv = explode(' ', trim(fgets(STDIN)));
}
if (empty($argv)) {
    echo "请输入版本范围后再试.\n";
    die;
}

# 校验版本
$versions = $argv;
$range_tags = [];
foreach ($versions as $versionKey => $version) {
    if (str_contains($version, '~')) {
        $versionRanges = explode('~', $version);
        # 最早的版本
        $earliestVersion = $versionRanges[0];
        if (empty($earliestVersion)) {
            echo "请输入正确的版本范围.\n -h 查看帮助信息！";
            die;
        }
        # 最晚的版本
        $latestVersion = $versionRanges[1];
        if (empty($latestVersion)) {
            echo "请输入正确的版本范围.\n -h 查看帮助信息！";
            die;
        }
        # 比较本本范围
        foreach ($tags as $key => $tag) {
            if (version_compare($tag, $earliestVersion, '>=') and version_compare($tag, $latestVersion, '<=')) {
                $range_tags[] = $tag;
            }
        }
        unset($versions[$versionKey]);
    }
}
# 再去除普通tag
$versions = array_merge($versions, $range_tags);
foreach ($versions as $version) {
    if (!in_array($version, $tags)) {
        echo "标签 $version 不存在.\n";
        die;
    }
}
# 去除未指定的标签
foreach ($tags as $key => $tag) {
    if (!in_array($tag, $versions)) {
        unset($tags[$key]);
    }
}
// 删除本地tag
foreach ($tags as $tag) {
    echo "git tag -d $tag" . "\n";
    exec("git tag -d $tag");
    foreach ($repositories as $repository) {
        echo "git push {$repository} --delete $tag" . "\n";
        exec("git push {$repository} --delete $tag");
        echo "标签 $tag 已从本地以及远程仓库{$repository}中删除.\n";
    }
}

echo "操作完成.";
