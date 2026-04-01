<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductionRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'work_date'        => $this->work_date?->toDateString(),
            'shift'            => $this->shift,
            'task'             => $this->task,
            'pairs_produced'   => $this->pairs_produced,
            'rate_amount'      => $this->rate_amount,
            'gross_earnings'   => $this->gross_earnings,
            'shift_adjustment' => $this->shift_adjustment,
            'source_tag'       => $this->source_tag,
            'validation_status'=> $this->validation_status,
            'is_locked'        => $this->is_locked,

            'line' => $this->whenLoaded('line', fn () => [
                'id'   => $this->line->id,
                'name' => $this->line->name,
            ]),
            'style_sku' => $this->whenLoaded('styleSku', fn () => [
                'id'         => $this->styleSku->id,
                'style_code' => $this->styleSku->style_code,
                'style_name' => $this->styleSku->style_name,
            ]),
            'shift_adj_reason'        => $this->shift_adj_reason,
            'shift_adj_authorized_by' => $this->whenLoaded('shiftAuthorizer', fn () => [
                'id'   => $this->shiftAuthorizer->id,
                'name' => $this->shiftAuthorizer->name,
            ]),

            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
