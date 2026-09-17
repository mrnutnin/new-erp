<?php

namespace App\Modules\Crm\Models;

use App\Models\Branch;
use App\Models\Party;
use App\Models\User;
use App\Modules\Pos\Models\SalesIntake;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Opportunity extends Model
{
    use SoftDeletes;

    public const STAGES = [
        'NEW' => ['label' => 'ลูกค้าเป้าหมายใหม่', 'class' => 'app-status-neutral', 'probability' => 10],
        'CONTACTED' => ['label' => 'ติดต่อแล้ว', 'class' => 'app-status-info', 'probability' => 25],
        'QUALIFIED' => ['label' => 'ผ่านการคัดกรอง', 'class' => 'app-status-info', 'probability' => 40],
        'PROPOSAL' => ['label' => 'เสนอขาย', 'class' => 'app-status-warning', 'probability' => 60],
        'NEGOTIATION' => ['label' => 'เจรจา', 'class' => 'app-status-warning', 'probability' => 80],
        'WON' => ['label' => 'ปิดการขายสำเร็จ', 'class' => 'app-status-success', 'probability' => 100],
        'LOST' => ['label' => 'ไม่สำเร็จ', 'class' => 'app-status-danger', 'probability' => 0],
    ];

    protected $table = 'crm_opportunities';

    protected $fillable = [
        'branch_id', 'party_id', 'owner_id', 'sales_intake_id', 'title', 'contact_name', 'phone', 'email', 'source', 'stage',
        'expected_value', 'probability', 'expected_close_date', 'next_action_at', 'notes', 'lost_reason', 'won_at', 'lost_at', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'branch_id' => 'integer', 'party_id' => 'integer', 'owner_id' => 'integer', 'sales_intake_id' => 'integer',
            'expected_value' => 'decimal:2', 'probability' => 'integer', 'expected_close_date' => 'date',
            'next_action_at' => 'datetime', 'won_at' => 'datetime', 'lost_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function party(): BelongsTo { return $this->belongsTo(Party::class); }
    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_id'); }
    public function salesIntake(): BelongsTo { return $this->belongsTo(SalesIntake::class); }
    public function activities(): HasMany { return $this->hasMany(Activity::class)->latest('created_at')->latest('id'); }
    public function productInterests(): HasMany { return $this->hasMany(ProductInterest::class)->latest('id'); }
}
