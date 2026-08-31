<?php
namespace App\Modules\Wms\Models;
use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\SoftDeletes;
final class IssueLine extends Model { use SoftDeletes; protected $table='wms_issue_lines'; protected $guarded=[]; protected function casts():array{return ['quantity'=>'decimal:8'];} public function document(){return $this->belongsTo(IssueDocument::class,'document_id');} public function item(){return $this->belongsTo(Item::class);} public function uom(){return $this->belongsTo(Uom::class);} public function movement(){return $this->belongsTo(StockMovement::class,'stock_movement_id');} public function allocation(){return $this->belongsTo(CostAllocation::class,'cost_allocation_id');} }
