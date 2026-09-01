<?php

namespace App\Modules\Asset\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssetAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'mimetypes:application/pdf,image/jpeg,image/png,image/webp', 'max:10240'],
            'file_type' => ['required', Rule::in(['PHOTO', 'INVOICE', 'WARRANTY', 'INSURANCE', 'REPAIR_REPORT', 'DISPOSAL_EVIDENCE', 'OTHER'])],
        ];
    }
}
