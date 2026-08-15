// ========================================================
// 公告系统 JavaScript 函数
// ========================================================

/**
 * 获取并显示公告列表
 */
async function fetchAndDisplayAnnouncements() {
    try {
        const userId = loggedInUser ? loggedInUser.id : 0;
        const response = await fetch(`api.php?action=get_announcements&user_id=${userId}`);
        const result = await response.json();
        
        if (result.code === 200 && result.data && result.data.length > 0) {
            // 显示第一条公告
            const announcement = result.data[0];
            displayAnnouncement(announcement);
            
            // 标记为已读
            if (userId > 0) {
                markAnnouncementAsRead(announcement.id);
            }
        }
    } catch (error) {
        console.error('获取公告失败:', error);
    }
}

/**
 * 在界面上显示单条公告
 */
function displayAnnouncement(announcement) {
    const panel = document.getElementById('announcementPanel');
    const title = document.getElementById('announcementTitle');
    const text = document.getElementById('announcementText');
    
    if (panel && title && text) {
        title.textContent = announcement.title;
        text.textContent = announcement.content;
        panel.classList.remove('hidden');
        
        // 设置面板的背景颜色（根据优先级）
        if (announcement.priority > 5) {
            panel.className = 'fixed top-0 left-0 right-0 z-[4999] bg-gradient-to-r from-red-500 to-red-600 text-white shadow-lg max-h-screen overflow-y-auto';
        } else if (announcement.priority > 2) {
            panel.className = 'fixed top-0 left-0 right-0 z-[4999] bg-gradient-to-r from-orange-500 to-orange-600 text-white shadow-lg max-h-screen overflow-y-auto';
        } else {
            panel.className = 'fixed top-0 left-0 right-0 z-[4999] bg-gradient-to-r from-blue-500 to-blue-600 text-white shadow-lg max-h-screen overflow-y-auto';
        }
    }
}

/**
 * 关闭公告面板
 */
function closeAnnouncement() {
    const panel = document.getElementById('announcementPanel');
    if (panel) {
        panel.classList.add('hidden');
    }
}

/**
 * 标记公告为已读
 */
async function markAnnouncementAsRead(announcementId) {
    if (!loggedInUser) return;
    
    try {
        await fetch('api.php?action=mark_announcement_read', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                announcement_id: announcementId,
                user_id: loggedInUser.id
            })
        });
    } catch (error) {
        console.error('标记公告为已读失败:', error);
    }
}

/**
 * 在用户登录后自动加载公告
 */
function loadAnnouncementsAfterLogin() {
    // 延迟一秒以确保登录完成
    setTimeout(() => {
        fetchAndDisplayAnnouncements();
        // 每5分钟检查一次新公告
        if (window.announcementCheckTimer) {
            clearInterval(window.announcementCheckTimer);
        }
        window.announcementCheckTimer = setInterval(() => {
            fetchAndDisplayAnnouncements();
        }, 5 * 60 * 1000);
    }, 1000);
}

/**
 * 创建公告（管理员用）
 */
async function createAnnouncement(title, content, priority = 0, endTime = '') {
    if (!loggedInUser) {
        alert('请先登录');
        return;
    }
    
    try {
        const response = await fetch('api.php?action=create_announcement', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                creator_id: loggedInUser.id,
                title: title,
                content: content,
                priority: parseInt(priority),
                end_time: endTime
            })
        });
        
        const result = await response.json();
        if (result.code === 200) {
            alert('公告创建成功！');
            return true;
        } else {
            alert('创建失败：' + result.msg);
            return false;
        }
    } catch (error) {
        console.error('创建公告失败:', error);
        alert('创建公告失败');
        return false;
    }
}

/**
 * 更新公告（管理员用）
 */
async function updateAnnouncement(announcementId, title, content, priority = 0, status = 'active', endTime = '') {
    if (!loggedInUser) {
        alert('请先登录');
        return;
    }
    
    try {
        const response = await fetch('api.php?action=update_announcement', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                creator_id: loggedInUser.id,
                announcement_id: parseInt(announcementId),
                title: title,
                content: content,
                priority: parseInt(priority),
                status: status,
                end_time: endTime
            })
        });
        
        const result = await response.json();
        if (result.code === 200) {
            alert('公告更新成功！');
            return true;
        } else {
            alert('更新失败：' + result.msg);
            return false;
        }
    } catch (error) {
        console.error('更新公告失败:', error);
        alert('更新公告失败');
        return false;
    }
}

/**
 * 删除公告（管理员用）
 */
async function deleteAnnouncement(announcementId) {
    if (!loggedInUser) {
        alert('请先登录');
        return;
    }
    
    if (!confirm('确定要删除该公告吗？')) {
        return false;
    }
    
    try {
        const response = await fetch('api.php?action=delete_announcement', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                user_id: loggedInUser.id,
                announcement_id: parseInt(announcementId)
            })
        });
        
        const result = await response.json();
        if (result.code === 200) {
            alert('公告已删除！');
            return true;
        } else {
            alert('删除失败：' + result.msg);
            return false;
        }
    } catch (error) {
        console.error('删除公告失败:', error);
        alert('删除公告失败');
        return false;
    }
}

console.log('announcement-functions.js 已加载');
