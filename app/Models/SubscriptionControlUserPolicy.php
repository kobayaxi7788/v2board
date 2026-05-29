<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionControlUserPolicy extends Model
{
    public const STATUS_WATCHING = 'watching';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_RELEASED = 'released';

    protected $table = 'v2_subscription_control_user_policies';

    protected $fillable = [
        'user_id',
        'status',
        'reason',
        'ss2022_domain',
        'anytls_domain',
        'anytls_sni',
        'enabled',
        'created_by',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function statusText(): string
    {
        switch ($this->status) {
            case self::STATUS_BLOCKED:
                return '封控';
            case self::STATUS_WATCHING:
                return '观察';
            case self::STATUS_RELEASED:
                return '解除';
            default:
                return (string) $this->status;
        }
    }
}
