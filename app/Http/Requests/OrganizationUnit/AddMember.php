<?php

namespace App\Http\Requests\OrganizationUnit;

use Illuminate\Foundation\Http\FormRequest;

class AddMember extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => 'required|exists:users,id',
        ];
    }
}
