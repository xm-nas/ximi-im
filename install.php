<?php
// ========================================================
// 1. 安全拦截：防止重复安装
// ========================================================
if (file_exists(__DIR__ . '/setting.php')) {
    die('<div style="padding:20px;background:#ffebee;color:#c62828;border-radius:5px;font-family:sans-serif;"><b>⛔ 安装程序已经运行过了！</b><br>如需重新安装，请删除目录内的 <code>setting.php</code> 以及原有数据库文件夹及文件，然后刷新此页面。</div>');
}

// ========================================================
// 2. 环境依赖检测 (严格区分必需与可选)
// ========================================================
$required_extensions = ['pdo', 'pdo_sqlite', 'json', 'mbstring'];
$optional_extensions = ['curl'];
$env_status = [];
$install_ok = true; // 默认允许安装

// 检查必需扩展
foreach ($required_extensions as $ext) {
    $loaded = extension_loaded($ext);
    $env_status[$ext] = ['name' => $ext, 'type' => 'required', 'loaded' => $loaded];
    if (!$loaded) $install_ok = false;
}

// 检查可选扩展
foreach ($optional_extensions as $ext) {
    $loaded = extension_loaded($ext);
    $env_status[$ext] = ['name' => $ext, 'type' => 'optional', 'loaded' => $loaded];
}

$curl_enabled = extension_loaded('curl');
$file_list = ['db.php', 'admin.php', 'api.php', 'ximi.js', 'web.js', 'crypto-js.min.js', 'index.html', 'favicon.ico'];

// ========================================================
// 3. 异步修复 API 接口 (仅当 curl 开启时有效)
// ========================================================
if (isset($_GET['action']) && $_GET['action'] === 'repair_file') {
    header('Content-Type: application/json');
    if (!$curl_enabled) {
        die(json_encode(['status' => 'error', 'msg' => '缺少CURL扩展']));
    }
    $file = $_POST['file'] ?? '';
    if (!in_array($file, $file_list)) {
        die(json_encode(['status' => 'error', 'msg' => '非法文件']));
    }

    $base_url = "https://www.ximi.me/usr/demo/xm-im/";
    $save_to = __DIR__ . '/' . $file;
    $tmp_zip = $save_to . '.zip';
    
    $ch = curl_init($base_url . $file . ".zip");
    $fp = fopen($tmp_zip, 'w+');
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    $success = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    if ($success && file_exists($tmp_zip)) {
        rename($tmp_zip, $save_to);
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'msg' => $error ?: '下载失败']);
    }
    exit;
}

// ========================================================
// 4. 表单提交与配置文件生成
// ========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$install_ok) die("环境检测未通过，无法继续安装！");

    function rand_str($len) {
        return substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, $len);
    }

    $dir_name = trim($_POST['dir_name'] ?? '');
    if (empty($dir_name)) $dir_name = rand_str(8);

    $db_name = trim($_POST['db_name'] ?? '');
    if (empty($db_name)) {
        $db_name = rand_str(16) . '.db';
    } else {
        if (substr($db_name, -3) !== '.db') $db_name .= '.db';
    }

    $admin_db_name = 'admin_' . rand_str(16) . '.db';

    $config_content = "<?php\n// 自动生成的配置文件\nreturn [\n"
        . "    'db_dir' => '" . addslashes($dir_name) . "',\n"
        . "    'db_name' => '" . addslashes($db_name) . "',\n"
        . "    'admin_db_name' => '" . addslashes($admin_db_name) . "'\n"
        . "];\n";

    file_put_contents(__DIR__ . '/setting.php', $config_content);

    // 优化后的安装成功页面，保持统一的毛玻璃 UI 风格
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><script src="https://cdn.tailwindcss.com"></script><title>安装成功 - ximi IM</title></head>
    <body class="bg-gradient-to-br from-slate-100 to-gray-200 flex items-center justify-center min-h-screen p-4">
    <div class="bg-white/70 backdrop-blur-xl border border-white/50 p-10 rounded-3xl shadow-2xl text-center max-w-md w-full">
    <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-6 text-4xl shadow-inner">✅</div>
    <h2 class="text-2xl text-slate-800 font-bold mb-4">安装成功就绪</h2>
    <p class="text-slate-500 mb-8 text-sm">系统环境、数据库目录与配置文件已安全生成，即将带您前往管理后台。</p>
    <div class="w-full bg-gray-200 rounded-full h-1.5 mb-2 overflow-hidden"><div class="bg-green-500 h-1.5 rounded-full animate-[progress_2s_ease-in-out_forwards]"></div></div>
    <div class="text-xs text-gray-400">正在重定向...</div>
    <style>@keyframes progress { 0% { width: 0%; } 100% { width: 100%; } }</style>
    <script>setTimeout(function(){ window.location.href="admin.php"; }, 2000);</script>
    </div></body></html>';
    exit;
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="https://cdn.tailwindcss.com"></script>
<title>ximi IM - 部署向导</title>
<style>
    body { font-family: -apple-system, BlinkMacSystemFont, "PingFang SC", "Microsoft YaHei", sans-serif; }
    /* 毛玻璃基础特效 */
    .glass-panel {
        background: rgba(255, 255, 255, 0.75);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.6);
    }
    /* 交互式步骤切换动画 */
    .step-content {
        display: none;
        opacity: 0;
        transform: translateX(10px);
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .step-content.active {
        display: block;
        opacity: 1;
        transform: translateX(0);
    }
    /* 极简终端风格展示 */
    .term-text { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; }
</style>
</head>
<body class="bg-gradient-to-br from-slate-100 to-gray-300 min-h-screen flex items-center justify-center p-4 sm:p-8 relative overflow-hidden">

    <div class="absolute top-[-10%] left-[-5%] w-96 h-96 bg-blue-300 rounded-full mix-blend-multiply filter blur-3xl opacity-40"></div>
    <div class="absolute bottom-[-10%] right-[-5%] w-96 h-96 bg-emerald-300 rounded-full mix-blend-multiply filter blur-3xl opacity-40"></div>

    <div class="glass-panel w-full max-w-5xl rounded-[2rem] shadow-2xl flex flex-col md:flex-row overflow-hidden relative z-10 min-h-[600px]">
        
        <div class="w-full md:w-1/3 bg-slate-900/5 p-8 border-b md:border-b-0 md:border-r border-white/40 flex flex-col">
            <div class="mb-12 mt-4">
                <h1 class="text-3xl font-bold text-slate-800 tracking-tight">ximi IM</h1>
                <p class="text-xs text-slate-500 mt-2 tracking-widest uppercase">Secure Setup Wizard</p>
            </div>

            <div class="space-y-8 relative">
                <div class="flex items-start step-indicator" id="nav-step-1">
                    <div class="w-8 h-8 rounded-full flex items-center justify-center font-bold text-sm transition-colors duration-300 bg-blue-600 text-white shadow-md z-10 relative nav-icon">1</div>
                    <div class="ml-4">
                        <h3 class="text-sm font-semibold text-slate-800">系统特性</h3>
                        <p class="text-xs text-slate-500 mt-1">了解核心架构机制</p>
                    </div>
                </div>
                <div class="flex items-start step-indicator" id="nav-step-2">
                    <div class="w-8 h-8 rounded-full flex items-center justify-center font-bold text-sm transition-colors duration-300 bg-white/60 text-slate-400 z-10 relative nav-icon">2</div>
                    <div class="ml-4">
                        <h3 class="text-sm font-semibold text-slate-800">环境巡检</h3>
                        <p class="text-xs text-slate-500 mt-1">服务器依赖项验证</p>
                    </div>
                </div>
                <div class="flex items-start step-indicator" id="nav-step-3">
                    <div class="w-8 h-8 rounded-full flex items-center justify-center font-bold text-sm transition-colors duration-300 bg-white/60 text-slate-400 z-10 relative nav-icon">3</div>
                    <div class="ml-4">
                        <h3 class="text-sm font-semibold text-slate-800">文件校验</h3>
                        <p class="text-xs text-slate-500 mt-1">完整性检测与自动化修复</p>
                    </div>
                </div>
                <div class="flex items-start step-indicator" id="nav-step-4">
                    <div class="w-8 h-8 rounded-full flex items-center justify-center font-bold text-sm transition-colors duration-300 bg-white/60 text-slate-400 z-10 relative nav-icon">4</div>
                    <div class="ml-4">
                        <h3 class="text-sm font-semibold text-slate-800">核心配置</h3>
                        <p class="text-xs text-slate-500 mt-1">数据库与存储参数设置</p>
                    </div>
                </div>
                <div class="absolute left-4 top-4 bottom-8 w-px bg-slate-300/50 -z-0"></div>
            </div>
            
            <div class="mt-auto pt-10">
                <p class="text-[10px] text-slate-400">Powered by PHP & SQLite</p>
            </div>
        </div>

        <div class="w-full md:w-2/3 p-8 md:p-12 relative flex flex-col">
            
            <div class="step-content active flex-1" id="step-1">
                <h2 class="text-2xl font-bold text-slate-800 mb-6">欢迎使用</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-8">
                    <div class="bg-white/50 border border-white p-5 rounded-xl shadow-sm hover:shadow-md transition-shadow">
                        <div class="text-xl mb-2">🔐</div>
                        <h3 class="font-semibold text-sm text-slate-700">端到端加密通信</h3>
                        <p class="text-xs text-slate-500 mt-2 leading-relaxed">服务端仅存储密文，彻底杜绝明文数据泄露及中间人监听风险。</p>
                    </div>
                    <div class="bg-white/50 border border-white p-5 rounded-xl shadow-sm hover:shadow-md transition-shadow">
                        <div class="text-xl mb-2">💣</div>
                        <h3 class="font-semibold text-sm text-slate-700">阅后即焚机制</h3>
                        <p class="text-xs text-slate-500 mt-2 leading-relaxed">高度敏感信息读取后立即在服务端及客户端实行物理销毁，不留痕迹。</p>
                    </div>
                    <div class="bg-white/50 border border-white p-5 rounded-xl shadow-sm hover:shadow-md transition-shadow">
                        <div class="text-xl mb-2">📦</div>
                        <h3 class="font-semibold text-sm text-slate-700">无缝轻量架构</h3>
                        <p class="text-xs text-slate-500 mt-2 leading-relaxed">基于原生 PHP 与 SQLite 构建，摆脱繁重的中间件，极简高效。</p>
                    </div>
                    <div class="bg-white/50 border border-white p-5 rounded-xl shadow-sm hover:shadow-md transition-shadow">
                        <div class="text-xl mb-2">🚀</div>
                        <h3 class="font-semibold text-sm text-slate-700">极简极速部署</h3>
                        <p class="text-xs text-slate-500 mt-2 leading-relaxed">无需繁琐命令，单文件上传即用，自动化向导完成所有初始化。</p>
                    </div>
                </div>
                <div class="bg-amber-50/80 border border-amber-100 text-amber-700 px-4 py-3 rounded-lg text-xs flex items-center">
                    <span class="mr-2">💡</span> 强烈建议在生产环境配置 Nginx HTTPS (SSL证书) 以激活完整的安全机制。
                </div>
            </div>

            <div class="step-content flex-1" id="step-2">
                <h2 class="text-2xl font-bold text-slate-800 mb-6">服务器环境巡检</h2>
                <div class="bg-white/60 border border-white rounded-xl shadow-sm overflow-hidden mb-6">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-slate-50/50 text-slate-500 text-xs uppercase border-b border-white">
                            <tr>
                                <th class="px-6 py-3 font-medium">扩展组件名称</th>
                                <th class="px-6 py-3 font-medium text-center">必要性</th>
                                <th class="px-6 py-3 font-medium text-right">状态检测</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/60">
                            <?php foreach ($env_status as $ext => $info): ?>
                            <tr class="hover:bg-white/40 transition-colors">
                                <td class="px-6 py-3 font-medium text-slate-700 term-text"><?=$info['name']?></td>
                                <td class="px-6 py-3 text-center text-xs">
                                    <span class="px-2 py-1 rounded-md <?= $info['type'] == 'required' ? 'bg-slate-200 text-slate-700' : 'bg-slate-100 text-slate-500' ?>">
                                        <?= $info['type'] == 'required' ? 'Required' : 'Optional' ?>
                                    </span>
                                </td>
                                <td class="px-6 py-3 text-right">
                                    <?php if ($info['loaded']): ?>
                                        <span class="inline-flex items-center text-emerald-600 bg-emerald-50 px-2 py-1 rounded-md text-xs font-semibold"><span class="w-1.5 h-1.5 bg-emerald-500 rounded-full mr-1.5"></span>PASS</span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center <?= $info['type'] == 'required' ? 'text-red-600 bg-red-50' : 'text-amber-600 bg-amber-50' ?> px-2 py-1 rounded-md text-xs font-semibold"><span class="w-1.5 h-1.5 <?= $info['type'] == 'required' ? 'bg-red-500' : 'bg-amber-500' ?> rounded-full mr-1.5"></span>FAIL</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <li class="" style="
    margin-top: -9px;
    padding-bottom: 0.5rem;
    color: #88909a;
    font-size: 14px;
    margin-left: 22px;
">
                    curl为非必需扩展,仅用于自动修复文件缺失!
                </li>
                <?php if (!$install_ok): ?>
                <div class="bg-red-50 border border-red-100 text-red-600 px-4 py-3 rounded-lg text-sm mb-4">
                    ❌ 核心必需扩展缺失，请配置 php.ini 或在服务器面板（如 1Panel/Synology）中安装后重试。
                </div>
                <?php else: ?>
                <div class="bg-emerald-50 border border-emerald-100 text-emerald-700 px-4 py-3 rounded-lg text-sm mb-4">
                    ✅ 核心环境依赖已就绪，准许安装。
                </div>
                <?php endif; ?>
            </div>

            <div class="step-content flex-1" id="step-3">
                <div class="flex justify-between items-end mb-6">
                    <div>
                        <h2 class="text-2xl font-bold text-slate-800">系统文件校验</h2>
                        <p class="text-xs text-slate-500 mt-1">检测程序主体文件完整性，支持自动从云端获取缺失项。</p>
                    </div>
                    <?php if ($curl_enabled): ?>
                    <button id="repair-all-btn" class="bg-blue-50 text-blue-600 hover:bg-blue-600 hover:text-white transition-colors px-3 py-1.5 rounded-lg text-xs font-medium shadow-sm">一键修复全部</button>
                    <?php endif; ?>
                </div>

                <div id="file-list" class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-6">
                    <?php foreach ($file_list as $file): 
                        $exists = file_exists(__DIR__ . '/' . $file);
                    ?>
                    <div class="bg-white/60 border border-white p-3 rounded-xl flex justify-between items-center shadow-sm" data-file="<?=$file?>">
                        <span class="text-sm font-medium text-slate-700 term-text"><?=$file?></span>
                        <div class="file-status text-xs">
                            <?php if ($exists): ?>
                                <span class="text-emerald-500 font-semibold bg-emerald-50 px-2 py-1 rounded">Normal</span>
                            <?php elseif ($curl_enabled): ?>
                                <button onclick="repairFile('<?=$file?>')" class="text-blue-500 hover:text-blue-700 font-medium bg-blue-50 px-2 py-1 rounded transition-colors">自动修复</button>
                            <?php else: ?>
                                <span class="text-slate-400 italic">缺失 (需手动上传)</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="step-content flex-1" id="step-4">
                <h2 class="text-2xl font-bold text-slate-800 mb-2">安全配置</h2>
                <p class="text-xs text-slate-500 mb-8">设定您的存储路径，保持留空将由算法自动生成高强度乱码命名以防止穷举探测。</p>
                
                <form id="install-form" method="POST" class="space-y-5">
                    <div class="bg-white/50 border border-white p-6 rounded-2xl shadow-sm">
                        <div class="mb-5">
                            <label class="block text-sm font-medium text-slate-700 mb-1">物理数据存放目录 <span class="text-slate-400 text-xs font-normal">(db_dir)</span></label>
                            <input type="text" name="dir_name" placeholder="建议留空，自动生成随机目录" class="w-full bg-white/70 border border-slate-200 rounded-xl px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all term-text">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">前端数据库文件名 <span class="text-slate-400 text-xs font-normal">(db_name)</span></label>
                            <input type="text" name="db_name" placeholder="建议留空，自动生成随机库名" class="w-full bg-white/70 border border-slate-200 rounded-xl px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all term-text">
                        </div>
                        
                        <div class="mt-4 pt-4 border-t border-slate-200/50 flex items-center justify-between">
                            <span class="text-xs text-slate-500">管理端独立数据库 (admin_db) 将强制执行随机生成。</span>
                            <span class="text-xs bg-slate-100 text-slate-500 px-2 py-1 rounded term-text">Auto</span>
                        </div>
                    </div>
                </form>
            </div>

            <div class="mt-auto pt-6 border-t border-white/50 flex justify-between items-center">
                <button id="btn-prev" class="px-5 py-2.5 rounded-xl text-sm font-medium text-slate-600 hover:bg-white/50 transition-colors hidden">
                    返回上一步
                </button>
                <div class="flex-1"></div> <button id="btn-next" class="px-8 py-2.5 rounded-xl text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 shadow-lg shadow-blue-500/30 transition-all">
                    下一步
                </button>
                <button id="btn-install" class="px-8 py-2.5 rounded-xl text-sm font-medium text-white bg-emerald-600 hover:bg-emerald-700 shadow-lg shadow-emerald-500/30 transition-all hidden" onclick="document.getElementById('install-form').submit()">
                    执行系统安装
                </button>
            </div>

        </div>
    </div>

<script>
// --- 交互式步骤导航逻辑 ---
let currentStep = 1;
const totalSteps = 4;
const envPassed = <?= $install_ok ? 'true' : 'false' ?>;

function updateUI() {
    // 内容区切换
    document.querySelectorAll('.step-content').forEach(el => el.classList.remove('active'));
    document.getElementById(`step-${currentStep}`).classList.add('active');

    // 侧边栏状态更新
    for (let i = 1; i <= totalSteps; i++) {
        const icon = document.querySelector(`#nav-step-${i} .nav-icon`);
        if (i < currentStep) {
            icon.className = 'w-8 h-8 rounded-full flex items-center justify-center font-bold text-sm transition-colors duration-300 bg-emerald-500 text-white shadow-md z-10 relative nav-icon';
            icon.innerHTML = '✓';
        } else if (i === currentStep) {
            icon.className = 'w-8 h-8 rounded-full flex items-center justify-center font-bold text-sm transition-colors duration-300 bg-blue-600 text-white shadow-md z-10 relative nav-icon';
            icon.innerHTML = i;
        } else {
            icon.className = 'w-8 h-8 rounded-full flex items-center justify-center font-bold text-sm transition-colors duration-300 bg-white/60 text-slate-400 z-10 relative nav-icon';
            icon.innerHTML = i;
        }
    }

    // 按钮显隐逻辑
    document.getElementById('btn-prev').style.display = currentStep > 1 ? 'block' : 'none';
    
    if (currentStep === totalSteps) {
        document.getElementById('btn-next').style.display = 'none';
        document.getElementById('btn-install').style.display = 'block';
    } else {
        document.getElementById('btn-next').style.display = 'block';
        document.getElementById('btn-install').style.display = 'none';
    }

    // 环境检测未通过，锁定第2步
    if (currentStep === 2 && !envPassed) {
        document.getElementById('btn-next').disabled = true;
        document.getElementById('btn-next').classList.add('opacity-50', 'cursor-not-allowed');
        document.getElementById('btn-next').innerHTML = '环境未就绪';
    } else {
        document.getElementById('btn-next').disabled = false;
        document.getElementById('btn-next').classList.remove('opacity-50', 'cursor-not-allowed');
        document.getElementById('btn-next').innerHTML = '下一步';
    }
}

document.getElementById('btn-next').addEventListener('click', () => {
    if (currentStep < totalSteps) { currentStep++; updateUI(); }
});

document.getElementById('btn-prev').addEventListener('click', () => {
    if (currentStep > 1) { currentStep--; updateUI(); }
});

// --- 原有的异步文件修复逻辑无缝接入 ---
async function repairFile(filename) {
    const el = document.querySelector(`[data-file="${filename}"] .file-status`);
    el.innerHTML = '<span class="text-amber-500 bg-amber-50 px-2 py-1 rounded font-medium animate-pulse">Pulling...</span>';
    const formData = new FormData();
    formData.append('file', filename);
    try {
        const response = await fetch('?action=repair_file', { method: 'POST', body: formData });
        const result = await response.json();
        if (result.status === 'success') {
            el.innerHTML = '<span class="text-emerald-500 font-semibold bg-emerald-50 px-2 py-1 rounded">Fixed</span>';
        } else {
            el.innerHTML = `<button class="text-red-500 hover:text-red-700 font-medium bg-red-50 px-2 py-1 rounded" onclick="repairFile('${filename}')">重试</button>`;
        }
    } catch (e) {
        el.innerHTML = `<button class="text-red-500 hover:text-red-700 font-medium bg-red-50 px-2 py-1 rounded" onclick="repairFile('${filename}')">Error(重试)</button>`;
    }
}

document.getElementById('repair-all-btn')?.addEventListener('click', async function() {
    const btn = this;
    btn.disabled = true;
    btn.innerHTML = '修复进行中...';
    btn.classList.add('opacity-50', 'cursor-not-allowed');
    
    const buttons = document.querySelectorAll('#file-list .file-status button');
    for (const actionBtn of buttons) { 
        actionBtn.click(); 
        await new Promise(r => setTimeout(r, 800)); 
    }
    
    setTimeout(() => {
        btn.innerHTML = '执行完毕';
        btn.classList.remove('text-blue-600', 'bg-blue-50');
        btn.classList.add('text-emerald-600', 'bg-emerald-50');
    }, 1000);
});

// 初始化界面
updateUI();
</script>
</body>
</html>