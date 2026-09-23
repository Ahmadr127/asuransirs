<?php

namespace App\Http\Requests\Provider;

use Illuminate\Foundation\Http\FormRequest;

class Update extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $provider = $this->route('provider');
        $providerId = $provider instanceof \App\Models\Provider ? $provider->id : $provider;

        return [
            'code' => 'required|string|max:50|unique:providers,code,' . $providerId,
            'name' => 'required|string|max:255',
            'status' => 'required|string|in:active,inactive',
        ];
    }
}
