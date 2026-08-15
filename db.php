<?php
// 安全控制：如果尚未安装，则阻断运行
if (!file_exists(__DIR__ . '/setting.php')) {
    header('Content-Type: application/json', true, 500);
    echo json_encode(['error' => '系统未安装或缺失 setting.php 配置文件']);
    exit;
}

// 载入配置
$config = require __DIR__ . '/setting.php';
$db_dir = __DIR__ . '/' . $config['db_dir'] . '/';
$db_file = $db_dir . $config['db_name'];

if (!is_dir($db_dir)) {
    mkdir($db_dir, 0755, true);
}

try {
    // =========================
    // SQLite 初始化
    // =========================
    $pdo = new PDO("sqlite:" . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("PRAGMA busy_timeout = 5000;");

    // =========================
    // 1. 用户表
    // =========================
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password_text TEXT NOT NULL,
        public_key TEXT,
        nickname TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_ip TEXT,
        stop_user INTEGER DEFAULT 0
    )");

    // =========================
    // 2. 消息表（群聊+单聊统一）
    // =========================
    $pdo->exec("CREATE TABLE IF NOT EXISTS messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,

        uuid TEXT UNIQUE,  -- 防重复消息（强烈建议使用）

        sender_id INTEGER NOT NULL,

        receiver_id INTEGER DEFAULT 0,  -- 私聊
        group_id INTEGER DEFAULT 0,     -- 群聊

        msg_type TEXT NOT NULL,

        encrypt_iv TEXT NOT NULL,
        encrypted_aes_key TEXT NOT NULL,
        encrypted_content TEXT NOT NULL,

        is_read INTEGER DEFAULT 0,
        is_deleted INTEGER DEFAULT 0,

        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // =========================
    // 3. 群聊表
    // =========================
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_groups (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name VARCHAR(50) NOT NULL,
        creator_id INTEGER NOT NULL,
        room_password TEXT DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // =========================
    // 4. 群成员表（支持角色/权限）
    // =========================
    $pdo->exec("CREATE TABLE IF NOT EXISTS group_members (
        id INTEGER PRIMARY KEY AUTOINCREMENT,

        group_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,

        role TEXT DEFAULT 'member',   -- owner / admin / member
        mute INTEGER DEFAULT 0,       -- 是否禁言

        joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,

        UNIQUE(group_id, user_id)
    )");

    // =========================
    // 5. 消息已读表（群聊/私聊扩展）
    // =========================
    $pdo->exec("CREATE TABLE IF NOT EXISTS message_reads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        message_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        read_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(message_id, user_id)
    )");

    // =========================
    // 6. 群操作日志（审计用）
    // =========================
    $pdo->exec("CREATE TABLE IF NOT EXISTS group_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        group_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        action TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // =========================
    // 7. 公告系统表
    // =========================
    $pdo->exec("CREATE TABLE IF NOT EXISTS announcements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        content TEXT NOT NULL,
        creator_id INTEGER NOT NULL,
        status TEXT DEFAULT 'active',  -- active / inactive
        priority INTEGER DEFAULT 0,    -- 优先级（越大越靠前）
        start_time DATETIME DEFAULT CURRENT_TIMESTAMP,
        end_time DATETIME,             -- 公告有效期，NULL表示永久
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // =========================
    // 8. 公告已读记录表
    // =========================
    $pdo->exec("CREATE TABLE IF NOT EXISTS announcement_reads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        announcement_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        read_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(announcement_id, user_id)
    )");

    // =========================
    // 9. 安全升级：自动补字段（兼容旧版本）
    // =========================
    $columns = $pdo->query("PRAGMA table_info(messages)")->fetchAll(PDO::FETCH_COLUMN, 1);

    if (!in_array('group_id', $columns)) {
        $pdo->exec("ALTER TABLE messages ADD COLUMN group_id INTEGER DEFAULT 0");
    }

    if (!in_array('uuid', $columns)) {
        $pdo->exec("ALTER TABLE messages ADD COLUMN uuid TEXT");
    }

} catch (PDOException $e) {
    die("数据库初始化失败: " . $e->getMessage());
}
?>