<?php

namespace App\Http\Requests;

class ResolveExceptionRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resolution_note' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }
}
