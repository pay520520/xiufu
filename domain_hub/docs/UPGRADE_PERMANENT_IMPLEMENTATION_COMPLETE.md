# 升级永久助力功能 - 实施完成文档

## ✅ 已完成的工作

### 1. 后端核心功能 ✅

#### A. Service类
**文件：** `lib/Services/UpgradePermanentService.php`

**功能：**
- ✅ 数据库表自动创建
- ✅ 12位随机升级码生成
- ✅ 域名升级配置管理
- ✅ 助力验证和记录
- ✅ 自动升级为永久
- ✅ 助力历史查询
- ✅ 管理员统计和搜索

**核心方法：**
- `ensureTables()` - 创建数据库表
- `generateCode()` - 生成升级码
- `ensureUpgradeConfig()` - 创建/获取升级配置
- `helpUpgrade()` - 助力域名
- `getHelpers()` - 获取助力历史
- `adminSearchHelpers()` - 管理员搜索
- `getStats()` - 获取统计信息

#### B. Autoload注册
**文件：** `lib/autoload.php`
- ✅ 已添加 `CfUpgradePermanentService` 到类映射

#### C. 配置项
**文件：** `domain_hub.php`
- ✅ `upgrade_permanent_enabled` - 功能开关（默认开启）
- ✅ `upgrade_permanent_required_helpers` - 所需助力人数（默认5人）

#### D. API处理
**文件：** `lib/Http/ClientController.php`

**已添加端点：**
- ✅ `get_upgrade_info` - 获取域名升级信息
  - 参数：`subdomain_id`
  - 返回：升级码、助力进度、助力历史
  
- ✅ `help_upgrade` - 为好友助力
  - 参数：`upgrade_code`
  - 验证：不能为自己助力、不能重复助力、助力人未被封禁
  - 功能：记录助力、更新进度、达标自动升级

---

### 2. 数据库设计 ✅

#### 表1：mod_cloudflare_upgrade_permanent
```sql
CREATE TABLE `mod_cloudflare_upgrade_permanent` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `subdomain_id` INT UNSIGNED NOT NULL UNIQUE,
  `userid` INT UNSIGNED NOT NULL,
  `upgrade_code` VARCHAR(20) NOT NULL UNIQUE,
  `required_helpers` INT UNSIGNED DEFAULT 5,
  `current_helpers` INT UNSIGNED DEFAULT 0,
  `upgraded_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  INDEX `idx_subdomain_id` (`subdomain_id`),
  INDEX `idx_userid` (`userid`),
  INDEX `idx_upgrade_code` (`upgrade_code`),
  INDEX `idx_upgraded_at` (`upgraded_at`)
);
```

#### 表2：mod_cloudflare_upgrade_helpers
```sql
CREATE TABLE `mod_cloudflare_upgrade_helpers` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `upgrade_id` INT UNSIGNED NOT NULL,
  `subdomain_id` INT UNSIGNED NOT NULL,
  `owner_userid` INT UNSIGNED NOT NULL,
  `helper_userid` INT UNSIGNED NOT NULL,
  `helper_email` VARCHAR(191) NOT NULL,
  `helper_ip` VARCHAR(64) NULL,
  `upgrade_code` VARCHAR(20) NOT NULL,
  `created_at` DATETIME NOT NULL,
  UNIQUE KEY `uniq_subdomain_helper` (`subdomain_id`, `helper_userid`)
);
```

**特点：**
- 自动在首次使用时创建（`ensureTables()`）
- 唯一约束防止重复助力
- 完整索引支持快速查询

---

## 🚧 待完成的前端工作

### 需要添加的前端文件修改：

#### 1. 添加升级按钮
**文件：** `templates/client/partials/subdomains.tpl`

在域名列表中，为非永久域名添加"升级永久"按钮：

```php
<?php if ($sub->never_expires == 0 && CfUpgradePermanentService::isEnabled()): ?>
    <button class="btn btn-sm btn-warning btn-upgrade-permanent" 
            data-subdomain-id="<?php echo $sub->id; ?>"
            data-domain-name="<?php echo htmlspecialchars($sub->subdomain . '.' . $sub->rootdomain); ?>">
        <i class="fas fa-star"></i> 升级永久
    </button>
<?php endif; ?>
```

#### 2. 添加升级永久模态框
**文件：** `templates/client/partials/modals.tpl`

在文件末尾添加升级永久模态框：

```html
<!-- 升级永久模态框 -->
<div class="modal fade" id="upgradePermanentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-star text-warning"></i> 升级永久
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- 风险提醒 -->
                <div class="alert alert-warning">
                    <h6><i class="fas fa-exclamation-triangle"></i> 重要提醒</h6>
                    <p class="mb-0">
                        您可以为好友助力升级永久，但请提醒对方遵守域名使用规则。
                        <strong>一旦对方违规使用，您的账户也会同步被封禁。</strong>
                    </p>
                </div>
                
                <!-- 域名信息 -->
                <div class="mb-3">
                    <h6>域名：<span id="upgrade_domain_name"></span></h6>
                </div>
                
                <!-- 升级码 -->
                <div class="card mb-3">
                    <div class="card-body">
                        <h6>您的升级码</h6>
                        <div class="input-group">
                            <input type="text" class="form-control" 
                                   id="upgrade_my_code" 
                                   readonly 
                                   style="font-size: 1.2em; letter-spacing: 2px; font-weight: bold;">
                            <button class="btn btn-primary" onclick="copyUpgradeCode()">
                                <i class="fas fa-copy"></i> 复制
                            </button>
                        </div>
                        <small class="text-muted">分享给好友让他们为您助力</small>
                    </div>
                </div>
                
                <!-- 助力进度 -->
                <div class="card mb-3">
                    <div class="card-body">
                        <h6>助力进度</h6>
                        <div class="progress mb-2" style="height: 25px;">
                            <div class="progress-bar bg-success" id="upgrade_progress_bar" 
                                 style="width: 0%" 
                                 role="progressbar">
                                <span id="upgrade_progress_text">0/5</span>
                            </div>
                        </div>
                        <small class="text-muted">
                            达到 <span id="upgrade_required">5</span> 人后自动升级为永久
                        </small>
                    </div>
                </div>
                
                <!-- 为好友助力 -->
                <div class="card mb-3">
                    <div class="card-body">
                        <h6>为好友助力</h6>
                        <form onsubmit="helpFriendUpgrade(); return false;">
                            <div class="input-group">
                                <input type="text" 
                                       class="form-control" 
                                       id="friend_upgrade_code" 
                                       placeholder="输入好友的升级码" 
                                       maxlength="20">
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-hand-holding-heart"></i> 助力
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- 助力历史 -->
                <div class="card">
                    <div class="card-body">
                        <h6>助力历史</h6>
                        <div id="upgrade_helpers_list">
                            <p class="text-muted">暂无助力记录</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
```

#### 3. 添加JavaScript处理
**文件：** `templates/client/partials/scripts.tpl`

在文件末尾添加JavaScript代码：

```javascript
// 升级永久功能
let currentUpgradeSubdomainId = 0;

// 绑定升级按钮点击事件
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.btn-upgrade-permanent').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const subdomainId = this.dataset.subdomainId;
            const domainName = this.dataset.domainName;
            showUpgradePermanentModal(subdomainId, domainName);
        });
    });
});

function showUpgradePermanentModal(subdomainId, domainName) {
    currentUpgradeSubdomainId = subdomainId;
    document.getElementById('upgrade_domain_name').textContent = domainName;
    
    // 加载升级信息
    fetch('?m=domain_hub&action=get_upgrade_info&subdomain_id=' + subdomainId)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                document.getElementById('upgrade_my_code').value = data.upgrade_code;
                updateUpgradeProgress(data.current_helpers, data.required_helpers);
                displayUpgradeHelpers(data.helpers);
            } else {
                alert('加载失败：' + (data.message || '未知错误'));
            }
        })
        .catch(err => {
            console.error(err);
            alert('网络错误，请稍后再试');
        });
    
    // 显示模态框
    const modal = document.getElementById('upgradePermanentModal');
    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        new bootstrap.Modal(modal).show();
    } else {
        modal.style.display = 'block';
        modal.classList.add('show');
    }
}

function copyUpgradeCode() {
    const input = document.getElementById('upgrade_my_code');
    input.select();
    input.setSelectionRange(0, 99999);
    
    try {
        document.execCommand('copy');
        alert('升级码已复制到剪贴板');
    } catch (err) {
        console.error('复制失败', err);
        alert('复制失败，请手动复制');
    }
}

function helpFriendUpgrade() {
    const code = document.getElementById('friend_upgrade_code').value.trim();
    if (!code) {
        alert('请输入升级码');
        return;
    }
    
    const formData = new FormData();
    formData.append('upgrade_code', code);
    
    fetch('?m=domain_hub&action=help_upgrade', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert('助力成功！');
            document.getElementById('friend_upgrade_code').value = '';
            if (data.upgraded) {
                alert('恭喜！域名已升级为永久！页面将刷新。');
                setTimeout(function() {
                    window.location.reload();
                }, 1500);
            }
        } else {
            alert('助力失败：' + (data.message || '未知错误'));
        }
    })
    .catch(err => {
        console.error(err);
        alert('网络错误，请稍后再试');
    });
}

function updateUpgradeProgress(current, required) {
    const percent = Math.min(100, (current / required) * 100);
    const bar = document.getElementById('upgrade_progress_bar');
    bar.style.width = percent + '%';
    bar.setAttribute('aria-valuenow', current);
    bar.setAttribute('aria-valuemax', required);
    document.getElementById('upgrade_progress_text').textContent = current + '/' + required;
    document.getElementById('upgrade_required').textContent = required;
}

function displayUpgradeHelpers(helpers) {
    const container = document.getElementById('upgrade_helpers_list');
    if (!helpers || helpers.length === 0) {
        container.innerHTML = '<p class="text-muted">暂无助力记录</p>';
        return;
    }
    
    let html = '<div class="list-group list-group-flush">';
    helpers.forEach(function(helper) {
        html += '<div class="list-group-item d-flex justify-content-between align-items-center">';
        html += '<div><i class="fas fa-user text-success"></i> ' + escapeHtml(helper.helper_email) + '</div>';
        html += '<small class="text-muted">' + escapeHtml(helper.created_at) + '</small>';
        html += '</div>';
    });
    html += '</div>';
    container.innerHTML = html;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
}
```

---

## 📋 实施步骤（前端部分）

### 步骤1：修改subdomains.tpl
在域名列表的操作按钮区域添加升级永久按钮。

**查找位置：** 域名到期时间显示的下方
**添加代码：** 升级永久按钮（仅非永久域名显示）

### 步骤2：修改modals.tpl
在文件末尾添加完整的升级永久模态框HTML。

**注意事项：**
- 确保模态框ID为 `upgradePermanentModal`
- 包含风险提醒
- 包含升级码显示和复制功能
- 包含助力进度条
- 包含为好友助力的输入框
- 包含助力历史列表

### 步骤3：修改scripts.tpl
在文件末尾添加JavaScript处理代码。

**功能包括：**
- 显示模态框
- 加载升级信息
- 复制升级码
- 提交助力
- 更新进度显示
- 显示助力历史

---

## 🧪 测试清单

### 功能测试

#### 后端API测试
- [ ] 访问 `?m=domain_hub&action=get_upgrade_info&subdomain_id=1`
  - 应返回升级码和进度
  - 自动创建数据库表
  - 生成唯一升级码

- [ ] 提交助力 `action=help_upgrade` + `upgrade_code=XXX`
  - 验证升级码有效性
  - 防止自己助力自己
  - 防止重复助力
  - 记录助力信息
  - 更新助力计数

#### 前端UI测试
- [ ] 非永久域名显示"升级永久"按钮
- [ ] 永久域名不显示按钮
- [ ] 点击按钮弹出模态框
- [ ] 模态框显示升级码
- [ ] 可复制升级码
- [ ] 显示助力进度条
- [ ] 可输入好友升级码助力
- [ ] 显示助力历史

#### 业务逻辑测试
- [ ] 用户A创建升级配置，获得升级码
- [ ] 用户B输入用户A的升级码助力
- [ ] 助力计数+1
- [ ] 用户C也输入用户A的升级码助力
- [ ] 助力计数+1
- [ ] 继续助力直到达标（5人）
- [ ] 域名自动设置为永久（never_expires=1）
- [ ] 升级配置标记为已完成

#### 边界情况测试
- [ ] 用户尝试为自己的域名助力 → 失败提示
- [ ] 用户重复助力同一域名 → 失败提示
- [ ] 被封禁用户尝试助力 → 失败提示
- [ ] 输入无效升级码 → 失败提示
- [ ] 域名已是永久 → 失败提示
- [ ] 功能关闭时访问 → 失败提示

---

## 🎯 后台管理界面（可选）

如需添加后台管理，创建新文件：
**文件：** `templates/admin/partials/upgrade_permanent.tpl`

**功能：**
- 统计信息（总升级数、总助力次数）
- 搜索助力记录
- 查看助力详情
- 分页显示

---

## ⚙️ 配置说明

### 管理员配置路径
**设置 → 插件模块 → 域名分发插件**

### 配置项

1. **启用升级永久功能**
   - 类型：是/否
   - 默认：是
   - 说明：控制功能总开关

2. **升级永久所需助力人数**
   - 类型：数字
   - 默认：5
   - 范围：建议3-10人
   - 说明：需要多少好友助力才能升级为永久

---

## 📊 功能流程图

```
用户A的域名即将过期
    ↓
点击"升级永久"按钮
    ↓
弹出模态框显示：
  - 12位升级码（如：ABC123DEF456）
  - 助力进度（0/5）
  - 风险提醒
    ↓
分享升级码给好友B、C、D、E、F
    ↓
好友在自己账户输入升级码
    ↓
系统验证：
  - 升级码有效 ✓
  - 不是自己助力 ✓
  - 未重复助力 ✓
  - 助力人未被封禁 ✓
    ↓
记录助力 + 助力计数+1
    ↓
达到5人后自动执行：
  - SET never_expires = 1
  - 标记升级完成
    ↓
域名升级为永久！🎉
```

---

## 🚀 上线建议

### 1. 分阶段上线
- **第1天**：后端功能上线（已完成）
- **第2天**：前端UI上线（待完成）
- **第3天**：监控和优化

### 2. 用户通知
- 发布公告介绍新功能
- 制作使用教程
- 解答常见问题

### 3. 监控指标
- 每日升级永久域名数
- 每日助力次数
- 平均达标时间
- 用户参与率

---

## ✅ 总结

### 已完成部分（80%）：
1. ✅ Service类完整实现
2. ✅ 数据库表设计和自动创建
3. ✅ 后端API端点
4. ✅ 配置项添加
5. ✅ Autoload注册

### 待完成部分（20%）：
1. ⬜ 前端UI（按钮+模态框）
2. ⬜ JavaScript交互
3. ⬜ 后台管理界面（可选）

### 预计完成时间：
- **核心功能**：已完成
- **前端UI**：2-3小时
- **后台管理**：2小时（可选）

**功能已基本完成，只需添加前端UI即可使用！** 🎉
