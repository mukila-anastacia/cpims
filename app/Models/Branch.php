<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
class Branch extends Model
{
    protected $guarded = [];
    protected $casts = ['is_head_office' => 'boolean'];

    public function users(): HasMany { return $this->hasMany(User::class); }
    public function budgets(): HasMany { return $this->hasMany(Budget::class); }
    public function purchaseRequests(): HasMany { return $this->hasMany(PurchaseRequest::class); }
}
