<?php

namespace App\Modules\Pos\Requests;

use App\Modules\Pos\Models\SalesDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PostSalesDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['posting_date' => ['required', 'date_format:Y-m-d']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $document = $this->route('salesDocument');
            if ($validator->errors()->isEmpty() && $document instanceof SalesDocument
                && $this->input('posting_date') < $document->document_date->format('Y-m-d')) {
                $validator->errors()->add('posting_date', 'วันที่ Post ต้องไม่ก่อนวันที่เอกสาร');
            }
        }];
    }
}
