<?php

namespace Tests\Unit;

use App\Modules\Accounting\Requests\StoreFiscalYearRequest;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StoreFiscalYearRequestTest extends TestCase
{
    #[DataProvider('invalidStartDates')]
    public function test_fiscal_year_must_start_on_the_first_day_of_a_month(string $startDate): void
    {
        $request = StoreFiscalYearRequest::create('/', 'POST', ['start_date' => $startDate]);
        $validator = (new Factory(new Translator(new ArrayLoader, 'en')))->make(
            $request->all(),
            ['start_date' => $request->rules()['start_date']],
        );

        $this->assertTrue($validator->errors()->has('start_date'));
    }

    public static function invalidStartDates(): array
    {
        return [['invalid-date'], ['2026-04-02']];
    }
}
