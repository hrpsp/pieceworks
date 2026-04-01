<?php

namespace App\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;

class UpdateWorkerRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $workerId = $this->route('worker')->id;

        return [
            'name'         => ['sometimes', 'string', 'max:255'],
            'cnic'         => ['sometimes', 'nullable', 'string', 'max:15',
                               Rule::unique('workers', 'cnic')->ignore($workerId)],
            'biometric_id' => ['sometimes', 'nullable', 'string', 'max:50',
                               Rule::unique('workers', 'biometric_id')->ignore($workerId)],
            'photo_path'   => ['sometimes', 'nullable', 'string', 'max:500'],

            'contractor_id'   => ['sometimes', 'nullable', 'integer', 'exists:contractors,id'],
            'default_line_id' => ['sometimes', 'nullable', 'integer', 'exists:lines,id'],

            'worker_type'   => ['sometimes', 'nullable', Rule::in(['permanent', 'contractual', 'trainee'])],
            'grade'         => ['sometimes', 'nullable', Rule::in(['A', 'B', 'C', 'D', 'trainee'])],
            'default_shift' => ['sometimes', 'nullable', Rule::in(['morning', 'evening', 'night'])],
            'status'        => ['sometimes', 'nullable', Rule::in(['active', 'inactive', 'terminated'])],

            'training_period'   => ['sometimes', 'nullable', 'integer', 'min:0'],
            'training_end_date' => ['sometimes', 'nullable', 'date'],

            'payment_method' => ['sometimes', 'nullable', Rule::in(['cash', 'bank', 'easypaisa', 'jazzcash'])],
            'payment_number' => ['sometimes', 'nullable', 'string', 'max:30'],
            'whatsapp'       => ['sometimes', 'nullable', 'string', 'max:20'],

            'eobi_number'  => ['sometimes', 'nullable', 'string', 'max:20'],
            'pessi_number' => ['sometimes', 'nullable', 'string', 'max:20'],
            'join_date'    => ['sometimes', 'nullable', 'date'],
        ];
    }
}
