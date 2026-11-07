<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
class Approval extends Model
{
    protected $guarded = [];
    protected $casts = ['decided_at' => 'datetime'];

    public function purchaseRequest(): BelongsTo { return $this->belongsTo(PurchaseRequest::class); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approver_id'); }
}
