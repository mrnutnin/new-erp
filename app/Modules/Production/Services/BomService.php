<?php

namespace App\Modules\Production\Services;

use App\Models\Branch;
use App\Models\User;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\BomLine;
use App\Modules\Production\Models\BomRevision;
use App\Modules\Production\Support\BomCycleDetector;
use App\Modules\Wms\Models\Item;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BomService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(array $values, Branch $branch, User $actor, Request $request): Bom
    {
        return DB::transaction(function () use ($values, $branch, $actor, $request): Bom {
            $header = $this->header($values, $branch);
            $dates = $this->revisionDates($values);
            $bom = Bom::query()->create([...$header, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
            $revision = $bom->revisions()->create([
                'revision_number' => 1,
                'status' => 'DRAFT',
                ...$dates,
                'notes' => $this->nullable($values['notes'] ?? null),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            $this->replaceLines($bom, $revision, $values['lines'] ?? []);
            $this->replaceOperations($revision, $values['operations'] ?? []);
            $this->audit->record('production.bom.created', $bom, [], $bom->load('revisions.lines')->toArray(), $actor, $request);

            return $bom->fresh(['finishedItem', 'baseUom', 'revisions.lines']);
        }, 3);
    }

    public function updateDraft(BomRevision $revision, array $values, User $actor, Request $request): BomRevision
    {
        return DB::transaction(function () use ($revision, $values, $actor, $request): BomRevision {
            $revision = BomRevision::query()->with('bom')->lockForUpdate()->findOrFail($revision->id);
            $this->assertDraft($revision);
            $before = $revision->load('lines')->toArray();
            $revision->update([
                ...$this->revisionDates($values),
                'notes' => $this->nullable($values['notes'] ?? null),
                'updated_by' => $actor->id,
            ]);
            $revision->lines()->delete();
            $this->replaceLines($revision->bom, $revision, $values['lines'] ?? []);
            $this->replaceOperations($revision, $values['operations'] ?? []);
            $this->audit->record('production.bom_revision.updated', $revision, $before, $revision->fresh('lines')->toArray(), $actor, $request);

            return $revision->fresh('lines');
        }, 3);
    }

    public function copyRevision(BomRevision $source, User $actor, Request $request): BomRevision
    {
        return DB::transaction(function () use ($source, $actor, $request): BomRevision {
            $source = BomRevision::query()->with(['bom', 'operations', 'lines.substitutes'])->lockForUpdate()->findOrFail($source->id);
            Bom::query()->whereKey($source->bom_id)->lockForUpdate()->firstOrFail();
            $number = (int) BomRevision::query()->where('bom_id', $source->bom_id)->lockForUpdate()->max('revision_number') + 1;
            $copy = BomRevision::query()->create([
                'bom_id' => $source->bom_id,
                'revision_number' => $number,
                'status' => 'DRAFT',
                'effective_from' => null,
                'effective_to' => null,
                'notes' => $source->notes,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            foreach ($source->operations as $operation) $copy->operations()->create($operation->only(['sequence', 'name', 'planned_minutes', 'notes']));
            foreach ($source->lines as $line) {
                $newLine = $copy->lines()->create($line->only(['line_number', 'component_item_id', 'uom_id', 'quantity', 'notes']));
                foreach ($line->substitutes as $substitute) $newLine->substitutes()->create($substitute->only(['substitute_item_id', 'uom_id', 'quantity_factor', 'priority', 'notes']));
            }
            $this->audit->record('production.bom_revision.copied', $copy, [], $copy->load('lines')->toArray(), $actor, $request);

            return $copy->fresh('lines');
        }, 3);
    }

    public function deleteDraft(Bom $bom, BomRevision $revision, User $actor, Request $request): void
    {
        DB::transaction(function () use ($bom, $revision, $actor, $request): void {
            $revision = BomRevision::query()->with('bom')->lockForUpdate()->findOrFail($revision->id);
            $this->assertDraft($revision);
            if ((int) $revision->bom_id !== (int) $bom->id || BomRevision::query()->where('bom_id', $bom->id)->count() !== 1) {
                throw ValidationException::withMessages(['bom' => 'ลบได้เฉพาะ BOM ร่างที่ยังไม่มี Revision อื่น']);
            }
            $before = $revision->load('lines')->toArray();
            $revision->lines()->delete();
            $revision->delete();
            $bom->delete();
            $this->audit->record('production.bom.deleted', $bom, $before, [], $actor, $request);
        }, 3);
    }

    public function deactivate(BomRevision $revision, User $actor, Request $request): BomRevision
    {
        return DB::transaction(function () use ($revision, $actor, $request): BomRevision {
            $revision = BomRevision::query()->with('bom')->lockForUpdate()->findOrFail($revision->id);
            if ($revision->status !== 'ACTIVE') throw ValidationException::withMessages(['status' => 'ปิดใช้งานได้เฉพาะ Revision ที่กำลังใช้งาน']);
            $before = $revision->toArray();
            $revision->forceFill(['status' => 'INACTIVE', 'effective_to' => today(), 'updated_by' => $actor->id])->save();
            $this->audit->record('production.bom.deactivated', $revision, $before, $revision->fresh()->toArray(), $actor, $request);

            return $revision->fresh();
        }, 3);
    }

    public function activate(BomRevision $revision, User $actor, Request $request): BomRevision
    {
        return DB::transaction(function () use ($revision, $actor, $request): BomRevision {
            $revision = BomRevision::query()->with(['bom', 'lines'])->lockForUpdate()->findOrFail($revision->id);
            $this->assertDraft($revision);
            if (! $revision->effective_from) {
                throw ValidationException::withMessages(['effective_from' => 'Revision ที่เปิดใช้ต้องมีวันที่เริ่มใช้']);
            }
            $this->validateLines($revision->bom, $revision->lines->toArray());
            $this->assertNoCycle($revision->bom, $revision->lines->pluck('component_item_id')->map(fn ($id): int => (int) $id)->all());
            $otherActiveBom = BomRevision::query()
                ->join('production_boms', 'production_boms.id', '=', 'production_bom_revisions.bom_id')
                ->where('production_boms.branch_id', $revision->bom->branch_id)
                ->where('production_boms.finished_item_id', $revision->bom->finished_item_id)
                ->where('production_bom_revisions.status', 'ACTIVE')
                ->where('production_bom_revisions.bom_id', '!=', $revision->bom_id)
                ->lockForUpdate()->exists();
            if ($otherActiveBom) {
                throw ValidationException::withMessages(['finished_item_id' => 'สินค้านี้มี BOM Active อื่นในสาขาแล้ว']);
            }

            $before = $revision->toArray();
            $activeRevisions = BomRevision::query()->where('bom_id', $revision->bom_id)->where('status', 'ACTIVE')->lockForUpdate()->get();
            if ($activeRevisions->contains(fn (BomRevision $active): bool => $active->effective_from && $revision->effective_from->lessThanOrEqualTo($active->effective_from))) {
                throw ValidationException::withMessages(['effective_from' => 'Revision ใหม่ต้องเริ่มหลัง Revision ที่ Active']);
            }
            $activeRevisions->each(fn (BomRevision $active) => $active->forceFill(['status' => 'INACTIVE', 'effective_to' => $revision->effective_from->copy()->subDay(), 'updated_by' => $actor->id])->save());
            $revision->forceFill(['status' => 'ACTIVE', 'activated_at' => now(), 'activated_by' => $actor->id, 'updated_by' => $actor->id])->save();
            $this->audit->record('production.bom_revision.activated', $revision, $before, $revision->fresh('lines')->toArray(), $actor, $request);

            return $revision->fresh('lines');
        }, 3);
    }

    private function header(array $values, Branch $branch): array
    {
        $code = strtoupper(trim((string) ($values['code'] ?? '')));
        $name = trim((string) ($values['name'] ?? ''));
        if ($code === '' || mb_strlen($code) > 50 || $name === '' || mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['code' => 'กรุณาระบุรหัสและชื่อ BOM ให้ถูกต้อง']);
        }
        if (Bom::query()->where('branch_id', $branch->id)->where('code', $code)->exists()) {
            throw ValidationException::withMessages(['code' => 'รหัส BOM ซ้ำในสาขา']);
        }
        $item = Item::query()->whereKey($values['finished_item_id'] ?? null)->where('is_active', true)->where('is_stock_item', true)->first();
        if (! $item || (int) $item->base_uom_id !== (int) ($values['base_uom_id'] ?? 0)) {
            throw ValidationException::withMessages(['finished_item_id' => 'สินค้าสำเร็จรูปต้องเป็น Stock Item ที่ Active และใช้หน่วยฐาน']);
        }

        return ['branch_id' => $branch->id, 'code' => $code, 'name' => $name, 'finished_item_id' => $item->id, 'base_uom_id' => $item->base_uom_id, 'is_active' => true];
    }

    private function replaceLines(Bom $bom, BomRevision $revision, array $lines): void
    {
        $this->validateLines($bom, $lines);
        foreach (array_values($lines) as $index => $line) {
            BomLine::query()->create([
                'bom_revision_id' => $revision->id,
                'line_number' => $index + 1,
                'component_item_id' => $line['component_item_id'],
                'uom_id' => $line['uom_id'],
                'quantity' => BigDecimal::of((string) $line['quantity'])->toScale(8, RoundingMode::UNNECESSARY)->__toString(),
                'notes' => $this->nullable($line['notes'] ?? null),
            ]);
            $created = BomLine::query()->where('bom_revision_id', $revision->id)->where('line_number', $index + 1)->firstOrFail();
            foreach (array_values($line['substitutes'] ?? []) as $substitute) {
                $created->substitutes()->create([
                    'substitute_item_id' => $substitute['substitute_item_id'], 'uom_id' => $substitute['uom_id'],
                    'quantity_factor' => $substitute['quantity_factor'], 'priority' => $substitute['priority'], 'notes' => $this->nullable($substitute['notes'] ?? null),
                ]);
            }
        }
    }

    private function replaceOperations(BomRevision $revision, array $operations): void
    {
        $revision->operations()->delete();
        foreach (array_values($operations) as $index => $operation) {
            $revision->operations()->create(['sequence' => $index + 1, 'name' => trim($operation['name']), 'planned_minutes' => $operation['planned_minutes'] ?? null, 'notes' => $this->nullable($operation['notes'] ?? null)]);
        }
    }

    private function validateLines(Bom $bom, array $lines): void
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'BOM ต้องมีวัตถุดิบอย่างน้อยหนึ่งรายการ']);
        }
        $seen = [];
        foreach (array_values($lines) as $index => $line) {
            $itemId = (int) ($line['component_item_id'] ?? 0);
            if ($itemId < 1 || $itemId === (int) $bom->finished_item_id || isset($seen[$itemId])) {
                throw ValidationException::withMessages(["lines.{$index}.component_item_id" => 'วัตถุดิบต้องไม่ซ้ำและห้ามเป็นสินค้าสำเร็จรูปเดียวกัน']);
            }
            $seen[$itemId] = true;
            $item = Item::query()->whereKey($itemId)->where('is_active', true)->where('is_stock_item', true)->first();
            if (! $item || (int) $item->base_uom_id !== (int) ($line['uom_id'] ?? 0)) {
                throw ValidationException::withMessages(["lines.{$index}.uom_id" => 'วัตถุดิบต้องเป็น Stock Item ที่ Active และใช้หน่วยฐาน']);
            }
            foreach (array_values($line['substitutes'] ?? []) as $subIndex => $substitute) {
                if ((int) ($substitute['substitute_item_id'] ?? 0) === $itemId || (int) ($substitute['substitute_item_id'] ?? 0) === (int) $bom->finished_item_id) {
                    throw ValidationException::withMessages(["lines.{$index}.substitutes.{$subIndex}.substitute_item_id" => 'วัตถุดิบทดแทนห้ามเป็นวัตถุดิบหลักหรือสินค้าสำเร็จรูป']);
                }
            }
            try {
                $quantity = BigDecimal::of((string) ($line['quantity'] ?? '0'))->toScale(8, RoundingMode::UNNECESSARY);
            } catch (\Throwable) {
                throw ValidationException::withMessages(["lines.{$index}.quantity" => 'จำนวนต้องมีทศนิยมไม่เกิน 8 ตำแหน่ง']);
            }
            if ($quantity->isLessThanOrEqualTo(BigDecimal::zero())) {
                throw ValidationException::withMessages(["lines.{$index}.quantity" => 'จำนวนต้องมากกว่าศูนย์']);
            }
        }
    }

    /** @param list<int> $components */
    private function assertNoCycle(Bom $bom, array $components): void
    {
        $edges = DB::table('production_bom_lines as lines')
            ->join('production_bom_revisions as revisions', 'revisions.id', '=', 'lines.bom_revision_id')
            ->join('production_boms as boms', 'boms.id', '=', 'revisions.bom_id')
            ->where('boms.branch_id', $bom->branch_id)
            ->where('revisions.status', 'ACTIVE')
            ->where('boms.id', '!=', $bom->id)
            ->select(['boms.finished_item_id', 'lines.component_item_id'])
            ->get()->groupBy('finished_item_id')->map(fn ($rows) => $rows->pluck('component_item_id')->map(fn ($id): int => (int) $id)->all())->all();

        BomCycleDetector::assertAcyclic((int) $bom->finished_item_id, $components, $edges);
    }

    private function revisionDates(array $values): array
    {
        $from = $this->nullable($values['effective_from'] ?? null);
        $to = $this->nullable($values['effective_to'] ?? null);
        foreach (['effective_from' => $from, 'effective_to' => $to] as $field => $date) {
            if ($date !== null && (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || date('Y-m-d', strtotime($date)) !== $date)) {
                throw ValidationException::withMessages([$field => 'วันที่ต้องอยู่ในรูปแบบ Y-m-d']);
            }
        }
        if ($from !== null && $to !== null && $to < $from) {
            throw ValidationException::withMessages(['effective_to' => 'วันที่สิ้นสุดต้องไม่ก่อนวันที่เริ่มใช้']);
        }

        return ['effective_from' => $from, 'effective_to' => $to];
    }

    private function assertDraft(BomRevision $revision): void
    {
        if ($revision->status !== 'DRAFT') {
            throw ValidationException::withMessages(['status' => 'แก้ไขหรือเปิดใช้ได้เฉพาะ BOM Revision สถานะร่าง']);
        }
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
