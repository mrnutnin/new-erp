<?php

namespace App\Modules\Accounting\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChangeJournalEntryStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['reason' => trim($this->string('reason')->toString())]);
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'reversal_date' => [$this->routeIs('accounting.journal-entries.reverse') ? 'required' : 'nullable', 'date_format:Y-m-d'],
        ];
    }
}
