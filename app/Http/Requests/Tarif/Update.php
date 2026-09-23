<?php

namespace App\Http\Requests\Tarif;

use App\Models\Tarif;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class Update extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $surgery = trim((string) $this->input('surgery_type', ''));
        $helper = trim((string) $this->input('helper', ''));
        $this->merge([
            'surgery_type' => ($surgery === '' || $surgery === '-') ? null : $surgery,
            'helper' => ($helper === '' || $helper === '-') ? null : $this->input('helper'),
        ]);
    }

    public function rules(): array
    {
        return [
            'jenis_tarif_id' => 'required|integer|exists:jenis_tarifs,id',
            'provider_id' => 'required|integer|exists:providers,id',
            'service_id' => 'required|integer|exists:services,id',
            'class_id' => 'required|integer|exists:classes,id',
            'surgery_type' => ['nullable', 'string', Rule::in(Tarif::SURGERY_TYPES)],
            'helper' => 'nullable|string|max:255',
            'tariff' => 'required|numeric|min:0|max:999999999999.99',
            'valid_date_from' => 'required|date',
            'end_date_to' => 'required|date|after_or_equal:valid_date_from',
        ];
    }

    public function messages(): array
    {
        return [
            'end_date_to.after_or_equal' => 'Tanggal berakhir harus sama atau setelah tanggal berlaku.',
        ];
    }
}
