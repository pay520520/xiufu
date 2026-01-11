# 新功能设计：域名升级永久助力系统

## 📋 功能概述

允许用户通过好友助力的方式，将非永久域名升级为永久域名。类似邀请注册系统，用户可以分享自己的升级码，邀请好友助力，当助力人数达标后自动升级为永久。

---

## 🎯 功能需求

### 用户前端功能

1. **升级永久按钮**
   - 在非永久域名的到期时间下方显示"升级永久"按钮
   - 只对`never_expires = 0`的域名显示

2. **升级码弹窗**
   - 显示自己的12位升级码（字母+数字随机生成，唯一）
   - 风险提醒：类似邀请注册的警告
   - 可复制升级码分享给好友
   - 输入框：输入好友升级码为其助力
   - 助力历史：显示已助力的好友邮箱
   - 进度条：显示当前助力进度（X/Y人）

3. **助力规则**
   - 不能为自己的域名助力
   - 每个用户可以助力多个不同用户的域名
   - 同一个用户不能重复助力同一个域名
   - 达到助力人数后自动升级为永久（`never_expires = 1`）

### 后台管理功能

1. **助力记录查询**
   - 查看所有助力记录
   - 搜索功能（按邮箱、域名、升级码）
   - 分页显示

2. **功能开关**
   - 开启/关闭升级永久功能
   - 设置所需助力人数（默认5人）

3. **统计信息**
   - 总升级永久域名数
   - 总助力次数
   - 热门助力域名排行

---

## 💾 数据库设计

### 表1：mod_cloudflare_upgrade_permanent

存储域名的升级码和升级状态

```sql
CREATE TABLE `mod_cloudflare_upgrade_permanent` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `subdomain_id` INT UNSIGNED NOT NULL UNIQUE COMMENT '域名ID',
  `userid` INT UNSIGNED NOT NULL COMMENT '域名所有者',
  `upgrade_code` VARCHAR(20) NOT NULL UNIQUE COMMENT '12位升级码',
  `required_helpers` INT UNSIGNED DEFAULT 5 COMMENT '所需助力人数',
  `current_helpers` INT UNSIGNED DEFAULT 0 COMMENT '当前助力人数',
  `upgraded_at` DATETIME NULL COMMENT '升级完成时间',
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  INDEX `idx_subdomain_id` (`subdomain_id`),
  INDEX `idx_userid` (`userid`),
  INDEX `idx_upgrade_code` (`upgrade_code`),
  INDEX `idx_upgraded_at` (`upgraded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='域名升级永久配置表';
```

### 表2：mod_cloudflare_upgrade_helpers

存储助力记录

```sql
CREATE TABLE `mod_cloudflare_upgrade_helpers` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `upgrade_id` INT UNSIGNED NOT NULL COMMENT '升级配置ID',
  `subdomain_id` INT UNSIGNED NOT NULL COMMENT '被助力的域名ID',
  `owner_userid` INT UNSIGNED NOT NULL COMMENT '域名所有者',
  `helper_userid` INT UNSIGNED NOT NULL COMMENT '助力人ID',
  `helper_email` VARCHAR(191) NOT NULL COMMENT '助力人邮箱',
  `helper_ip` VARCHAR(64) COMMENT '助力人IP',
  `upgrade_code` VARCHAR(20) NOT NULL COMMENT '使用的升级码',
  `created_at` DATETIME NOT NULL,
  INDEX `idx_upgrade_id` (`upgrade_id`),
  INDEX `idx_subdomain_id` (`subdomain_id`),
  INDEX `idx_owner_userid` (`owner_userid`),
  INDEX `idx_helper_userid` (`helper_userid`),
  INDEX `idx_helper_email` (`helper_email`),
  INDEX `idx_created_at` (`created_at`),
  UNIQUE KEY `uniq_subdomain_helper` (`subdomain_id`, `helper_userid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='域名升级助力记录表';
```

---

## 🔧 实现难度评估

### 难度等级：⭐⭐⭐（中等）

### 有利条件：

1. ✅ **可复用代码多**
   - 已有`InviteRegistrationService`可作为模板
   - 代码生成、验证、日志记录都有现成实现
   - 前端模态框、表单处理成熟

2. ✅ **架构完善**
   - Service层设计清晰
   - Action处理规范
   - 数据库操作封装好

3. ✅ **UI组件齐全**
   - Bootstrap模态框
   - 表单验证
   - 提示消息系统

### 需要开发的部分：

| 部分 | 难度 | 预计时间 | 说明 |
|------|------|---------|------|
| 数据库表 | ⭐ | 30分钟 | 参考邀请表结构 |
| Service类 | ⭐⭐ | 2-3小时 | 复用90%代码 |
| 前端UI | ⭐⭐ | 2小时 | 模态框+表单 |
| 后端Action | ⭐⭐ | 1-2小时 | 3-4个action |
| 后台管理 | ⭐⭐ | 2小时 | 新增tab页 |
| 配置项 | ⭐ | 30分钟 | 2个设置 |
| 测试调试 | ⭐⭐ | 2小时 | 功能测试 |

**总计工作量：10-12小时**

---

## 📝 详细实现方案

### 1. 创建Service类

**文件：** `lib/Services/UpgradePermanentService.php`

```php
<?php
declare(strict_types=1);

use WHMCS\Database\Capsule;

class CfUpgradePermanentService
{
    private const CODE_LENGTH = 12;
    private const TABLE_UPGRADE = 'mod_cloudflare_upgrade_permanent';
    private const TABLE_HELPERS = 'mod_cloudflare_upgrade_helpers';
    
    /**
     * 确保数据库表存在
     */
    public static function ensureTables(): void
    {
        try {
            if (!Capsule::schema()->hasTable(self::TABLE_UPGRADE)) {
                Capsule::schema()->create(self::TABLE_UPGRADE, function ($table) {
                    $table->increments('id');
                    $table->integer('subdomain_id')->unsigned()->unique();
                    $table->integer('userid')->unsigned();
                    $table->string('upgrade_code', 20)->unique();
                    $table->integer('required_helpers')->unsigned()->default(5);
                    $table->integer('current_helpers')->unsigned()->default(0);
                    $table->dateTime('upgraded_at')->nullable();
                    $table->timestamps();
                    
                    $table->index('subdomain_id');
                    $table->index('userid');
                    $table->index('upgrade_code');
                    $table->index('upgraded_at');
                });
            }
            
            if (!Capsule::schema()->hasTable(self::TABLE_HELPERS)) {
                Capsule::schema()->create(self::TABLE_HELPERS, function ($table) {
                    $table->increments('id');
                    $table->integer('upgrade_id')->unsigned();
                    $table->integer('subdomain_id')->unsigned();
                    $table->integer('owner_userid')->unsigned();
                    $table->integer('helper_userid')->unsigned();
                    $table->string('helper_email', 191);
                    $table->string('helper_ip', 64)->nullable();
                    $table->string('upgrade_code', 20);
                    $table->dateTime('created_at');
                    
                    $table->index('upgrade_id');
                    $table->index('subdomain_id');
                    $table->index('owner_userid');
                    $table->index('helper_userid');
                    $table->index('helper_email');
                    $table->index('created_at');
                    $table->unique(['subdomain_id', 'helper_userid'], 'uniq_subdomain_helper');
                });
            }
        } catch (\Throwable $e) {
            // 忽略错误
        }
    }
    
    /**
     * 生成12位随机升级码
     */
    public static function generateCode(): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // 去掉易混淆字符
        $code = '';
        $length = self::CODE_LENGTH;
        
        for ($i = 0; $i < $length; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        
        return $code;
    }
    
    /**
     * 为域名创建升级配置
     */
    public static function ensureUpgradeConfig(int $subdomainId, int $userId, int $requiredHelpers = 5): array
    {
        self::ensureTables();
        
        $existing = Capsule::table(self::TABLE_UPGRADE)
            ->where('subdomain_id', $subdomainId)
            ->first();
        
        if ($existing) {
            return self::normalizeUpgradeRow($existing);
        }
        
        // 生成唯一升级码
        $maxAttempts = 10;
        $code = '';
        for ($i = 0; $i < $maxAttempts; $i++) {
            $code = self::generateCode();
            $exists = Capsule::table(self::TABLE_UPGRADE)
                ->where('upgrade_code', $code)
                ->exists();
            if (!$exists) {
                break;
            }
        }
        
        $id = Capsule::table(self::TABLE_UPGRADE)->insertGetId([
            'subdomain_id' => $subdomainId,
            'userid' => $userId,
            'upgrade_code' => $code,
            'required_helpers' => max(1, $requiredHelpers),
            'current_helpers' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        return [
            'id' => $id,
            'subdomain_id' => $subdomainId,
            'userid' => $userId,
            'upgrade_code' => $code,
            'required_helpers' => $requiredHelpers,
            'current_helpers' => 0,
            'upgraded_at' => null,
        ];
    }
    
    /**
     * 助力域名升级
     */
    public static function helpUpgrade(int $helperUserId, string $helperEmail, string $upgradeCode, string $ipAddress = ''): array
    {
        self::ensureTables();
        
        $cleanCode = strtoupper(trim($upgradeCode));
        if ($cleanCode === '') {
            throw new \InvalidArgumentException('升级码不能为空');
        }
        
        // 查找升级配置
        $config = Capsule::table(self::TABLE_UPGRADE)
            ->where('upgrade_code', $cleanCode)
            ->first();
        
        if (!$config) {
            throw new \InvalidArgumentException('升级码无效');
        }
        
        // 检查是否已经升级
        if ($config->upgraded_at) {
            throw new \InvalidArgumentException('该域名已经升级为永久');
        }
        
        // 不能为自己助力
        if ($config->userid == $helperUserId) {
            throw new \InvalidArgumentException('不能为自己的域名助力');
        }
        
        // 检查是否已经助力过
        $alreadyHelped = Capsule::table(self::TABLE_HELPERS)
            ->where('subdomain_id', $config->subdomain_id)
            ->where('helper_userid', $helperUserId)
            ->exists();
        
        if ($alreadyHelped) {
            throw new \InvalidArgumentException('您已经助力过该域名');
        }
        
        // 检查助力人是否被封禁
        $helperBanned = Capsule::table('mod_cloudflare_banned_users')
            ->where('userid', $helperUserId)
            ->where('is_banned', 1)
            ->exists();
        
        if ($helperBanned) {
            throw new \InvalidArgumentException('您的账户已被封禁，无法助力');
        }
        
        // 记录助力
        Capsule::table(self::TABLE_HELPERS)->insert([
            'upgrade_id' => $config->id,
            'subdomain_id' => $config->subdomain_id,
            'owner_userid' => $config->userid,
            'helper_userid' => $helperUserId,
            'helper_email' => $helperEmail,
            'helper_ip' => $ipAddress,
            'upgrade_code' => $cleanCode,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        
        // 更新助力人数
        $newHelperCount = $config->current_helpers + 1;
        Capsule::table(self::TABLE_UPGRADE)
            ->where('id', $config->id)
            ->update([
                'current_helpers' => $newHelperCount,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        
        // 检查是否达到助力人数
        if ($newHelperCount >= $config->required_helpers) {
            self::upgradeToPermanent($config->id, $config->subdomain_id);
        }
        
        return [
            'success' => true,
            'current_helpers' => $newHelperCount,
            'required_helpers' => $config->required_helpers,
            'upgraded' => $newHelperCount >= $config->required_helpers,
        ];
    }
    
    /**
     * 升级域名为永久
     */
    private static function upgradeToPermanent(int $upgradeId, int $subdomainId): void
    {
        // 更新域名为永久
        Capsule::table('mod_cloudflare_subdomain')
            ->where('id', $subdomainId)
            ->update([
                'never_expires' => 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        
        // 标记升级完成
        Capsule::table(self::TABLE_UPGRADE)
            ->where('id', $upgradeId)
            ->update([
                'upgraded_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        
        // 记录日志
        if (function_exists('cloudflare_subdomain_log')) {
            cloudflare_subdomain_log('upgrade_permanent_completed', [
                'subdomain_id' => $subdomainId,
                'upgrade_id' => $upgradeId,
            ]);
        }
    }
    
    /**
     * 获取域名的助力历史
     */
    public static function getHelpers(int $subdomainId): array
    {
        self::ensureTables();
        
        $helpers = Capsule::table(self::TABLE_HELPERS)
            ->where('subdomain_id', $subdomainId)
            ->orderBy('created_at', 'desc')
            ->get();
        
        return $helpers ? $helpers->toArray() : [];
    }
    
    /**
     * 获取升级配置
     */
    public static function getUpgradeConfig(int $subdomainId): ?array
    {
        self::ensureTables();
        
        $config = Capsule::table(self::TABLE_UPGRADE)
            ->where('subdomain_id', $subdomainId)
            ->first();
        
        return $config ? self::normalizeUpgradeRow($config) : null;
    }
    
    /**
     * 标准化升级配置行
     */
    private static function normalizeUpgradeRow($row): array
    {
        if ($row instanceof \stdClass) {
            $row = (array) $row;
        }
        
        return [
            'id' => (int) ($row['id'] ?? 0),
            'subdomain_id' => (int) ($row['subdomain_id'] ?? 0),
            'userid' => (int) ($row['userid'] ?? 0),
            'upgrade_code' => (string) ($row['upgrade_code'] ?? ''),
            'required_helpers' => (int) ($row['required_helpers'] ?? 5),
            'current_helpers' => (int) ($row['current_helpers'] ?? 0),
            'upgraded_at' => $row['upgraded_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }
    
    /**
     * 管理员：搜索助力记录
     */
    public static function adminSearchHelpers(string $keyword, int $page = 1, int $perPage = 20): array
    {
        self::ensureTables();
        
        $query = Capsule::table(self::TABLE_HELPERS . ' as h')
            ->join('tblclients as owner', 'h.owner_userid', '=', 'owner.id')
            ->join('tblclients as helper', 'h.helper_userid', '=', 'helper.id')
            ->join('mod_cloudflare_subdomain as s', 'h.subdomain_id', '=', 's.id')
            ->select([
                'h.*',
                'owner.email as owner_email',
                'helper.email as helper_email',
                's.subdomain',
                's.rootdomain',
            ]);
        
        if ($keyword !== '') {
            $query->where(function($q) use ($keyword) {
                $q->where('owner.email', 'like', '%' . $keyword . '%')
                  ->orWhere('helper.email', 'like', '%' . $keyword . '%')
                  ->orWhere('h.upgrade_code', 'like', '%' . $keyword . '%')
                  ->orWhere('s.subdomain', 'like', '%' . $keyword . '%');
            });
        }
        
        $total = $query->count();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;
        
        $items = $query->orderBy('h.created_at', 'desc')
            ->offset($offset)
            ->limit($perPage)
            ->get();
        
        return [
            'items' => $items ? $items->toArray() : [],
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
        ];
    }
    
    /**
     * 获取统计信息
     */
    public static function getStats(): array
    {
        self::ensureTables();
        
        $totalUpgraded = Capsule::table(self::TABLE_UPGRADE)
            ->whereNotNull('upgraded_at')
            ->count();
        
        $totalHelpers = Capsule::table(self::TABLE_HELPERS)
            ->count();
        
        $todayHelpers = Capsule::table(self::TABLE_HELPERS)
            ->whereDate('created_at', date('Y-m-d'))
            ->count();
        
        return [
            'total_upgraded' => $totalUpgraded,
            'total_helpers' => $totalHelpers,
            'today_helpers' => $todayHelpers,
        ];
    }
}
```

### 2. 前端UI实现

**在 `templates/client/partials/subdomains.tpl` 中添加升级按钮：**

```php
<?php if ($sub->never_expires == 0): ?>
    <button class="btn btn-sm btn-warning" 
            onclick="showUpgradePermanentModal(<?php echo $sub->id; ?>, '<?php echo htmlspecialchars($sub->subdomain . '.' . $sub->rootdomain); ?>')">
        <i class="fas fa-star"></i> 升级永久
    </button>
<?php endif; ?>
```

**创建升级永久模态框（在 `templates/client/partials/modals.tpl` 中添加）：**

```html
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
                            <div class="progress-bar" id="upgrade_progress_bar" 
                                 style="width: 0%" 
                                 aria-valuenow="0" 
                                 aria-valuemin="0" 
                                 aria-valuemax="100">
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

**JavaScript处理（在 `templates/client/partials/scripts.tpl` 中添加）：**

```javascript
let currentUpgradeSubdomainId = 0;

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
            }
        });
    
    new bootstrap.Modal(document.getElementById('upgradePermanentModal')).show();
}

function copyUpgradeCode() {
    const input = document.getElementById('upgrade_my_code');
    input.select();
    document.execCommand('copy');
    alert('升级码已复制到剪贴板');
}

function helpFriendUpgrade() {
    const code = document.getElementById('friend_upgrade_code').value.trim();
    if (!code) {
        alert('请输入升级码');
        return;
    }
    
    fetch('?m=domain_hub&action=help_upgrade', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'upgrade_code=' + encodeURIComponent(code)
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert('助力成功！');
            document.getElementById('friend_upgrade_code').value = '';
            if (data.upgraded) {
                alert('恭喜！域名已升级为永久！');
            }
        } else {
            alert('助力失败：' + data.message);
        }
    });
}

function updateUpgradeProgress(current, required) {
    const percent = Math.min(100, (current / required) * 100);
    document.getElementById('upgrade_progress_bar').style.width = percent + '%';
    document.getElementById('upgrade_progress_text').textContent = current + '/' + required;
    document.getElementById('upgrade_required').textContent = required;
}

function displayUpgradeHelpers(helpers) {
    const container = document.getElementById('upgrade_helpers_list');
    if (!helpers || helpers.length === 0) {
        container.innerHTML = '<p class="text-muted">暂无助力记录</p>';
        return;
    }
    
    let html = '<div class="list-group">';
    helpers.forEach(helper => {
        html += `
            <div class="list-group-item">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <i class="fas fa-user text-success"></i> 
                        ${escapeHtml(helper.helper_email)}
                    </div>
                    <small class="text-muted">${helper.created_at}</small>
                </div>
            </div>
        `;
    });
    html += '</div>';
    container.innerHTML = html;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
```

### 3. 后端Action处理

**在 `lib/Services/ClientActionService.php` 中添加：**

```php
// 获取升级信息
if ($action === 'get_upgrade_info') {
    $subdomainId = intval($_GET['subdomain_id'] ?? 0);
    $subdomain = Capsule::table('mod_cloudflare_subdomain')
        ->where('id', $subdomainId)
        ->where('userid', $userId)
        ->first();
    
    if (!$subdomain) {
        echo json_encode(['success' => false, 'message' => '域名不存在']);
        exit;
    }
    
    $settings = cfmod_get_module_settings();
    $requiredHelpers = max(1, intval($settings['upgrade_permanent_required_helpers'] ?? 5));
    
    $config = CfUpgradePermanentService::ensureUpgradeConfig(
        $subdomainId, 
        $userId, 
        $requiredHelpers
    );
    
    $helpers = CfUpgradePermanentService::getHelpers($subdomainId);
    
    echo json_encode([
        'success' => true,
        'upgrade_code' => $config['upgrade_code'],
        'current_helpers' => $config['current_helpers'],
        'required_helpers' => $config['required_helpers'],
        'upgraded' => !empty($config['upgraded_at']),
        'helpers' => $helpers,
    ]);
    exit;
}

// 助力好友升级
if ($action === 'help_upgrade') {
    $upgradeCode = trim($_POST['upgrade_code'] ?? '');
    
    $user = Capsule::table('tblclients')->where('id', $userId)->first();
    if (!$user) {
        echo json_encode(['success' => false, 'message' => '用户不存在']);
        exit;
    }
    
    try {
        $result = CfUpgradePermanentService::helpUpgrade(
            $userId,
            $user->email,
            $upgradeCode,
            $_SERVER['REMOTE_ADDR'] ?? ''
        );
        
        echo json_encode([
            'success' => true,
            'current_helpers' => $result['current_helpers'],
            'required_helpers' => $result['required_helpers'],
            'upgraded' => $result['upgraded'],
        ]);
    } catch (\Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }
    exit;
}
```

### 4. 配置项

**在 `domain_hub.php` 中添加配置：**

```php
"upgrade_permanent_enabled" => [
    "FriendlyName" => "启用升级永久功能",
    "Type" => "yesno",
    "Description" => "允许用户通过好友助力将域名升级为永久",
    "Default" => "yes",
],
"upgrade_permanent_required_helpers" => [
    "FriendlyName" => "升级永久所需助力人数",
    "Type" => "text",
    "Size" => "5",
    "Default" => "5",
    "Description" => "用户需要多少个好友助力才能升级为永久（建议3-10人）",
],
```

### 5. 后台管理界面

**在 `templates/admin/partials/upgrade_permanent.tpl` 创建新文件：**

```php
<div class="card" id="upgrade-permanent">
    <div class="card-body">
        <h5 class="card-title">
            <i class="fas fa-star"></i> 升级永久助力管理
        </h5>
        
        <!-- 统计信息 -->
        <div class="row mb-3">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body text-center">
                        <h3><?php echo $stats['total_upgraded']; ?></h3>
                        <p class="text-muted mb-0">已升级永久</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body text-center">
                        <h3><?php echo $stats['total_helpers']; ?></h3>
                        <p class="text-muted mb-0">总助力次数</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body text-center">
                        <h3><?php echo $stats['today_helpers']; ?></h3>
                        <p class="text-muted mb-0">今日助力</p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- 搜索 -->
        <form method="get" class="mb-3">
            <input type="hidden" name="module" value="domain_hub">
            <div class="input-group">
                <input type="text" 
                       name="upgrade_search" 
                       class="form-control" 
                       placeholder="搜索邮箱、域名或升级码"
                       value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="btn btn-primary">搜索</button>
            </div>
        </form>
        
        <!-- 助力记录列表 -->
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>域名</th>
                        <th>所有者</th>
                        <th>助力人</th>
                        <th>升级码</th>
                        <th>时间</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($helpers as $helper): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($helper->subdomain . '.' . $helper->rootdomain); ?></td>
                            <td><?php echo htmlspecialchars($helper->owner_email); ?></td>
                            <td><?php echo htmlspecialchars($helper->helper_email); ?></td>
                            <td><code><?php echo htmlspecialchars($helper->upgrade_code); ?></code></td>
                            <td><?php echo $helper->created_at; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
```

---

## ⏱️ 实施时间表

### 第1阶段：基础功能（4-5小时）
- [ ] 创建数据库表
- [ ] 实现Service类
- [ ] 基础UI（按钮+模态框）

### 第2阶段：完善功能（3-4小时）
- [ ] 前端交互逻辑
- [ ] 后端Action处理
- [ ] 助力历史显示

### 第3阶段：后台管理（2-3小时）
- [ ] 后台统计信息
- [ ] 助力记录查询
- [ ] 配置项设置

### 第4阶段：测试优化（2小时）
- [ ] 功能测试
- [ ] 边界情况处理
- [ ] 性能优化

**总计：11-14小时**

---

## 🎯 总结

### 实现难度：⭐⭐⭐（中等）

### 优势：
1. ✅ 可复用90%的邀请注册代码
2. ✅ 数据库设计简单清晰
3. ✅ UI组件已经完善
4. ✅ 架构支持良好

### 建议：
1. **先实现核心功能**：助力系统
2. **再优化用户体验**：进度条、通知
3. **最后完善管理**：后台查询、统计

### 价值：
- 提升用户粘性
- 增加用户互动
- 减少过期域名管理成本
- 病毒式传播效应

**这是一个非常值得实现的功能！** 🎉
