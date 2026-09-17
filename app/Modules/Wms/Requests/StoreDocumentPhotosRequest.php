<?php

namespace App\Modules\Wms\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreDocumentPhotosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'photos' => ['required', 'array', 'min:1', 'max:5'],
            'photos.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:10240'],
        ];
    }

    public function attributes(): array
    {
        return ['photos' => 'รูปภาพ', 'photos.*' => 'รูปภาพ'];
    }
}
