<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
// 强制设置响应头为 JSON
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Shanghai');

// 💡 【安全限制】：只允许您信任的域进行跨域访问
$allowed_origins = ['https://app.hhqq.net', 'http://www.ximi.me']; 
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

// ========================================================
// 核心模块 1：全局黑名单拦截 (Req 7)
// ========================================================
$blacklistFile = __DIR__ . '/blacklist.json';
$failedAttemptsFile = __DIR__ . '/failed_attempts.json';
if (!file_exists($blacklistFile)) file_put_contents($blacklistFile, json_encode([]));

function get_client_ip() {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

$current_ip = get_client_ip();
$blacklist = json_decode(file_get_contents($blacklistFile), true) ?? [];
if (in_array($current_ip, $blacklist)) {
    header('HTTP/1.0 403 Forbidden');
    exit(json_encode(['code' => 403, 'msg' => 'Access Denied: IP Banned']));
}

function ban_ip($reason) {
    global $blacklistFile, $current_ip;
    $blacklist = json_decode(file_get_contents($blacklistFile), true) ?? [];
    if (!in_array($current_ip, $blacklist)) {
        $blacklist[] = $current_ip;
        file_put_contents($blacklistFile, json_encode($blacklist));
    }
    error_log("[SECURITY ALERT] IP: $current_ip Banned. Reason: $reason");
    echo json_encode(['code' => 403, 'msg' => '严重违规：您的请求触发了防御机制，IP 已被永久拦截。']);
    exit;
}

// ========================================================
// 核心模块 2：非法攻击计数与熔断 (Req 3)
// ========================================================
function record_failed_attempt() {
    global $failedAttemptsFile, $current_ip;
    $attempts = file_exists($failedAttemptsFile) ? json_decode(file_get_contents($failedAttemptsFile), true) : [];
    $attempts[$current_ip] = ($attempts[$current_ip] ?? 0) + 1;
    file_put_contents($failedAttemptsFile, json_encode($attempts));
    
    if ($attempts[$current_ip] >= 10) {
        ban_ip("非法字符尝试攻击超过10次");
    }
}

function validate_input_or_fail($str, $regex) {
    if (!preg_match($regex, $str)) {
        record_failed_attempt();
        echo json_encode(['code' => 400, 'msg' => '输入包含非法字符或长度不符，,请求被拦截, 至少4位用户名,6位密码']);
        exit;
    }
    return true;
}

// ========================================================
// 核心模块 3：数据库初始化与连接 (Req 1, 9, 10)
// ========================================================
require_once __DIR__ . '/db.php'; // 需确保 db.php 中正确实例化了 $pdo

// 请将此段代码放在 api.php 中 $pdo 连接定义之后（通常在 db.php 引入之后）
function check_upload_enabled() {
    global $pdo; // 关键：声明使用全局的 $pdo 对象
    try {
        $stmt = $pdo->query("SELECT value FROM settings WHERE key='upload_enabled'");
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($res && $res['value'] == '0') {
            // 返回 JSON 并立即停止执行
            echo json_encode(['code' => 403, 'msg' => '系统全局文件上传通道已关闭！']);
            exit;
        }
    } catch (Exception $e) {
        // 如果表不存在，默认视为开启（防止报错）
        return;
    }
}



try {
    // Req 1 & 9: 自动创建新结构的用户表
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
    
    // Req 10: 保持 messages 表不变
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
    )");
} catch (PDOException $e) {
    exit(json_encode(['code' => 500, 'msg' => '初始化数据表失败']));
}

// ========================================================
// 核心模块 4：IP与账号状态鉴权体系 (Req 4, 7)
// ========================================================
function verify_user_auth($pdo, $userId) {
    global $current_ip;
    if (!$userId) {
        exit(json_encode(['code' => 400, 'msg' => '鉴权失败：缺少UID']));
    }
    $stmt = $pdo->prepare("SELECT last_ip, stop_user FROM users WHERE id = :id");
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();
    
    if (!$user) exit(json_encode(['code' => 404, 'msg' => '用户不存在']));
    if ($user['stop_user'] == 1) exit(json_encode(['code' => 403, 'msg' => '账号已被封禁']));
    if ($user['last_ip'] !== $current_ip) exit(json_encode(['code' => 403, 'msg' => '登录 IP 不匹配，拒绝执行越权操作']));
}

// ========================================================
// 核心模块 5：底层物理防御沙盒 (Req 5, 6)
// ========================================================
define('UPLOADS_DIR', __DIR__ . '/uploads');
if (!file_exists(UPLOADS_DIR)) mkdir(UPLOADS_DIR, 0755, true);

function enforce_sandbox($pdo, $userId, $target_path) {
    global $current_ip;
    $base_dir = realpath(UPLOADS_DIR);
    $real_target = realpath($target_path);

    // 如果路径存在，并且其物理绝对路径不是以 uploads 目录开头 -> 绝对是越权/跳跃目录攻击
    if ($real_target !== false && strpos($real_target, $base_dir) !== 0) {
        
        // 1. 禁用账号
        if ($userId) {
            $pdo->prepare("UPDATE users SET stop_user = 1 WHERE id = ?")->execute([$userId]);
        }
        
        // 2. 记录错误日志 (ero.json)
        $logFile = __DIR__ . '/ero.json';
        $logData = file_exists($logFile) ? json_decode(file_get_contents($logFile), true) : [];
        $logData[] = [
            'time' => date('Y-m-d H:i:s'),
            'user_id' => $userId ?? 'unknown',
            'ip' => $current_ip,
            'attempted_path' => $target_path,
            'resolved_path' => $real_target
        ];
        file_put_contents($logFile, json_encode($logData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        
        // 3. 封禁IP并熔断
        ban_ip("检测到致命的越权文件操作尝试");
    }
}

// 删除文件夹工具（带沙盒防御）
function delete_dir_safe($pdo, $userId, $dir) {
    enforce_sandbox($pdo, $userId, $dir);
    if (!is_dir($dir)) return;
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        is_dir($path) ? delete_dir_safe($pdo, $userId, $path) : @unlink($path);
    }
    @rmdir($dir);
}

// ========================================================
// 路由处理入口
// ========================================================
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? '';

// ------------------------------------------
// 1. 用户注册 (Req 1, 2, 3)
// ------------------------------------------
if ($action === 'register') {
    $username = trim($input['username'] ?? '');
    $password = trim($input['password'] ?? '');
    $publicKey = trim($input['public_key'] ?? ''); 
    $nickname = trim($input['nickname'] ?? '');
    if (empty($nickname)) $nickname = $username; 

    // Req 2 & 3: 严格正则验证
    // 用户名：只允许英文、数字，4-20位
    validate_input_or_fail($username, '/^[a-zA-Z0-9]{4,20}$/');
    // 密码：允许大小写字母、数字及常见安全标点，不允许 < > ? 等
    validate_input_or_fail($password, '/^[a-zA-Z0-9!@#\$%\^&\*\(\)_\+\-\=\[\]\{\}\|;\':",\.\/~` ]{6,64}$/');
    // 昵称：中文、英文、数字、下划线，3-16位
    validate_input_or_fail($nickname, '/^[\x{4e00}-\x{9fa5}a-zA-Z0-9_]{3,16}$/u');

    try {
        $md5_password = md5($password);
        $stmt = $pdo->prepare("INSERT INTO users (username, password_text, public_key, nickname, last_ip, stop_user) VALUES (:username, :password, :public_key, :nickname, :last_ip, 0)");
        $stmt->execute([
            ':username' => $username,
            ':password' => $md5_password,
            ':public_key' => $publicKey,
            ':nickname' => $nickname,
            ':last_ip' => $current_ip
        ]);
        echo json_encode(['code' => 200, 'msg' => '注册成功']);
    } catch (PDOException $e) {
        echo json_encode(['code' => $e->getCode() == '23000' ? 400 : 500, 'msg' => $e->getCode() == '23000' ? '用户名已存在' : '注册失败']);
    }
    exit;
}

// ------------------------------------------
// 2. 用户登录 (Req 1, 7)
// ------------------------------------------
if ($action === 'login') {
    $username = trim($input['username'] ?? '');
    $password = trim($input['password'] ?? '');

    // 防爆破：同样对登录输入进行合法性校验
    validate_input_or_fail($username, '/^[a-zA-Z0-9]{4,20}$/');

    $stmt = $pdo->prepare("SELECT id, username, password_text, public_key, nickname, stop_user FROM users WHERE username = :username");
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch();

    if ($user) {
        if ($user['stop_user'] == 1) {
            exit(json_encode(['code' => 403, 'msg' => '该账号因严重违规已被管理员或系统封禁']));
        }
        if ($user['password_text'] === md5($password)) {
            // 更新最后登录IP
            $pdo->prepare("UPDATE users SET last_ip = :ip WHERE id = :id")->execute([':ip' => $current_ip, ':id' => $user['id']]);
            echo json_encode([
                'code' => 200,
                'msg' => '登录成功',
                'data' => ['id' => $user['id'], 'username' => $user['username'], 'public_key' => $user['public_key'], 'nickname' => $user['nickname']]
            ]);
            exit;
        }
    }
    
    // 登录失败增加错误记录
    record_failed_attempt();
    echo json_encode(['code' => 400, 'msg' => '用户名或密码错误']);
    exit;
}

// ------------------------------------------
// 3. 获取公钥
// ------------------------------------------
if ($action === 'get_public_key') {
    $userId = intval($_GET['user_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT public_key FROM users WHERE id = :id");
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();
    if ($user) echo json_encode(['code' => 200, 'data' => ['public_key' => $user['public_key']]]);
    else echo json_encode(['code' => 404, 'msg' => '未找到该用户']);
    exit;
}

// ------------------------------------------
// 4. 发送消息/上传单文件 (Req 4)
// ------------------------------------------
if ($action === 'send_message') {
    $inputData = (strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) ? $input : $_POST;
    
    $sender_id = intval($inputData['sender_id'] ?? 0);
    $receiver_id = intval($inputData['receiver_id'] ?? 0);
    $msg_type = trim($inputData['msg_type'] ?? 'text');
    $encrypted_content = trim($inputData['encrypted_content'] ?? '');

    verify_user_auth($pdo, $sender_id);

if ($msg_type === 'file') { check_upload_enabled();   }

    if ($msg_type === 'file' && isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $originalName = basename($_FILES['file']['name']);
        if (!in_array(strtolower(pathinfo($originalName, PATHINFO_EXTENSION)), ['enc', 'json'])) {
            ban_ip("尝试上传非法格式后缀文件");
        }

        $safeFileName = time() . '_' . uniqid() . '.enc';
        $targetPath = UPLOADS_DIR . '/' . $safeFileName;

        if (move_uploaded_file($_FILES['file']['tmp_name'], $targetPath)) {
            $encrypted_content = base64_encode(urlencode($originalName) . '|uploads/' . $safeFileName); 
        } else {
            exit(json_encode(['code' => 500, 'msg' => '文件保存失败']));
        }
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, msg_type, encrypt_iv, encrypted_aes_key, encrypted_content) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$sender_id, $receiver_id, $msg_type, $inputData['encrypt_iv'] ?? '', $inputData['encrypted_aes_key'] ?? '', $encrypted_content]);
        echo json_encode(['code' => 200, 'msg' => '发送成功']);
    } catch (PDOException $e) {
        echo json_encode(['code' => 500, 'msg' => '写入失败']);
    }
    exit;
}

// ------------------------------------------
// 5. 拉取及列表路由
// ------------------------------------------
if ($action === 'list_users') {
    $stmt = $pdo->query("SELECT id, username, nickname FROM users ORDER BY id ASC");
    echo json_encode(['code' => 200, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($action === 'pull_messages') {
    $userId = intval($_GET['user_id'] ?? 0);
    verify_user_auth($pdo, $userId);

    $pdo->prepare("DELETE FROM messages WHERE receiver_id = ? AND is_downloaded = 1")->execute([$userId]);
    $stmt = $pdo->prepare("SELECT * FROM messages WHERE receiver_id = ? AND is_downloaded = 0");
    $stmt->execute([$userId]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $textIds = array_column(array_filter($messages, fn($m) => $m['msg_type'] === 'text'), 'id');
    if ($textIds) $pdo->exec("UPDATE messages SET is_downloaded = 1 WHERE id IN (" . implode(',', $textIds) . ")");

    echo json_encode(['code' => 200, 'data' => $messages]); exit;
}

// ------------------------------------------
// 6. 清除列队及关联文件 (Req 4, 5, 6)
// ------------------------------------------
if ($action === 'clear_server_queue') {
    $userId = intval($input['user_id'] ?? 0);
    verify_user_auth($pdo, $userId);

    $stmt = $pdo->prepare("SELECT encrypted_content FROM messages WHERE receiver_id = ? AND msg_type = 'file'");
    $stmt->execute([$userId]);
    
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $info) {
        $parts = explode('|', base64_decode($info['encrypted_content']));
        if (count($parts) >= 2) {
            $pathStr = trim($parts[1]);
            
            // 安全沙盒检测：如果是完整的文件夹（分片），或者是单个文件
            if (preg_match('/^[a-f0-9]{32}$/i', $pathStr)) {
                $targetDir = UPLOADS_DIR . '/' . $pathStr;


                delete_dir_safe($pdo, $userId, $targetDir);
            } else if (strpos($pathStr, 'uploads/') === 0) {
                // 防止传入 uploads/../../index.php
                $targetFile = __DIR__ . '/' . $pathStr;
                enforce_sandbox($pdo, $userId, $targetFile);
                if (file_exists($targetFile)) @unlink($targetFile);
            }
        }
    }
    $pdo->prepare("DELETE FROM messages WHERE receiver_id = ?")->execute([$userId]);
    echo json_encode(['code' => 200, 'msg' => '队列及相关文件已安全清空']);
    exit;
}

// ------------------------------------------
// 7. 分片上传与清单管理
// ------------------------------------------
if ($action === 'upload_chunk') {
    $dirId = trim($_GET['id'] ?? '');
    $chunkName = trim($_POST['chunk_name'] ?? '');

check_upload_enabled(); // 修正：不要传任何参数

    // 强正则限制文件夹和文件名格式，从源头切断 ../ 的可能
    if (!preg_match('/^[a-f0-9]{32}$/i', $dirId) || !preg_match('/^chunk_\d+\.enc$/', $chunkName)) {
        ban_ip("非法的分片上传格式参数");
    }

    $uploadDir = UPLOADS_DIR . "/$dirId/";
    if (!file_exists($uploadDir)) mkdir($uploadDir, 0755, true);
    
    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $targetPath = $uploadDir . $chunkName;
        move_uploaded_file($_FILES['file']['tmp_name'], $targetPath);
        echo json_encode(['code' => 200]);
    } else {
        echo json_encode(['code' => 500]);
    }
    exit;
}


if ($action === 'save_manifest') {
    // 补上这行，这才是阻断非法上传的核心！
    check_upload_enabled(); 

    $dirId = trim($_POST['id'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/i', $dirId)) exit;
    
    $uploadDir = UPLOADS_DIR . "/$dirId/";
    if (!file_exists($uploadDir)) mkdir($uploadDir, 0755, true);
    
    file_put_contents($uploadDir . "manifest.json", $_POST['manifest'] ?? '');
    echo json_encode(['code' => 200]); exit;
}

if ($action === 'get_manifest') {
    $dirId = $_GET['id'] ?? '';
    if (!preg_match('/^[a-f0-9]{32}$/i', $dirId)) exit;
    
    $file = UPLOADS_DIR . "/$dirId/manifest.json";
    if (file_exists($file)) echo file_get_contents($file);
    exit;
}

// ------------------------------------------check_upload_enabled();
// 8. 物理文件删除 (Req 4, 5, 6)
// ------------------------------------------
if ($action === 'delete_files') {
    // 【重要变更】：此接口现在强制要求传入操作者的 user_id 用于鉴权
    $userId = intval($_GET['user_id'] ?? 0);
    verify_user_auth($pdo, $userId);

    $dirId = trim($_GET['id'] ?? '');
    $messageId = intval($_GET['message_id'] ?? 0);
    
    // 双重保险：格式校验 + 沙盒检测
    if (!preg_match('/^[a-f0-9]{32}$/i', $dirId)) {
        ban_ip("尝试使用非标准标识符删除文件");
    }

   // $targetDir = UPLOADS_DIR . "/$dirId/";

   // 强制执行物理删除
if (is_dir($targetDir)) {
    // 执行递归删除文件夹下所有分片
    $files = array_diff(scandir($targetDir), ['.', '..']);
    foreach ($files as $file) { @unlink($targetDir . '/' . $file); }
    @rmdir($targetDir);
} else if (file_exists($targetDir)) {
    @unlink($targetDir); // 如果是单个文件则直接删除
}

    delete_dir_safe($pdo, $userId, $targetDir);
    
    if ($messageId > 0) {
    $pdo->prepare("DELETE FROM messages WHERE id = ?")->execute([$messageId]);
}
    echo json_encode(['code' => 200]); exit;
}

echo json_encode(['code' => 404, 'msg' => '无对应接口']);