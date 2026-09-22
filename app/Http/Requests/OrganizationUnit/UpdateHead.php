<?php

namespace App\Http\Requests\OrganizationUnit;

use Illuminate\Foundation\Http\FormRequest;

class UpdateHead extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'head_id' => 'nullable|exists:users,id',
        ];
    }
}
