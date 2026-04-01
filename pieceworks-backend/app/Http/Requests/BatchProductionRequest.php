<?php

namespace App\Http\Requests;

class BatchProductionRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'records'                           => ['required', 'array', 'min:1', 'max:100'],
            'records.*.worker_id'               => ['required', 'integer', 'exists:workers,id'],
            'records.*.line_id'                 => ['required', 'integer', 'exists:lines,id'],
            'records.*.work_date'               => ['required', 'date'],
            'records.*.shift'                   => ['required', 'in:morning,evening,night'],
            'records.*.task'                    => ['required', 'string', 'max:100'],
            'records.*.pairs_produced'          => ['required', 'integer', 'min:0'],
            'records.*.style_sku_id'            => ['nullable', 'integer', 'exists:style_sku,id'],
            'records.*.source_tag'              => ['nullable', 'in:bata_api,manual_supervisor,manual_backfill'],
            'records.*.shift_adjustment'        => ['nullable', 'numeric'],
            'records.*.shift_adj_authorized_by' => ['nullable', 'integer', 'exists:users,id'],
            'records.*.shift_adj_reason'        => ['nullable', 'string', 'max:500'],
            'records.*.supervisor_notes'        => ['nullable', 'string', 'max:1000'],
        ];
    }
}
