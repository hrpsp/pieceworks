<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiSyncLog extends Model
{
    protected $table = 'api_sync_log';

    protected $fillable = [
        'sync_type',
        'records_received',
        'records_clean',
        'records_held',
        'error_message',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'synced_at'        => 'datetime',
            'records_received' => 'integer',
            'records_clean'    => 'integer',
            'records_held'     => 'integer',
        ];
    }
}
