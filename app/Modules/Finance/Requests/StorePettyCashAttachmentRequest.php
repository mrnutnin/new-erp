<?php

namespace App\Modules\Finance\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StorePettyCashAttachmentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'mimetypes:application/pdf,image/jpeg,image/png,image/webp', 'max:10240'],
            'file_type' => ['required', Rule::in(['RECEIPT', 'TAX_INVOICE', 'WHT_CERTIFICATE', 'CASH_COUNT', 'RETURN', 'REFUND', 'OTHER'])],
        ];
    }
}
