<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'cnic'         => $this->cnic,
            'biometric_id' => $this->biometric_id,
            'worker_type'  => $this->worker_type,
            'grade'        => $this->grade,
            'status'       => $this->status,

            'default_shift' => $this->default_shift,
            'join_date'     => $this->join_date?->toDateString(),

            // Training
            'training_period'   => $this->training_period,
            'training_end_date' => $this->training_end_date?->toDateString(),
            'is_in_training'    => $this->is_in_training,

            // Payment
            'payment_method' => $this->payment_method,
            'payment_number' => $this->payment_number,
            'whatsapp'       => $this->whatsapp,

            // Compliance
            'eobi_number'  => $this->eobi_number,
            'pessi_number' => $this->pessi_number,

            // Media
            'photo_path' => $this->photo_path,

            // Relations (loaded conditionally)
            'contractor' => $this->whenLoaded('contractor', fn () => [
                'id'   => $this->contractor->id,
                'name' => $this->contractor->name,
            ]),
            'default_line' => $this->whenLoaded('defaultLine', fn () => [
                'id'   => $this->defaultLine->id,
                'name' => $this->defaultLine->name,
            ]),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),
        ];
    }
}
