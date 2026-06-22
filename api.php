<?php
// 强制设置响应头为 JSON
header('Content-Type: application/json; charset=utf-8');

// 💡 【安全限制】：确保精确匹配跨域域名
$allowed_origins = ['https://app.hhqq.net', 'http://www.ximi.me']; 
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// ========================================================
// 安全加固 1：IP 黑名单与主动防御拦截机制
// ========================================================
$blacklistFile = __DIR__ . '/blacklist.json';
if (!file_exists($blacklistFile)) file_put_contents($blacklistFile, json_encode([]));

function get_client_ip() {
    // 采用防伪造的真实 IP 获取方式
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function ban_ip($reason) {
    global $blacklistFile;
    $ip = get_client_ip();
    $blacklist = json_decode(file_get_contents($blacklistFile), true) ?? [];
    if (!in_array($ip, $blacklist)) {
        $blacklist[] = $ip;
        file_put_contents($blacklistFile, json_encode($blacklist));
    }
    error_log("[SECURITY ALERT] IP: $ip Banned. Reason: $reason");
    echo json_encode(['code' => 403, 'msg' => 'Access Denied: 您的请求触发了系统安全防御，IP 已被拦截。']);
    exit;
}

$current_ip = get_client_ip();
$blacklist = json_decode(file_get_contents($blacklistFile), true) ?? [];
if (in_array($current_ip, $blacklist)) {
    header('HTTP/1.0 403 Forbidden');
    exit(json_encode(['code' => 403, 'msg' => 'Access Denied']));
}

// ========================================================
// 新增安全加固：严格过滤一切用于攻击的字符、危险函数与关键字
// ========================================================
function check_attack_vectors($str) {
    // 禁止任何包含 <, ?, 以及 Web 攻击常见函数/关键字的输入
    $blacklist = [
        '<', '?', 'script', 'eval', 'assert', 'base64_decode', 
        'system', 'exec', 'shell_exec', 'passthru', 'popen', 'proc_open',
        'select', 'union', 'insert', 'update', 'delete', 'drop', 'load_file', 'outfile'
    ];
    foreach ($blacklist as $bad) {
        if (stripos($str, $bad) !== false) {
            return false;
        }
    }
    return true;
}

// ========================================================
// 安全加固 2：强制后缀校验与目录异常审计
// ========================================================
function is_safe_extension($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, ['enc', 'json']);
}

function delete_dir($dir) {
    if (!is_dir($dir)) return;
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        is_dir($path) ? delete_dir($path) : @unlink($path);
    }
    @rmdir($dir);
}

function audit_directory($dir) {
    if (!is_dir($dir)) return;
    $files = scandir($dir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        if (is_file($path) && !is_safe_extension($file)) {
            delete_dir($dir); 
            ban_ip("非法文件上传被捕获: $file");
        }
    }
}

ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('error_log', __DIR__ . '/php_errors.log');

require_once __DIR__ . '/db.php';

// ========================================================
// 功能支持：全局上传开关验证
// ========================================================
function check_upload_enabled($pdo) {
    try {
        $stmt = $pdo->query("SELECT value FROM settings WHERE key='upload_enabled'");
        $res = $stmt->fetch();
        if ($res && $res['value'] == '0') {
            echo json_encode(['code' => 403, 'msg' => '上传通道已关闭']);
            exit;
        }
    } catch (PDOException $e) {
        // 若 settings 表不存在，则默认放行
    }
}

// ========================================================
try {
    $sql = 'CREATE TABLE IF NOT EXISTS messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sender_id INTEGER NOT NULL,
        receiver_id INTEGER NOT NULL,
        msg_type TEXT NOT NULL,
        encrypt_iv TEXT NOT NULL,
        encrypted_aes_key TEXT NOT NULL,
        encrypted_content TEXT NOT NULL,
        is_downloaded INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )';
    $pdo->exec($sql);
} catch (PDOException $e) {
    echo json_encode(['code' => 500, 'msg' => '初始化消息表失败: ' . $e->getMessage()]);
    exit;
}
// ========================================================
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? '';

// ==========================================
// 路由: 注册处理（加入强规则校验及 MD5 加密）
// ==========================================
if ($action === 'register') {
    $username = trim($input['username'] ?? '');
    $password = trim($input['password'] ?? '');
    $publicKey = trim($input['public_key'] ?? ''); 
    $nickname = trim($input['nickname'] ?? '');
    if (empty($nickname)) $nickname = $username; 

    if (!$username || !$password) {
        echo json_encode(['code' => 400, 'msg' => '用户名或密码不能为空']);
        exit;
    }

    // 1. 严格校验用户名：只允许数字和字母
    if (!preg_match('/^[a-zA-Z0-9]+$/', $username)) {
        echo json_encode(['code' => 400, 'msg' => '注册失败：用户名只允许包含数字和字母']);
        exit;
    }

    // 2. 严格校验密码：只允许字母、数字及英文常见标点（明确排除 '<' 和 '?'）
    if (!preg_match('/^[a-zA-Z0-9!@#\$%\^&\*\(\)_\+\-\=\[\]\{\}\|;\':",\.\/~` ]+$/', $password)) {
        echo json_encode(['code' => 400, 'msg' => '注册失败：密码包含非法字符或不被允许的标点']);
        exit;
    }

    // 3. 拦截一切攻击特征字符与恶意函数
    if (!check_attack_vectors($username) || !check_attack_vectors($password) || !check_attack_vectors($nickname)) {
        ban_ip("恶意注册尝试 (包含黑名单特殊字符或函数)");
        echo json_encode(['code' => 400, 'msg' => '提交的内容包含非法攻击字符']);
        exit;
    }

    try {
        // 4. 将密码以 MD5 形式存储到数据库中
        $md5_password = md5($password);

        $stmt = $pdo->prepare("INSERT INTO users (username, password_text, public_key, nickname) VALUES (:username, :password, :public_key, :nickname)");
        $stmt->execute([':username' => $username, ':password' => $md5_password, ':public_key' => $publicKey, ':nickname' => $nickname]);
        echo json_encode(['code' => 200, 'msg' => '注册成功']);
    } catch (PDOException $e) {
        echo json_encode(['code' => $e->getCode() == '23000' ? 400 : 500, 'msg' => $e->getCode() == '23000' ? '用户名已存在' : '服务器错误']);
    }
    exit;
}

// ==========================================
// 路由: 登录处理（加入防御检测与 MD5 比对）
// ==========================================
if ($action === 'login') {
    $username = trim($input['username'] ?? '');
    $password = trim($input['password'] ?? '');

    if (!$username || !$password) {
        echo json_encode(['code' => 400, 'msg' => '不能为空']); exit;
    }

    // 1. 登录输入防御性检查，防止 SQL 注入或特殊字符绕过
    if (!check_attack_vectors($username) || !check_attack_vectors($password)) {
        ban_ip("恶意登录尝试 (包含黑名单特殊字符或函数)");
        echo json_encode(['code' => 400, 'msg' => '提交的内容包含非法攻击字符']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT id, username, password_text, public_key, nickname FROM users WHERE username = :username");
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch();

        // 2. 将输入的明文密码计算 MD5 后与数据库进行比对
        if ($user && $user['password_text'] === md5($password)) {
            echo json_encode(['code' => 200, 'msg' => '成功', 'data' => ['id' => $user['id'], 'username' => $user['username'], 'public_key' => $user['public_key'], 'nickname' => $user['nickname']]]);
        } else {
            echo json_encode(['code' => 400, 'msg' => '用户名或密码错误']);
        }
    } catch (PDOException $e) {
        echo json_encode(['code' => 500, 'msg' => '服务器错误']);
    }
    exit;
}

if ($action === 'get_public_key') {
    $userId = intval($_GET['user_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT public_key FROM users WHERE id = :id");
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();
    if ($user) echo json_encode(['code' => 200, 'data' => ['public_key' => $user['public_key']]]);
    else echo json_encode(['code' => 404, 'msg' => '未找到该用户']);
    exit;
}

// ==========================================
// 路由: 发送消息/上传文件
// ==========================================
if ($action === 'send_message') {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($contentType, 'application/json') !== false) {
        $inputData = json_decode(file_get_contents('php://input'), true) ?? [];
    } else {
        $inputData = $_POST;
    }

    $sender_id         = intval($inputData['sender_id'] ?? 0);
    $receiver_id       = intval($inputData['receiver_id'] ?? 0);
    $msg_type          = trim($inputData['msg_type'] ?? 'text');
    $encrypt_iv        = trim($inputData['encrypt_iv'] ?? '');
    $encrypted_aes_key = trim($inputData['encrypted_aes_key'] ?? '');
    $encrypted_content = trim($inputData['encrypted_content'] ?? '');

    if (!$sender_id || !$receiver_id) {
        echo json_encode(['code' => 400, 'msg' => '参数缺失']); exit;
    }

    // 输入基本防护过滤
    if (!check_attack_vectors($encrypted_content)) {
        echo json_encode(['code' => 400, 'msg' => '内容包含非法攻击字符']); exit;
    }

    $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE id = :sender_id LIMIT 1");
    $stmtCheck->execute([':sender_id' => $sender_id]);
    if (!$stmtCheck->fetch()) {
        echo json_encode(['code' => 403, 'msg' => '用户已失效']); exit;
    }

    if ($msg_type === 'file' && isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        check_upload_enabled($pdo);
        $originalName = basename($_FILES['file']['name']);
        
        if (!is_safe_extension($originalName)) ban_ip("非法文件后缀尝试直传: $originalName");

        $uploadDir = __DIR__ . '/uploads/';
        if (!file_exists($uploadDir)) mkdir($uploadDir, 0755, true);
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $safeFileName = time() . '_' . uniqid() . ($extension ? '.' . $extension : '');
        $targetPath = $uploadDir . $safeFileName;

        if (move_uploaded_file($_FILES['file']['tmp_name'], $targetPath)) {
            audit_directory($uploadDir);
            $encrypted_content = base64_encode(urlencode($originalName) . '|uploads/' . $safeFileName); 
        } else {
            echo json_encode(['code' => 500, 'msg' => '移存失败']); exit;
        }
    }

    if (empty($encrypted_content) && $msg_type === 'text') {
        echo json_encode(['code' => 400, 'msg' => '内容为空']); exit;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, msg_type, encrypt_iv, encrypted_aes_key, encrypted_content) VALUES (:sender_id, :receiver_id, :msg_type, :encrypt_iv, :encrypted_aes_key, :encrypted_content)");
        $stmt->execute([':sender_id' => $sender_id, ':receiver_id' => $receiver_id, ':msg_type' => $msg_type, ':encrypt_iv' => $encrypt_iv, ':encrypted_aes_key' => $encrypted_aes_key, ':encrypted_content' => $encrypted_content]);
        echo json_encode(['code' => 200, 'msg' => '成功']);
    } catch (PDOException $e) {
        echo json_encode(['code' => 500, 'msg' => '数据库写入失败']);
    }
    exit;
}

if ($action === 'list_users') {
    $stmt = $pdo->query("SELECT id, username, nickname FROM users ORDER BY id ASC");
    echo json_encode(['code' => 200, 'msg' => '获取成功', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($action === 'pull_messages') {
    $userId = intval($_GET['user_id'] ?? 0);
    $pdo->prepare("DELETE FROM messages WHERE receiver_id = :user_id AND is_downloaded = 1")->execute([':user_id' => $userId]);
    $stmt = $pdo->prepare("SELECT id, sender_id, msg_type, encrypt_iv, encrypted_aes_key, encrypted_content, created_at FROM messages WHERE receiver_id = :user_id AND is_downloaded = 0");
    $stmt->execute([':user_id' => $userId]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $textMsgIds = array_column(array_filter($messages, fn($m) => $m['msg_type'] === 'text'), 'id');
    if (!empty($textMsgIds)) {
        $pdo->exec("UPDATE messages SET is_downloaded = 1 WHERE id IN (" . implode(',', $textMsgIds) . ")");
    }
    echo json_encode(['code' => 200, 'data' => $messages]); exit;
}

// ==========================================
// 路由：强制清空属于我的服务端积压列队
// ==========================================
if ($action === 'clear_server_queue') {
    $inputData = json_decode(file_get_contents('php://input'), true) ?? [];
    $userId = intval($inputData['user_id'] ?? 0);

    if (!$userId) {
        echo json_encode(['code' => 400, 'msg' => '安全校验未通过，缺少UID验证']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT encrypted_content FROM messages WHERE receiver_id = :user_id AND msg_type = 'file'");
        $stmt->execute([':user_id' => $userId]);
        $filesInfo = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($filesInfo as $info) {
            $rawData = base64_decode($info['encrypted_content']);
            $parts = explode('|', $rawData);
            
            if (count($parts) >= 2) {
                $dirOrPath = trim($parts[1]); 
                if (preg_match('/^[a-f0-9]{32}$/i', $dirOrPath)) {
                    $dir = __DIR__ . "/uploads/$dirOrPath/";
                    delete_dir($dir);
                } else if (strpos($dirOrPath, 'uploads/') === 0) {
                    // 严防路径穿越 (Path Traversal) 漏洞
                    $safeFileName = basename(substr($dirOrPath, 8)); 
                    if (!empty($safeFileName)) {
                        $fullPath = __DIR__ . '/uploads/' . $safeFileName;
                        if (file_exists($fullPath) && is_file($fullPath)) {
                            @unlink($fullPath);
                        }
                    }
                }
            }
        }

        $pdo->prepare("DELETE FROM messages WHERE receiver_id = :user_id")->execute([':user_id' => $userId]);
        echo json_encode(['code' => 200, 'msg' => '云端队列已物理清空']);
    } catch (PDOException $e) {
        echo json_encode(['code' => 500, 'msg' => '清理故障']);
    }
    exit;
}

// ==========================================
// 分片相关路由
// ==========================================
if ($action === 'upload_chunk') {
    check_upload_enabled($pdo);
    $dirId = trim($_GET['id'] ?? '');
    $chunkName = trim($_POST['chunk_name'] ?? '');

    if (!is_safe_extension($chunkName)) ban_ip("非法分片文件拦截: $chunkName");

    // dirId 严格要求为 32 位 MD5 格式，防御越权与跨目录操作
    if (empty($dirId) || !preg_match('/^[a-f0-9]{32}$/i', $dirId) || empty($chunkName) || !preg_match('/^chunk_\d+\.enc$/', $chunkName)) {
        echo json_encode(['code' => 400, 'msg' => '非法格式']); exit;
    }
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['code' => 500, 'msg' => '传输中断']); exit;
    }
    
    $uploadDir = __DIR__ . "/uploads/$dirId/";
    if (!file_exists($uploadDir)) mkdir($uploadDir, 0755, true);
    
    $targetPath = $uploadDir . $chunkName;
    if (move_uploaded_file($_FILES['file']['tmp_name'], $targetPath)) {
        audit_directory($uploadDir);
        echo json_encode(['code' => filesize($targetPath) > 0 ? 200 : 500]);
        if (filesize($targetPath) == 0) @unlink($targetPath);
    }
    exit;
}

if ($action === 'save_manifest') {
    check_upload_enabled($pdo);
    $dirId = trim($_POST['id'] ?? '');
    $manifestData = $_POST['manifest'] ?? '';
    
    if (empty($dirId) || !preg_match('/^[a-f0-9]{32}$/i', $dirId)) exit;
    
    $uploadDir = __DIR__ . "/uploads/$dirId/";
    if (!file_exists($uploadDir)) mkdir($uploadDir, 0755, true);

    file_put_contents($uploadDir . "manifest.json", $manifestData);
    audit_directory($uploadDir);

    echo json_encode(['code' => 200]); exit;
}

if ($action === 'get_manifest') {
    $dirId = $_GET['id'] ?? '';
    if (empty($dirId) || !preg_match('/^[a-f0-9]{32}$/i', $dirId)) {
        echo json_encode(['code' => 400, 'msg' => '非法目录标识']); exit;
    }
    $file = __DIR__ . "/uploads/$dirId/manifest.json";
    if (file_exists($file)) echo file_get_contents($file);
    exit;
}

if ($action === 'delete_files') {
    $dirId = trim($_GET['id'] ?? '');
    $messageId = intval($_GET['message_id'] ?? 0);
    
    if (empty($dirId) || !preg_match('/^[a-f0-9]{32}$/i', $dirId)) {
        echo json_encode(['code' => 400, 'msg' => '非法目录标识']); exit;
    }

    $dir = __DIR__ . "/uploads/$dirId/";
    if (is_dir($dir)) delete_dir($dir);
    
    if ($messageId > 0) {
        $pdo->prepare("UPDATE messages SET is_downloaded = 1 WHERE id = :id")->execute([':id' => $messageId]);
    }
    echo json_encode(['code' => 200]); exit;
}

echo json_encode(['code' => 404, 'msg' => '无对应接口']);