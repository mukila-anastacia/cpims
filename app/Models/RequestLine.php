<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
class RequestLine extends Model
{
    protected $guarded = [];
    protected $casts = ['quantity' => 'decimal:2', 'unit_cost_est' => 'decimal:2'];

    public function purchaseRequest(): BelongsTo { return $this->belongsTo(PurchaseRequest::class); }
    public function item(): BelongsTo { return $this->belongsTo(Item::class); }
}
