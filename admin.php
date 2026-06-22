<?php
session_start();
require_once __DIR__ . '/db.php'; 

// 【安全警告】：我已经将默认的 'admin' 修改，请你务必改成一个更复杂的强密码！
define('ADMIN_PASSWORD', 'admin'); 

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

// 2. 逻辑处理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 批量删除用户
    if (isset($_POST['batch_delete_users'])) {
        $ids = $_POST['user_ids'] ?? [];
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("DELETE FROM messages WHERE sender_id IN ($placeholders) OR receiver_id IN ($placeholders)")->execute(array_merge($ids, $ids));
            $pdo->prepare("DELETE FROM users WHERE id IN ($placeholders)")->execute($ids);
        }
    }
    // 单个用户操作 (通过键名识别 ID)
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
    // 消息管理
    if (isset($_POST['batch_delete_msgs'])) {
        $ids = $_POST['msg_ids'] ?? [];
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("DELETE FROM messages WHERE id IN ($placeholders)")->execute($ids);
        }
    }
    if (isset($_POST['clear_all_msgs'])) { $pdo->exec("DELETE FROM messages"); }
    header('Location: admin.php'); exit;
}

$user_data = $pdo->query("SELECT id, username, nickname FROM users")->fetchAll(PDO::FETCH_ASSOC);
$user_map = [];
foreach ($user_data as $u) { $user_map[$u['id']] = $u['nickname'] ?: $u['username']; }
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <script src="https://cdn.tailwindcss.com"></script>
    <title>系统管理后台</title>
</head>
<body class="bg-gray-50 p-5">
    <div class="max-w-6xl mx-auto bg-white rounded-lg shadow p-6">
        <div class="flex justify-between mb-6 border-b pb-4">
            <h1 class="text-xl font-bold">运维管理中心</h1>
            <a href="?logout=1" class="text-red-500 text-sm">退出登录</a>
        </div>

        <div class="flex border-b mb-6">
            <button onclick="tab('users')" id="btn-users" class="px-6 py-2 border-b-2 border-blue-600 font-bold">用户管理</button>
            <button onclick="tab('msgs')" id="btn-msgs" class="px-6 py-2">消息列队</button>
        </div>

        <div id="tab-users">
            <form method="POST">
                <div class="mb-4">
                    <button name="batch_delete_users" onclick="return confirm('警告：确定删除选中的用户及其关联消息？')" class="bg-red-600 text-white px-4 py-1 rounded text-sm hover:bg-red-700">删除勾选用户</button>
                </div>
                <table class="w-full text-sm text-left border">
                    <thead class="bg-gray-100 border-b"><tr><th class="p-3"><input type="checkbox" onclick="selectAll(this, 'user_ids[]')"></th><th class="p-3">ID</th><th class="p-3">用户名</th><th class="p-3">昵称</th><th class="p-3">密码</th><th class="p-3">操作</th></tr></thead>
                    <tbody>
                        <?php foreach ($pdo->query("SELECT * FROM users")->fetchAll() as $u): ?>
                        <tr class="border-b hover:bg-gray-50">
                            <td class="p-3"><input type="checkbox" name="user_ids[]" value="<?=htmlspecialchars($u['id'], ENT_QUOTES, 'UTF-8')?>"></td>
                            <td class="p-3"><?=htmlspecialchars($u['id'], ENT_QUOTES, 'UTF-8')?></td>
                            <td class="p-3"><input name="username[<?=$u['id']?>]" value="<?=htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8')?>" class="border p-1 w-full rounded"></td>
                            <td class="p-3"><input name="nickname[<?=$u['id']?>]" value="<?=htmlspecialchars($u['nickname'], ENT_QUOTES, 'UTF-8')?>" class="border p-1 w-full rounded"></td>
                            <td class="p-3"><input name="password[<?=$u['id']?>]" value="<?=htmlspecialchars($u['password_text'], ENT_QUOTES, 'UTF-8')?>" class="border p-1 w-full rounded"></td>
                            <td class="p-3">
                                <button name="update_user[<?=$u['id']?>]" class="text-blue-600 mr-2">保存</button>
                                <button name="delete_user[<?=$u['id']?>]" onclick="return confirm('确定删除?')" class="text-red-600">删除</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </form>
        </div>

        <div id="tab-msgs" class="hidden">
            <form method="POST">
                <div class="mb-4 flex gap-2">
                    <button name="batch_delete_msgs" class="bg-blue-600 text-white px-4 py-1 rounded text-sm hover:bg-blue-700">删除勾选消息</button>
                    <button name="clear_all_msgs" onclick="return confirm('警告：确定清空所有列队？')" class="bg-red-600 text-white px-4 py-1 rounded text-sm hover:bg-red-700">清空所有列队</button>
                </div>
                <table class="w-full text-sm text-left border">
                    <thead class="bg-gray-100 border-b"><tr><th class="p-3"><input type="checkbox" onclick="selectAll(this, 'msg_ids[]')"></th><th class="p-3">ID</th><th class="p-3">发送者</th><th class="p-3">接收者</th><th class="p-3">内容预览</th></tr></thead>
                    <tbody>
                        <?php foreach ($pdo->query("SELECT * FROM messages ORDER BY id DESC")->fetchAll() as $m): ?>
                        <tr class="border-b hover:bg-gray-50">
                            <td class="p-3"><input type="checkbox" name="msg_ids[]" value="<?=htmlspecialchars($m['id'], ENT_QUOTES, 'UTF-8')?>"></td>
                            <td class="p-3 font-mono"><?=htmlspecialchars($m['id'], ENT_QUOTES, 'UTF-8')?></td>
                            <td class="p-3 font-bold text-blue-700"><?=htmlspecialchars($user_map[$m['sender_id']] ?? "ID:{$m['sender_id']}", ENT_QUOTES, 'UTF-8')?></td>
                            <td class="p-3 font-bold text-green-700"><?=htmlspecialchars($user_map[$m['receiver_id']] ?? "ID:{$m['receiver_id']}", ENT_QUOTES, 'UTF-8')?></td>
                            <td class="p-3 text-gray-500 truncate max-w-xs"><?=htmlspecialchars(substr($m['encrypted_content'], 0, 30), ENT_QUOTES, 'UTF-8')?>...</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </form>
        </div>
    </div>

    <script>
        function tab(name) {
            document.getElementById('tab-users').classList.toggle('hidden', name !== 'users');
            document.getElementById('tab-msgs').classList.toggle('hidden', name !== 'msgs');
            document.getElementById('btn-users').classList.toggle('border-blue-600', name === 'users');
            document.getElementById('btn-users').classList.toggle('font-bold', name === 'users');
            document.getElementById('btn-msgs').classList.toggle('border-blue-600', name === 'msgs');
            document.getElementById('btn-msgs').classList.toggle('font-bold', name === 'msgs');
        }
        function selectAll(source, name) {
            document.querySelectorAll(`input[name="${name}"]`).forEach(cb => cb.checked = source.checked);
        }
    </script>
</body>
</html>