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
     * Import hanya dari batch yang sudah scan_completed. HTTP tidak
     * membaca Excel sama sekali.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'batch_id' => 'required|integer|exists:import_batches,id',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'batch_id.required' => 'Batch import tidak valid. Ulangi proses scan.',
            'batch_id.exists' => 'Batch import tidak ditemukan.',
        ];
    }
}
