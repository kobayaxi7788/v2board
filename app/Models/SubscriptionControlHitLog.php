<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionControlHitLog extends Model
{
    protected $table = 'v2_subscription_control_hit_logs';

    protected $fillable = [
        'user_id',
        'policy_type',
        'policy_id',
        'request_ip',
        'ip_location',
        'user_agent',
        'subscribe_type',
        'matched_keywords',
        'replaced_types',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
