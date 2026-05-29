<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSubscribeLog extends Model
{
    protected $table = 'v2_user_subscribe_log';

    protected $fillable = [
        'user_id',
        'request_ip',
        'request_user_agent',
        'client_type',
        'subscribe_type',
        'request_path',
        'query_string',
        'ip_location',
        'risk_score',
        'risk_tags',
        'matched_policy_type',
        'matched_policy_id',
        'is_policy_applied',
        'replaced_types',
    ];

    protected $casts = [
        'is_policy_applied' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
