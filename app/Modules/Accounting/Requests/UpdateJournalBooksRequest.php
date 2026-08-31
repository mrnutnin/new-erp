<?php

namespace App\Modules\Accounting\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateJournalBooksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['change_reason' => trim($this->string('change_reason')->toString())]);
    }

    public function rules(): array
    {
        return [
            'books' => ['required', 'array', 'size:5'],
            'books.*.is_active' => ['required', 'boolean'],
            'change_reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }
}
