<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdvanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'week_ref'       => $this->week_ref,
            'amount'         => $this->amount,
            'payment_method' => $this->payment_method,
            'deduction_week' => $this->deduction_week,
            'carry_weeks'    => $this->carry_weeks,
            'status'         => $this->status,
            'approved_by'    => $this->whenLoaded('approver', fn () => [
                'id'   => $this->approver->id,
                'name' => $this->approver->name,
            ]),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
