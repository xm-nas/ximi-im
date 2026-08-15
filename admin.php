<?php
session_start();

// ========================================================
// 1. 读取配置文件与环境准备
// ========================================================
if (!file_exists(__DIR__ . '/setting.php')) {
    header('Location: install.php');
    exit;
}
$config = require __DIR__ . '/setting.php';

$pdo_dir = __DIR__ . '/' . $config['db_dir'] . '/';
if (!is_dir($pdo_dir)) mkdir($pdo_dir, 0755, true);

$admin_db_file = $pdo_dir . $config['admin_db_name'];

// ========================================================
// 2. 连接管理员独立数据库
// ========================================================
try {
    $admin_pdo = new PDO("sqlite:" . $admin_db_file);
    $admin_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $admin_pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $admin_pdo->exec("CREATE TABLE IF NOT EXISTS admin_user (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        login_key TEXT NOT NULL,
        last_ips TEXT DEFAULT '[]',
        account_status INTEGER DEFAULT 1
    )");
} catch (PDOException $e) {
    die("管理员数据库异常: " . $e->getMessage());
}

$admin_data = $admin_pdo->query("SELECT * FROM admin_user LIMIT 1")->fetch();

// ========================================================
// 3. 后台首次运行：初始化密码
// ========================================================
if (!$admin_data) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['init_admin'])) {
        $pwd = trim($_POST['admin_password']);
        if (!empty($pwd)) {
            $hash = password_hash($pwd, PASSWORD_DEFAULT);
            $admin_pdo->prepare("INSERT INTO admin_user (login_key, last_ips, account_status) VALUES (?, ?, 1)")->execute([$hash, '[]']);
            header('Location: admipwdn.php');
            exit;
        }
    }
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><script src="https://cdn.tailwindcss.com"></script><title>初始化管理密码</title><style>body{background:#f5f7fb;}</style></head><body class="flex items-center justify-center min-h-screen px-4"><div class="w-full max-w-md"><div class="text-center mb-6"><div class="text-2xl font-semibold text-gray-900">ximi IM</div><div class="text-xs text-gray-500 mt-1">初始化安全中心 · 管理员配置</div></div><div class="bg-white border border-gray-200 rounded-xl shadow-sm p-6"><h2 class="text-lg font-semibold text-gray-800 text-center">⚙️ 初始化超级管理员</h2><form method="POST" class="mt-5 space-y-4"><input type="hidden" name="init_admin" value="1"><div><input type="password" name="admin_password" placeholder="请输入安全密码" required class="w-full mt-1 border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-green-500"></div><button class="w-full py-2.5 rounded-lg text-white text-sm font-medium bg-green-500 hover:bg-green-600 transition">保存并完成初始化</button></form></div></div></body></html>';
    exit;
}

// ========================================================
// 4. 后台登录校验
// ========================================================
if (isset($_GET['logout'])) { session_destroy(); header('Location: admin.php'); exit; }

if (isset($_POST['action']) && $_POST['action'] === 'login') {
    if ($admin_data['account_status'] != 1) {
        $error = "该超管账号已被封禁阻断！";
    } elseif (password_verify($_POST['password'], $admin_data['login_key'])) {
        $_SESSION['admin_logged_in'] = true;
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $ips = json_decode($admin_data['last_ips'] ?: '[]', true);
        array_unshift($ips, $ip);
        $ips = array_slice(array_unique($ips), 0, 5); 
        $admin_pdo->prepare("UPDATE admin_user SET last_ips = ? WHERE id = ?")->execute([json_encode($ips), $admin_data['id']]);
        header('Location: admin.php');
        exit;
    } else {
        $error = "安全校验失败：密码错误";
    }
}

if (!isset($_SESSION['admin_logged_in'])) {
  echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><script src="https://cdn.tailwindcss.com"></script><title>管理登录</title><style>body{background:#f5f7fb;}</style></head><body class="flex items-center justify-center min-h-screen px-4"><div class="w-full max-w-sm"><div class="text-center mb-6"><div class="text-2xl font-semibold text-gray-900">ximi IM</div><div class="text-xs text-gray-500 mt-1">System Operation Center</div></div><div class="bg-white border border-gray-200 rounded-xl shadow-sm p-6"><h2 class="text-lg font-semibold text-center text-gray-800">系统运维中心</h2>' . (isset($error) ? '<div class="mt-4 bg-red-50 border border-red-200 text-red-600 text-xs p-2 rounded text-center">' . $error . '</div>' : '') . '<form method="POST" class="mt-5 space-y-4"><input type="hidden" name="action" value="login"><div><input type="password" name="password" placeholder="请输入安全密码" class="w-full mt-1 border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-green-500"></div><button class="w-full py-2.5 rounded-lg text-white text-sm font-medium bg-green-500 hover:bg-green-600 transition">安全登录</button></form></div></div></body></html>';
  exit;
}

$admin_data = $admin_pdo->query("SELECT * FROM admin_user LIMIT 1")->fetch();

// ========================================================
// 5. 引入主数据库
// ========================================================
require_once __DIR__ . '/db.php'; 

// 初始化设置表 (保证配置项可用)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (`key` TEXT PRIMARY KEY, `value` TEXT)");
    
    // 初始化上传开关
    $stmt = $pdo->query("SELECT value FROM settings WHERE key='upload_enabled'");
    $upload_setting = $stmt->fetchColumn();
    if ($upload_setting === false) {
        $pdo->exec("INSERT INTO settings (`key`, `value`) VALUES ('upload_enabled', '1')");
        $upload_setting = '1';
    }

    // 初始化注册开关 (如果不存在则自动新建并默认开启)
    $stmt_reg = $pdo->query("SELECT value FROM settings WHERE key='register_enabled'");
    $register_setting = $stmt_reg->fetchColumn();
    if ($register_setting === false) {
        $pdo->exec("INSERT INTO settings (`key`, `value`) VALUES ('register_enabled', '1')");
        $register_setting = '1';
    }
} catch (PDOException $e) {
    die("数据库配置异常: " . $e->getMessage());
}


// ========================================================
// 6. POST 请求统筹处理中心
// ========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 安全中心操作
    if (isset($_POST['action']) && $_POST['action'] === 'change_password') {
        $hash = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
        $admin_pdo->prepare("UPDATE admin_user SET login_key = ? WHERE id = 1")->execute([$hash]);
    }

    // ========================================================
    // 公告系统操作
    // ========================================================
    if (isset($_POST['action']) && $_POST['action'] === 'create_announcement') {
        $title = trim($_POST['announcement_title'] ?? '');
        $content = trim($_POST['announcement_content'] ?? '');
        $priority = intval($_POST['announcement_priority'] ?? 0);
        $end_time = trim($_POST['announcement_end_time'] ?? '');
        
        if (!empty($title) && !empty($content)) {
            $stmt = $pdo->prepare("
                INSERT INTO announcements (title, content, creator_id, priority, end_time, status, start_time, created_at, updated_at)
                VALUES (?, ?, 1, ?, ?, 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([$title, $content, $priority, empty($end_time) ? null : $end_time]);
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'update_announcement') {
        $announcement_id = intval($_POST['announcement_id'] ?? 0);
        $title = trim($_POST['announcement_title'] ?? '');
        $content = trim($_POST['announcement_content'] ?? '');
        $priority = intval($_POST['announcement_priority'] ?? 0);
        $status = trim($_POST['announcement_status'] ?? 'active');
        $end_time = trim($_POST['announcement_end_time'] ?? '');
        
        if ($announcement_id > 0) {
            $stmt = $pdo->prepare("
                UPDATE announcements
                SET title = ?, content = ?, priority = ?, status = ?, end_time = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$title, $content, $priority, $status, empty($end_time) ? null : $end_time, $announcement_id]);
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'delete_announcement') {
        $announcement_id = intval($_POST['announcement_id'] ?? 0);
        if ($announcement_id > 0) {
            $pdo->prepare("DELETE FROM announcement_reads WHERE announcement_id = ?")->execute([$announcement_id]);
            $pdo->prepare("DELETE FROM announcements WHERE id = ?")->execute([$announcement_id]);
        }
    }

    if (isset($_POST['save_settings'])) {
        $new_val = $_POST['upload_enabled'] === '1' ? '1' : '0';
        $pdo->prepare("UPDATE settings SET value=? WHERE key='upload_enabled'")->execute([$new_val]);
        
        // 【新增】：保存注册开关
        $new_reg_val = $_POST['register_enabled'] === '1' ? '1' : '0';
        $pdo->prepare("UPDATE settings SET value=? WHERE key='register_enabled'")->execute([$new_reg_val]);
    }

    // 群管理操作
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'delete_group') {
            $pdo->prepare("DELETE FROM chat_groups WHERE id = ?")->execute([$_POST['group_id']]);
            $pdo->prepare("DELETE FROM group_members WHERE group_id = ?")->execute([$_POST['group_id']]);
            $pdo->prepare("DELETE FROM messages WHERE receiver_id = ? AND msg_type = 'group'")->execute([$_POST['group_id']]);
        }
        if ($_POST['action'] === 'add_member') {
            $check = $pdo->prepare("SELECT id FROM group_members WHERE group_id = ? AND user_id = ?");
            $check->execute([$_POST['group_id'], $_POST['user_id']]);
            if (!$check->fetch()) {
                $pdo->prepare("INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, 'member')")->execute([$_POST['group_id'], $_POST['user_id']]);
            }
        }
        if ($_POST['action'] === 'remove_member') {
            $pdo->prepare("DELETE FROM group_members WHERE group_id = ? AND user_id = ?")->execute([$_POST['group_id'], $_POST['user_id']]);
        }
    }

    // 用户数据导出
    if (isset($_POST['export_users'])) {
        $users = $pdo->query("SELECT * FROM users")->fetchAll(PDO::FETCH_ASSOC);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="ximi_users_backup_' . date('Ymd_His') . '.json"');
        echo json_encode($users, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    // 用户数据导入
    if (isset($_POST['import_users']) && isset($_FILES['import_file']) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
        $json_data = file_get_contents($_FILES['import_file']['tmp_name']);
        $users = json_decode($json_data, true);
        if (is_array($users)) {
            foreach ($users as $u) {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $stmt->execute([$u['username'] ?? '']);
                if (!$stmt->fetch()) {
                    $pdo->prepare("INSERT INTO users (username, password_text, public_key, nickname, created_at, last_ip, stop_user) VALUES (?, ?, ?, ?, ?, ?, ?)")
                        ->execute([
                            $u['username'], $u['password_text'], $u['public_key'] ?? '', $u['nickname'] ?? '',
                            $u['created_at'] ?? date('Y-m-d H:i:s'), $u['last_ip'] ?? '', $u['stop_user'] ?? 0
                        ]);
                }
            }
        }
    }

    // 用户及消息操作
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
        $username = trim($_POST['username'][$id] ?? '');
        $nickname = trim($_POST['nickname'][$id] ?? '');
        $password = trim($_POST['password'][$id] ?? '');
        $created_at = trim($_POST['created_at'][$id] ?? '');
        $last_ip = trim($_POST['last_ip'][$id] ?? '');
        $stop_user = intval($_POST['stop_user'][$id] ?? 0);

        if (strlen($password) !== 32 && !empty($password)) { $password = md5($password); }
        $pdo->prepare("UPDATE users SET username=?, nickname=?, password_text=?, created_at=?, last_ip=?, stop_user=? WHERE id=?")
            ->execute([$username, $nickname, $password, $created_at, $last_ip, $stop_user, $id]);
    }

    if (isset($_POST['delete_user'])) {
        $id = array_key_first($_POST['delete_user']);
        $pdo->prepare("DELETE FROM messages WHERE sender_id=? OR receiver_id=?")->execute([$id, $id]);
        $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
    }

    if (isset($_POST['batch_delete_msgs'])) {
        $ids = $_POST['msg_ids'] ?? [];
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("DELETE FROM messages WHERE id IN ($placeholders)")->execute($ids);
        }
    }

    if (isset($_POST['clear_all_msgs'])) { $pdo->exec("DELETE FROM messages"); }

    if (isset($_POST['action']) && $_POST['action'] === 'update_password') {
        $group_id = intval($_POST['group_id'] ?? 0);
        $pwd = trim($_POST['room_password'] ?? '');
        if ($group_id > 0 && !empty($pwd)) {
            $stmt = $pdo->prepare("UPDATE chat_groups SET room_password = ? WHERE id = ?");
            $stmt->execute([$pwd, $group_id]);
        }
    }
    
    header('Location: admin.php'); 
    exit;
}

// 缓存用户列表映射
$user_data = $pdo->query("SELECT id, username, nickname FROM users")->fetchAll(PDO::FETCH_ASSOC);
$user_map = [];
foreach ($user_data as $u) { $user_map[$u['id']] = $u['nickname'] ?: $u['username']; }
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <script src="https://cdn.tailwindcss.com"></script>
    <title>系统运维管理后台</title>
    <script>
        function switchTab(target) {
            ['users', 'msgs', 'groups', 'security', 'announcements'].forEach(id => {
                document.getElementById('tab-' + id).classList.add('hidden');
                document.getElementById('btn-' + id).className = 'px-6 py-2 text-gray-500 hover:text-blue-600 transition whitespace-nowrap';
            });
            document.getElementById('tab-' + target).classList.remove('hidden');
            document.getElementById('btn-' + target).className = 'px-6 py-2 border-b-2 border-blue-600 font-bold text-blue-600 transition whitespace-nowrap';
        }
        function selectAll(source, name) {
            document.querySelectorAll(`input[name="${name}"]`).forEach(cb => cb.checked = source.checked);
        }
    </script>
</head>
<body class="bg-gray-50 p-5 font-sans">
    <div class="max-w-7xl mx-auto bg-white rounded-lg shadow-lg p-6">
        
        <div class="flex justify-between items-center mb-6 border-b pb-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-800">安全通讯运维中心</h1>
                <div class="text-xs text-gray-500 mt-2 font-mono">
                    🛡️ 超级管理员状态：正常 | 最近活动 IP: 
                    <?php 
                        $ips = json_decode($admin_data['last_ips'], true);
                        echo empty($ips) ? '暂无记录' : implode(' / ', $ips);
                    ?>
                </div>
            </div>
            <a href="?logout=1" class="bg-red-50 text-red-600 px-4 py-2 rounded-md font-bold hover:bg-red-500 hover:text-white transition">退出登录</a>
        </div>

        <div class="flex border-b border-gray-200 mb-6 overflow-x-auto">
            <button id="btn-users" onclick="switchTab('users')" class="px-6 py-2 border-b-2 border-blue-600 font-bold text-blue-600 transition whitespace-nowrap">用户管理</button>
            <button id="btn-msgs" onclick="switchTab('msgs')" class="px-6 py-2 text-gray-500 hover:text-blue-600 transition whitespace-nowrap">消息队列</button>
            <button id="btn-groups" onclick="switchTab('groups')" class="px-6 py-2 text-gray-500 hover:text-blue-600 transition whitespace-nowrap">群组管理</button>
            <button id="btn-announcements" onclick="switchTab('announcements')" class="px-6 py-2 text-gray-500 hover:text-blue-600 transition whitespace-nowrap">📢 公告系统</button>
            <button id="btn-security" onclick="switchTab('security')" class="px-6 py-2 text-gray-500 hover:text-blue-600 transition whitespace-nowrap">安全中心</button>
        </div>

        <div id="tab-users">
            <form method="POST" enctype="multipart/form-data">
                <div class="mb-4 flex flex-wrap gap-2 justify-between items-center">
                    <div>
                        <button name="batch_delete_users" onclick="return confirm('警告：确定删除选中的用户及其所有关联消息吗？此操作不可逆！')" class="bg-red-500 text-white px-4 py-2 rounded text-sm hover:bg-red-600 shadow transition">🗑️ 删除勾选用户</button>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <button name="export_users" formnovalidate class="bg-emerald-500 text-white px-4 py-2 rounded text-sm hover:bg-emerald-600 shadow transition">💾 导出全库 (JSON)</button>
                        <div class="flex items-center gap-2 bg-white p-1 rounded border border-gray-200 shadow-sm">
                            <input type="file" name="import_file" accept=".json" class="text-sm text-gray-600 file:mr-2 file:py-1.5 file:px-3 file:border-0 file:text-sm file:font-semibold file:bg-gray-100 file:text-gray-700 hover:file:bg-gray-200 rounded cursor-pointer">
                            <button name="import_users" class="bg-gray-800 text-white px-4 py-1.5 rounded text-sm hover:bg-gray-900 shadow transition">📥 导入还原</button>
                        </div>
                    </div>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left border text-gray-700 whitespace-nowrap">
                        <thead class="bg-gray-100 border-b">
                            <tr>
                                <th class="p-3 w-10 text-center"><input type="checkbox" onclick="selectAll(this, 'user_ids[]')"></th>
                                <th class="p-3">UID</th>
                                <th class="p-3">用户名</th>
                                <th class="p-3">MD5密码/修改密码</th>
                                <th class="p-3">用户昵称</th>
                                <th class="p-3">注册时间</th>
                                <th class="p-3">最后登录 IP</th>
                                <th class="p-3">账号状态</th>
                                <th class="p-3">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pdo->query("SELECT * FROM users ORDER BY id DESC")->fetchAll() as $u): ?>
                            <tr class="border-b hover:bg-gray-50">
                                <td class="p-3 text-center"><input type="checkbox" name="user_ids[]" value="<?=htmlspecialchars($u['id'], ENT_QUOTES, 'UTF-8')?>"></td>
                                <td class="p-3 font-bold text-gray-500"><?=htmlspecialchars($u['id'], ENT_QUOTES, 'UTF-8')?></td>
                                <td class="p-2"><input name="username[<?=$u['id']?>]" value="<?=htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8')?>" class="border p-2 w-32 rounded focus:ring-1 focus:ring-blue-500"></td>
                                <td class="p-2"><input name="password[<?=$u['id']?>]" value="<?=htmlspecialchars($u['password_text'], ENT_QUOTES, 'UTF-8')?>" title="提示: 直接输入明文密码点击保存，系统会自动转为MD5" class="border p-2 w-48 rounded text-xs text-gray-500 focus:ring-1 focus:ring-blue-500" placeholder="填入新明文将自动加密"></td>
                                <td class="p-2"><input name="nickname[<?=$u['id']?>]" value="<?=htmlspecialchars($u['nickname'] ?? '', ENT_QUOTES, 'UTF-8')?>" class="border p-2 w-32 rounded focus:ring-1 focus:ring-blue-500"></td>
                                <td class="p-2"><input name="created_at[<?=$u['id']?>]" value="<?=htmlspecialchars($u['created_at'] ?? '', ENT_QUOTES, 'UTF-8')?>" class="border p-2 w-40 rounded text-xs text-gray-500 focus:ring-1 focus:ring-blue-500"></td>
                                <td class="p-2"><input name="last_ip[<?=$u['id']?>]" value="<?=htmlspecialchars($u['last_ip'] ?? '', ENT_QUOTES, 'UTF-8')?>" class="border p-2 w-32 rounded text-xs font-mono focus:ring-1 focus:ring-blue-500"></td>
                                <td class="p-2">
                                    <select name="stop_user[<?=$u['id']?>]" class="border p-2 rounded text-sm focus:outline-none focus:ring-1 focus:ring-blue-500 <?= $u['stop_user']==1 ? 'bg-red-100 text-red-700 font-bold border-red-300' : 'bg-green-50 text-green-700 border-green-300' ?>">
                                        <option value="0" <?= $u['stop_user']==0 ? 'selected' : '' ?>>🟢 正常</option>
                                        <option value="1" <?= $u['stop_user']==1 ? 'selected' : '' ?>>🔴 封禁</option>
                                    </select>
                                </td>
                                <td class="p-2">
                                    <button name="update_user[<?=$u['id']?>]" class="bg-blue-100 text-blue-700 px-3 py-1 rounded hover:bg-blue-200 transition mr-1">保存</button>
                                    <button name="delete_user[<?=$u['id']?>]" onclick="return confirm('确定彻底删除该用户吗?')" class="bg-red-100 text-red-700 px-3 py-1 rounded hover:bg-red-200 transition">删除</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        </div>

        <div id="tab-msgs" class="hidden">
            <form method="POST">
                <div class="mb-4 flex gap-2">
                    <button name="batch_delete_msgs" class="bg-orange-500 text-white px-4 py-2 rounded text-sm hover:bg-orange-600 shadow transition">🗑️ 删除勾选列队</button>
                    <button name="clear_all_msgs" onclick="return confirm('🚨 严重警告：确定要一键清空整个数据库里的所有未读消息和传输列队吗？')" class="bg-red-600 text-white px-4 py-2 rounded text-sm hover:bg-red-700 shadow transition">💣 一键清空所有列队</button>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left border text-gray-700 whitespace-nowrap">
                        <thead class="bg-gray-100 border-b">
                            <tr>
                                <th class="p-3 w-10 text-center"><input type="checkbox" onclick="selectAll(this, 'msg_ids[]')"></th>
                                <th class="p-3">MSG ID</th>
                                <th class="p-3">类型</th>
                                <th class="p-3">发送者</th>
                                <th class="p-3">接收者</th>
                                <th class="p-3">状态</th>
                                <th class="p-3">产生时间</th>
                                <th class="p-3 w-1/4">密文/分片目录预览</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pdo->query("SELECT * FROM messages ORDER BY id DESC")->fetchAll() as $m): ?>
                            <tr class="border-b hover:bg-gray-50">
                                <td class="p-3 text-center"><input type="checkbox" name="msg_ids[]" value="<?=htmlspecialchars($m['id'], ENT_QUOTES, 'UTF-8')?>"></td>
                                <td class="p-3 font-mono text-gray-500"><?=htmlspecialchars($m['id'], ENT_QUOTES, 'UTF-8')?></td>
                                <td class="p-3"><span class="px-2 py-1 rounded text-xs font-bold <?= $m['msg_type'] == 'file' ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700' ?>"><?=htmlspecialchars(strtoupper($m['msg_type']), ENT_QUOTES, 'UTF-8')?></span></td>
                                <td class="p-3 font-bold text-blue-700"><?=htmlspecialchars($user_map[$m['sender_id']] ?? "UID:{$m['sender_id']}", ENT_QUOTES, 'UTF-8')?></td>
                                <td class="p-3 font-bold text-green-700"><?=htmlspecialchars($user_map[$m['receiver_id']] ?? "UID:{$m['receiver_id']}", ENT_QUOTES, 'UTF-8')?></td>
                                <td class="p-3"><?= $m['is_downloaded'] ? '<span class="text-green-500 font-bold text-xs">已接收</span>' : '<span class="text-orange-500 font-bold text-xs">列队中...</span>' ?></td>
                                <td class="p-3 text-xs text-gray-500"><?=htmlspecialchars($m['created_at'], ENT_QUOTES, 'UTF-8')?></td>
                                <td class="p-3 text-gray-400 font-mono text-xs truncate max-w-xs" title="<?=htmlspecialchars($m['encrypted_content'], ENT_QUOTES, 'UTF-8')?>"><?=htmlspecialchars(substr($m['encrypted_content'], 0, 45), ENT_QUOTES, 'UTF-8')?>...</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        </div>


<?php

// ============================
// 1. 拉取群列表
// ============================
$stmt_groups = $pdo->query("
    SELECT *
    FROM chat_groups
    ORDER BY id DESC
");

$groups = $stmt_groups->fetchAll(PDO::FETCH_ASSOC);


// ============================
// 2. 拉取所有成员（避免N+1查询）
// ============================
$members_map = [];

$stmt_members = $pdo->query("
    SELECT 
        gm.group_id,
        gm.user_id,
        gm.role,
        gm.joined_at,
        u.nickname
    FROM group_members gm
    LEFT JOIN users u ON u.id = gm.user_id
");

while ($row = $stmt_members->fetch(PDO::FETCH_ASSOC)) {
    $members_map[$row['group_id']][] = $row;
}

?>



<div id="tab-groups" class="hidden space-y-4">

<?php foreach ($groups as $g): 
    $gid = $g['id'];
    $members = $members_map[$gid] ?? [];
?>

<!-- 群卡片 -->
<div class="bg-white border rounded-lg shadow-sm overflow-hidden">

    <!-- 群头部 -->
    <div class="flex justify-between items-center p-4 bg-gray-50">

        <div>
            <div class="font-bold text-blue-700">
                #<?= $gid ?> <?= htmlspecialchars($g['name']) ?>
            </div>
            <div class="text-xs text-gray-500">
                群主：<?= htmlspecialchars($user_map[$g['creator_id']] ?? $g['creator_id']) ?>
                ｜成员：<?= count($members) ?> 人
                ｜创建：<?= $g['created_at'] ?>
            </div>
        </div>

        <div class="flex items-center gap-2">

            <!-- 群密码 -->
            <form method="POST" class="flex gap-1">
                <input type="hidden" name="action" value="update_password">
                <input type="hidden" name="group_id" value="<?= $gid ?>">

                <input name="room_password"
                       value="<?= htmlspecialchars($g['room_password']) ?>"
                       class="border px-2 py-1 text-xs rounded w-24">

                <button class="bg-blue-600 text-white px-2 py-1 text-xs rounded">
                    改密
                </button>
            </form>

            <!-- 折叠按钮 -->
            <button onclick="toggleGroup(<?= $gid ?>)"
                    class="bg-gray-200 px-3 py-1 text-xs rounded">
                展开成员
            </button>

            <!-- 解散 -->
            <form method="POST">
                <input type="hidden" name="action" value="delete_group">
                <input type="hidden" name="group_id" value="<?= $gid ?>">
                <button class="text-red-600 text-xs"
                        onclick="return confirm('确定解散群？')">
                    解散
                </button>
            </form>

        </div>
    </div>

    <!-- 成员区域 -->
    <div id="group-<?= $gid ?>" class="hidden p-4 border-t bg-white">

        <table class="w-full text-xs">
            <thead class="text-gray-500">
                <tr>
                    <th class="text-left p-2">UID</th>
                    <th class="text-left p-2">昵称</th>
                    <th class="text-left p-2">加入时间</th>
                    <th class="text-left p-2">角色</th>
                    <th class="text-left p-2">操作</th>
                </tr>
            </thead>

            <tbody>
            <?php foreach ($members as $m): ?>
                <tr class="border-t">
                    <td class="p-2 font-mono"><?= $m['user_id'] ?></td>
                    <td class="p-2"><?= htmlspecialchars($m['nickname'] ?? '未知') ?></td>
                    <td class="p-2 text-gray-500"><?= $m['joined_at'] ?></td>
                    <td class="p-2"><?= $m['role'] ?></td>
                    <td class="p-2">

                        <form method="POST">
                            <input type="hidden" name="action" value="remove_member">
                            <input type="hidden" name="group_id" value="<?= $gid ?>">
                            <input type="hidden" name="user_id" value="<?= $m['user_id'] ?>">

                            <button class="text-red-500 text-xs">
                                移除
                            </button>
                        </form>

                    </td>
                </tr>
            <?php endforeach; ?>

            <!-- 添加成员 -->
            <tr class="border-t">
                <form method="POST">
                    <td colspan="4" class="p-2">
                        <input type="hidden" name="action" value="add_member">
                        <input type="hidden" name="group_id" value="<?= $gid ?>">

                        <input name="user_id"
                               placeholder="输入UID添加成员"
                               class="border px-2 py-1 text-xs w-40">
                    </td>

                    <td class="p-2">
                        <button class="bg-green-600 text-white px-3 py-1 text-xs rounded">
                            + 添加
                        </button>
                    </td>
                </form>
            </tr>

            </tbody>
        </table>

    </div>

</div>

<?php endforeach; ?>

</div>


        <div id="tab-security" class="hidden space-y-6">
            <div class="p-6 bg-gray-50 border border-gray-200 rounded-lg shadow-sm">
                <div class="mb-4">
                    <h2 class="font-bold text-lg text-gray-800">全局接口熔断机制</h2>
                    <p class="text-sm text-gray-500">控制用户注册通道，或在被攻击时紧急关闭所有接口的文件上传权限。</p>
                </div>
                <form method="POST" class="flex flex-col sm:flex-row items-center gap-4">
                    <select name="register_enabled" class="border border-gray-300 p-2.5 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 font-medium text-gray-700 bg-white w-full sm:w-auto">
                        <option value="1" <?= $register_setting == '1' ? 'selected' : '' ?>>✅ 允许新用户注册</option>
                        <option value="0" <?= $register_setting == '0' ? 'selected' : '' ?>>❌ 阻断新用户注册</option>
                    </select>
                    <select name="upload_enabled" class="border border-gray-300 p-2.5 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 font-medium text-gray-700 bg-white w-full sm:w-auto">
                        <option value="1" <?= $upload_setting == '1' ? 'selected' : '' ?>>✅ 允许图片/文件上传</option>
                        <option value="0" <?= $upload_setting == '0' ? 'selected' : '' ?>>❌ 紧急禁止上传 (全站熔断)</option>
                    </select>
                    <button name="save_settings" class="bg-blue-600 text-white px-6 py-2.5 rounded-lg shadow hover:bg-blue-700 transition w-full sm:w-auto font-medium">应用配置</button>
                </form>
            </div>

            <div class="p-6 bg-gray-50 border border-gray-200 rounded-lg shadow-sm">
                <div class="mb-4">
                    <h3 class="font-bold text-lg text-gray-800">超级管理员凭证</h3>
                    <p class="text-sm text-gray-500">修改 admin.php 后台的独立登录密码。采用 bcrypt 算法强散列加密。</p>
                </div>
                <form method="POST" class="flex flex-col sm:flex-row gap-3">
                    <input type="hidden" name="action" value="change_password">
                    <input type="password" name="new_password" placeholder="请输入高强度新密码" required class="border border-gray-300 p-2.5 rounded-lg w-full sm:w-72 focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white">
                    <button class="bg-gray-800 text-white px-6 py-2.5 rounded-lg hover:bg-gray-900 shadow transition font-medium w-full sm:w-auto">重新颁发安全凭证</button>
                </form>
            </div>
        </div>

        <!-- 公告管理标签页 -->
        <div id="tab-announcements" class="hidden space-y-4">
            <form method="POST" id="announcementForm" class="p-4 bg-blue-50 border border-blue-200 rounded-lg shadow-sm space-y-4">
                <input type="hidden" name="action" value="create_announcement">
                <input type="hidden" name="announcement_id" value="">
                
                <h3 class="font-bold text-lg text-gray-800">创建/编辑公告</h3>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">公告标题</label>
                    <input type="text" name="announcement_title" required placeholder="输入公告标题" class="w-full border border-gray-300 rounded-lg p-3 focus:ring-2 focus:ring-blue-500 focus:outline-none">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">公告内容</label>
                    <textarea name="announcement_content" required placeholder="输入公告内容" rows="5" class="w-full border border-gray-300 rounded-lg p-3 focus:ring-2 focus:ring-blue-500 focus:outline-none"></textarea>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">优先级</label>
                        <input type="number" name="announcement_priority" value="0" min="0" class="w-full border border-gray-300 rounded-lg p-3 focus:ring-2 focus:ring-blue-500 focus:outline-none" placeholder="数字越大越靠前">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">状态</label>
                        <select name="announcement_status" class="w-full border border-gray-300 rounded-lg p-3 focus:ring-2 focus:ring-blue-500 focus:outline-none">
                            <option value="active">📢 发布中</option>
                            <option value="inactive">❌ 已下线</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">有效期至</label>
                        <input type="datetime-local" name="announcement_end_time" class="w-full border border-gray-300 rounded-lg p-3 focus:ring-2 focus:ring-blue-500 focus:outline-none" placeholder="留空表示永久有效">
                    </div>
                </div>

                <div class="flex gap-2">
                    <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 transition font-medium">创建公告</button>
                    <button type="button" onclick="document.getElementById('announcementForm').reset(); document.querySelector('[name=\"action\"]').value = 'create_announcement';" class="bg-gray-300 text-gray-700 px-6 py-2 rounded-lg hover:bg-gray-400 transition font-medium">清空</button>
                </div>
            </form>

            <!-- 公告列表 -->
            <div class="space-y-3">
                <h3 class="font-bold text-lg text-gray-800">所有公告</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left border text-gray-700 whitespace-nowrap">
                        <thead class="bg-gray-100 border-b">
                            <tr>
                                <th class="p-3">ID</th>
                                <th class="p-3">标题</th>
                                <th class="p-3">内容预览</th>
                                <th class="p-3">优先级</th>
                                <th class="p-3">状态</th>
                                <th class="p-3">创建时间</th>
                                <th class="p-3">有效期至</th>
                                <th class="p-3">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            try {
                                $announcements = $pdo->query("SELECT * FROM announcements ORDER BY priority DESC, created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
                                foreach ($announcements as $ann):
                            ?>
                            <tr class="border-b hover:bg-gray-50">
                                <td class="p-3 font-mono text-gray-500"><?= htmlspecialchars($ann['id']) ?></td>
                                <td class="p-3 font-bold"><?= htmlspecialchars($ann['title']) ?></td>
                                <td class="p-3 text-gray-600 truncate max-w-xs"><?= htmlspecialchars(substr($ann['content'], 0, 50)) ?>...</td>
                                <td class="p-3 text-center"><span class="bg-orange-100 text-orange-700 px-2 py-1 rounded text-xs font-bold"><?= $ann['priority'] ?></span></td>
                                <td class="p-3">
                                    <span class="px-2 py-1 rounded text-xs font-bold <?= $ann['status'] === 'active' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
                                        <?= $ann['status'] === 'active' ? '📢 发布中' : '❌ 已下线' ?>
                                    </span>
                                </td>
                                <td class="p-3 text-gray-500 text-xs"><?= htmlspecialchars($ann['created_at']) ?></td>
                                <td class="p-3 text-gray-500 text-xs"><?= $ann['end_time'] ? htmlspecialchars($ann['end_time']) : '永久' ?></td>
                                <td class="p-3 space-x-2">
                                    <button type="button" onclick="editAnnouncement(<?= $ann['id'] ?>, '<?= htmlspecialchars(addslashes($ann['title'])) ?>', '<?= htmlspecialchars(addslashes($ann['content'])) ?>', <?= $ann['priority'] ?>, '<?= $ann['status'] ?>', '<?= htmlspecialchars($ann['end_time'] ?? '') ?>')" class="bg-blue-500 text-white px-3 py-1 rounded text-xs hover:bg-blue-600 transition">编辑</button>
                                    <button type="button" onclick="deleteAnnouncement(<?= $ann['id'] ?>)" class="bg-red-500 text-white px-3 py-1 rounded text-xs hover:bg-red-600 transition">删除</button>
                                </td>
                            </tr>
                            <?php
                                endforeach;
                            } catch (Exception $e) {
                                echo '<tr><td colspan="8" class="p-3 text-center text-gray-500">公告表未初始化，请刷新页面</td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
<script>
function toggleGroup(id) {
    const el = document.getElementById('group-' + id);
    el.classList.toggle('hidden');
}

        // 公告系统相关函数
        function showAnnouncementForm(isEdit = false, announcementId = null) {
            if (!isEdit) {
                document.getElementById('announcementForm').reset();
                document.querySelector('input[name="announcement_id"]').value = '';
                document.querySelector('button[type="submit"]').textContent = '创建公告';
                document.querySelector('[name="action"]').value = 'create_announcement';
            }
        }

        function editAnnouncement(id, title, content, priority, status, endTime) {
            showAnnouncementForm(true);
            document.querySelector('input[name="announcement_id"]').value = id;
            document.querySelector('input[name="announcement_title"]').value = title;
            document.querySelector('textarea[name="announcement_content"]').value = content;
            document.querySelector('input[name="announcement_priority"]').value = priority;
            document.querySelector('select[name="announcement_status"]').value = status;
            document.querySelector('input[name="announcement_end_time"]').value = endTime || '';
            document.querySelector('button[type="submit"]').textContent = '更新公告';
            document.querySelector('[name="action"]').value = 'update_announcement';
            // 滚动到表单
            document.getElementById('announcementForm').scrollIntoView({ behavior: 'smooth' });
        }

        function deleteAnnouncement(id) {
            if (confirm('确定删除该公告吗？')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_announcement">
                    <input type="hidden" name="announcement_id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
</script>
</body>
</html>