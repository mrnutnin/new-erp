<?php

namespace App\Modules\Wms\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StageOpeningBalanceImportRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return ['file' => ['required', 'file', 'mimes:xlsx', 'max:10240'], 'cutover_date' => ['required', 'date'], 'costing_method' => ['required', 'in:AVG,FIFO'], 'source_reference' => ['nullable', 'string', 'max:100']]; }
}
