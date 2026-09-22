<?php

namespace App\Http\Requests\Permission;

use Illuminate\Foundation\Http\FormRequest;

class Update extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $perm = $this->route('permission');
        $permId = $perm instanceof \App\Models\Permission ? $perm->id : $perm;

        return [
            'name' => 'required|string|max:255|unique:permissions,name,' . $permId,
            'display_name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ];
    }
}
