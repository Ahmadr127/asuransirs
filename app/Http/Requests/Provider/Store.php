<?php

namespace App\Http\Requests\Provider;

use Illuminate\Foundation\Http\FormRequest;

class Store extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => 'required|string|max:50|unique:providers,code',
            'name' => 'required|string|max:255',
            'status' => 'required|string|in:active,inactive',
        ];
    }
}
