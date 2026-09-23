<?php

namespace App\Http\Requests\ServiceClass;

use Illuminate\Foundation\Http\FormRequest;

class Update extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $class = $this->route('serviceClass');
        $classId = $class instanceof \App\Models\ServiceClass ? $class->id : $class;

        return [
            'code' => 'required|string|max:50|unique:classes,code,' . $classId,
            'name' => 'required|string|max:255',
            'status' => 'required|string|in:active,inactive',
        ];
    }
}
