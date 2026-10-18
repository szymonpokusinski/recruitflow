<?php

namespace App\Widgets;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class WidgetSnapshotObserver
{
    public function saved(Model $model): void
    {
        DB::afterCommit(fn () => app(WidgetSnapshotPublisher::class)->queue());
    }

    public function deleted(Model $model): void
    {
        $this->saved($model);
    }
}
