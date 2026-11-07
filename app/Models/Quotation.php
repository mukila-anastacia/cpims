<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
class Quotation extends Model
{
    protected $guarded = [];
    protected $casts = ['valid_until' => 'date', 'is_selected' => 'boolean'];

    public function purchaseRequest(): BelongsTo { return $this->belongsTo(PurchaseRequest::class); }
    public function vendor(): BelongsTo { return $this->belongsTo(Vendor::class); }
    public function lines(): HasMany { return $this->hasMany(QuotationLine::class); }
}
