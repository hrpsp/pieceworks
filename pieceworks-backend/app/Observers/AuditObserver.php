<?php

namespace App\Observers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

class AuditObserver
{
    public function created(Model $model): void
    {
        $this->log('created', $model, null, $model->getAttributes());
    }

    public function updated(Model $model): void
    {
        $dirty = $model->getDirty();
        if (empty($dirty)) {
            return;
        }

        $old = array_intersect_key($model->getOriginal(), $dirty);
        $this->log('updated', $model, $old, $dirty);
    }

    public function deleted(Model $model): void
    {
        $this->log('deleted', $model, $model->getAttributes(), null);
    }

    private function log(string $action, Model $model, ?array $old, ?array $new): void
    {
        // Strip hidden fields (passwords, tokens) from audit payload
        $hidden = $model->getHidden();
        $strip  = fn(?array $d) => $d ? array_diff_key($d, array_flip($hidden)) : null;

        DB::table('audit_logs')->insert([
            'user_id'    => Auth::id(),
            'action'     => $action,
            'model_type' => get_class($model),
            'model_id'   => $model->getKey(),
            'old_values' => $old  ? json_encode($strip($old))  : null,
            'new_values' => $new  ? json_encode($strip($new))  : null,
            'ip_address' => Request::ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
