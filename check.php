<?php
// check.php
header('Content-Type: text/plain; charset=utf-8');

$required = ['pdo', 'pdo_sqlite', 'json', 'mbstring'];

echo "--- 环境扩展检测 ---\n";
foreach ($required as $ext) {
    if (extension_loaded($ext)) {
        echo "[√] $ext 扩展已加载\n";
    } else {
        echo "[X] $ext 扩展未安装或未启用！\n";
    }
}
?>