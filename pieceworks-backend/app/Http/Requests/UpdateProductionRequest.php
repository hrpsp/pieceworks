<?php

namespace App\Http\Requests;

class UpdateProductionRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pairs_produced'          => ['sometimes', 'integer', 'min:0'],
            'rate_amount'             => ['sometimes', 'numeric', 'min:0'],
            'shift_adjustment'        => ['sometimes', 'numeric'],
            'shift_adj_authorized_by' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'shift_adj_reason'        => ['sometimes', 'nullable', 'string', 'max:500'],
            'supervisor_notes'        => ['sometimes', 'nullable', 'string', 'max:1000'],
            'validation_status'       => ['sometimes', 'in:pending,approved,flagged,rejected'],
        ];
    }
}
