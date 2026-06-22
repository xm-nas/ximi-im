<?php
// 强制设置响应头为 JSON
header('Content-Type: application/json; charset=utf-8');

// 💡 【安全限制】：只允许您信任的域进行跨域访问
$allowed_origins = ['https://app.hhqq.net/', 'http://www.ximi.me']; 
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
    return $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
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
    echo json_encode(['code' => 403, 'msg' => 'Access Denied: 您的 IP 已被列入黑名单，拒绝服务。']);
    exit;
;
}

$current_ip = get_client_ip();
$blacklist = json_decode(file_get_contents($blacklistFile), true) ?? [];
if (in_array($current_ip, $blacklist)) {
    header('HTTP/1.0 403 Forbidden');
    exit(json_encode(['code' => 403, 'msg' => 'Access Denied']));
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
        // 如果是文件且不是合规后缀，触发拉黑与清理
        if (is_file($path) && !is_safe_extension($file)) {
            delete_dir($dir); // 抹除受到污染的目录
            ban_ip("非法文件上传被捕获: $file");
        }
    }
}

// ========================================================

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
// 路由: 用户注册
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

    // 🔥【核心漏洞修复】：防止存储型 XSS，严禁输入包含 HTML/Script 标签
    if ($username !== strip_tags($username) || $nickname !== strip_tags($nickname)) {
        ban_ip("注册请求中包含恶意脚本或HTML标签：Username: $username, Nickname: $nickname");
        echo json_encode(['code' => 400, 'msg' => '用户名或昵称包含非法字符']);
        exit;
    }

    // 实体转义二次加固，确保存入数据库的数据绝对安全
    $username = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
    $nickname = htmlspecialchars($nickname, ENT_QUOTES, 'UTF-8');

    try {
        $stmt = $pdo->prepare("INSERT INTO users (username, password_text, public_key, nickname) VALUES (:username, :password, :public_key, :nickname)");
        $stmt->execute([
            ':username' => $username,
            ':password' => $password,
            ':public_key' => $publicKey,
            ':nickname' => $nickname
        ]);
        echo json_encode(['code' => 200, 'msg' => '注册成功']);
    } catch (PDOException $e) {
        if ($e->getCode() == '23000') {
            echo json_encode(['code' => 400, 'msg' => '用户名已存在']);
        } else {
            echo json_encode(['code' => 500, 'msg' => '服务器错误: ' . $e->getMessage()]);
        }
    }
    exit;
}

// ==========================================
// 路由: 用户登录
// ==========================================
if ($action === 'login') {
    $username = trim($input['username'] ?? '');
    $password = trim($input['password'] ?? '');

    if (!$username || !$password) {
        echo json_encode(['code' => 400, 'msg' => '用户名或密码不能为空']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT id, username, password_text, public_key, nickname FROM users WHERE username = :username");
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch();

        if ($user && $user['password_text'] === $password) {
            echo json_encode([
                'code' => 200,
                'msg' => '登录成功',
                'data' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'public_key' => $user['public_key'],
                    'nickname' => $user['nickname'] 
                ]
            ]);
        } else {
            echo json_encode(['code' => 400, 'msg' => '用户名或密码错误']);
        }
    } catch (PDOException $e) {
        echo json_encode(['code' => 500, 'msg' => '服务器错误: ' . $e->getMessage()]);
    }
    exit;
}

// ==========================================
// 路由: 获取公钥
// ==========================================
if ($action === 'get_public_key') {
    $userId = intval($_GET['user_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT id, username, public_key FROM users WHERE id = :id");
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();

    if ($user) {
        echo json_encode(['code' => 200, 'data' => ['public_key' => $user['public_key']]]);
    } else {
        echo json_encode(['code' => 404, 'msg' => '未找到该用户']);
    }
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

    // 1. 基础参数校验
    if (!$sender_id || !$receiver_id) {
        echo json_encode(['code' => 400, 'msg' => '发送者或接收者 ID 缺失']);
        exit;
    }

    // 2. 【核心修复】：后端鉴权 - 物理存在性校验
    $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE id = :sender_id LIMIT 1");
    $stmtCheck->execute([':sender_id' => $sender_id]);
    if (!$stmtCheck->fetch()) {
        echo json_encode(['code' => 403, 'msg' => '鉴权失败：用户身份已失效，请重新登录']);
        exit;
    }

    // 3. 文件处理逻辑 (直传验证)
    if ($msg_type === 'file' && isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        // 校验全局上传开关
        check_upload_enabled($pdo);

        $originalName = basename($_FILES['file']['name']);
        
        // 严格检查单文件后缀
        if (!is_safe_extension($originalName)) {
            ban_ip("非法文件后缀尝试直传: $originalName");
        }

        $uploadDir = __DIR__ . '/uploads/';
        if (!file_exists($uploadDir)) { mkdir($uploadDir, 0755, true); }
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $safeFileName = time() . '_' . uniqid() . ($extension ? '.' . $extension : '');
        $targetPath = $uploadDir . $safeFileName;

        if (move_uploaded_file($_FILES['file']['tmp_name'], $targetPath)) {
            // 操作完成立即审计直传目录
            audit_directory($uploadDir);

            $encodedName = urlencode($originalName);
            $fileSavedPath = 'uploads/' . $safeFileName;
            $encrypted_content = base64_encode($encodedName . '|' . $fileSavedPath); 
        } else {
            echo json_encode(['code' => 500, 'msg' => '文件移存失败']);
            exit;
        }
    }

    // 4. 内容校验
    if (empty($encrypted_content) && $msg_type === 'text') {
        echo json_encode(['code' => 400, 'msg' => '加密密文内容不能为空']);
        exit;
    }

    // 5. 数据入库
    try {
        $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, msg_type, encrypt_iv, encrypted_aes_key, encrypted_content) VALUES (:sender_id, :receiver_id, :msg_type, :encrypt_iv, :encrypted_aes_key, :encrypted_content)");
        $stmt->execute([
            ':sender_id'         => $sender_id,
            ':receiver_id'       => $receiver_id,
            ':msg_type'          => $msg_type,
            ':encrypt_iv'        => $encrypt_iv,
            ':encrypted_aes_key' => $encrypted_aes_key, 
            ':encrypted_content' => $encrypted_content
        ]);
        echo json_encode(['code' => 200, 'msg' => '消息成功写入队列']);
    } catch (PDOException $e) {
        echo json_encode(['code' => 500, 'msg' => '数据库写入失败: ' . $e->getMessage()]);
    }
    exit;
}

// ==========================================
// 路由: 获取用户列表 
// ==========================================
if ($action === 'list_users') {
    try {
        $stmt = $pdo->prepare("SELECT id, username, nickname FROM users ORDER BY id ASC");
        $stmt->execute();
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['code' => 200, 'msg' => '获取成功', 'data' => $users]);
    } catch (PDOException $e) {
        echo json_encode(['code' => 500, 'msg' => '查询失败: ' . $e->getMessage()]);
    }
    exit;
}

// =========================================
// 路由: 拉取离线消息
// =========================================
if ($action === 'pull_messages') {
    $userId = intval($_GET['user_id'] ?? 0);

    $pdo->prepare("DELETE FROM messages WHERE receiver_id = :user_id AND is_downloaded = 1")
        ->execute([':user_id' => $userId]);

    $stmt = $pdo->prepare("SELECT id, sender_id, msg_type, encrypt_iv, encrypted_aes_key, encrypted_content, created_at 
                           FROM messages WHERE receiver_id = :user_id AND is_downloaded = 0");
    $stmt->execute([':user_id' => $userId]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $textMsgIds = [];
    foreach ($messages as $msg) {
        if ($msg['msg_type'] === 'text') $textMsgIds[] = $msg['id'];
    }

    if (!empty($textMsgIds)) {
        $idList = implode(',', $textMsgIds);
        $pdo->exec("UPDATE messages SET is_downloaded = 1 WHERE id IN ($idList)");
    }

    echo json_encode(['code' => 200, 'data' => $messages]);
    exit;
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
                    $fullPath = __DIR__ . '/' . $dirOrPath;
                    if (file_exists($fullPath) && is_file($fullPath)) {
                        @unlink($fullPath);
                    }
                }
            }
        }

        $delStmt = $pdo->prepare("DELETE FROM messages WHERE receiver_id = :user_id");
        $delStmt->execute([':user_id' => $userId]);

        echo json_encode(['code' => 200, 'msg' => '云端队列已物理清空']);
    } catch (PDOException $e) {
        echo json_encode(['code' => 500, 'msg' => '服务端清理故障: ' . $e->getMessage()]);
    }
    exit;
}

// ==========================================
// 分片相关路由 (引入上传开关与安全审计)
// ==========================================
if ($action === 'upload_chunk') {
    // 校验全局上传开关
    check_upload_enabled($pdo);

    $dirId = trim($_GET['id'] ?? '');
    $chunkName = trim($_POST['chunk_name'] ?? '');

    // 严厉校验：所有分片切块也必须合规
    if (!is_safe_extension($chunkName)) {
        ban_ip("非法分片文件拦截: $chunkName");
    }

    if (empty($dirId) || empty($chunkName) || !preg_match('/^[a-f0-9]{32}$/i', $dirId) || !preg_match('/^chunk_\d+\.enc$/', $chunkName)) {
        echo json_encode(['code' => 400, 'msg' => '非法格式']); exit;
    }
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['code' => 500, 'msg' => '传输中断']); exit;
    }
    $uploadDir = __DIR__ . "/uploads/$dirId/";
    if (!file_exists($uploadDir)) mkdir($uploadDir, 0755, true);
    
    $targetPath = $uploadDir . $chunkName;
    if (move_uploaded_file($_FILES['file']['tmp_name'], $targetPath)) {
        // 完成移动后自动审计分片文件夹
        audit_directory($uploadDir);

        if (file_exists($targetPath) && filesize($targetPath) > 0) {
            echo json_encode(['code' => 200]);
        } else { 
            @unlink($targetPath); 
            echo json_encode(['code' => 500]); 
        }
    }
    exit;
}

if ($action === 'save_manifest') {
    // 校验全局上传开关
    check_upload_enabled($pdo);

    $dirId = trim($_POST['id'] ?? '');
    $manifestData = $_POST['manifest'] ?? '';
    if (empty($dirId) || !preg_match('/^[a-f0-9]{32}$/i', $dirId)) exit;
    
    $uploadDir = __DIR__ . "/uploads/$dirId/";
    if (!file_exists($uploadDir)) mkdir($uploadDir, 0755, true);

    file_put_contents($uploadDir . "manifest.json", $manifestData);
    
    // 生成 manifest 后立即审计
    audit_directory($uploadDir);

    echo json_encode(['code' => 200]);
    exit;
}

if ($action === 'get_manifest') {
    $dirId = $_GET['id'] ?? '';
    $file = __DIR__ . "/uploads/$dirId/manifest.json";
    if (file_exists($file)) echo file_get_contents($file);
    exit;
}

if ($action === 'delete_files') {
    $dirId = trim($_GET['id'] ?? '');
    $messageId = intval($_GET['message_id'] ?? 0);
    $dir = __DIR__ . "/uploads/$dirId/";
    if (is_dir($dir)) {
        delete_dir($dir);
    }
    if ($messageId > 0) {
        $pdo->prepare("UPDATE messages SET is_downloaded = 1 WHERE id = :id")->execute([':id' => $messageId]);
    }
    echo json_encode(['code' => 200]);
    exit;
}

echo json_encode(['code' => 404, 'msg' => '无对应接口']);
