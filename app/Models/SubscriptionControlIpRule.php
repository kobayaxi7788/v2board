<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionControlIpRule extends Model
{
    protected $table = 'v2_subscription_control_ip_rules';

    protected $fillable = [
        'name',
        'rule_type',
        'rule_value',
        'ip_version',
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

    public function ruleTypeText(): string
    {
        return $this->rule_type === 'cidr' ? 'CIDR网段' : '单个IP';
    }
}
