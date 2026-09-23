<?php

namespace App\Http\Requests\Service;

use Illuminate\Foundation\Http\FormRequest;

class Update extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $service = $this->route('service');
        $serviceId = $service instanceof \App\Models\Service ? $service->id : $service;

        return [
            'code' => 'required|string|max:50|unique:services,code,' . $serviceId,
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'required|string|in:active,inactive',
        ];
    }
}
