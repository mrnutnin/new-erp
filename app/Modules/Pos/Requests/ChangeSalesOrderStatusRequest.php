<?php

namespace App\Modules\Pos\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChangeSalesOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['reason' => $this->routeIs('pos.sales-orders.cancel') ? ['required', 'string', 'min:10', 'max:500'] : ['nullable', 'string', 'max:500']];
    }
}
