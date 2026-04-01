<?php

namespace App\Http\Requests;

class CalculatePayrollRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'week_ref' => [
                'required',
                'string',
                'regex:/^\d{4}-W(0[1-9]|[1-4]\d|5[0-3])$/',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'week_ref.regex' => 'The week_ref must be in ISO format: YYYY-W## (e.g. 2025-W12).',
        ];
    }
}
