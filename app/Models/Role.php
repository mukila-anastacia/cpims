<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
class Role extends Model
{
    protected $guarded = [];

    public function users(): HasMany { return $this->hasMany(User::class); }
    public function approvalRules(): HasMany { return $this->hasMany(ApprovalRule::class); }
}
