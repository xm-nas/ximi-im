<?php
// 安全控制：防止数据库文件被外部直接下载
$db_dir = __DIR__ . '/../data/';
$db_file = $db_dir . 'secure_im.db';

// 如果 data 目录不存在，则自动创建
if (!is_dir($db_dir)) {
    mkdir($db_dir, 0755, true);
}

try {
    // 初始化 PDO SQLite 连接
    $pdo = new PDO("sqlite:" . $db_file);
    
    // 开启异常错误模式
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // 【避坑配置】设置繁忙等待超时为 5 秒，防止 20 人并发时 SQLite 文件锁死报错
    $pdo->exec("PRAGMA busy_timeout = 5000;");
    
    // 自动初始化建表
    // 1. 用户表：存储用户名、昵称、登录凭证、以及用于端到端加密的【身份公钥】
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


// 2. 离线消息表：存储端到端加密后的密文，服务器无法解密
    $pdo->exec("CREATE TABLE IF NOT EXISTS messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sender_id INTEGER NOT NULL,
        receiver_id INTEGER NOT NULL,
        msg_type TEXT NOT NULL,       -- text, image, video, file
        encrypt_iv TEXT NOT NULL,     -- 加密初始化向量（前端解密必需）
        encrypted_aes_key TEXT NOT NULL, -- 💡 新增：用于存放非对称加密后的AES密钥
        encrypted_content TEXT NOT NULL, -- 加密后的密文，或者是包含加密头的文件URL
        is_downloaded INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );");

} catch (PDOException $e) {
    header('Content-Type: application/json', true, 500);
    echo json_encode(['error' => '数据库连接或初始化失败: ' . $e->getMessage()]);
    exit;
}
