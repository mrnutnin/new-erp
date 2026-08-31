<?php

namespace App\Modules\Pos\Requests;

use App\Modules\Accounting\Models\FiscalPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class CancelFullPhysicalSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reversal_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('reversal_date')) {
                return;
            }

            $hasOpenPeriod = FiscalPeriod::query()
                ->where('status', 'OPEN')
                ->where('start_date', '<=', $this->input('reversal_date'))
                ->where('end_date', '>=', $this->input('reversal_date'))
                ->exists();

            if (! $hasOpenPeriod) {
                $validator->errors()->add('reversal_date', 'วันที่กลับรายการต้องอยู่ในงวดบัญชีที่เปิดอยู่');
            }
        }];
    }
}
