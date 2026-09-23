<?php

namespace App\Http\Requests\TarifImport;

use Illuminate\Foundation\Http\FormRequest;

class ScanRequest extends FormRequest
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
            'jenis_tarif_id' => 'required|integer|exists:jenis_tarifs,id',
            'file' => 'required|file|mimes:xlsx,xls,csv|max:20480',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'jenis_tarif_id.required' => 'Jenis tarif wajib dipilih.',
            'jenis_tarif_id.exists' => 'Jenis tarif tidak ditemukan di master.',
            'file.required' => 'File Excel wajib diupload.',
            'file.mimes' => 'Format file harus .xlsx, .xls, atau .csv.',
            'file.max' => 'Ukuran file maksimal 20 MB.',
        ];
    }
}
