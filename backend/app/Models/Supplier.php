<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Supplier extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'code', 'company_name', 'code_name', 'contact_person', 'contact_number', 'channel',
        'address', 'store_link', 'status', 'created_by', 'updated_by',
    ];

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function items(): BelongsToMany { return $this->belongsToMany(RetailProduct::class, 'supplier_items')->withTimestamps(); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function updater(): BelongsTo { return $this->belongsTo(User::class, 'updated_by'); }
}
