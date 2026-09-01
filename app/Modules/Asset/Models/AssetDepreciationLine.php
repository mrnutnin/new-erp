<?php

namespace App\Modules\Asset\Models;

use App\Modules\Accounting\Models\JournalEntryLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetDepreciationLine extends Model
{
    protected $fillable = [
        'asset_depreciation_run_id', 'asset_id', 'asset_depreciation_book_id', 'line_number', 'asset_number', 'category_code', 'category_name',
        'opening_cost', 'opening_accumulated_depreciation', 'opening_accumulated_impairment', 'period_depreciation', 'catch_up_adjustment',
        'closing_cost', 'closing_accumulated_depreciation', 'closing_accumulated_impairment', 'closing_book_value',
        'calculation_input_snapshot', 'calculation_explanation', 'journal_entry_line_id',
    ];

    protected function casts(): array
    {
        return [
            'opening_cost' => 'decimal:2', 'opening_accumulated_depreciation' => 'decimal:2', 'opening_accumulated_impairment' => 'decimal:2',
            'period_depreciation' => 'decimal:2', 'catch_up_adjustment' => 'decimal:2', 'closing_cost' => 'decimal:2',
            'closing_accumulated_depreciation' => 'decimal:2', 'closing_accumulated_impairment' => 'decimal:2', 'closing_book_value' => 'decimal:2',
            'calculation_input_snapshot' => 'array', 'calculation_explanation' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AssetDepreciationRun::class, 'asset_depreciation_run_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function depreciationBook(): BelongsTo
    {
        return $this->belongsTo(AssetDepreciationBook::class, 'asset_depreciation_book_id');
    }

    public function journalEntryLine(): BelongsTo
    {
        return $this->belongsTo(JournalEntryLine::class);
    }
}
