<?php

namespace App\Http\Requests\TarifImport;

use Illuminate\Foundation\Http\FormRequest;

class CommitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => 'required|string|max:64',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'token.required' => 'Sesi scan tidak valid. Ulangi proses scan.',
        ];
    }
}
