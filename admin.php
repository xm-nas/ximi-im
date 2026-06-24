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

$db_dir = __DIR__ . '/' . $config['db_dir'] . '/';
if (!is_dir($db_dir)) mkdir($db_dir, 0755, true);

$admin_db_file = $db_dir . $config['admin_db_name'];

// ========================================================
// 2. 连接管理员独立数据库
// ========================================================
try {
    $admin_pdo = new PDO("sqlite:" . $admin_db_file);
    $admin_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $admin_pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // 建表：包含 登录密钥、最近5次IP、账号状态
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
// 3. 后台首次运行：初始化密码 (Hash安全加密)
// ========================================================
if (!$admin_data) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['init_admin'])) {
        $pwd = trim($_POST['admin_password']);
        if (!empty($pwd)) {
            $hash = password_hash($pwd, PASSWORD_DEFAULT);
            $admin_pdo->prepare("INSERT INTO admin_user (login_key, last_ips, account_status) VALUES (?, ?, 1)")->execute([$hash, '[]']);
            header('Location: admin.php');
            exit;
        }
    }
echo '<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="https://cdn.tailwindcss.com"></script>
<title>初始化管理密码</title>

<style>
body{
    font-family:-apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",sans-serif;
    background:#f5f7fb;
}
.card{
    background:#fff;
    border:1px solid #e6e8ee;
    border-radius:14px;
}
.soft{
    box-shadow:0 10px 30px rgba(18,38,63,.05);
}
.btn{
    background:#07c160;
}
.btn:hover{
    background:#06ad56;
}
</style>

</head>

<body class="flex items-center justify-center min-h-screen px-4">

<div class="w-full max-w-md">

    <!-- 顶部品牌 -->
    <div class="text-center mb-6">
        <div class="text-2xl font-semibold text-gray-900">ximi IM</div>
        <div class="text-xs text-gray-500 mt-1">初始化安全中心 · 管理员配置</div>
    </div>

    <!-- 主卡片 -->
    <div class="card soft p-6">

        <h2 class="text-lg font-semibold text-gray-800 text-center">
            ⚙️ 初始化超级管理员
        </h2>

        <p class="text-xs text-gray-500 text-center mt-2 leading-relaxed">
            系统首次运行检测到未配置管理员账号<br>
            请设置<strong class="text-gray-700">高强度密码</strong>用于后台登录
        </p>

        <!-- 安全提示 -->
        <div class="mt-4 bg-gray-50 border border-gray-100 rounded-lg p-3 text-xs text-gray-500 leading-relaxed">
            ✔ 密码采用单向 Hash 加密存储<br>
            ✔ 系统不会保存明文密码<br>
            ✔ 建议使用 ≥ 8 位包含字母+数字组合
        </div>

        <form method="POST" class="mt-5 space-y-4">

            <input type="hidden" name="init_admin" value="1">

            <div>
                <label class="text-xs text-gray-500">管理员密码</label>
                <input type="password"
                       name="admin_password"
                       placeholder="请输入安全密码"
                       required
                       class="w-full mt-1 border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-green-500">
            </div>

            <button class="w-full py-2.5 rounded-lg text-white text-sm font-medium btn transition">
                保存并完成初始化
            </button>

        </form>

    </div>

    <!-- 底部说明 -->
    <div class="text-center text-xs text-gray-400 mt-5">
        Secure Setup Wizard · ximi IM System
    </div>

</div>

</body>
</html>';
exit;
}

// ========================================================
// 4. 后台登录校验与 IP 追踪
// ========================================================
if (isset($_GET['logout'])) { session_destroy(); header('Location: admin.php'); exit; }

if (isset($_POST['action']) && $_POST['action'] === 'login') {
    if ($admin_data['account_status'] != 1) {
        $error = "该超管账号已被封禁阻断！";
    } elseif (password_verify($_POST['password'], $admin_data['login_key'])) {
        $_SESSION['admin_logged_in'] = true;
        
        // 追踪并保存最近 5 次的不重复登录 IP
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $ips = json_decode($admin_data['last_ips'] ?: '[]', true);
        array_unshift($ips, $ip); // 插入到开头
        $ips = array_slice(array_unique($ips), 0, 5); // 去重并截取前5个
        $admin_pdo->prepare("UPDATE admin_user SET last_ips = ? WHERE id = ?")->execute([json_encode($ips), $admin_data['id']]);
        
        header('Location: admin.php');
        exit;
    } else {
        $error = "安全校验失败：密码错误";
    }
}

if (!isset($_SESSION['admin_logged_in'])) {
  echo '<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="https://cdn.tailwindcss.com"></script>
<title>管理登录</title>

<style>
body{
    font-family:-apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",sans-serif;
    background:#f5f7fb;
}
.card{
    background:#fff;
    border:1px solid #e6e8ee;
    border-radius:14px;
}
.soft{
    box-shadow:0 10px 30px rgba(18,38,63,.05);
}
.btn{
    background:#07c160;
}
.btn:hover{
    background:#06ad56;
}
</style>

</head>

<body class="flex items-center justify-center min-h-screen px-4">

<div class="w-full max-w-sm">

    <!-- 品牌 -->
    <div class="text-center mb-6">
        <div class="text-2xl font-semibold text-gray-900">ximi IM</div>
        <div class="text-xs text-gray-500 mt-1">System Operation Center</div>
    </div>

    <!-- 登录卡片 -->
    <div class="card soft p-6">

        <h2 class="text-lg font-semibold text-center text-gray-800">
            系统运维中心
        </h2>

        <p class="text-xs text-gray-500 text-center mt-2">
            请输入管理员凭证以进入控制台
        </p>

        ' . (isset($error) ? '
        <div class="mt-4 bg-red-50 border border-red-200 text-red-600 text-xs p-2 rounded text-center">
            ' . $error . '
        </div>' : '') . '

        <form method="POST" class="mt-5 space-y-4">

            <input type="hidden" name="action" value="login">

            <div>
                <label class="text-xs text-gray-500">管理密码</label>
                <input type="password"
                       name="password"
                       placeholder="请输入安全密码"
                       class="w-full mt-1 border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-green-500">
            </div>

            <button class="w-full py-2.5 rounded-lg text-white text-sm font-medium btn transition">
                安全登录
            </button>

        </form>

    </div>

    <!-- 底部 -->
    <div class="text-center text-xs text-gray-400 mt-5">
        Secure Console Access · ximi IM
    </div>

</div>

</body>
</html>';
exit;
}

// 重新获取包含最新登录 IP 的管理员数据
$admin_data = $admin_pdo->query("SELECT * FROM admin_user LIMIT 1")->fetch();

// ========================================================
// 5. 引入主数据库执行业务运维 (继承原有逻辑)
// ========================================================
require_once __DIR__ . '/db.php'; 

// 初始化设置表 (保证上传开关可用)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (`key` TEXT PRIMARY KEY, `value` TEXT)");
    $stmt = $pdo->query("SELECT value FROM settings WHERE key='upload_enabled'");
    $upload_setting = $stmt->fetchColumn();
    if ($upload_setting === false) {
        $pdo->exec("INSERT INTO settings (`key`, `value`) VALUES ('upload_enabled', '1')");
        $upload_setting = '1';
    }
} catch (PDOException $e) {
    die("数据库配置异常: " . $e->getMessage());
}

// 6. 数据表单提交逻辑处理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if (isset($_POST['save_settings'])) {
        $new_val = $_POST['upload_enabled'] === '1' ? '1' : '0';
        $pdo->prepare("UPDATE settings SET value=? WHERE key='upload_enabled'")->execute([$new_val]);
    }

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

        if (strlen($password) !== 32 && !empty($password)) {
            $password = md5($password);
        }

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
    <title>系统运维管理后台</title>
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

        <div class="mb-6 p-4 bg-blue-50 border border-blue-100 rounded-lg flex flex-col md:flex-row justify-between items-center gap-4">
            <div>
                <h2 class="font-bold text-lg text-blue-900">全局系统设置</h2>
                <p class="text-sm text-blue-700">可在此处紧急关闭所有接口的文件上传通道，切断物理传输链路。</p>
            </div>
            <form method="POST" class="flex items-center gap-3">
                <select name="upload_enabled" class="border border-gray-300 p-2 rounded focus:outline-none focus:ring-2 focus:ring-blue-500 font-bold text-gray-700 bg-white">
                    <option value="1" <?= $upload_setting == '1' ? 'selected' : '' ?>>✅ 允许上传 (通道开启)</option>
                    <option value="0" <?= $upload_setting == '0' ? 'selected' : '' ?>>❌ 禁止上传 (紧急熔断)</option>
                </select>
                <button name="save_settings" class="bg-blue-600 text-white px-5 py-2 rounded shadow hover:bg-blue-700 transition">保存配置</button>
            </form>
        </div>

        <div class="flex border-b mb-6">
            <button onclick="tab('users')" id="btn-users" class="px-6 py-2 border-b-2 border-blue-600 font-bold text-blue-600 transition">用户管理</button>
            <button onclick="tab('msgs')" id="btn-msgs" class="px-6 py-2 text-gray-500 hover:text-blue-600 transition">消息列队</button>
        </div>

        <div id="tab-users">
            <form method="POST">
                <div class="mb-4">
                    <button name="batch_delete_users" onclick="return confirm('警告：确定删除选中的用户及其所有关联消息吗？此操作不可逆！')" class="bg-red-500 text-white px-4 py-2 rounded text-sm hover:bg-red-600 shadow transition">🗑️ 删除勾选用户</button>
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
                                
                                <td class="p-3">
                                    <span class="px-2 py-1 rounded text-xs font-bold <?= $m['msg_type'] == 'file' ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700' ?>">
                                        <?=htmlspecialchars(strtoupper($m['msg_type']), ENT_QUOTES, 'UTF-8')?>
                                    </span>
                                </td>
                                
                                <td class="p-3 font-bold text-blue-700"><?=htmlspecialchars($user_map[$m['sender_id']] ?? "UID:{$m['sender_id']}", ENT_QUOTES, 'UTF-8')?></td>
                                <td class="p-3 font-bold text-green-700"><?=htmlspecialchars($user_map[$m['receiver_id']] ?? "UID:{$m['receiver_id']}", ENT_QUOTES, 'UTF-8')?></td>
                                
                                <td class="p-3">
                                    <?= $m['is_downloaded'] ? '<span class="text-green-500 font-bold text-xs">已接收</span>' : '<span class="text-orange-500 font-bold text-xs">列队中...</span>' ?>
                                </td>
                                
                                <td class="p-3 text-xs text-gray-500"><?=htmlspecialchars($m['created_at'], ENT_QUOTES, 'UTF-8')?></td>
                                
                                <td class="p-3 text-gray-400 font-mono text-xs truncate max-w-xs" title="<?=htmlspecialchars($m['encrypted_content'], ENT_QUOTES, 'UTF-8')?>">
                                    <?=htmlspecialchars(substr($m['encrypted_content'], 0, 45), ENT_QUOTES, 'UTF-8')?>...
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        </div>
    </div>

    <script>
        function tab(name) {
            document.getElementById('tab-users').classList.toggle('hidden', name !== 'users');
            document.getElementById('tab-msgs').classList.toggle('hidden', name !== 'msgs');
            
            const btnUsers = document.getElementById('btn-users');
            const btnMsgs = document.getElementById('btn-msgs');
            
            if (name === 'users') {
                btnUsers.className = 'px-6 py-2 border-b-2 border-blue-600 font-bold text-blue-600 transition';
                btnMsgs.className = 'px-6 py-2 text-gray-500 hover:text-blue-600 transition';
            } else {
                btnMsgs.className = 'px-6 py-2 border-b-2 border-blue-600 font-bold text-blue-600 transition';
                btnUsers.className = 'px-6 py-2 text-gray-500 hover:text-blue-600 transition';
            }
        }
        
        function selectAll(source, name) {
            document.querySelectorAll(`input[name="${name}"]`).forEach(cb => cb.checked = source.checked);
        }
    </script>
</body>
</html>