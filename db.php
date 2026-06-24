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

// 如果指定的数据存放目录不存在，则自动创建
if (!is_dir($db_dir)) {
    mkdir($db_dir, 0755, true);
}

try {
    // 初始化 PDO SQLite 连接
    $pdo = new PDO("sqlite:" . $db_file);
    
    // 开启异常错误模式
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // 【避坑配置】设置繁忙等待超时为 5 秒，防止并发时 SQLite 文件锁死报错
    $pdo->exec("PRAGMA busy_timeout = 5000;");
    
    // 自动初始化建表
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password_text TEXT NOT NULL,
        public_key TEXT,
        nickname TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_ip TEXT,
        stop_user INTEGER DEFAULT 0
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sender_id INTEGER NOT NULL,
        receiver_id INTEGER NOT NULL,
        msg_type TEXT NOT NULL,       
        encrypt_iv TEXT NOT NULL,     
        encrypted_aes_key TEXT NOT NULL, 
        encrypted_content TEXT NOT NULL, 
        is_downloaded INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );");

} catch (PDOException $e) {
    header('Content-Type: application/json', true, 500);
    echo json_encode(['error' => '数据库连接或初始化失败: ' . $e->getMessage()]);
    exit;
}