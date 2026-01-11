<?php
// phpcs:ignoreFile

declare(strict_types=1);

use WHMCS\Database\Capsule;

/**
 * 域名升级永久助力服务
 * 用户可以通过好友助力将非永久域名升级为永久域名
 */
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
            // 忽略表创建错误
        }
    }
    
    /**
     * 生成12位随机升级码
     */
    public static function generateCode(): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
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
        
        $config = Capsule::table(self::TABLE_UPGRADE)
            ->where('upgrade_code', $cleanCode)
            ->first();
        
        if (!$config) {
            throw new \InvalidArgumentException('升级码无效');
        }
        
        if ($config->upgraded_at) {
            throw new \InvalidArgumentException('该域名已经升级为永久');
        }
        
        if ($config->userid == $helperUserId) {
            throw new \InvalidArgumentException('不能为自己的域名助力');
        }
        
        $alreadyHelped = Capsule::table(self::TABLE_HELPERS)
            ->where('subdomain_id', $config->subdomain_id)
            ->where('helper_userid', $helperUserId)
            ->exists();
        
        if ($alreadyHelped) {
            throw new \InvalidArgumentException('您已经助力过该域名');
        }
        
        $helperBanned = Capsule::table('mod_cloudflare_banned_users')
            ->where('userid', $helperUserId)
            ->where('is_banned', 1)
            ->exists();
        
        if ($helperBanned) {
            throw new \InvalidArgumentException('您的账户已被封禁，无法助力');
        }
        
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
        
        $newHelperCount = $config->current_helpers + 1;
        Capsule::table(self::TABLE_UPGRADE)
            ->where('id', $config->id)
            ->update([
                'current_helpers' => $newHelperCount,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        
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
        Capsule::table('mod_cloudflare_subdomain')
            ->where('id', $subdomainId)
            ->update([
                'never_expires' => 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        
        Capsule::table(self::TABLE_UPGRADE)
            ->where('id', $upgradeId)
            ->update([
                'upgraded_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        
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
        
        if (!$helpers) {
            return [];
        }
        
        return array_map(function($helper) {
            if ($helper instanceof \stdClass) {
                $helper = (array) $helper;
            }
            return [
                'id' => $helper['id'] ?? 0,
                'helper_email' => $helper['helper_email'] ?? '',
                'created_at' => $helper['created_at'] ?? '',
            ];
        }, $helpers->toArray());
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
                'helper.email as helper_email_full',
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
        
        try {
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
        } catch (\Throwable $e) {
            return [
                'total_upgraded' => 0,
                'total_helpers' => 0,
                'today_helpers' => 0,
            ];
        }
    }
    
    /**
     * 检查功能是否启用
     */
    public static function isEnabled(): bool
    {
        if (!function_exists('cfmod_setting_enabled')) {
            return false;
        }
        
        $settings = function_exists('cf_get_module_settings_cached') 
            ? cf_get_module_settings_cached() 
            : [];
        
        return cfmod_setting_enabled($settings['upgrade_permanent_enabled'] ?? 'no');
    }
}
