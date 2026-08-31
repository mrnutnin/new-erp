<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\JournalBook;
use App\Modules\Accounting\Requests\UpdateJournalBooksRequest;
use App\Modules\Platform\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class JournalBookController extends Controller
{
    public function index(): View
    {
        return view('Accounting::journal-books.index', [
            'books' => JournalBook::query()->orderBy('sort_order')->get(),
        ]);
    }

    public function update(UpdateJournalBooksRequest $request, AuditLogger $audit): JsonResponse
    {
        $changed = DB::transaction(function () use ($request, $audit) {
            $books = JournalBook::query()->lockForUpdate()->orderBy('sort_order')->get();
            $submittedIds = collect(array_keys($request->validated('books')))->map(fn ($id) => (string) $id)->sort()->values();
            $systemIds = $books->pluck('id')->map(fn ($id) => (string) $id)->sort()->values();

            if ($submittedIds->all() !== $systemIds->all()) {
                throw ValidationException::withMessages(['books' => 'รายการสมุดบัญชีไม่ครบหรือไม่ถูกต้อง']);
            }

            $changed = 0;

            foreach ($books as $book) {
                $isActive = $request->boolean("books.{$book->id}.is_active");

                if ($book->is_active === $isActive) {
                    continue;
                }

                $before = ['is_active' => $book->is_active];
                $book->update(['is_active' => $isActive]);
                $audit->record(
                    'accounting.journal_book.updated',
                    $book,
                    $before,
                    ['is_active' => $isActive, 'change_reason' => $request->validated('change_reason')],
                    $request->user(),
                    $request,
                );
                $changed++;
            }

            return $changed;
        });

        return response()->json([
            'status' => true,
            'msg' => $changed > 0 ? 'บันทึกสถานะสมุดบัญชีแล้ว' : 'ไม่มีการเปลี่ยนแปลง',
        ]);
    }
}
