<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
class PurchaseRequest extends Model
{
    protected $guarded = [];
    protected $casts = [
        'needed_by' => 'date',
        'submitted_at' => 'datetime',
        'total_estimate' => 'decimal:2',
        'duplicate_flag' => 'boolean',
        'budget_exception' => 'boolean',
    ];

    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function category(): BelongsTo { return $this->belongsTo(Category::class); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requester_id'); }
    public function lines(): HasMany { return $this->hasMany(RequestLine::class); }
    public function approvals(): HasMany { return $this->hasMany(Approval::class); }
    public function quotations(): HasMany { return $this->hasMany(Quotation::class); }
    public function purchaseOrder(): HasOne { return $this->hasOne(PurchaseOrder::class); }
}
