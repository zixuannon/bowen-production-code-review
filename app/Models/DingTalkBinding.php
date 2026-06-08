<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DingTalkBinding extends Model
{
    /**
     * 绑定表固定放在主库，不加 school 库。
     */
    protected $connection = 'mysql';

    protected $fillable = [
        'dingtalk_open_id',
        'dingtalk_union_id',
        'school_id',
        'school_code',
        'user_id',
        'dingtalk_nick',
        'last_login_at',
    ];
}
