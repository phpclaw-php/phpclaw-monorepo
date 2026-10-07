<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use PhpClaw\Laravel\Http\Controllers\PhpClawController;
use PhpClaw\Laravel\Http\Controllers\RunsController;

Route::prefix((string) config('phpclaw.api.prefix', 'phpclaw'))
    ->middleware(array_merge(
        ['phpclaw.json'],
        (array) config('phpclaw.api.middleware', ['api']),
        ['throttle:'.config('phpclaw.api.throttle', '60,1'), 'phpclaw.api', 'phpclaw.owns'],
    ))
    ->group(static function (): void {
        Route::post('send', [PhpClawController::class, 'send']);
        Route::post('chat/stream', [PhpClawController::class, 'stream']);

        if ((bool) config('phpclaw.durable_runs', false)) {
            Route::get('runs', [RunsController::class, 'index']);
            Route::post('runs/{runId}/approve', [RunsController::class, 'approve'])->where('runId', '[A-Za-z0-9]{26}');
            Route::post('runs/{runId}/deny', [RunsController::class, 'deny'])->where('runId', '[A-Za-z0-9]{26}');
        }
    });
