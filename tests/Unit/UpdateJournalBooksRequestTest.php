<?php

namespace Tests\Unit;

use App\Modules\Accounting\Requests\UpdateJournalBooksRequest;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;

class UpdateJournalBooksRequestTest extends TestCase
{
    public function test_it_requires_all_five_books_and_a_change_reason(): void
    {
        $request = UpdateJournalBooksRequest::create('/', 'PUT', [
            'books' => [1 => ['is_active' => '1']],
            'change_reason' => 'short',
        ]);
        $validator = (new Factory(new Translator(new ArrayLoader, 'en')))->make($request->all(), $request->rules());

        $this->assertTrue($validator->errors()->has('books'));
        $this->assertTrue($validator->errors()->has('change_reason'));
    }
}
