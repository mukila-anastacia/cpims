<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
class User extends Authenticatable
{
    protected $guarded = [];
    protected $hidden = ['password', 'remember_token'];
    protected $casts = [
        'password' => 'hashed',
        'locked_until' => 'datetime',
        'last_login_at' => 'datetime',
        'deputy_until' => 'date',
    ];

    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function role(): BelongsTo { return $this->belongsTo(Role::class); }
    public function deputy(): BelongsTo { return $this->belongsTo(User::class, 'deputy_user_id'); }

    /** True if the user holds any of the given role names. */
    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role->name, $roles, true);
    }

    /** Head office roles can see all branches. */
    public function isHeadOffice(): bool
    {
        return (bool) $this->branch->is_head_office;
    }
}
