<?php

namespace App\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;

class StoreWorkerRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'          => ['required', 'string', 'max:255'],
            'cnic'          => ['nullable', 'string', 'max:15', 'unique:workers,cnic'],
            'biometric_id'  => ['nullable', 'string', 'max:50', 'unique:workers,biometric_id'],
            'photo_path'    => ['nullable', 'string', 'max:500'],

            'contractor_id'   => ['nullable', 'integer', 'exists:contractors,id'],
            'default_line_id' => ['nullable', 'integer', 'exists:lines,id'],

            'worker_type'   => ['nullable', Rule::in(['permanent', 'contractual', 'trainee'])],
            'grade'         => ['nullable', Rule::in(['A', 'B', 'C', 'D', 'trainee'])],
            'default_shift' => ['nullable', Rule::in(['morning', 'evening', 'night'])],
            'status'        => ['nullable', Rule::in(['active', 'inactive', 'terminated'])],

            'training_period'   => ['nullable', 'integer', 'min:0'],
            'training_end_date' => ['nullable', 'date'],

            'payment_method' => ['nullable', Rule::in(['cash', 'bank', 'easypaisa', 'jazzcash'])],
            'payment_number' => ['nullable', 'string', 'max:30'],
            'whatsapp'       => ['nullable', 'string', 'max:20'],

            'eobi_number'  => ['nullable', 'string', 'max:20'],
            'pessi_number' => ['nullable', 'string', 'max:20'],
            'join_date'    => ['nullable', 'date'],
        ];
    }
}
