<?php
session_start();
// 确保 db.php 中定义了 $pdo 数据库连接对象
require_once __DIR__ . '/db.php'; 

// --- 配置区域 ---
define('ADMIN_PASSWORD', 'admin'); // 请修改为你的强密码

// 初始化设置表
$pdo->exec("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)");

// 1. 登录逻辑
if (isset($_POST['action']) && $_POST['action'] === 'login') {
    if ($_POST['password'] === ADMIN_PASSWORD) $_SESSION['admin_logged_in'] = true;
    else $error = "密码错误";
}
if (isset($_GET['logout'])) { session_destroy(); header('Location: admin.php'); exit; }

if (!isset($_SESSION['admin_logged_in'])) {
    echo '<!DOCTYPE html><html><head><script src="https://cdn.tailwindcss.com"></script><title>管理登录</title></head>
    <body class="bg-gray-100 flex items-center justify-center h-screen"><form method="POST" class="bg-white p-8 rounded shadow-md w-80"><h2 class="text-xl mb-4 font-bold">系统管理登录</h2>
    '.(isset($error)?'<p class="text-red-500 mb-2">'.$error.'</p>':'').'
    <input type="hidden" name="action" value="login"><input type="password" name="password" placeholder="管理密码" class="w-full border p-2 mb-4 rounded"><button class="w-full bg-blue-600 text-white py-2 rounded">登录</button></form></body></html>';
    exit;
}

// 2. 后端逻辑处理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // A. 用户管理
    if (isset($_POST['batch_delete_users'])) {
        $ids = $_POST['user_ids'] ?? [];
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("DELETE FROM messages WHERE sender_id IN ($placeholders) OR receiver_id IN ($placeholders)")->execute(array_merge($ids, $ids));
            $pdo->prepare("DELETE FROM users WHERE id IN ($placeholders)")->execute($ids);
        }
    }
    if (isset($_POST['update_user'])) {
        $id = array_key_first($_POST['update_user']);
        $pdo->prepare("UPDATE users SET username=?, nickname=?, password_text=? WHERE id=?")
            ->execute([$_POST['username'][$id], $_POST['nickname'][$id], $_POST['password'][$id], $id]);
    }
    if (isset($_POST['delete_user'])) {
        $id = array_key_first($_POST['delete_user']);
        $pdo->prepare("DELETE FROM messages WHERE sender_id=? OR receiver_id=?")->execute([$id, $id]);
        $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
    }
    // B. 消息队列
    if (isset($_POST['batch_delete_msgs'])) {
        $ids = $_POST['msg_ids'] ?? [];
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("DELETE FROM messages WHERE id IN ($placeholders)")->execute($ids);
        }
    }
    if (isset($_POST['clear_all_msgs'])) { $pdo->exec("DELETE FROM messages"); }
    // C. 系统设置
    if (isset($_POST['update_settings'])) {
        $pdo->prepare("REPLACE INTO settings (key, value) VALUES ('upload_enabled', ?)")->execute([$_POST['upload_status']]);
    }
    header('Location: admin.php'); exit;
}

// 3. 数据准备
$user_data = $pdo->query("SELECT id, username, nickname FROM users")->fetchAll(PDO::FETCH_ASSOC);
$user_map = [];
foreach ($user_data as $u) { $user_map[$u['id']] = $u['nickname'] ?: $u['username']; }

$res = $pdo->query("SELECT value FROM settings WHERE key='upload_enabled'")->fetch();
$upload_enabled = ($res && $res['value'] == '0') ? false : true;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head><meta charset="UTF-8"><script src="https://cdn.tailwindcss.com"></script><title>后台管理</title></head>
<body class="bg-gray-50 p-5">
    <div class="max-w-6xl mx-auto bg-white rounded-lg shadow p-6">
        <div class="flex justify-between mb-6 border-b pb-4">
            <h1 class="text-xl font-bold">运维管理中心</h1>
            <a href="?logout=1" class="text-red-500 text-sm">退出登录</a>
        </div>
        <div class="flex border-b mb-6 space-x-4">
            <button onclick="tab('users')" id="btn-users" class="px-6 py-2 border-b-2 border-blue-600 font-bold">用户管理</button>
            <button onclick="tab('msgs')" id="btn-msgs" class="px-6 py-2">消息列队</button>
            <button onclick="tab('settings')" id="btn-settings" class="px-6 py-2">系统配置</button>
        </div>

        <div id="tab-users"><form method="POST"><div class="mb-4"><button name="batch_delete_users" onclick="return confirm('确定删除选中的用户及其关联消息？')" class="bg-red-600 text-white px-4 py-1 rounded text-sm hover:bg-red-700">删除勾选用户</button></div>
        <table class="w-full text-sm text-left border"><thead class="bg-gray-100 border-b"><tr><th class="p-3"><input type="checkbox" onclick="selectAll(this, 'user_ids[]')"></th><th>ID</th><th>用户名</th><th>昵称</th><th>密码</th><th>操作</th></tr></thead>
        <tbody><?php foreach ($pdo->query("SELECT * FROM users")->fetchAll() as $u): ?>
            <tr class="border-b hover:bg-gray-50">
                <td class="p-3"><input type="checkbox" name="user_ids[]" value="<?=$u['id']?>"></td>
                <td class="p-3"><?=$u['id']?></td>
                <td class="p-3"><input name="username[<?=$u['id']?>]" value="<?=$u['username']?>" class="border p-1 w-24 rounded"></td>
                <td class="p-3"><input name="nickname[<?=$u['id']?>]" value="<?=$u['nickname']?>" class="border p-1 w-24 rounded"></td>
                <td class="p-3"><input name="password[<?=$u['id']?>]" value="<?=$u['password_text']?>" class="border p-1 w-24 rounded"></td>
                <td class="p-3"><button name="update_user[<?=$u['id']?>]" class="text-blue-600 mr-2">保存</button><button name="delete_user[<?=$u['id']?>]" onclick="return confirm('确定删除?')" class="text-red-600">删除</button></td>
            </tr><?php endforeach; ?></tbody></table></form></div>

        <div id="tab-msgs" class="hidden"><form method="POST"><div class="mb-4 flex gap-2"><button name="batch_delete_msgs" class="bg-blue-600 text-white px-4 py-1 rounded text-sm hover:bg-blue-700">删除勾选消息</button><button name="clear_all_msgs" onclick="return confirm('警告：确定清空所有列队？')" class="bg-red-600 text-white px-4 py-1 rounded text-sm hover:bg-red-700">清空所有列队</button></div>
        <table class="w-full text-sm text-left border"><thead class="bg-gray-100 border-b"><tr><th class="p-3"><input type="checkbox" onclick="selectAll(this, 'msg_ids[]')"></th><th>ID</th><th>发送者</th><th>接收者</th><th>预览</th></tr></thead>
        <tbody><?php foreach ($pdo->query("SELECT * FROM messages ORDER BY id DESC")->fetchAll() as $m): ?>
            <tr class="border-b hover:bg-gray-50"><td class="p-3"><input type="checkbox" name="msg_ids[]" value="<?=$m['id']?>"></td><td class="p-3"><?=$m['id']?></td><td class="p-3 font-bold text-blue-700"><?= $user_map[$m['sender_id']] ?? "ID:{$m['sender_id']}" ?></td><td class="p-3 font-bold text-green-700"><?= $user_map[$m['receiver_id']] ?? "ID:{$m['receiver_id']}" ?></td><td class="p-3 text-gray-500 truncate max-w-xs"><?=substr($m['encrypted_content'], 0, 30)?>...</td></tr>
        <?php endforeach; ?></tbody></table></form></div>

        <div id="tab-settings" class="hidden"><form method="POST" class="p-6 border rounded"><h3 class="font-bold mb-4">功能开关</h3><div class="flex items-center gap-4"><label>文件上传状态：</label><select name="upload_status" class="border p-2 rounded"><option value="1" <?=$upload_enabled?'selected':''?>>✅ 允许上传</option><option value="0" <?=$upload_enabled?'':'selected'?>>❌ 已关闭上传通道</option></select><button name="update_settings" class="bg-blue-600 text-white px-4 py-2 rounded">保存设置</button></div></form></div>
    </div>

    <script>
        function tab(n) {
            ['users','msgs','settings'].forEach(t => {
                document.getElementById('tab-'+t).classList.toggle('hidden', t !== n);
                document.getElementById('btn-'+t).classList.toggle('border-blue-600', t === n);
                document.getElementById('btn-'+t).classList.toggle('font-bold', t === n);
            });
        }
        function selectAll(s, name) { document.querySelectorAll(`input[name="${name}"]`).forEach(cb => cb.checked = s.checked); }
    </script>
</body>
</html>