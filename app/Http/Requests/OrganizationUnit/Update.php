<?php

namespace App\Http\Requests\OrganizationUnit;

use Illuminate\Foundation\Http\FormRequest;

class Update extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $unit = $this->route('organization_unit') ?? $this->route('organizationUnit');
        $unitId = $unit instanceof \App\Models\OrganizationUnit ? $unit->id : $unit;

        return [
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:organization_units,code,' . $unitId,
            'type_id' => 'required|exists:organization_types,id',
            'parent_id' => 'nullable|exists:organization_units,id',
            'head_id' => 'nullable|exists:users,id',
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper($this->code)]);
        }
    }
}
