<?php

namespace App\Http\Requests\JenisTarif;

use Illuminate\Foundation\Http\FormRequest;

class Update extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $jenis = $this->route('jenis_tarif');
        $jenisId = $jenis instanceof \App\Models\JenisTarif ? $jenis->id : $jenis;

        return [
            'code' => 'required|string|max:50|unique:jenis_tarifs,code,' . $jenisId,
            'name' => 'required|string|max:255',
            'status' => 'required|string|in:active,inactive',
        ];
    }
}
