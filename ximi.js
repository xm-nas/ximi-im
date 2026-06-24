// 在 ximi.js 最顶部添加：
(async function() {
    try {
        // 尝试请求一次 api.php，即使没有任何 action，后端也会在最顶部拦截并检查 setting.php
        const response = await fetch('api.php');
        const data = await response.json();
        
        // 如果后端返回 503，说明没有配置文件，强制跳转到安装页面
        if (data.code === 503) {
            window.location.href = data.redirect;
        }
    } catch (e) {
        // 如果网络请求本身失败，或者已经是安装页，则跳过
        console.log("系统状态检测未触发或网络异常");
    }
})();

let loggedInUser = null;
let globalUserList = []; 
let autoPullTimer = null; 
let currentSidebarTab = 'chat'; // 用于标记当前所处视图：'chat'(聊天记录列表) 或 'contact'(全部通讯录)

// 【新增路由持久化状态中心】
// chatHistory 数据结构形如: { "当前登录账号UID": { "好友UID": [ {sender_id, type, text, name, timestamp}, ... ] } }
let chatHistory = {}; 
let currentActiveTargetId = null; // 标记当前正在专注于哪一个用户的聊天历史容器



/**
 * 新增辅助函数：渲染用户信息面板 (点击用户触发)
 */
function showUserInfoPanel(uid) {
    const user = globalUserList.find(u => String(u.id) === String(uid));
    if (!user) return;

    const chatBox = document.getElementById('chatBox'); // 假设这是第三栏容器
    chatBox.innerHTML = `
        <div class="flex flex-col items-center justify-center h-full space-y-4">
            <div class="w-24 h-24 bg-gray-300 rounded-lg flex items-center justify-center text-3xl">
                ${user.nickname ? user.nickname.charAt(0) : 'U'}
            </div>
            <h2 class="text-2xl font-bold">${user.nickname || '未知用户'}</h2>
            <p class="text-gray-500">UID: ${user.id}</p>
            <button onclick="startChatWith('${user.id}')" 
                    class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700">
                开启聊天
            </button>
        </div>
    `;
}

/**
 * 启动聊天逻辑
 */
function startChatWith(uid) {
    currentActiveTargetId = parseInt(uid);
    // 这里调用你原本渲染聊天窗口的逻辑，例如：
    renderActiveContainer(); 
}




function log(title, data) {
    const consoleEl = document.getElementById('logConsole');
    const time = new Date().toLocaleTimeString();
    consoleEl.innerText += `\n[${time}] === ${title} ===\n${typeof data === 'object' ? JSON.stringify(data, null, 2) : data}\n`;
    consoleEl.scrollTop = consoleEl.scrollHeight;
}


//================================================

/**
 * 切换到：聊天记录列表视图 (需求1)
 */
function showChatList() {
    currentSidebarTab = 'chat';
    
    const btnChat = document.getElementById('menuBtnChat');
    const btnContact = document.getElementById('menuBtnContact');
    if (btnChat) btnChat.classList.add('active');
    if (btnContact) btnContact.classList.remove('active');
    
    // 渲染列表（此时会自动走上面的“模式一：按聊天记录过滤”）
    renderUserListDisplay();
}

function showContactList() {
    currentSidebarTab = 'contact';
    
    const btnChat = document.getElementById('menuBtnChat');
    const btnContact = document.getElementById('menuBtnContact');
    if (btnChat) btnChat.classList.remove('active');
    if (btnContact) btnContact.classList.add('active');
    
    // 渲染列表（此时会自动走上面的“模式二：通讯录显示全量”）
    renderUserListDisplay();
}

function showContactList() {
    currentSidebarTab = 'contact';
    
    // 切换左侧侧边栏按钮高亮状态
    const btnChat = document.getElementById('menuBtnChat');
    const btnContact = document.getElementById('menuBtnContact');
    if (btnChat) btnChat.classList.remove('active');
    if (btnContact) btnContact.classList.add('active');
    
    // 重新渲染第二栏列表
    renderUserListDisplay();
}
/**
 * 切换到：全部用户通讯录视图 (需求2)
 */
function showContactList() {
    currentSidebarTab = 'contact';
    
    // 切换左侧侧边栏按钮高亮状态
    const btnChat = document.getElementById('menuBtnChat');
    const btnContact = document.getElementById('menuBtnContact');
    if (btnChat) btnChat.classList.remove('active');
    if (btnContact) btnContact.classList.add('active');
    
    // 重新渲染第二栏列表
    renderUserListDisplay();
}

/**
 * 核心渲染器：根据当前的Tab状态，精细过滤并动态装配第二栏名册
 */
function renderUserListDisplay() {
    // 🛡️ 登录防护：如果当前没有登录用户，直接退出，防止卡死
    if (!loggedInUser) return;
    
    const listContainer = document.getElementById('userListContainer');
    if (!listContainer) return;

    let filteredUsers = [];
    
    if (currentSidebarTab === 'chat') {
        // 【模式一：聊天记录】严格保留你原来的过滤规则：只显示本地有聊天记录的用户
        const myHistory = chatHistory[loggedInUser.id] || {};
        const activeChatIds = Object.keys(myHistory); 
        
        filteredUsers = globalUserList.filter(u => {
            return String(u.id) !== String(loggedInUser.id) && activeChatIds.includes(String(u.id));
        });
        
        if (filteredUsers.length === 0) {
            listContainer.innerHTML = `<div class="text-center text-xs text-gray-400 py-12">暂无聊天记录用户</div>`;
            return;
        }
    } else {
        // 【模式二：通讯录】你的新要求：直接无条件展示所有注册人员（仅排除自己）
        filteredUsers = globalUserList.filter(u => String(u.id) !== String(loggedInUser.id));
        
        if (filteredUsers.length === 0) {
            listContainer.innerHTML = `<div class="text-center text-xs text-gray-400 py-12">暂无其他用户</div>`;
            return;
        }
    }

    // 拼装第二栏 DOM 流
    listContainer.innerHTML = filteredUsers.map(u => {
        const name = u.nickname || u.username;
        const isActive = currentActiveTargetId === parseInt(u.id) ? 'active' : '';
        
        return `
            <div class="user-item ${isActive}" data-uid="${u.id}" onclick="handleUserItemClick('${name}', '${u.id}', this)">
                <div class="w-8 h-8 bg-blue-500 text-white rounded flex items-center justify-center font-bold text-xs flex-shrink-0">${name.substring(0,1).toUpperCase()}</div>
                <div class="flex-1 overflow-hidden">
                    <div class="text-xs font-bold text-gray-800 truncate">${name}</div>
                    <div class="text-[10px] text-gray-500 truncate">UID: ${u.id}</div>
                </div>
            </div>
        `;
    }).join('');
}
/**
 * 统一接管第二栏卡片的点击事件，依据当前左侧所处的Tab进行交互分发
 */
function handleUserItemClick(name, id, element) {
    if (!loggedInUser) return;

    // 1. 刷新卡片高亮
    document.querySelectorAll('.user-item').forEach(item => item.classList.remove('active'));
    if (element) element.classList.add('active');
    
    // 2. 标记当前活跃的对话目标 UID
    currentActiveTargetId = parseInt(id);

    // 3. 🔥【关键修复】：强行让右侧聊天视窗显形，隐藏名片，防止右侧一片空白
    const chatMainContent = document.getElementById('chatMainContent');
    const chatInputArea = document.getElementById('chatInputArea') || document.querySelector('.h-36');
    const userInfoContainer = document.getElementById('userInfoContainer');
    const infoPanel = document.getElementById('userInfoPanel');

    if (chatMainContent) chatMainContent.classList.remove('hidden');
    if (chatInputArea) chatInputArea.classList.remove('hidden');
    if (userInfoContainer) userInfoContainer.classList.add('hidden');
    if (infoPanel) infoPanel.classList.add('hidden');

    // 4. 同步更新输入框和顶部状态条
    const receiverInput = document.getElementById('receiverInput');
    if (receiverInput) receiverInput.value = name;
    
    const currentChatNode = document.getElementById('currentChatNode');
    if (currentChatNode) currentChatNode.innerText = `正在与 [ ${name} ] 进行加密会话`;

    // 5. 如果是第一次在通讯录里点击该用户，顺便初始化它的本地存储槽位
    if (!chatHistory[loggedInUser.id]) {
        chatHistory[loggedInUser.id] = {};
    }
    if (!chatHistory[loggedInUser.id][id]) {
        chatHistory[loggedInUser.id][id] = []; 
    }

    // 6. 🟢 复制原汁原味的数据渲染逻辑：直接读取离线消息并重绘整个消息框
    renderActiveContainer();

    // 7. 手机端适配
    if (window.innerWidth <= 768) {
        setMobileView('chat');
    }
}

/**
 * 新增：在通讯录中点击联系人时触发，负责在第三栏展示名片
 */
function selectContactUser(name, id, element) {
    // 1. 刷新左侧第二栏列表的高亮样式
    document.querySelectorAll('.user-item').forEach(item => item.classList.remove('active'));
    if (element) element.classList.add('active');

    // 2. 隐藏原本的聊天组件（隐藏消息历史容器和输入框）
    // 注：根据你实际的 HTML ID 调整，通常是消息流容器和底部的输入栏
    const chatMessages = document.getElementById('chatMessages'); 
    const chatInputArea = document.getElementById('chatInputArea') || document.querySelector('.h-36'); 
    
    if (chatMessages) chatMessages.classList.add('hidden');
    if (chatInputArea) chatInputArea.classList.add('hidden');

    // 3. 唤醒并渲染右侧名片面板
    const infoPanel = document.getElementById('userInfoPanel');
    if (infoPanel) {
        infoPanel.classList.remove('hidden'); // 显示名片
        
        // 动态注入点击的用户数据
        document.getElementById('infoAvatar').innerText = name.substring(0, 1).toUpperCase();
        document.getElementById('infoNickname').innerText = name;
        document.getElementById('infoUid').innerText = `UID: ${id}`;
        
        // 4. 绑定名片中“发消息”按钮的点击穿透事件
        document.getElementById('infoChatBtn').onclick = function() {
            // A. 触发你现有的“切换到聊天列表”的方法（让左侧第二栏刷新为有记录的用户）
            if (typeof showChatList === 'function') {
                showChatList(); 
            }
            
            // B. 改变全局聚焦的目标 UID 
            currentActiveTargetId = parseInt(id);
            
            // C. 直接调用你原有的 selectUser 方法，进入高强度私密解密聊天视窗
            // 此时名片会隐退，聊天框和输入法会复原
            selectUser(name, id, element);
        };
    }
}



//===============================================









function toggleLogConsole(e) {
    e.stopPropagation();
    const panel = document.getElementById('logConsolePanel');
    const btn = document.getElementById('logToggleBtn');
    if (panel.classList.contains('hidden')) {
        panel.classList.remove('hidden');
        btn.classList.add('color', '#3b82f6');
        btn.style.opacity = "1";
    } else {
        panel.classList.add('hidden');
        btn.style.opacity = "0.6";
    }
}

function toggleWechatMenu(e) {
    e.stopPropagation();
    const menu = document.getElementById('wechatSettingsMenu');
    menu.classList.toggle('hidden');
}
function closeWechatMenu() {
    document.getElementById('wechatSettingsMenu').classList.add('hidden');
}



function syncApiUrl(sourceId, targetId) {
    const sourceInput = document.getElementById(sourceId);
    const targetInput = document.getElementById(targetId);
    if (sourceInput && targetInput) {
        targetInput.value = sourceInput.value;
    }
}



// 使用 DOMContentLoaded 确保所有元素存在后再绑定
document.addEventListener('DOMContentLoaded', () => {
    
    // 1. 全局点击关闭菜单 (使用事件委托机制)
    document.addEventListener('click', (e) => {
        // 如果点击的不是菜单本身，则关闭
        if (typeof closeWechatMenu === 'function') closeWechatMenu();
        if (typeof closeMobileSettingsMenu === 'function') closeMobileSettingsMenu();
        
        const apiPopover = document.getElementById('apiPopover');
        const apiPopover2 = document.getElementById('apiPopover2');
        
        if (apiPopover && !apiPopover.contains(e.target)) apiPopover.classList.add('hidden');
        if (apiPopover2 && !apiPopover2.contains(e.target)) apiPopover2.classList.add('hidden');
    });

    // 2. 绑定点击不关闭事件 (使用可选链 ?. 防止 null 导致的报错)
    // 这里不再直接查找元素，如果元素暂时不存在也不报错
    const persistentElements = [
        'wechatSettingsMenu', 
        'apiPopover', 
        'mobileSettingsMenu', 
        'apiPopover2'
    ];

    persistentElements.forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('click', (e) => e.stopPropagation());
        }
    });

    // 3. API URL 同步逻辑 (保持稳定)
    const apiUrl = document.getElementById('apiUrl');
    const apiUrl2 = document.getElementById('apiUrl2');
    
    if (apiUrl && apiUrl2) {
        apiUrl.addEventListener('input', () => { apiUrl2.value = apiUrl.value; });
        apiUrl2.addEventListener('input', () => { apiUrl.value = apiUrl2.value; });
    }
    
    console.log("ximi.js 事件监听已安全初始化");
});

document.getElementById('wechatSettingsMenu').addEventListener('click', (e) => e.stopPropagation());
document.getElementById('apiPopover').addEventListener('click', (e) => e.stopPropagation());
const mobileSettingsMenu = document.getElementById('mobileSettingsMenu');
if (mobileSettingsMenu) mobileSettingsMenu.addEventListener('click', (e) => e.stopPropagation());
const apiPopover2 = document.getElementById('apiPopover2');
if (apiPopover2) apiPopover2.addEventListener('click', (e) => e.stopPropagation());

// 监听API URL的变化并同步
 document.addEventListener('DOMContentLoaded', () => {
    const apiUrl = document.getElementById('apiUrl');
    const apiUrl2 = document.getElementById('apiUrl2');
    
    if (apiUrl) {
        apiUrl.addEventListener('input', () => {
            if (apiUrl2) apiUrl2.value = apiUrl.value;
        });
    }
    if (apiUrl2) {
        apiUrl2.addEventListener('input', () => {
            if (apiUrl) apiUrl.value = apiUrl2.value;
        });
    }
});

async function pingNetworkNode() {
    const startTime = Date.now();
    const indicator = document.getElementById('netSpeedIndicator');
    const latencyVal = document.getElementById('latencyVal');
    try {
        await fetch(getApiUrl('list_users'), { method: 'HEAD' });
        const rtt = Date.now() - startTime;
        latencyVal.innerText = `${rtt}ms`;
        if (rtt < 500) {
            indicator.className = "flex flex-col items-center justify-center text-[9px] font-mono font-bold text-green-500 transition-colors duration-300";
        } else if (rtt >= 500 && rtt < 2000) {
            indicator.className = "flex flex-col items-center justify-center text-[9px] font-mono font-bold text-yellow-500 transition-colors duration-300";
        } else {
            indicator.className = "flex flex-col items-center justify-center text-[9px] font-mono font-bold text-red-500 transition-colors duration-300";
        }
    } catch (err) {
        latencyVal.innerText = "Error";
        indicator.className = "flex flex-col items-center justify-center text-[9px] font-mono font-bold text-red-600 transition-colors duration-300";
    }
}

function toggleAutoPull() {
    const select1 = document.getElementById('autoPullInterval');
    const select2 = document.getElementById('autoPullInterval2');
    
    // 读取值（从任何一个有效的选择框）
    let val = select1 ? parseInt(select1.value) : 0;
    if (!val && select2) val = parseInt(select2.value);
    
    if (autoPullTimer) {
        clearInterval(autoPullTimer);
        autoPullTimer = null;
        log("全自动同步", "已暂停自动化定时轮询。");
    }
    if (val > 0) {
        if (!loggedInUser) {
            alert("请先登录账户，方能激活自动收取进程！");
            if (select1) select1.value = "0";
            if (select2) select2.value = "0";
            return;
        }
        log("全自动同步", `全自动同步已就绪，轮询周期: ${val}ms`);
        autoPullTimer = setInterval(() => {
            pullMessages(true); 
        }, val);
    }
    
    // 同步两个选择框的值
    if (select1 && select2) {
        select1.value = select2.value = val.toString();
    }
}

function arrayBufferToWordArray(buffer) {
    const uint8Array = new Uint8Array(buffer);
    const words = [];
    for (let i = 0; i < uint8Array.length; i += 4) {
        words.push((uint8Array[i] << 24) | (uint8Array[i+1] << 16) | (uint8Array[i+2] << 8) | uint8Array[i+3]);
    }
    return CryptoJS.lib.WordArray.create(words, uint8Array.length);
}

function wordArrayToArrayBuffer(wordArray) {
    const words = wordArray.words;
    const sigBytes = wordArray.sigBytes;
    const u8 = new Uint8Array(sigBytes);
    for (let i = 0; i < sigBytes; i++) {
        u8[i] = (words[i >>> 2] >>> (24 - (i % 4) * 8)) & 0xff;
    }
    return u8.buffer;
}

function getApiUrl(action) {
    const base = document.getElementById('apiUrl').value;
    return `${base}?action=${action}`;
}

window.addEventListener('DOMContentLoaded', () => {
    const cachedUser = localStorage.getItem('im_panel_user');
    if (cachedUser) {
        loggedInUser = JSON.parse(cachedUser);
        activateLoginState();
    } else {
        // 移动端未登陆时显示认证模块
        showMobileAuthModule();
    }
    pingNetworkNode();
    setInterval(pingNetworkNode, 5000);
});

function activateLoginState() {
    // 1. PC 端鉴权区隐藏
    const authSection = document.getElementById('authSection');
    if (authSection) authSection.classList.add('hidden');

    // 2. 移动端鉴权区隐藏 (使用 -translate-y-full 动画移除)
    const mAuthPage = document.getElementById('m-auth-page');
    if (mAuthPage) mAuthPage.classList.add('-translate-y-full');
    
    // 3. 移除冗余的旧版 mobileAuthModule (如不再需要可删除)
    const mobileAuthModule = document.getElementById('mobileAuthModule');
    if (mobileAuthModule) mobileAuthModule.classList.add('hidden');
    
    // 4. 用户信息渲染 (PC 端)
    const userNick = loggedInUser.nickname || loggedInUser.username;
    const senderNicknameEl = document.getElementById('senderNickname');
    if (senderNicknameEl) senderNicknameEl.value = `✨ ${userNick}`;
    
    const senderIdentityNode = document.getElementById('senderIdentityNode');
    if (senderIdentityNode) senderIdentityNode.innerText = `ID: ${loggedInUser.id} (${userNick})`;
    
// 在页面初始化或用户登录后执行一次即可
// const avatarBox = document.getElementById('avatarBox');
// if (avatarBox && typeof loggedInUser !== 'undefined') {
//     avatarBox.title = `ID: ${loggedInUser.id} (${loggedInUser.nickname || '无昵称'})`;
// }


    const avatarBox = document.getElementById('avatarBox');
    if (avatarBox) avatarBox.innerText = userNick.substring(0,1).toUpperCase();
    
    // 5. 菜单显隐控制
    const logoutMenuRow = document.getElementById('logoutMenuRow');
    if (logoutMenuRow) logoutMenuRow.classList.remove('hidden');
    
    const logoutMenuRow2 = document.getElementById('logoutMenuRow2');
    if (logoutMenuRow2) logoutMenuRow2.classList.remove('hidden');
    
    // 【读取 LocalStorage 沙箱缓存】
    const localStore = localStorage.getItem('im_chat_persisted_history');
    if (localStore) {
        try {
            chatHistory = JSON.parse(localStore);
        } catch(e) { chatHistory = {}; }
    }
    if (!chatHistory[loggedInUser.id]) {
        chatHistory[loggedInUser.id] = {};
    }

    // 6. 触发数据拉取 (适配移动端与 PC 端列表)
    fetchAndRenderReceiverList();
    
    // 如果你在 web.js 中定义了 mLoopRender，这里也可以触发一次刷新
    if (typeof mLoopRender === 'function') {
        mLoopRender();
    }
}



function handleLogout() {
    if(autoPullTimer) clearInterval(autoPullTimer);
    localStorage.removeItem('im_panel_user');
    location.reload();
}




function getUserNicknameById(uid) {
    // 调试：打印一下当前寻找的ID和全量列表
  //  console.log("正在查找 UID:", uid, "全量用户列表:", globalUserList);
    
    const user = globalUserList.find(u => String(u.id) === String(uid));
    return user ? user.nickname : "未知用户";
}


        // 【持久化：将数据存入LocalStorage本地硬盘】
        function saveHistoryToDisk() {
            localStorage.setItem('im_chat_persisted_history', JSON.stringify(chatHistory));
        }

        // 【核心隔离层：根据当前选中的好友，重新绘制消息盒子区域】

function renderActiveContainer() {
    const chatBox = document.getElementById('chatBox');
    
    // 【核心修复】：在清空并重新渲染前，计算当前滚动条是否处于底部区域 (预留 50px 容差)
    const isAtBottom = chatBox.scrollHeight - chatBox.scrollTop <= chatBox.clientHeight + 50;
    
    chatBox.innerHTML = `<div class="text-center text-[11px] text-gray-400 my-1">🛡️ 聊天及流媒体数据均进行本地非对称离线解密，不留存明文于宿主服务器</div>`;
    
    if (!loggedInUser || !currentActiveTargetId) return;

    const myHistory = chatHistory[loggedInUser.id] || {};
    const activePool = myHistory[currentActiveTargetId] || [];

    activePool.forEach(msg => {
        const item = document.createElement('div');
        if (parseInt(msg.sender_id) === parseInt(loggedInUser.id)) {
            // 我发送的
            item.className = "flex flex-col items-end space-y-1";
            item.innerHTML = `
                <div class="bg-blue-600 text-white rounded-lg p-2.5 text-xs max-w-xs shadow-sm"></div>
                <span class="text-[9px] text-gray-400">已加密外外发</span>
            `;
            item.querySelector('.bg-blue-600').textContent = msg.text;
        } else {
            // 对方发来的
            item.className = "flex flex-col items-start space-y-1";
            item.innerHTML = `
                <span class="text-[10px] text-gray-400"></span>
                <div class="bg-white text-gray-800 rounded-lg p-2.5 text-xs max-w-xs shadow-sm border border-gray-200"></div>
            `;
            item.querySelector('span').textContent = getUserNicknameById(msg.sender_id);
            
            const contentDiv = item.querySelector('.bg-white');

            // 💡【核心优化】：如果判定当前消息是文件通知，且带有完整的解密上下文，则将其渲染为可点击的超链接
            if (msg.msg_type === 'file' && msg.file_info) {
                contentDiv.innerHTML = ` 📎 请接收加密文件：<a href="javascript:void(0);" class="text-blue-600 underline font-bold hover:text-blue-800 break-all file-chat-link"></a> `;
                
                const linkEl = contentDiv.querySelector('.file-chat-link');
                linkEl.textContent = msg.file_info.originName; // 安全插入文件名，杜绝恶意脚本注入
                
                // 绑定点击穿透事件：点击聊天记录里的文件名直接触发核心安全下发解密机制
                linkEl.addEventListener('click', (e) => {
                    e.preventDefault();
                    downloadAndDecryptChunks(
                        msg.file_info.dirId,
                        msg.file_info.originName,
                        msg.file_info.totalChunks,
                        msg.file_info.encryptedAesKey,
                        msg.file_info.iv,
                        msg.file_info.messageId
                    );
                });
            } else {
                // 普通文本消息保持原生 textContent 渲染，确保绝对安全
                contentDiv.textContent = msg.text;
            }
        }
        chatBox.appendChild(item);
    });
    
    // 【核心修复】：仅在用户原本就在底部（或初始加载、消息极少未超出视窗）时，才触发强制探底
    if (isAtBottom) {
        chatBox.scrollTop = chatBox.scrollHeight;
    }
}

//======================

        // 【路由重算：当输入框打字变动时同步匹配路由】
        function handleReceiverInput() {
            const inputVal = document.getElementById('receiverInput').value;
            const targetId = resolveReceiverId(inputVal);
            
            // 取消当前名册中所有高亮
            document.querySelectorAll('.user-item').forEach(item => item.classList.remove('active'));
            
            if (targetId) {
                currentActiveTargetId = targetId;
                document.getElementById('currentChatNode').innerText = `正在与 [ ${inputVal} ] 进行加密会话`;
                // 如果刚好有名单对应的DOM卡片，顺便挂载高亮
                const matchedCard = document.querySelector(`.user-item[data-uid="${targetId}"]`);
                if (matchedCard) matchedCard.classList.add('active');
            } else {
                currentActiveTargetId = null;
                document.getElementById('currentChatNode').innerText = `Privacy IM 节点控制中心`;
            }
            renderActiveContainer();
        }


// 统一控制高亮激活态的切换逻辑
function selectUser(name, id, element) {
    // === 【新增状态复原补丁】 ===
    // 隐藏用户信息名片面板
    const infoPanel = document.getElementById('userInfoPanel');
    if (infoPanel) infoPanel.classList.add('hidden');
    
    // 重新展现聊天消息视窗和输入框
    const chatMessages = document.getElementById('chatMessages');
    const chatInputArea = document.getElementById('chatInputArea') || document.querySelector('.h-36');
    if (chatMessages) chatMessages.classList.remove('hidden');
    if (chatInputArea) chatInputArea.classList.remove('hidden');

    document.getElementById('receiverInput').value = name;
    document.getElementById('currentChatNode').innerText = `正在与 [ ${name} ] 进行加密会话`;
    
    document.querySelectorAll('.user-item').forEach(item => item.classList.remove('active'));
    element.classList.add('active');

    // 【切换当前活跃容器标识】
    currentActiveTargetId = parseInt(id);
    renderActiveContainer();

    // 专属自适应逻辑：当手机端点击好友卡片时，瞬间进入全屏单对单精细聊天室
    if (window.innerWidth <= 768) {
        setMobileView('chat');
    }
}
    
    
async function fetchAndRenderReceiverList() {
    try {
        const res = await fetch(getApiUrl('list_users'));
        const json = await res.json();
        if (json.code === 200) {
            globalUserList = json.data;
            
            // 安全更新原生下拉数据源
            const receiverDataList = document.getElementById('receiverDataList');
            if (receiverDataList) {
                receiverDataList.innerHTML = json.data
                    .filter(u => u.id !== loggedInUser.id)
                    .map(u => `<option value="${u.nickname || u.username}">ID: ${u.id}</option>`).join('');
            }
            
            // 🟢 核心解耦：交由独立渲染器，根据当前是“聊天”还是“通讯录”来绘制列表
            renderUserListDisplay();

            // 获取名单后重新渲染一次（此时能正确把UID翻译成昵称）
            renderActiveContainer();
        }
    } catch (err) { 
        log("名单获取失败", err.message); 
    }
}

function getOrGenerateKeys() {
    let pubKey = localStorage.getItem('my_pub_key');
    let privKey = localStorage.getItem('my_priv_key');
    if (!pubKey || !privKey) {
        log("安全系统", "未检测到本地密钥，正在生成全新 RSA 密钥对...");
        const encrypt = new JSEncrypt({ default_key_size: 2048 });
        pubKey = encrypt.getPublicKey();
        privKey = encrypt.getPrivateKey();
        localStorage.setItem('my_pub_key', pubKey);
        localStorage.setItem('my_priv_key', privKey);
        log("安全系统", "新密钥对已安全保存在本地。");
    } else {
        log("安全系统", "检测到本地已有身份密钥，直接使用。");
    }
    return { publicKey: pubKey, privateKey: privKey };
}

async function handleRegister() {
    const username = document.getElementById('username').value.trim();
    const password = document.getElementById('password').value.trim();
    const nickname = document.getElementById('nickname').value.trim();
    if (!username || !password) { alert("请输入用户名和密码！"); return; }
    try {
        const keys = getOrGenerateKeys();
        const res = await fetch(getApiUrl('register'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ username, password, nickname, public_key: keys.publicKey })
        });
        const result = await res.json();
        log("注册结果", result);
        if (result.code === 200) { alert("注册成功！公钥已上传。"); } else { alert("注册失败: " + result.msg); }
    } catch (err) { log("注册崩溃", err.message); }
}

// async function handleLogin() {
//     const username = document.getElementById('username').value;
//     const password = document.getElementById('password').value;
//     try {
//         const res = await fetch(getApiUrl('login'), {
//             method: 'POST',
//             headers: { 'Content-Type': 'application/json' },
//             body: JSON.stringify({ username, password })
//         });
//         const data = await res.json();
//         if (data.code === 200) {
//             loggedInUser = data.data;
//             localStorage.setItem('im_panel_user', JSON.stringify(loggedInUser));
//             activateLoginState();
//         }
//         log("登录结果", data);
//     } catch (err) { log("登录失败", err.message); }
// }

async function handleLogin() {
    const username = document.getElementById('username').value;
    const password = document.getElementById('password').value;
    try {
        const res = await fetch(getApiUrl('login'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ username, password })
        });
        const data = await res.json();
        
        if (data.code === 200) {
            // 1. 赋值全局变量
            loggedInUser = data.data;
            // 2. 保存缓存
            localStorage.setItem('im_panel_user', JSON.stringify(loggedInUser));
            
            // 3. 执行原有登录状态激活逻辑
            activateLoginState();
            
            // 4. 【新增】安全执行悬浮框初始化
            initAvatarTooltip();
        }
        log("登录结果", data);
    } catch (err) { 
        log("登录失败", err.message); 
    }
}

/**
 * 页面加载完成或登录成功后调用的 UI 初始化函数
 */
// 在 ximi.js 中定义此函数
// function initAvatarTooltip() {
//     const avatarBox = document.getElementById('avatarBox');
    
//     // 增加调试日志，查看是否找到了元素
//     if (!avatarBox) {
//         console.log("调试：未找到 id='avatarBox' 的元素，请检查 HTML 结构");
//         return;
//     }

//     if (typeof loggedInUser !== 'undefined' && loggedInUser && loggedInUser.id) {
//         const nickname = loggedInUser.nickname || '无昵称';
//         avatarBox.title = `ID: ${loggedInUser.id} (${nickname})`;
//         console.log("调试：已成功绑定悬浮提示到 avatarBox");
//     } else {
//         console.log("调试：loggedInUser 尚未初始化，跳过绑定");
//     }
// }
/**
 * 升级版：零延迟、秒瞬显的头像悬浮提示
 */
/**
 * 终极、完美的零延迟、秒瞬显头像悬浮提示
 * 彻底解决两层标签重叠显示的问题
 */

/**
 * 修正版：彻底消灭 "null" 顶层标签的零延迟悬浮提示
 */

//function initAvatarTooltip() {
//     const avatarBox = document.getElementById('avatarBox');
//     if (!avatarBox) return;

//     // 1. 初始化时彻底拔掉 title 属性
//     avatarBox.removeAttribute('title');

//     if (typeof loggedInUser !== 'undefined' && loggedInUser && loggedInUser.id) {
//         const nickname = loggedInUser.nickname || '无昵称';
//         const tooltipText = `ID: ${loggedInUser.id} (${nickname})`;

//         // 2. 动态创建或获取自定义提示框
//         let tooltip = document.getElementById('m-fast-tooltip');
//         if (!tooltip) {
//             tooltip = document.createElement('div');
//             tooltip.id = 'm-fast-tooltip';
//             tooltip.style.position = 'fixed';
//             tooltip.style.backgroundColor = '#4b5563'; 
//             tooltip.style.color = '#ffffff';
//             tooltip.style.padding = '6px 10px';
//             tooltip.style.borderRadius = '2px';
//             tooltip.style.fontSize = '12px';
//             tooltip.style.boxShadow = '0 10px 15px -3px rgba(0, 0, 0, 0.3)';
//             tooltip.style.zIndex = '99999';
//             tooltip.style.display = 'none'; 
//             tooltip.style.pointerEvents = 'none'; 
//             tooltip.style.whiteSpace = 'nowrap';
//             document.body.appendChild(tooltip);
//         }

//         tooltip.innerText = tooltipText;

//         // 3. 鼠标移入：不仅显示自定义框，而且死死卡住原生 title
//         avatarBox.addEventListener('mouseenter', () => {
//             // 🚨 核心修复：直接设为空字符串，或者直接移除。这样浏览器绝对不会弹窗
//             avatarBox.title = ""; 
//             avatarBox.removeAttribute('title');
            
//             tooltip.style.display = 'block';
//         });

//         // 4. 鼠标移动
//         avatarBox.addEventListener('mousemove', (e) => {
//             tooltip.style.left = (e.clientX + 12) + 'px';
//             tooltip.style.top = (e.clientY + 12) + 'px';
//         });

//         // 5. 鼠标移出
//         avatarBox.addEventListener('mouseleave', () => {
//             avatarBox.title = "";
//             tooltip.style.display = 'none';
//         });
//     }
// }

/**
 * 升级版：多功能零延迟悬浮提示中心
 * 集成了头像、聊天记录、通讯录、运行日志四个按钮
 */
function initAvatarTooltip() {
    // 1. 确保全局唯一的自定义提示框存在（共享同一个提示框，节省内存）
    let tooltip = document.getElementById('m-fast-tooltip');
    if (!tooltip) {
        tooltip = document.createElement('div');
        tooltip.id = 'm-fast-tooltip';
        tooltip.style.position = 'fixed';
        tooltip.style.backgroundColor = 'rgba(70, 73, 79, 0.9)'; // 深色高档背景
        tooltip.style.color = '#ffffff';
        tooltip.style.padding = '6px 10px';
        tooltip.style.borderRadius = '3px';
        tooltip.style.fontSize = '12px';
        tooltip.style.boxShadow = '0 10px 15px -3px rgba(0, 0, 0, 0.3)';
        tooltip.style.zIndex = '99999';
        tooltip.style.display = 'none'; 
        tooltip.style.pointerEvents = 'none'; // 鼠标穿透，防止卡顿
        tooltip.style.whiteSpace = 'nowrap';
        document.body.appendChild(tooltip);
    }

    // 2. 定义所有需要悬浮提示的配置列表（包含动态获取和写死文本）
    const tooltipConfig = [
        {
            id: 'avatarBox',
            getText: () => {
                if (typeof loggedInUser !== 'undefined' && loggedInUser && loggedInUser.id) {
                    // return `ID: ${loggedInUser.id} (${loggedInUser.nickname || '无昵称'})`;
                       return `${loggedInUser.nickname || '无昵称'} (UID: ${loggedInUser.id})`;
                }
                return null; // 未登录时不显示
            }
        },
        { id: 'menuBtnChat', getText: () => '聊天记录' },
        { id: 'menuBtnContact', getText: () => '通讯录' },
        { id: 'logToggleBtn', getText: () => '运行日志' }
    ];

    // 3. 循环遍历配置，为每个存在的元素绑定事件
    tooltipConfig.forEach(item => {
        const el = document.getElementById(item.id);
        if (!el) return; // 防御性编程：如果当前页面找不到该按钮，直接跳过，不报错

        // 彻底拔掉原生 title 属性，防止双层标签重叠
        el.removeAttribute('title');

        // 鼠标移入：0ms 瞬间触发
        el.addEventListener('mouseenter', () => {
            const text = item.getText();
            if (!text) return; // 如果没有文本内容，不显示提示框

            // 再次拦截，死死卡住可能被动态写回的原生 title
            el.title = ""; 
            el.removeAttribute('title'); 
            
            tooltip.innerText = text;
            tooltip.style.display = 'block';
        });

        // 鼠标移动：提示框紧跟鼠标指针
        el.addEventListener('mousemove', (e) => {
            tooltip.style.left = (e.clientX + 12) + 'px';
            tooltip.style.top = (e.clientY + 12) + 'px';
        });

        // 鼠标移出：瞬间隐藏
        el.addEventListener('mouseleave', () => {
            el.title = "";
            tooltip.style.display = 'none';
        });
    });
}






// 确保 DOM 加载完成后尝试绑定
window.addEventListener('DOMContentLoaded', () => {
    initAvatarTooltip();
});

// 在 ximi.js 中使用事件委托，不需要频繁绑定
document.addEventListener('mouseover', function(e) {
    // 检查鼠标划过的是不是 avatarBox
    if (e.target && e.target.id === 'avatarBox') {
        if (typeof loggedInUser !== 'undefined' && loggedInUser) {
            e.target.title = `ID: ${loggedInUser.id} (${loggedInUser.nickname || '无昵称'})`;
        }
    }
});

async function sendEncryptedText() {
    const receiver_id = resolveReceiverId(document.getElementById('receiverInput').value);
    const text = document.getElementById('msgText').value;
    if (!receiver_id || !text) { alert("请检查接收者和内容码流"); return; }
    try {
        log("正在获取接收者公钥...");
        const pubKeyRes = await fetch(getApiUrl('get_public_key') + `&user_id=${receiver_id}`);
        const pubKeyData = await pubKeyRes.json();
        if (pubKeyData.code !== 200 || !pubKeyData.data.public_key) { alert("对方未上传公钥！"); return; }
        
        const aesKey = CryptoJS.lib.WordArray.random(32).toString(CryptoJS.enc.Hex);
        const iv = CryptoJS.lib.WordArray.random(16).toString(CryptoJS.enc.Hex);
        const encryptedContent = CryptoJS.AES.encrypt(text, CryptoJS.enc.Hex.parse(aesKey), {
            iv: CryptoJS.enc.Hex.parse(iv), mode: CryptoJS.mode.CBC, padding: CryptoJS.pad.Pkcs7
        }).toString();

        const encryptor = new JSEncrypt();
        encryptor.setPublicKey(pubKeyData.data.public_key);
        const encryptedAesKey = encryptor.encrypt(aesKey);

        const payload = {
            sender_id: parseInt(loggedInUser.id), receiver_id, msg_type: "text",
            encrypt_iv: iv, encrypted_aes_key: encryptedAesKey, encrypted_content: encryptedContent
        };
        const res = await fetch(getApiUrl('send_message'), {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)
        });
        const result = await res.json();
        if (result.code === 200) { 
            // 【将自己发出的内容归档进特定的独立隔离池】
            if (!chatHistory[loggedInUser.id][receiver_id]) {
                chatHistory[loggedInUser.id][receiver_id] = [];
            }
            chatHistory[loggedInUser.id][receiver_id].push({
                sender_id: loggedInUser.id,
                text: text
            });
            saveHistoryToDisk();

            // 清空输入区并重新渲染受载容器
            document.getElementById('msgText').value = ''; 
            renderActiveContainer();
        }
    } catch (err) { log("发送失败", err.message); }
}

function updateFileIndicator(input) {
    const indicator = document.getElementById('fileIndicator');
    const btn = document.getElementById('uploadFileBtn');
    if(input.files.length > 0) {
        indicator.innerText = input.files[0].name;
        btn.classList.remove('hidden');
    } else {
        indicator.innerText = "";
        btn.classList.add('hidden');
    }
}

async function sendEncryptedFile() {
    const fileInput = document.getElementById('fileInput');
    const receiver_id = resolveReceiverId(document.getElementById('receiverInput').value);
    if (!fileInput.files.length || !receiver_id) { alert("请选择文件并输入接收者"); return; }
    const file = fileInput.files[0];
    log("📂 正在拉取接收者公钥...", file.name);

    try {
        const pubKeyRes = await fetch(getApiUrl('get_public_key') + `&user_id=${receiver_id}`);
        const pubKeyData = await pubKeyRes.json();
        if (pubKeyData.code !== 200 || !pubKeyData.data.public_key) { alert("获取接收者公钥失败！"); return; }
        const receiverPublicKey = pubKeyData.data.public_key;

        log("🔒 正在本地读取并进行全密文加密...", file.name);
        const reader = new FileReader();
        reader.readAsArrayBuffer(file);
        reader.onload = async (e) => {
            const arrayBuffer = e.target.result;
            const wordArray = arrayBufferToWordArray(arrayBuffer);
            const aesKey = CryptoJS.lib.WordArray.random(32).toString(CryptoJS.enc.Hex);
            const iv = CryptoJS.lib.WordArray.random(16).toString(CryptoJS.enc.Hex);

            const encryptedBase64 = CryptoJS.AES.encrypt(wordArray, CryptoJS.enc.Hex.parse(aesKey), {
                iv: CryptoJS.enc.Hex.parse(iv), mode: CryptoJS.mode.CBC, padding: CryptoJS.pad.Pkcs7
            }).toString();

            const dirId = CryptoJS.lib.WordArray.random(16).toString(CryptoJS.enc.Hex); 
            const CHUNK_SIZE = 512 * 1024; 
            const totalLength = encryptedBase64.length;
            let index = 0; let chunkIndex = 0;
            const chunksInfo = [];

            while (index < totalLength) {
                const chunkText = encryptedBase64.substring(index, index + CHUNK_SIZE);
                const chunkName = `chunk_${chunkIndex}.enc`;
                const chunkBlob = new Blob([chunkText], { type: 'text/plain' });
                const chunkFormData = new FormData();
                chunkFormData.append('file', chunkBlob);
                chunkFormData.append('chunk_name', chunkName);

                const chunkRes = await fetch(`api.php?action=upload_chunk&id=${dirId}`, {
                    method: 'POST', body: chunkFormData
                });
                
                // === 【修改点：解析 JSON 并拦截 403 错误】 ===
                const chunkData = await chunkRes.json();
                if (chunkData.code === 403) {
                    alert(chunkData.msg || "⚠️ 上传通道已关闭或操作被拒绝！");
                    log("❌ 上传被拦截", chunkData.msg);
                    fileInput.value = ''; // 清空选择的文件
                    return; // 立即终止循环和后续发送逻辑
                }
                if (chunkData.code !== 200) {
                    throw new Error(`切片 ${chunkIndex} 上传失败: ${chunkData.msg || '未知错误'}`);
                }
                // =============================================

                chunksInfo.push({ index: chunkIndex, name: chunkName });
                index += CHUNK_SIZE; chunkIndex++;
            }

            const manifestFormData = new FormData();
            manifestFormData.append('id', dirId);
            manifestFormData.append('manifest', JSON.stringify({ chunks: chunksInfo }));
            await fetch(`api.php?action=save_manifest`, { method: 'POST', body: manifestFormData });

            const encryptor = new JSEncrypt();
            encryptor.setPublicKey(receiverPublicKey);
            const encryptedAesKey = encryptor.encrypt(aesKey);
            const contentText = btoa(encodeURIComponent(file.name) + '|' + dirId + '|' + chunkIndex);

            const payload = {
                sender_id: parseInt(loggedInUser.id), receiver_id: receiver_id, msg_type: "file",
                encrypt_iv: iv, encrypted_aes_key: encryptedAesKey, encrypted_content: contentText 
            };

            const res = await fetch(getApiUrl('send_message'), {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (data.code === 200) {
                log("✅ 加密文件外发就绪", data.msg);
                fileInput.value = '';
                updateFileIndicator(fileInput);
                alert("文件已安全加密分片并发送！");
            }
        };
    } catch (err) { log("网络链路崩溃", err.message); }
}



async function pullMessages(isSilent = false) {
    if (!loggedInUser) return;
    const url = `${getApiUrl('pull_messages')}&user_id=${loggedInUser.id}`;
    if(!isSilent) log(`🔄 链路提取中...`, `正在同步离线缓冲队列...`);

    try {
        const res = await fetch(url);
        const data = await res.json();
        const fileList = document.getElementById('fileList');

        if (data.code === 200) {
            if (!data.data || data.data.length === 0) { 
                if(!isSilent) log("📭 状态", "消息箱干净，没有新离线消息。"); 
                return; 
            }

            data.data.forEach((msg) => {
                try {
                    // 将昵称解析逻辑提到顶部，供文件和文本类型统一使用
                    const senderIdStr = String(msg.sender_id);
                    const senderUser = typeof globalUserList !== 'undefined' ? globalUserList.find(u => String(u.id) === String(senderIdStr)) : null;
                    const senderName = senderUser ? (senderUser.nickname || `UID:${senderIdStr}`) : `UID:${senderIdStr}`;

                    if (msg.msg_type === 'file') {
                        const rawData = atob(msg.encrypted_content);
                        const parts = rawData.split('|');
                        const originName = decodeURIComponent(parts[0]);
                        const dirId = parts[1]; 
                        const totalChunks = parts[2] ? parseInt(parts[2]) : 0;

                        // 防止重复处理：如果该分片资产节点已存在，则不重复写入右侧面板和聊天记录
                        if (document.getElementById(`file-node-${dirId}`)) return;

                        const item = document.createElement('div');
                        item.id = `file-node-${dirId}`;
                        item.className = "flex items-center justify-between p-2 bg-purple-100 rounded border border-purple-200 text-xs";
                        item.innerHTML = `
                            <div class="file-title truncate font-bold text-purple-900 mr-2 max-w-[140px]"></div>
                            <button class="download-btn bg-purple-600 text-white px-2 py-0.5 rounded text-[10px] hover:bg-purple-700 transition">
                                安全下发解密
                            </button>
                        `;

                        item.querySelector('.file-title').textContent = originName;
                        item.querySelector('.download-btn').addEventListener('click', () => {
                            downloadAndDecryptChunks(dirId, originName, totalChunks, msg.encrypted_aes_key, msg.encrypt_iv, msg.id);
                        });

                        if(fileList.querySelector('div.italic')) fileList.innerHTML = '';
                        fileList.appendChild(item);

                        // 输出到控制台/日志面板
                        log(`📎 收到[${senderName}]的远端加密文件`, originName);
                        
                        // 💡【优化】：将文件接收资产通知及解密核心元数据，同步归档到聊天气泡历史中
                        const sender_id = msg.sender_id;
                        if (!chatHistory[loggedInUser.id][sender_id]) {
                            chatHistory[loggedInUser.id][sender_id] = [];
                        }
                        chatHistory[loggedInUser.id][sender_id].push({
                            sender_id: sender_id,
                            msg_type: 'file', // 标记这条消息是文件资产类型
                            text: ` 📎 收到加密文件：${originName} `, // 兜底文本
                            file_info: { // 注入完整的解密上下文参数
                                dirId: dirId,
                                originName: originName,
                                totalChunks: totalChunks,
                                encryptedAesKey: msg.encrypted_aes_key,
                                iv: msg.encrypt_iv,
                                messageId: msg.id
                            }
                        });
                        saveHistoryToDisk(); // 持久化到本地缓存
                        
                    } else {
                        const privKey = localStorage.getItem('my_priv_key');
                        if (!privKey) throw new Error("缺失私钥无法破译内容");
                        const decryptor = new JSEncrypt();
                        decryptor.setPrivateKey(privKey);
                        const aesKeyHex = decryptor.decrypt(msg.encrypted_aes_key);
                        if (!aesKeyHex) throw new Error("密钥解密失败");

                        const decryptedText = CryptoJS.AES.decrypt(msg.encrypted_content, CryptoJS.enc.Hex.parse(aesKeyHex), {
                            iv: CryptoJS.enc.Hex.parse(msg.encrypt_iv), mode: CryptoJS.mode.CBC, padding: CryptoJS.pad.Pkcs7
                        }).toString(CryptoJS.enc.Utf8);
                        
                        // 动态输出带有昵称的文本日志
                        log(`✨ 收到[${senderName}]文本消息`, decryptedText);

                        // 将拉取到的新消息，归档到发送人对应的隔离隔离池
                        const sender_id = msg.sender_id;
                        if (!chatHistory[loggedInUser.id][sender_id]) {
                            chatHistory[loggedInUser.id][sender_id] = [];
                        }
                        chatHistory[loggedInUser.id][sender_id].push({
                            sender_id: sender_id,
                            msg_type: 'text',
                            text: decryptedText
                        });
                        saveHistoryToDisk();
                    }
                } catch (err) { log("❌ 解密链路捕获异常", `错误: ${err.message}`); }
            });

            // 所有离线包处理完毕后，触发统一刷新当前视窗
            await fetchAndRenderReceiverList();
            renderActiveContainer();
        }
    } catch (err) { if(!isSilent) log("❌ 同步故障", err.message); }
}


        async function downloadAndDecryptChunks(dirId, fileName, totalChunks, encryptedAesKey, iv, messageId) {
            log("📥 正在准备安全拉取文件分片...", fileName);
            try {
                const res = await fetch(`api.php?action=get_manifest&id=${dirId}`);
                if (!res.ok) throw new Error("获取分片清单网响应失败");
                const manifest = await res.json();
                
                if (!manifest || manifest.code === 404 || !manifest.chunks) {
                    throw new Error("文件清单不存在或已被阅后即焚销毁。");
                }
                
                const chunks = manifest.chunks.sort((a, b) => a.index - b.index);
                let encryptedBase64 = "";
                for (let c of chunks) {
                    const chunkRes = await fetch(`uploads/${dirId}/${c.name}`);
                    if (!chunkRes.ok) throw new Error(`分流下载失败`);
                    encryptedBase64 += await chunkRes.text();
                }

                const privKey = localStorage.getItem('my_priv_key');
                const decryptor = new JSEncrypt();
                decryptor.setPrivateKey(privKey);
                const aesKeyHex = decryptor.decrypt(encryptedAesKey); 

                const decrypted = CryptoJS.AES.decrypt(encryptedBase64, CryptoJS.enc.Hex.parse(aesKeyHex), {
                    iv: CryptoJS.enc.Hex.parse(iv), mode: CryptoJS.mode.CBC, padding: CryptoJS.pad.Pkcs7
                });
                
                const arrayBuffer = wordArrayToArrayBuffer(decrypted);
                const finalBlob = new Blob([arrayBuffer]);
                const blobUrl = window.URL.createObjectURL(finalBlob);
                
                const a = document.createElement('a');
                a.style.display = 'none'; a.href = blobUrl; a.download = fileName; 
                document.body.appendChild(a); a.click();
                window.URL.revokeObjectURL(blobUrl); document.body.removeChild(a);
                log("✅ 成功解密并还原保存文件", fileName);

               // await fetch(`api.php?action=delete_files&id=${dirId}`);
                await fetch(`api.php?action=delete_files&user_id=${loggedInUser.id}&id=${dirId}&message_id=${msgId}`);
                log("🧹 服务器物理分片已彻底执行无痕焚毁", `目录ID: ${dirId}`);
                
                const node = document.getElementById(`file-node-${dirId}`);
                if(node) node.remove();
                if(document.getElementById('fileList').children.length === 0) {
                    document.getElementById('fileList').innerHTML = '<div class="text-[11px] text-purple-400 italic">暂无流式缓冲分片。</div>';
                }
            } catch (e) { log("❌ 分片下载流故障", e.message); }
        }

        function exportIdentityKeys() {
            const pubKey = localStorage.getItem('my_pub_key');
            const privKey = localStorage.getItem('my_priv_key');
            if (!pubKey || !privKey) { alert("本地尚未生成密钥！"); return; }
            const keyData = {
                username: loggedInUser ? loggedInUser.username : "未登录用户",
                publicKey: pubKey, privateKey: privKey, export_time: new Date().toLocaleString()
            };
            const blob = new Blob([JSON.stringify(keyData, null, 2)], { type: "application/json" });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.style.display = 'none'; a.href = url; a.download = "privacy_identity.json";
            document.body.appendChild(a); a.click();
            window.URL.revokeObjectURL(url); document.body.removeChild(a);
        }

        function triggerKeyImport() { document.getElementById('importKeyInput').click(); }

        function handleKeyImport(event) {
            const file = event.target.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = function(e) {
                try {
                    const data = JSON.parse(e.target.result);
                    localStorage.setItem('my_pub_key', data.publicKey);
                    localStorage.setItem('my_priv_key', data.privateKey);
                    alert("✅ 安全身份恢复导入成功！");
                    location.reload(); 
                } catch (err) { alert("导入失败！文件格式错误。"); }
            };
            reader.readAsText(file);
        }

        function resetSystem() {
            if (!confirm("确定要物理销毁本地缓存并重置系统链路吗？")) return;
            localStorage.clear();
            location.reload();
        }

        /* ==================== 拖拽底层引擎机制 ==================== */
        const win = document.getElementById('wechatWindow');
        const handles = document.querySelectorAll('.drag-handle');
        let isDragging = false; let startX, startY; let initialLeft, initialTop;

        function initPosition() {
            const rect = win.getBoundingClientRect();
            win.style.transform = 'none';
            win.style.left = rect.left + 'px'; win.style.top = rect.top + 'px';
        }

        handles.forEach(handle => {
            handle.addEventListener('mousedown', (e) => {
                // 如果是移动端视图，直接禁止桌面窗体拖拽引擎，防止发生位移闪烁
                if (window.innerWidth <= 768) return;

                if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'BUTTON' || e.target.tagName === 'SELECT' || e.target.closest('#apiPopover') || e.target.closest('#wechatSettingsMenu')) {
                    return;
                }
                isDragging = true;
                if (win.style.transform !== 'none') initPosition();
                startX = e.clientX; startY = e.clientY;
                initialLeft = parseFloat(win.style.left); initialTop = parseFloat(win.style.top);
                document.addEventListener('mousemove', onMouseMove);
                document.addEventListener('mouseup', onMouseUp);
            });
        });

        function onMouseMove(e) {
            if (!isDragging) return;
            win.style.left = (initialLeft + (e.clientX - startX)) + 'px';
            win.style.top = (initialTop + (e.clientY - startY)) + 'px';
        }

        function onMouseUp() {
            isDragging = false;
            document.removeEventListener('mousemove', onMouseMove);
            document.removeEventListener('mouseup', onMouseUp);
        }

/* ==================== 专属自适应移动端高级控制器 ==================== */



/**
 * 核心补丁：用于解析当前消息的收件人ID
 */
/**
 * 核心补丁：用于解析当前消息的收件人ID
 */
function resolveReceiverId(inputVal) {
    // 逻辑 1：优先从界面选中的当前聊天对象中获取
    if (currentActiveTargetId) {
        return currentActiveTargetId;
    }
    
    // 逻辑 2：备选方案，如果界面没有选中，尝试从参数或 receiverInput 输入框获取并解析
    const val = (inputVal || document.getElementById('receiverInput')?.value || '').trim();
    if (val) {
        // 如果输入的是纯数字 UID
        if (/^\d+$/.test(val)) {
            return parseInt(val);
        }
        // 如果输入的是昵称或用户名，则从全量用户列表中查找对应的 ID
        const user = globalUserList.find(u => u.nickname === val || u.username === val);
        if (user) {
            return user.id;
        }
    }
    
    console.error("无法解析收件人 ID：当前未选中任何聊天对象，且输入框为空或找不到对应用户。");
    return null;
}

(function(){

  function fixVH(){
    let vh = window.innerHeight * 0.01;
    document.documentElement.style.setProperty('--vh', vh + 'px');
  }

  fixVH();

  window.addEventListener('resize', fixVH);
  window.addEventListener('orientationchange', fixVH);

})();

//window.addEventListener==========
// ==================== 统一页面加载与高级鉴权初始化 ====================
window.addEventListener('load', async () => {
    // 1. 获取本地缓存
    const cachedUserStr = localStorage.getItem('im_panel_user');
    
    // 2. 锁定双端登录面板节点
    const authSection = document.getElementById('authSection'); // PC端鉴权区
    const mAuthPage = document.getElementById('m-auth-page');   // 移动端鉴权区

    if (cachedUserStr) {
        try {
            const tempUser = JSON.parse(cachedUserStr);
            console.log("检测到本地缓存，正在向服务器严格核验账号存活性...");

            // 【核心修复】：巧妙利用 get_public_key 接口来探测账号是否在云端被删
            const res = await fetch(getApiUrl('get_public_key') + `&user_id=${tempUser.id}`);
            const data = await res.json();

            if (data.code === 200) {
                console.log("✅ 云端鉴权通过，恢复登录状态...");
                loggedInUser = tempUser;
                
                // 安全隐藏双端登录界面
                if (authSection) authSection.classList.add('hidden');
                if (mAuthPage) mAuthPage.classList.add('-translate-y-full');
                
                activateLoginState(); 
            } else {
                console.warn("🚫 鉴权被拒：当前账号已在服务器被物理销毁！");
                
                // 物理熔断无效缓存
                localStorage.removeItem('im_panel_user');
                loggedInUser = null;
                
                // 强制弹出双端登录界面，拒绝入内
                if (authSection) authSection.classList.remove('hidden');
                if (mAuthPage) mAuthPage.classList.remove('-translate-y-full');
            }
        } catch (e) {
            console.error("服务端核验请求中断", e);
            // 兜底策略：如果是暂时断网，允许先进入离线模式。
            // 因为 api.php 已经加了拦截，就算强行进去了，发信息也会被后端无情打回。
            loggedInUser = JSON.parse(cachedUserStr);
            activateLoginState();
        }
    } else {
        // 纯新用户或已注销，正常弹出登录界面
        if (authSection) authSection.classList.remove('hidden');
        if (mAuthPage) mAuthPage.classList.remove('-translate-y-full');
    }

    const savedUser = localStorage.getItem('im_panel_user');
    if (savedUser) {
        loggedInUser = JSON.parse(savedUser);
        initAvatarTooltip(); // 页面刷新时自动重新绑定悬浮提示
    }

});


// ==================== PC 端独立消息管理引擎 ==================== new
function pcOpenMsgManager() {
    document.getElementById('pcMsgManagerModal').classList.remove('hidden');
    pcRenderMsgManagerList();
}

function pcCloseMsgManager() {
    document.getElementById('pcMsgManagerModal').classList.add('hidden');
}

function pcRenderMsgManagerList() {
    const container = document.getElementById('pcMsgManagerList');
    if (!loggedInUser || !chatHistory[loggedInUser.id] || Object.keys(chatHistory[loggedInUser.id]).length === 0) {
        container.innerHTML = '<div class="text-center text-gray-400 text-sm py-8">暂无任何本地消息缓存</div>';
        return;
    }
    
    const myHistory = chatHistory[loggedInUser.id];
    let html = '<div class="space-y-1">';
    Object.keys(myHistory).forEach(uid => {
        const name = getUserNicknameById(uid) || `UID: ${uid}`;
        const count = myHistory[uid].length;
        if (count > 0) {
            html += `
                <label class="flex items-center p-3 hover:bg-gray-50 rounded cursor-pointer border-b border-gray-100 transition">
                    <input type="checkbox" class="pc-msg-cb mr-3" value="${uid}" data-name="${name}">
                    <div class="flex-1">
                        <div class="text-sm font-bold text-gray-800">${name}</div>
                        <div class="text-[11px] text-gray-500 mt-0.5">共 ${count} 条加密记录</div>
                    </div>
                </label>
            `;
        }
    });
    container.innerHTML = html + '</div>';
}

function pcExportSelectedMsgs() {
    const checked = Array.from(document.querySelectorAll('.pc-msg-cb:checked'));
    if (!checked.length) return alert("请先勾选需要导出的对象！");
    
    const exportData = { exporter_uid: loggedInUser.id, time: new Date().toLocaleString(), data: {} };
    checked.forEach(cb => { exportData.data[cb.dataset.name] = chatHistory[loggedInUser.id][cb.value]; });
    
    const blob = new Blob([JSON.stringify(exportData, null, 2)], { type: "application/json" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.style.display = 'none'; a.href = url; a.download = "Ximi_PC_Archive.json";
    document.body.appendChild(a); a.click();
    URL.revokeObjectURL(url);
}

function pcDeleteSelectedMsgs() {
    const checked = Array.from(document.querySelectorAll('.pc-msg-cb:checked'));
    if (!checked.length) return alert("请先勾选需要删除的对象！");
    if (!confirm(`警告：确定要彻底销毁选中的 ${checked.length} 个对话记录吗？`)) return;
    
    checked.forEach(cb => { delete chatHistory[loggedInUser.id][cb.value]; });
    saveHistoryToDisk();
    pcRenderMsgManagerList();
    renderActiveContainer();
}


// ==================== 优雅的单函数 UI 增强方案 ====================

async function pcClearServerQueue() {
    if (!loggedInUser) return alert("请先验证身份！");

    const overlay = document.createElement('div');
    overlay.style.cssText = "position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.2); z-index:99999; display:flex; align-items:center; justify-content:center; backdrop-filter:blur(2px);";

    const modal = document.createElement('div');
    modal.style.cssText = "background:#ffffff; width:320px; border-radius:4px; box-shadow:0 8px 30px rgba(0,0,0,0.12); overflow:hidden; border:1px solid rgba(0,0,0,0.08);";

    modal.innerHTML = `
        <div style="padding:20px 20px 10px 20px; font-size:15px; font-weight:600; color:#000;">提示</div>
        <div style="padding:10px 20px 25px 20px; font-size:13px; color:#555; line-height:1.5;">确定要彻底销毁服务器上所有尚未拉取的消息队列及附属文件吗？该操作不可逆。</div>
        <div style="display:flex; border-top:1px solid #ededed; height:45px;">
            <button class="cancel-btn" style="flex:1; border:none; background:transparent; font-size:14px; color:#19c370; cursor:pointer;">取消</button>
            <button class="confirm-btn" style="flex:1; border:none; background:transparent; font-size:14px; color:#ff0808; font-weight:600; cursor:pointer; border-left:1px solid #ededed;">确定</button>
        </div>
    `;

    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    modal.querySelector('.cancel-btn').onclick = () => document.body.removeChild(overlay);
    modal.querySelector('.confirm-btn').onclick = async () => {
        document.body.removeChild(overlay);
        try {
            const res = await fetch(getApiUrl('clear_server_queue'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ user_id: loggedInUser.id })
            });
            const data = await res.json();
            if (data.code === 200) {
                window.log("云端管理", "成功销毁滞留列队");
            } else {
                alert("清理中断: " + data.msg);
            }
        } catch (err) {
            alert("网络执行异常: " + err.message);
        }
    };
}



// 保证点击设置菜单内部时，不会触发 document 的隐藏事件
document.getElementById('wechatSettingsMenu').addEventListener('click', (e) => e.stopPropagation());