<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
class Category extends Model
{
    protected $guarded = [];

    public function items(): HasMany { return $this->hasMany(Item::class); }
    public function budgets(): HasMany { return $this->hasMany(Budget::class); }
}
