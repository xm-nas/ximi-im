/**
 * web.js - 移动端专属独立控制器 (基于纯净微信UI)
 * 依赖: ximi.js 中提供的加密算法和底层全局变量
 */

// 移动端内部状态
let mCurrentTab = 0;
const mTitles = ["聊天", "通讯录", "配置"];
let mActiveTargetId = null;
let mSyncTimer = null;
let mPullTimer = null;




// ==================== 移动端长按触控状态机变量 ====================
let mTouchTimer = null;
let mIsLongPressTriggered = false;
let mTouchStartPos = { x: 0, y: 0 };
let mTargetActionUid = null;
let mTargetActionName = null;

/**
 * 升级版：渲染聊天记录列表 (支持长按与防滚动误触)
 */
/**
 * 修复版：渲染聊天记录列表
 * 1. 强制绑定 onclick 事件以触发跳转
 * 2. 保留 ontouch 事件用于长按逻辑
 */
function mRenderChatList() {
    const container = document.getElementById('m-page-msg');
    const myHistory = chatHistory[loggedInUser.id] || {};
    const activeIds = Object.keys(myHistory);
    
    if (activeIds.length === 0) {
        container.innerHTML = '<div class="p-6 text-center text-gray-400 text-sm">暂无聊天记录</div>';
        return;
    }
    
    const list = globalUserList.filter(u => String(u.id) !== String(loggedInUser.id) && activeIds.includes(String(u.id)));
    
    container.innerHTML = list.map(u => {
        const name = u.nickname || u.username;
        const char = name.substring(0, 1).toUpperCase();
        
        // 提取最后一条消息作为摘要
        const msgs = myHistory[u.id] || [];
        const lastMsg = msgs.length > 0 ? msgs[msgs.length - 1].text : '';
        
        // 【关键修复】：增加了 onclick="mStartChat('${u.id}')"
        // 同时保留了原有的 ontouch 事件处理长按菜单
        return `
            <div class="m-chat-item" 
                 data-uid="${u.id}" 
                 data-name="${name}"
                 onclick="mStartChat('${u.id}')"
                 ontouchstart="mHandleTouchStart(event, this)"
                 ontouchmove="mHandleTouchMove(event)"
                 ontouchend="mHandleTouchEnd(event, this)">
                <div class="m-avatar">${char}</div>
                <div class="flex-1 overflow-hidden">
                    <div class="flex justify-between items-center">
                        <div class="font-medium text-[16px] text-[#1a1a1a]">${name}</div>
                    </div>
                    <div class="text-gray-400 text-[13px] mt-1 truncate">${lastMsg}</div>
                </div>
            </div>
        `;
    }).join('');
}

/**
 * 移动端：切换到指定用户的聊天窗口
 */
function mStartChat(uid) {
    // 1. 设置当前活动目标 UID
    mActiveTargetId = parseInt(uid);
    
    // 2. 隐藏消息列表页，显示聊天详情页
    const msgPage = document.getElementById('m-page-msg');
    const chatPage = document.getElementById('m-page-chat');
    
    if (msgPage) msgPage.classList.add('hidden');
    if (chatPage) chatPage.classList.remove('hidden');
    
    // 3. 更新标题栏名字
    const user = globalUserList.find(u => String(u.id) === String(uid));
    const titleEl = document.getElementById('m-chat-title');
    if (titleEl && user) {
        titleEl.textContent = user.nickname || user.username;
    }
    
    // 4. 执行渲染 (假设你原本有此渲染函数)
    if (typeof mRenderChatHistory === 'function') {
        mRenderChatHistory();
    }
}


// 选项变更回调
function mUpdatePullInterval() {
    const val = parseInt(document.getElementById('m-autoPullInterval').value);
    localStorage.setItem('m_pull_interval', val);
    startAutoPull(val);
}



function toggleAutoPull() {
    const select = document.getElementById('autoPullInterval') || document.getElementById('m-autoPullInterval');
    if (!select) return;
    
    const interval = select.value;
    localStorage.setItem('m_pull_interval', interval);
    
    // 调用全局函数
    window.startAutoPull(interval);
    
    // 同步两个界面的状态
    const pcSelect = document.getElementById('autoPullInterval');
    const mSelect = document.getElementById('m-autoPullInterval');
    if (pcSelect) pcSelect.value = interval;
    if (mSelect) mSelect.value = interval;
}

// --- 统一轮询管理器 (Safe Mode) ---
// 使用 window 对象存储，防止重复声明导致的报错
window.autoPullTimer = window.autoPullTimer || null;

// 启动轮询的通用函数（全平台代理）
window.startAutoPull = function(interval) {
    if (window.autoPullTimer) {
        clearInterval(window.autoPullTimer);
    }
    
    const time = parseInt(interval);
    if (time > 0) {
        console.log(`自动拉取已开启，间隔: ${time}ms`);
        window.autoPullTimer = setInterval(() => {
            
            // 💡 核心修复：调用你真实的拉取消息函数 mSyncNow
            if (typeof mSyncNow === 'function') {
                mSyncNow();
            } else {
                console.warn("未找到核心拉取函数 mSyncNow，请检查函数名是否正确");
            }
            
            // 如果用户当前停留在手机端的聊天页(Index 0)，拉取完后立刻强制刷新界面
            if (typeof mCurrentTab !== 'undefined' && mCurrentTab === 0) {
                if (typeof mLoopRender === 'function') {
                    mLoopRender();
                }
            }
            
        }, time);
    } else {
        console.log("自动拉取已关闭 (手动模式)");
    }
};





// 核心循环：将 ximi.js 的数据状态实时映射到手机端界面
function mLoopRender() {
    // 判断登录状态
    const authPage = document.getElementById('m-auth-page');
    if (!loggedInUser) {
        if(authPage.classList.contains('-translate-y-full')) {
            authPage.classList.remove('-translate-y-full'); // 弹出登录页
        }
        return;
    } else {
        authPage.classList.add('-translate-y-full'); // 隐藏登录页
    }

    // 更新联系人与消息列表
    if (mCurrentTab === 0) mRenderChatList();
    if (mCurrentTab === 1) mRenderContacts();
    
    // 如果当前正处于聊天界面中，同步刷新气泡
    if (mActiveTargetId && !document.getElementById('m-chat-window').classList.contains('translate-x-full')) {
        mRenderChatHistory();
    }
}

// ============== 鉴权代理 ==============
// 借壳调用 PC 端的逻辑，避免重复写 API 路由和存储代码
function mLogin() {
    const un = document.getElementById('m-username').value;
    const pw = document.getElementById('m-password').value;
    if (!un || !pw) return alert("账号密码不能为空");
    
    // 将值抛给底层 PC DOM 影子节点并触发 ximi.js 的请求
    document.getElementById('username').value = un;
    document.getElementById('password').value = pw;
    if(typeof handleLogin === 'function') handleLogin();
    
    // 等待登录回调结果
    setTimeout(() => {
        if (loggedInUser) mLoopRender();
    }, 800);
}

function mRegister() {
    document.getElementById('username').value = document.getElementById('m-username').value;
    document.getElementById('password').value = document.getElementById('m-password').value;
    document.getElementById('nickname').value = document.getElementById('m-nickname').value;
    if(typeof handleRegister === 'function') handleRegister();
}

function mLogout() {
    if(typeof handleLogout === 'function') handleLogout();
}

// ============== Tab 切换逻辑 ==============
// 全局变量，用于存储定时器 ID
window.mMsgLoopTimer = window.mMsgLoopTimer || null;
// 全局变量，用于存储聊天页面的轮询定时器


function mSwitchTab(index) {
    mCurrentTab = index;
    
    // 1. 更新顶部标题
    const headerTitle = document.getElementById('m-headerTitle');
    if (headerTitle) headerTitle.innerText = mTitles[index];
    
    // 2. 切换页面显隐 (active)
    const pages = [
        document.getElementById("m-page-msg"), 
        document.getElementById("m-page-contact"), 
        document.getElementById("m-page-settings")
    ];
    pages.forEach(p => p && p.classList.remove("active"));
    if (pages[index]) pages[index].classList.add("active");

    // 3. 切换底部 Tab 高亮状态
    const tabbar = document.getElementById('m-tabbar');
    if (tabbar) {
        const tabs = tabbar.querySelectorAll('.m-tab');
        tabs.forEach(t => t.classList.remove("active"));
        if (tabs[index]) tabs[index].classList.add("active");
    }

    // 4. 【核心修复】根据切换的 Tab 分流执行对应的渲染逻辑
    
    // 先清理聊天的轮询定时器，避免在其他页面后台空转
    if (window.mMsgLoopTimer) {
        clearInterval(window.mMsgLoopTimer);
        window.mMsgLoopTimer = null;
    }

    if (index === 0) {
        // ================= 聊天页 (Index 0) =================
        console.log("进入手机端聊天页，开启实时消息轮询...");
        
        // 立即渲染一次
        if (typeof mLoopRender === 'function') mLoopRender();
        
        // 开启 1s 轮询保持消息实时更新
        window.mMsgLoopTimer = setInterval(() => {
            if (typeof mLoopRender === 'function') mLoopRender();
        }, 1500);

    } else if (index === 1) {
        // ================= 通讯录页 (Index 1) =================
        console.log("进入手机端通讯录，触发列表渲染...");
        
        // 💡 请检查你代码中负责渲染联系人列表的函数名：
        // 常见名字如 renderContactList(), mRenderContacts(), renderContacts()
        if (typeof renderContactList === 'function') {
            renderContactList(); 
        } else if (typeof mLoopRender === 'function') {
            // 如果你的通讯录渲染也写在 mLoopRender 里面，就调用它
            mLoopRender(); 
        } else {
            console.warn("未找到通讯录渲染函数，请确认函数名");
        }

    } else if (index === 2) {
        // ================= 设置页 (Index 2) =================
        console.log("进入手机端设置页，正在渲染菜单...");
        if (typeof renderSettingsPage === 'function') {
            renderSettingsPage();
        }
    }
}

// ============== 列表渲染 ==============
// 渲染通讯录
function mRenderContacts() {
    const container = document.getElementById('m-page-contact');
    if (!globalUserList || globalUserList.length === 0) return container.innerHTML = '<div class="p-6 text-center text-gray-400 text-sm">正在加载中...</div>';
    
    // 剔除自己
    const list = globalUserList.filter(u => String(u.id) !== String(loggedInUser.id));
    
    container.innerHTML = list.map(u => {
        const name = u.nickname || u.username;
        const char = name.substring(0,1).toUpperCase();
        return `
            <div class="m-chat-item" onclick="mOpenChat('${u.id}', '${name}')">
                <div class="m-avatar">${char}</div>
                <div class="flex-1 overflow-hidden">
                    <div class="font-medium text-[16px] text-[#1a1a1a]">${name}</div>
                    <div class="text-gray-400 text-[12px] mt-0.5">UID: ${u.id}</div>
                </div>
            </div>
        `;
    }).join('');
}

// 渲染聊天记录列表 (聊天首页)



// ============== 聊天主视窗 ==============
function mOpenChat(uid, name) {
    mActiveTargetId = parseInt(uid);
    document.getElementById('m-chat-title').innerText = name;
    
    // 打开面板
    document.getElementById('m-chat-window').classList.add('show');
    
    // 如果是新联系人，初始化沙箱槽位
    if (!chatHistory[loggedInUser.id]) chatHistory[loggedInUser.id] = {};
    if (!chatHistory[loggedInUser.id][uid]) chatHistory[loggedInUser.id][uid] = [];
    
    mRenderChatHistory();
}

function mCloseChat() {
    document.getElementById('m-chat-window').classList.remove('show');
    mActiveTargetId = null;
    mLoopRender();
}

/*

function mRenderChatHistory() {
    const container = document.getElementById('m-chat-history');
    if (!container) {
        console.error("未找到容器 m-chat-history");
        return;
    }
    
    // 增加调试点
    console.log("当前活动UID:", mActiveTargetId);
    console.log("当前用户聊天历史:", chatHistory[loggedInUser.id]);

    const myHistory = chatHistory[loggedInUser.id] || {};
    const msgs = myHistory[mActiveTargetId] || [];
    
    console.log("该好友消息数:", msgs.length); // 如果这里输出 0，说明数据源确实没存进去
    
    // ... 后续代码
}

*/
// ==========================================
// 重新补充：渲染手机端聊天气泡历史的核心函数
// ==========================================
function mRenderChatHistory() {
    const container = document.getElementById('m-chat-history');
    if (!container) return;
    
    // 【判断是否在底部，防止刷新乱跳】
    const isAtBottom = container.scrollHeight - container.scrollTop <= container.clientHeight + 50;
    
    container.innerHTML = ''; // 清空原有内容
    
    if (!loggedInUser || !mActiveTargetId) return;

    const myHistory = chatHistory[loggedInUser.id] || {};
    const msgs = myHistory[mActiveTargetId] || [];
    
    msgs.forEach(msg => {
        const item = document.createElement('div');
        const isMe = parseInt(msg.sender_id) === parseInt(loggedInUser.id);
        const name = isMe ? "" : getUserNicknameById(msg.sender_id);
        const char = isMe ? (loggedInUser.nickname || loggedInUser.username).charAt(0).toUpperCase() : name.charAt(0).toUpperCase();
        const wrapClass = isMe ? "m-bubble-wrap me" : "m-bubble-wrap them";
        const avatarColor = isMe ? "bg-gray-600" : "bg-[#1296db]";
        
        item.className = wrapClass;
        item.innerHTML = `
            <div class="w-10 h-10 rounded shadow-sm text-white flex items-center justify-center font-bold flex-shrink-0 ${avatarColor}">
                ${char}
            </div>
            <div class="m-bubble"></div>
        `;
        
        const bubbleEl = item.querySelector('.m-bubble');
        
        // 文件解析与下载绑定
        if (msg.msg_type === 'file' && msg.file_info) {
            bubbleEl.innerHTML = ` 📎 请接收加密文件：<a href="javascript:void(0);" class="text-blue-600 underline font-bold break-all m-file-chat-link"></a> `;
            const linkEl = bubbleEl.querySelector('.m-file-chat-link');
            linkEl.textContent = msg.file_info.originName; 
            
            linkEl.addEventListener('click', (e) => {
                e.preventDefault();
                if (typeof downloadAndDecryptChunks === 'function') {
                    downloadAndDecryptChunks(
                        msg.file_info.dirId, msg.file_info.originName,
                        msg.file_info.totalChunks, msg.file_info.encryptedAesKey,
                        msg.file_info.iv, msg.file_info.messageId
                    );
                }
            });
        } else {
            // 普通文本
            bubbleEl.textContent = msg.text;
        }
        container.appendChild(item);
    });
    
    if (isAtBottom || container.children.length <= 1) {
        container.scrollTop = container.scrollHeight;
    }
}

// 渲染气泡（支持文件通知超链接与安全下载解密）
function mRenderChatList() {
    const container = document.getElementById('m-page-msg');
    const myHistory = chatHistory[loggedInUser.id] || {};
    const activeIds = Object.keys(myHistory);
    
    if (activeIds.length === 0) return container.innerHTML = '<div class="p-6 text-center text-gray-400 text-sm">暂无聊天记录</div>';
    
    const list = globalUserList.filter(u => String(u.id) !== String(loggedInUser.id) && activeIds.includes(String(u.id)));
    
    container.innerHTML = list.map(u => {
        const name = u.nickname || u.username;
        const char = name.substring(0,1).toUpperCase();
        
        // 提取最后一条消息作为摘要
        const msgs = myHistory[u.id] || [];
        const lastMsg = msgs.length > 0 ? msgs[msgs.length - 1].text : '';
        
        // 【核心修正】：用 onclick 直接调用你代码原汁原味的 mOpenChat 函数！
        return `
            <div class="m-chat-item" 
                 data-uid="${u.id}" 
                 data-name="${name}"
                 onclick="mOpenChat('${u.id}', '${name}')"
                 ontouchstart="mHandleTouchStart(event, this)"
                 ontouchmove="mHandleTouchMove(event)"
                 ontouchend="mHandleTouchEnd(event, this)">
                <div class="m-avatar">${char}</div>
                <div class="flex-1 overflow-hidden">
                    <div class="flex justify-between items-center">
                        <div class="font-medium text-[16px] text-[#1a1a1a]">${name}</div>
                    </div>
                    <div class="text-gray-400 text-[13px] mt-1 truncate">${lastMsg}</div>
                </div>
            </div>
        `;
    }).join('');
}
// 移动端发送加密消息
async function mSendMsg() {
    const inputEl = document.getElementById('m-msg-input');
    const text = inputEl.value.trim();
    if (!text || !mActiveTargetId) return;

    // 依然利用底层引擎 ximi.js 的加密通道进行静默发送
    // 将焦点和参数代理给 ximi.js 对应的输入框
    document.getElementById('receiverInput').value = mActiveTargetId;
    document.getElementById('msgText').value = text;
    
    if(typeof sendEncryptedText === 'function') {
        inputEl.value = "加密处理中...";
        inputEl.disabled = true;
        
        await sendEncryptedText();
        
        inputEl.value = "";
        inputEl.disabled = false;
        mRenderChatHistory();
        inputEl.focus();
    }
}

// 手机端手动收取控制
function mSyncNow() {
    if(typeof pullMessages === 'function') {
      //  alert('正在静默提取云端数据...');
        pullMessages(true);
    }
}

// 重写移动端的底层 log 输出捕获，让其同时打印到手机的日志屏中
const originalLog = log;
window.log = function(title, data) {
    if(originalLog) originalLog(title, data); // 维持PC端日志正常运作
    
    // 注入移动端日志
    const mLogBox = document.getElementById('m-log-box');
    if(mLogBox) {
        const time = new Date().toLocaleTimeString();
        mLogBox.innerText += `\n[${time}] == ${title} ==\n${typeof data === 'object' ? JSON.stringify(data) : data}\n`;
        mLogBox.scrollTop = mLogBox.scrollHeight;
    }
};

//============

function renderSettingsPage() {
    const container = document.getElementById('m-page-settings');
    if (!container) return;

    // 动态获取当前底层正在使用的路由地址
    const pcApiInput = document.getElementById('apiUrl');
    const currentApiUrl = pcApiInput ? pcApiInput.value : 'https://app.hhqq.net/api.php';

    // 统一的区块标题样式 (去除了极端的负边距，恢复正常间距)
    const sectionTitleStyle = "background: #ededed; padding: 12px 15px 4px 15px; color: #999f9e; font-size: 14px;";

    container.innerHTML = `
        <div class="mt-4 bg-white border-y border-gray-200"style="margin-top: -3px;">
            
            <div style="${sectionTitleStyle}">
                功能
            </div>

            <div class="mt-0 bg-white border-b border-gray-200">
                <div class="m-chat-item justify-between" onclick="document.getElementById('m-api-box').classList.toggle('hidden')">
                   <span class="flex items-center gap-2 leading-none"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" data-lucide="globe" aria-hidden="true" class="lucide lucide-globe w-4 h-4 text-gray-500"><circle cx="12" cy="12" r="10"></circle><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"></path><path d="M2 12h20"></path></svg> 路由设置</span>
                    <span class="text-gray-400">›</span>
                </div>
                
                <div id="m-api-box" class="hidden px-4 py-3 bg-gray-50 border-b border-gray-100">
                    <p class="text-[12px] font-bold text-gray-500 mb-1.5">后端 API 路由节点：</p>
                    <input type="text" id="m-apiUrl" 
                           class="w-full text-[13px] p-2.5 bg-white border border-gray-200 rounded-lg font-mono text-gray-700 outline-none" 
                           value="${currentApiUrl}" 
                           oninput="if(document.getElementById('apiUrl')) document.getElementById('apiUrl').value = this.value">
                    <p class="text-[10px] text-gray-400 mt-1.5">修改后将实时同步至底层通信引擎</p>
                </div>

                <div class="m-chat-item justify-between">
                    <span class="flex items-center gap-2 leading-none"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" data-lucide="activity" aria-hidden="true" class="lucide lucide-activity w-4 h-4 text-gray-500"><path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"></path></svg> 网络延迟</span>
                    <span id="m-latency-val" class="text-green-500 font-mono text-sm">检测中...</span>
                </div>
            
                <div class="m-chat-item justify-between">
                   <span class="flex items-center gap-2 leading-none"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" data-lucide="refresh-cw" aria-hidden="true" class="lucide lucide-refresh-cw w-4 h-4 text-gray-500"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"></path><path d="M21 3v5h-5"></path><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"></path><path d="M8 16H3v5"></path></svg> 自动接收</span>
                    <select id="m-autoPullInterval" onchange="mUpdatePullInterval()" class="text-gray-500 bg-transparent outline-none text-right">
                        <option value="0">手动</option>
                        <option value="500">0.5s</option>
                        <option value="1000" selected>1s</option>
                        <option value="3000">3s</option>
                        <option value="5000">5s</option>
                    </select>
                </div>
         
                <div class="m-chat-item justify-between" onclick="mOpenMsgManager()">
                    <span class="flex items-center gap-2 leading-none"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" data-lucide="folder" aria-hidden="true" class="lucide lucide-folder w-4 h-4 text-gray-500"><path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"></path></svg> 离线消息记录管理</span>
                    <span class="text-gray-400">›</span>
                </div>
            </div>

            <div style="${sectionTitleStyle}">
                安全守护
            </div>
            
            <div class="mt-0 bg-white border-b border-gray-200">
                <div class="m-chat-item" onclick="exportIdentityKeys()">
                   <span class="flex items-center gap-2 leading-none"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" data-lucide="download" aria-hidden="true" class="lucide lucide-download w-4 h-4 text-gray-500"><path d="M12 15V3"></path><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><path d="m7 10 5 5 5-5"></path></svg> 导出密钥</span>
                </div>
                <div class="m-chat-item" onclick="triggerKeyImport()">
                   <span class="flex items-center gap-2 leading-none"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" data-lucide="upload" aria-hidden="true" class="lucide lucide-upload w-4 h-4 text-gray-500"><path d="M12 3v12"></path><path d="m17 8-5-5-5 5"></path><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path></svg> 导入密钥</span>
                </div>
            </div>
    
            <div style="${sectionTitleStyle}">
                账号
            </div>
            
            <div class="mt-0 bg-white border-b border-gray-200">
                <div class="m-chat-item text-red-500" onclick="handleLogout()">
                   <span class="flex items-center gap-2 leading-none"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" data-lucide="log-out" aria-hidden="true" class="lucide lucide-log-out w-4 h-4 text-red-500"><path d="m16 17 5-5-5-5"></path><path d="M21 12H9"></path><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path></svg> 注销当前身份</span>
                </div>
                <div class="m-chat-item text-red-600 font-bold" onclick="resetSystem()">
                   <span class="flex items-center gap-2 leading-none"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" data-lucide="alert-triangle" aria-hidden="true" class="lucide lucide-alert-triangle w-4 h-4 text-red-600"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"></path><path d="M12 9v4"></path><path d="M12 17h.01"></path></svg> 系统重置</span>
                </div>
            </div>

            <div style="${sectionTitleStyle}">
                关于
            </div>
            
            <div class="mt-0 bg-white border-b border-gray-200" style="margin-bottom:30px;">
                 
                 <div class="m-chat-item text-gray-700" style="cursor:pointer; text-overflow: ellipsis;" onclick="window.open('https://www.ximi.me/post-6043.html','_blank')">
                    <span class="flex items-center gap-2 leading-none"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" data-lucide="user" aria-hidden="true" class="lucide lucide-user w-4 h-4 text-gray-600"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg> 作者: 希米</span>
                 </div>
                 
                 <div class="m-chat-item text-gray-700">
                   <span class="flex items-center gap-2 leading-none"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" data-lucide="tag" aria-hidden="true" class="lucide lucide-tag w-4 h-4 text-gray-500"><path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"></path><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"></circle></svg> 版本: V1.03</span>
                 </div>

                 <div class="m-chat-item text-blue-500" style="cursor:pointer; text-overflow: ellipsis;" onclick="window.open('https://github.com/xm-nas/ximi-im','_blank')">
                    <span class="text-[15px]" style="cursor:pointer;text-overflow: ellipsis;style=&quot;cursor: pointer; text-overflow: ellipsis;&quot;;style=&quot;cursor: pointer; text-overflow: ellipsis;&quot;;white-space: nowrap;overflow: hidden;color: #374151;">🔗 Github: ximi-im</span>
                </div>

            </div>
            

<div style="background: #ededed;padding: 12px 15px 4px 15px;color: #999f9e;font-size: 14px;margin-top: -30px;">
                状态
            </div>
                            <div class="m-chat-item justify-between" onclick="document.getElementById('m-log-container').classList.toggle('hidden')">
                    <span class="flex items-center gap-2 leading-none"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" data-lucide="file-text" aria-hidden="true" class="lucide lucide-file-text w-4 h-4 text-gray-500"><path d="M6 22a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h8a2.4 2.4 0 0 1 1.704.706l3.588 3.588A2.4 2.4 0 0 1 20 8v12a2 2 0 0 1-2 2z"></path><path d="M14 2v5a1 1 0 0 0 1 1h5"></path><path d="M10 9H8"></path><path d="M16 13H8"></path><path d="M16 17H8"></path></svg> 运行日志</span>
                    <span class="text-gray-400">›</span>
                </div>
            <div class="mt-0 bg-white border-b border-gray-200">
            <div id="m-log-container" class="hidden m-4 rounded border border-gray-800 overflow-hidden shadow-sm" style="height: 500px;">
                <div class="bg-gray-950 px-3 py-2 flex justify-between items-center border-b border-gray-800">
                    <span class="text-gray-500 text-[10px] font-mono tracking-widest">SYSTEM LOG</span>
                    <button onclick="document.getElementById('m-log-box').innerText=''" class="text-gray-400 text-[10px] hover:text-white transition">CLEAR</button>
                </div>
                <div id="m-log-box" class="p-3 bg-gray-900 text-emerald-400 font-mono text-[10px] h-40 overflow-y-auto break-all" style="height: 500px;">
                    日志已初始化...
                </div>
            </div>
        </div>
                </div>
    `;



    // 同步当前设置的选中状态
    const savedInterval = localStorage.getItem('m_pull_interval') || 1000;
    const select = document.getElementById('m-autoPullInterval');
    if (select) select.value = savedInterval;
}
/*
*/
//==============


function checkLatency() {
    const start = Date.now();
    fetch(getApiUrl('ping') || 'https://app.hhqq.net/api.php').then(() => {
        const latency = Date.now() - start;
        const el = document.getElementById('m-latency-val');
        if (el) el.innerText = latency + 'ms';
    }).catch(() => {
        const el = document.getElementById('m-latency-val');
        if (el) el.innerText = '连接超时';
    });
}

function appendLog(module, message) {
    const logBox = document.getElementById('m-log-box'); // 兼容手机端
    if (!logBox) return;

    // 获取当前时间戳
    const timestamp = new Date().toLocaleTimeString();
    const logEntry = `[${timestamp}] ${module}: ${message}\n`;
    
    // 追加日志
    logBox.innerText += logEntry;
    
    // 自动滚动到底部
    logBox.scrollTop = logBox.scrollHeight;

    // --- 核心逻辑：超过 1000 字自动清理 ---
    if (logBox.innerText.length > 1000) {
        logBox.innerText = "[系统提示：日志过长，已自动清理]\n" + logEntry;
    }
}
// ==================== 统一页面加载初始化 ====================
// ==================== 统一页面加载初始化 ====================
window.addEventListener('load', function() {
    // 💡 核心修复：使用你系统中真实的缓存键名 'im_panel_user'
    var hasCache = localStorage.getItem('im_panel_user');
    
    // 恢复保存的间隔设置
    var savedInterval = localStorage.getItem('m_pull_interval') || "1500";
    
    var pcSelect = document.getElementById('autoPullInterval');
    var mSelect = document.getElementById('m-autoPullInterval');
    if (pcSelect) { pcSelect.value = savedInterval; }
    if (mSelect) { mSelect.value = savedInterval; }
    
    // 只有检测到本地有缓存（已登录）时，才启动自动拉取
    if (hasCache) {
        if (typeof window.startAutoPull === 'function') {
            window.startAutoPull(savedInterval);
        }
        console.log("检测到登录状态，自动接收已激活，当前间隔: " + savedInterval + "ms");
    } else {
        console.log("未检测到登录缓存（无痕或首次打开），自动接收保持静默。");
    }
});
// 以后所有原来的 log("安全系统", "...") 都可以替换为:
// appendLog("安全系统", "新密钥对已生成");
// ==================== 移动端专属加密分片文件发送引擎 ====================

/**
 * 唤起手机端原生文件管理器
 */
function mTriggerFileSelect() {
    if (!mActiveTargetId) {
        alert("未检测到活跃对话，请先选择聊天对象！");
        return;
    }
    const mFileInput = document.getElementById('m-file-input');
    if (mFileInput) {
        mFileInput.click();
    }
}

/**
 * 接管文件选择事件，进行底层核心流代理与穿透外发
 */
async function mHandleFileSelect() {
    const mFileInput = document.getElementById('m-file-input');
    if (!mFileInput || !mFileInput.files.length || !mActiveTargetId) return;

    const file = mFileInput.files[0];
    
    // 弹出移动端人性化确认，防止误触高能加密消耗流量
    if (!confirm(`确定要将此文件进行本地全密文分片并发送给对方吗？\n\n📄 文件名: ${file.name}\n⚖️ 大小: ${(file.size / 1024).toFixed(2)} KB`)) {
        mFileInput.value = ''; // 放弃则清空指针
        return;
    }

    // 提取 PC 底层核心输入节点进行全映射代理
    const pcReceiverInput = document.getElementById('receiverInput');
    const pcFileInput = document.getElementById('fileInput');

    if (pcReceiverInput && pcFileInput && typeof sendEncryptedFile === 'function') {
        
        // 1. 同步目标 UID 数据路由
        pcReceiverInput.value = mActiveTargetId;
        
        // 2. 核心穿透：将手机端的 File 资产队列完整复刻给 PC 端物理节点
        pcFileInput.files = mFileInput.files;

        // 3. UI 状态锁定，展示高强度分片上传进度提示
        const inputEl = document.getElementById('m-msg-input');
        const originalPlaceholder = inputEl.placeholder;
        
        inputEl.placeholder = `🔒 [${file.name}] 本地全密文破译分片上传中...`;
        inputEl.disabled = true;

        try {
            // 4. 强力调用 ximi.js 中的核心分片多流上传引擎
            await sendEncryptedFile();
        } catch (error) {
            console.error("移动端异步文件传输崩溃:", error);
            window.log("文件传输异常", error.message);
            alert("传输链路故障，请查看运行日志面板。");
        } finally {
            // 5. 垃圾回收与状态复原
            mFileInput.value = ''; 
            inputEl.placeholder = originalPlaceholder;
            inputEl.disabled = false;
            
            // 6. 瞬间联动重绘消息气泡窗
            if (typeof mRenderChatHistory === 'function') {
                mRenderChatHistory();
            }
        }
    } else {
        alert("系统初始化异常：未检测到 PC 影子节点或核心加密引擎 `sendEncryptedFile`！");
    }
}

// ==================== 移动端手势感知与 Action Sheet 控制引擎 ====================

/**
 * 触控开始：设定 600 毫秒的高精长按时间检测炸弹
 */
function mHandleTouchStart(e, element) {
    mIsLongPressTriggered = false;
    const touch = e.touches[0];
    // 记录初始物理锚点，防止页面滚动时误杀
    mTouchStartPos = { x: touch.clientX, y: touch.clientY };
    
    mTouchTimer = setTimeout(() => {
        mIsLongPressTriggered = true;
        const uid = element.getAttribute('data-uid');
        const name = element.getAttribute('data-name');
        mOpenActionSheet(uid, name);
    }, 600); // 600ms 为微信生态标准长按阈值
}

/**
 * 触控位移：如果用户在滚动屏幕，立刻解除长按定时炸弹
 */
function mHandleTouchMove(e) {
    if (!mTouchTimer) return;
    const touch = e.touches[0];
    // 容差率：若滑动位移超过 10 像素，判定为常规页面滚动，取消长按
    if (Math.abs(touch.clientX - mTouchStartPos.x) > 10 || Math.abs(touch.clientY - mTouchStartPos.y) > 10) {
        clearTimeout(mTouchTimer);
        mTouchTimer = null;
    }
}

/**
 * 触控释放：安全拆除炸弹，如果没有触发长按，则无缝转换为普通点击聊天
 */
function mHandleTouchEnd(e, element) {
    if (mTouchTimer) {
        clearTimeout(mTouchTimer);
        mTouchTimer = null;
    }
    // 穿透判定：非长按状态下，直接唤醒原生聊天会话窗口
    if (!mIsLongPressTriggered) {
        const uid = element.getAttribute('data-uid');
        const name = element.getAttribute('data-name');
        mOpenChat(uid, name);
    }
    mIsLongPressTriggered = false;
}

/**
 * 唤醒 Action Sheet
 */
function mOpenActionSheet(uid, name) {
    mTargetActionUid = uid;
    mTargetActionName = name;
    const sheet = document.getElementById('m-action-sheet');
    const title = document.getElementById('m-action-sheet-title');
    if (sheet && title) {
        title.innerText = `正在管理与 [ ${name} ] 的离线密文历史`;
        sheet.classList.remove('hidden');
    }
}

/**
 * 关闭 Action Sheet
 */
function mCloseActionSheet() {
    const sheet = document.getElementById('m-action-sheet');
    if (sheet) sheet.classList.add('hidden');
    mTargetActionUid = null;
    mTargetActionName = null;
}

/**
 * 核心逻辑 1：安全导出当前对话人的全量聊天记录
 */
function mActionExport() {
    if (!mTargetActionUid) return;
    const myHistory = chatHistory[loggedInUser.id] || {};
    const msgs = myHistory[mTargetActionUid] || [];
    
    if (msgs.length === 0) {
        alert("与当前用户的明文聊天缓冲池为空，无需导出。");
        mCloseActionSheet();
        return;
    }

    // 装配高可读性的标准化备份 JSON
    const exportPayload = {
        app: "Ximi Privacy IM Mobile",
        export_at: new Date().toLocaleString(),
        account_uid: loggedInUser.id,
        chat_partner: {
            uid: mTargetActionUid,
            name: mTargetActionName
        },
        records_count: msgs.length,
        data_stream: msgs
    };

    const blob = new Blob([JSON.stringify(exportPayload, null, 2)], { type: "application/json" });
    const blobUrl = URL.createObjectURL(blob);
    
    const downloadAnchor = document.createElement('a');
    downloadAnchor.style.display = 'none';
    downloadAnchor.href = blobUrl;
    downloadAnchor.download = `ximi_archive_${mTargetActionName}_uid${mTargetActionUid}.json`;
    
    document.body.appendChild(downloadAnchor);
    downloadAnchor.click();
    
    // 垃圾回收
    window.URL.revokeObjectURL(blobUrl);
    document.body.removeChild(downloadAnchor);
    
    mCloseActionSheet();
    window.log("安全管理", `成功向移动端本地导出与 [${mTargetActionName}] 的历史序列`);
}

/**
 * 核心逻辑 2：物理擦除本地缓存中的当前目标会话
 */
function mActionDelete() {
    if (!mTargetActionUid) return;
    
    const confirmMessage = `⚠️ 警告！\n\n确定要彻底清空与 [${mTargetActionName}] 的所有本地明文历史吗？此操作将执行物理扇区覆写，无法恢复！`;
    if (!confirm(confirmMessage)) return;

    if (chatHistory[loggedInUser.id] && chatHistory[loggedInUser.id][mTargetActionUid]) {
        // 执行无痕彻底删除，直接踢出沙箱对象
        delete chatHistory[loggedInUser.id][mTargetActionUid];
    }

    // 联动调用 ximi.js 的统一落盘与渲染调度引擎
    if (typeof saveHistoryToDisk === 'function') {
        saveHistoryToDisk();
    } else {
        localStorage.setItem('im_chat_persisted_history', JSON.stringify(chatHistory));
    }

    // 容错同步：如果手机端现在正好打开着和该用户的聊天气泡窗，强制将其闭合防止界面悬空
    if (mActiveTargetId === parseInt(mTargetActionUid)) {
        mCloseChat();
    }

    mCloseActionSheet();
    
    // 强制全局管道刷新重绘
    if (typeof mLoopRender === 'function') {
        mLoopRender();
    }
    
    window.log("本地擦除", `已物理熔断与 UID:${mTargetActionUid} 的全量对话链路。`);
}


// ==================== 移动端独立消息管理引擎 ==================== new feature for v1.2.0+ ====================
function mOpenMsgManager() {
    document.getElementById('m-msg-manager-page').classList.add('show');
    mRenderMsgManagerList();
}

function mCloseMsgManager() {
    document.getElementById('m-msg-manager-page').classList.remove('show');
}

function mRenderMsgManagerList() {
    const container = document.getElementById('m-msg-manager-list');
    if (!loggedInUser || !chatHistory[loggedInUser.id] || Object.keys(chatHistory[loggedInUser.id]).length === 0) {
        container.innerHTML = '<div class="text-center text-gray-400 text-sm py-12">暂无缓存消息</div>';
        return;
    }
    
    const myHistory = chatHistory[loggedInUser.id];
    let html = '';
    Object.keys(myHistory).forEach(uid => {
        // 安全获取昵称，防止 web.js 里缺少依赖报错
        const name = typeof getUserNicknameById === 'function' ? getUserNicknameById(uid) : `UID: ${uid}`;
        const count = myHistory[uid].length;
        if (count > 0) {
            html += `
                <label class="m-chat-item flex items-center bg-white">
                    <input type="checkbox" class="m-msg-cb mr-4 w-5 h-5" value="${uid}" data-name="${name}">
                    <div class="flex-1 overflow-hidden">
                        <div class="text-[16px] text-[#1a1a1a] truncate">${name}</div>
                        <div class="text-gray-400 text-[13px] mt-1">${count} 条记录</div>
                    </div>
                </label>
            `;
        }
    });
    container.innerHTML = html;
}

function mExportSelectedMsgs() {
    const checked = Array.from(document.querySelectorAll('.m-msg-cb:checked'));
    if (!checked.length) return alert("未勾选目标");
    
    const exportData = { source: "Mobile Web", time: new Date().toLocaleString(), data: {} };
    checked.forEach(cb => { exportData.data[cb.dataset.name] = chatHistory[loggedInUser.id][cb.value]; });
    
    const blob = new Blob([JSON.stringify(exportData, null, 2)], { type: "application/json" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.style.display = 'none'; a.href = url; a.download = "Ximi_Mobile_Archive.json";
    document.body.appendChild(a); a.click();
    URL.revokeObjectURL(url);
}

function mDeleteSelectedMsgs() {
    const checked = Array.from(document.querySelectorAll('.m-msg-cb:checked'));
    if (!checked.length) return alert("未勾选目标");
    if (!confirm(`将永久清除选中的 ${checked.length} 个本地对话，不可逆转。继续？`)) return;
    
    checked.forEach(cb => { delete chatHistory[loggedInUser.id][cb.value]; });
    if(typeof saveHistoryToDisk === 'function') saveHistoryToDisk();
    
    mRenderMsgManagerList();
    if(typeof mLoopRender === 'function') mLoopRender();
}

// ==================== 移动端独立: 顶栏云端列队清理触发器 ====================
// ==================== 移动端独立: 顶栏云端列队清理触发器 ====================
function mOpenServerQueueMenu() {
    const sheet = document.getElementById('m-server-queue-sheet');
    if (sheet) {
        sheet.classList.remove('hidden');
    } else {
        alert("未找到菜单容器！请确保 index.html 中已经添加了 id 为 m-server-queue-sheet 的 HTML 代码。");
    }
}

function mCloseServerQueueMenu() {
    const sheet = document.getElementById('m-server-queue-sheet');
    if (sheet) sheet.classList.add('hidden');
}

async function mClearServerQueue() {
    mCloseServerQueueMenu(); 
    
    if (!loggedInUser) return alert("鉴权失败");
    if (!confirm("⚠️ 彻底销毁云端发给你的全部未读消息流？\n\n注意：此操作将进行物理删除，无法撤回！")) {
        return;
    }
    
    try {
        const baseApi = document.getElementById('apiUrl') ? document.getElementById('apiUrl').value : 'https://app.hhqq.net/api.php';
        const targetUrl = `${baseApi}?action=clear_server_queue`;
        
        const res = await fetch(targetUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ user_id: loggedInUser.id })
        });
        
        const data = await res.json();
        if (data.code === 200) {
            alert("✅ 执行成功：您专属的云端接收池已被全部销毁。");
            if(window.log) window.log("云端管理", "成功销毁滞留列队 (移动端触发)");
        } else {
            alert("清理遇阻: " + data.msg);
        }
    } catch (err) {
        alert("执行链路中断: " + err.message);
    }
}



// 每 3 秒检测一次
setInterval(checkLatency, 3000);