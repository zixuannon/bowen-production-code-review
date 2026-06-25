<?php

namespace App\Console\Commands;

use App\Models\ApiToken;
use App\Models\School;
use Illuminate\Console\Command;

class DifyTokenCreate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dify:token-create
                            {school-code : 学校 code，例如 BOWEN-MYANMAR}
                            {--name= : Token 名称，默认 "Dify API Token"}
                            {--permissions=* : 权限列表，默认 admission:read}
                            {--expires= : 过期天数，不传则永不过期}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '为指定学校创建 Dify API Token（明文 token 仅显示一次）';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $schoolCode = $this->argument('school-code');

        // Look up school
        $school = School::on('mysql')->where('code', $schoolCode)->first();

        if (!$school) {
            $this->error("学校不存在: {$schoolCode}");
            return self::FAILURE;
        }

        // Resolve options
        $name        = $this->option('name') ?: 'Dify API Token';
        $permissions = $this->option('permissions') ?: ['admission:read'];
        $expiresDays = $this->option('expires');

        $expiresAt = null;
        if ($expiresDays) {
            $expiresAt = now()->addDays((int) $expiresDays);
        }

        // Generate token
        $tokenData = ApiToken::generateToken();

        // Store in database
        ApiToken::create([
            'school_id'   => $school->id,
            'name'        => $name,
            'token_hash'  => $tokenData['hash'],
            'permissions' => $permissions,
            'expires_at'  => $expiresAt,
            'is_active'   => true,
        ]);

        // Output - plain-text token shown only once
        $this->info('========================================');
        $this->info('  Dify API Token 创建成功');
        $this->info('========================================');
        $this->newLine();
        $this->line("  <fg=green>学校:</>       {$school->name} ({$schoolCode})");
        $this->line("  <fg=green>名称:</>       {$name}");
        $this->line("  <fg=green>权限:</>       " . implode(', ', $permissions));
        $this->line("  <fg=green>过期:</>       " . ($expiresAt ? $expiresAt->toDateTimeString() : '永不过期'));
        $this->newLine();
        $this->warn("  ⚠  请立即保存以下 Token，仅显示一次！");
        $this->newLine();
        $this->line("  <fg=yellow>{$tokenData['plain']}</>");
        $this->newLine();
        $this->info('========================================');
        $this->newLine();
        $this->line('  使用示例:');
        $this->line("  curl -H 'Authorization: Bearer {$tokenData['plain']}' \\");
        $this->line("       -H 'school-code: {$schoolCode}' \\");
        $this->line("       -H 'Accept: application/json' \\");
        $this->line("       " . url('/api/dify/admission/today-count'));
        $this->newLine();

        return self::SUCCESS;
    }
}
