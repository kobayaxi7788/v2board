<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionControlUaRule extends Model
{
    protected $table = 'v2_subscription_control_ua_rules';

    protected $fillable = [
        'name',
        'keywords',
        'match_mode',
        'ss2022_domain',
        'anytls_domain',
        'anytls_sni',
        'priority',
        'enabled',
        'created_by',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function matchModeText(): string
    {
        return $this->match_mode === 'all' ? '全部关键词命中' : '任一关键词命中';
    }
}
