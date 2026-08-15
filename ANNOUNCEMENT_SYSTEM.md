# 公告系统使用说明

## 概述

本项目已成功集成了完整的公告系统（Announcement System），允许管理员创建、编辑、删除和管理系统公告，用户可以在登录后自动看到最新的公告。

## 功能特性

### 1. 后端功能

#### 数据库表结构
- **announcements** - 公告主表
  - id: 公告ID (主键)
  - title: 公告标题
  - content: 公告内容
  - creator_id: 创建者ID
  - status: 状态 (active/inactive)
  - priority: 优先级 (数字越大越靠前)
  - start_time: 开始时间
  - end_time: 结束时间 (为空表示永久)
  - created_at: 创建时间
  - updated_at: 更新时间

- **announcement_reads** - 公告阅读记录表
  - id: 记录ID
  - announcement_id: 公告ID
  - user_id: 用户ID
  - read_at: 阅读时间

#### API 端点

##### 1. 获取公告列表
- **URL**: `api.php?action=get_announcements`
- **方法**: GET
- **参数**:
  - `user_id`: 用户ID (可选，用于获取阅读状态)
- **返回**: JSON 格式的公告列表，已按优先级和创建时间排序
- **示例**:
```javascript
fetch('api.php?action=get_announcements&user_id=1')
    .then(res => res.json())
    .then(data => console.log(data));
```

##### 2. 创建公告 (仅限已认证用户)
- **URL**: `api.php?action=create_announcement`
- **方法**: POST
- **参数** (JSON):
  - `creator_id`: 创建者ID (必需)
  - `title`: 公告标题 (必需)
  - `content`: 公告内容 (必需)
  - `priority`: 优先级 (可选，默认0)
  - `end_time`: 结束时间 (可选，格式: YYYY-MM-DD HH:MM:SS)
- **返回**: 创建成功返回公告ID

##### 3. 更新公告 (仅限已认证用户)
- **URL**: `api.php?action=update_announcement`
- **方法**: POST
- **参数** (JSON):
  - `creator_id`: 创建者ID (必需)
  - `announcement_id`: 公告ID (必需)
  - `title`: 新标题
  - `content`: 新内容
  - `priority`: 新优先级
  - `status`: 新状态 (active/inactive)
  - `end_time`: 新结束时间

##### 4. 删除公告 (仅限已认证用户)
- **URL**: `api.php?action=delete_announcement`
- **方法**: POST
- **参数** (JSON):
  - `user_id`: 用户ID (必需)
  - `announcement_id`: 公告ID (必需)

##### 5. 标记公告已读
- **URL**: `api.php?action=mark_announcement_read`
- **方法**: POST
- **参数** (JSON):
  - `user_id`: 用户ID (必需)
  - `announcement_id`: 公告ID (必需)

### 2. 前端功能

#### 管理员界面
- **位置**: `admin.php` 中的 "📢 公告系统" 标签页
- **功能**:
  - 创建新公告
  - 编辑现有公告
  - 删除公告
  - 设置公告优先级和有效期
  - 查看所有公告列表

#### 用户界面
- **位置**: 登录后在应用顶部显示公告面板
- **功能**:
  - 自动显示最新的有效公告
  - 支持关闭公告面板
  - 根据优先级自动改变颜色（高优先级为红色）
  - 每5分钟自动检查新公告

### 3. JavaScript 函数接口

#### 在 `announcement-functions.js` 中定义的主要函数:

1. **fetchAndDisplayAnnouncements()**
   - 获取并显示最新的公告
   - 自动标记为已读

2. **displayAnnouncement(announcement)**
   - 将公告显示在前端面板上
   - 根据优先级改变显示颜色

3. **closeAnnouncement()**
   - 关闭公告面板

4. **markAnnouncementAsRead(announcementId)**
   - 标记指定公告为已读

5. **createAnnouncement(title, content, priority, endTime)**
   - 创建新公告

6. **updateAnnouncement(id, title, content, priority, status, endTime)**
   - 更新现有公告

7. **deleteAnnouncement(id)**
   - 删除公告

8. **loadAnnouncementsAfterLogin()**
   - 在用户登录后自动加载公告

## 使用示例

### 通过管理员界面创建公告
1. 登录 `admin.php`
2. 点击 "📢 公告系统" 标签页
3. 填写:
   - 公告标题
   - 公告内容
   - 优先级 (0-10，数字越大越靠前)
   - 状态 (发布中 或 已下线)
   - 有效期至 (留空表示永久)
4. 点击 "创建公告" 按钮

### 通过 API 创建公告
```javascript
createAnnouncement(
    '系统维护公告',
    '系统将于明天凌晨进行维护，预计2小时',
    5,  // 优先级
    '2024-12-31 06:00:00'  // 有效期至
);
```

## 优先级与颜色对应

- **优先级 > 5**: 红色背景 (紧急)
- **优先级 > 2**: 橙色背景 (重要)
- **其他**: 蓝色背景 (普通)

## 技术实现细节

### 自动刷新机制
- 用户登录后，系统每5分钟自动检查一次新公告
- 已读状态记录在数据库中，不会重复标记

### 有效期管理
- 公告自动在有效期到期后不再显示
- 后端查询时自动过滤已过期的公告

### 已读状态管理
- 用户首次看到公告时自动标记为已读
- 可在前端通过 `is_read` 字段查看阅读状态

## 文件变更清单

### 新增文件
- `announcement-functions.js` - 前端公告系统函数库

### 修改的文件
- `db.php` - 添加 announcements 和 announcement_reads 表
- `api.php` - 添加5个公告相关的API端点
- `admin.php` - 添加公告管理界面和相应的POST处理
- `index.html` - 添加公告显示面板，引入announcement-functions.js
- `ximi.js` - 在登录后调用 loadAnnouncementsAfterLogin()

## 安全性考虑

1. **身份验证**: 所有修改操作 (创建、编辑、删除) 都需要用户认证
2. **输入验证**: 标题和内容通过服务器端验证
3. **XSS防护**: 前端使用 `textContent` 而非 `innerHTML` 显示内容
4. **SQL注入防护**: 使用参数化查询

## 后续扩展建议

1. **角色权限**: 可扩展为仅特定角色能创建/编辑公告
2. **分类系统**: 添加公告分类 (系统公告、活动、维护等)
3. **定时发布**: 支持定时自动发布公告
4. **多语言**: 支持多语言公告显示
5. **推送通知**: 集成消息推送，实时通知用户新公告
6. **公告统计**: 添加公告浏览统计功能

## 故障排查

### 公告不显示
1. 检查浏览器控制台是否有错误
2. 确认已创建至少一条 status='active' 的公告
3. 检查浏览器是否加载了 `announcement-functions.js`
4. 清除浏览器缓存并重新登录

### API 返回 500 错误
1. 检查 announcements 表是否成功创建
2. 查看 PHP 错误日志
3. 确认数据库连接正常

### 样式不正确
1. 确认 Tailwind CSS 已正确加载
2. 检查浏览器是否支持 CSS Grid 和 Flexbox
3. 清除浏览器缓存

## 许可证

本公告系统遵循原项目的许可证规则。

---

**最后更新**: 2024年
**维护者**: Ximi IM Team
