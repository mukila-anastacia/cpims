<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
class ApprovalRule extends Model
{
    protected $guarded = [];
    protected $casts = ['min_value' => 'decimal:2', 'max_value' => 'decimal:2', 'is_active' => 'boolean'];

    public function role(): BelongsTo { return $this->belongsTo(Role::class); }
}
