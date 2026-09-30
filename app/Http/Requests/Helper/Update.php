<?php

namespace App\Http\Requests\Helper;

use Illuminate\Foundation\Http\FormRequest;

class Update extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $helper = $this->route('helper');
        $helperId = $helper instanceof \App\Models\Helper ? $helper->id : $helper;

        return [
            'code' => 'required|string|max:50|unique:helpers,code,' . $helperId,
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'operator_pct' => 'required|numeric|min:0|max:1000',
            'anesthesia_pct' => 'required|numeric|min:0|max:1000',
            'room_pct' => 'required|numeric|min:0|max:1000',
            'child_pct' => 'required|numeric|min:0|max:1000',
            'status' => 'required|string|in:active,inactive',
        ];
    }
}
