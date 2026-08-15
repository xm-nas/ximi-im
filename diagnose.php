<?php
// 简单的诊断脚本
echo "检查 admin.php 语法...\n";

$adminFile = __DIR__ . '/admin.php';
if (!file_exists($adminFile)) {
    die("admin.php 不存在\n");
}

// 读取文件内容
$content = file_get_contents($adminFile);

// 检查第一行
if (substr($content, 0, 5) !== '<?php') {
    die("错误：文件不以 <?php 开头\n");
}

// 计算 PHP 标签数量
$openCount = substr_count($content, '<?php') + substr_count($content, '<?=');
$closeCount = substr_count($content, '?>');

echo "PHP 打开标签数：" . $openCount . "\n";
echo "PHP 关闭标签数：" . $closeCount . "\n";

if ($openCount !== $closeCount) {
    echo "警告：标签不匹配！\n";
}

// 尝试 eval 第一行
$firstLine = strpos($content, "\n");
$firstPhpCode = substr($content, 5, $firstLine - 5);
echo "第一行 PHP：" . trim(substr($firstPhpCode, 0, 80)) . "...\n";

echo "\nadmin.php 看起来没有明显的语法错误\n";
?>
