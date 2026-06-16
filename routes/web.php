<?php

use App\Http\Controllers\SimulationController;
use Illuminate\Support\Facades\Route;

// ── Load Balancer Visualizer Routes ─────────────────────────────────────────

// Dashboard
Route::get('/', [SimulationController::class, 'index'])->name('dispatcher.index');
Route::get('/dispatcher', [SimulationController::class, 'index'])->name('dispatcher');

// Dispatch actions (called by Alpine.js fetch)
Route::post('/dispatcher/dispatch',           [SimulationController::class, 'dispatch'])->name('dispatcher.dispatch');
Route::post('/dispatcher/burst',              [SimulationController::class, 'burst'])->name('dispatcher.burst');
Route::post('/dispatcher/reset',              [SimulationController::class, 'reset'])->name('dispatcher.reset');

// Node control
Route::post('/dispatcher/node/toggle',        [SimulationController::class, 'toggleNode'])->name('dispatcher.node.toggle');
Route::post('/dispatcher/node/chaos',         [SimulationController::class, 'chaosNode'])->name('dispatcher.node.chaos');
Route::post('/dispatcher/node/latency-spike', [SimulationController::class, 'injectLatencySpike'])->name('dispatcher.node.latency');
Route::post('/dispatcher/node/cpu-spike',     [SimulationController::class, 'injectCPUSpike'])->name('dispatcher.node.cpu');

// Polling endpoint (live metrics)
Route::get('/dispatcher/poll',                [SimulationController::class, 'poll'])->name('dispatcher.poll');
Route::post('/dispatcher/log/clear', [SimulationController::class, 'clearLog']);
