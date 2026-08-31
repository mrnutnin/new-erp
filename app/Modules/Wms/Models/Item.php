<?php

namespace App\Modules\Wms\Models;

use App\Modules\Accounting\Models\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Item extends Model
{
    use SoftDeletes;

    protected $table = 'wms_items';

    protected $fillable = ['category_id', 'code', 'name', 'item_type', 'base_uom', 'base_uom_id', 'is_stock_item', 'inventory_account_id', 'sales_account_id', 'cogs_account_id', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return ['category_id' => 'integer', 'base_uom_id' => 'integer', 'inventory_account_id' => 'integer', 'sales_account_id' => 'integer', 'cogs_account_id' => 'integer', 'is_stock_item' => 'boolean', 'is_active' => 'boolean'];
    }

    public function category()
    {
        return $this->belongsTo(ItemCategory::class, 'category_id');
    }

    public function baseUom()
    {
        return $this->belongsTo(Uom::class, 'base_uom_id');
    }

    public function inventoryAccount()
    {
        return $this->belongsTo(Account::class, 'inventory_account_id');
    }

    public function salesAccount()
    {
        return $this->belongsTo(Account::class, 'sales_account_id');
    }

    public function cogsAccount()
    {
        return $this->belongsTo(Account::class, 'cogs_account_id');
    }
}
