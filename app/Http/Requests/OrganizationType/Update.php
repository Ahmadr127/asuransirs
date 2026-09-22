<?php

namespace App\Http\Requests\OrganizationType;

use Illuminate\Foundation\Http\FormRequest;

class Update extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $type = $this->route('organization_type') ?? $this->route('organizationType');
        $typeId = $type instanceof \App\Models\OrganizationType ? $type->id : $type;

        return [
            'name' => 'required|string|max:255|unique:organization_types,name,' . $typeId,
            'display_name' => 'required|string|max:255',
            'level' => 'required|integer|min:1',
            'description' => 'nullable|string',
        ];
    }
}
