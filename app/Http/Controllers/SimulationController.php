<?php

namespace App\Http\Controllers;

use App\Http\Services\DispatcherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * SimulationController
 * ────────────────────
 * REST-style controller consumed by Alpine.js via fetch().
 * All endpoints return JSON. The Blade view is rendered once on initial
 * GET /dispatcher; subsequent interactions are XHR-driven.
 */
class SimulationController extends Controller
{
    public function __construct(private readonly DispatcherService $dispatcher) {}

    // ── Initial page render ──────────────────────────────────────────────────
    public function index(): \Illuminate\View\View
    {
        $nodes = $this->dispatcher->getNodes();
        return view('dispatcher.index', [
            'nodes'      => $nodes,
            'strategies' => $this->strategyList(),
        ]);
    }

    // ── Single dispatch ──────────────────────────────────────────────────────
    /**
     * POST /dispatcher/dispatch
     * Body: { strategy: string, hash_key?: string }
     */
    public function dispatch(Request $request): JsonResponse
    {
        $request->validate([
            'strategy' => 'required|string',
            'hash_key' => 'nullable|string',
        ]);

        $result = $this->dispatcher->dispatch(
            $request->input('strategy'),
            ['hash_key' => $request->input('hash_key', uniqid())]
        );

        return response()->json([
            'ok'        => true,
            'nodes'     => $result['nodes'],
            'target'    => $result['target'],
            'log_entry' => $result['log_entry'],
        ]);
    }

    // ── Burst simulation ─────────────────────────────────────────────────────
    /**
     * POST /dispatcher/burst
     * Body: { strategy: string, count?: int }
     */
    public function burst(Request $request): JsonResponse
    {
        $request->validate([
            'strategy' => 'required|string',
            'count'    => 'nullable|integer|min:1|max:100',
        ]);

        $result = $this->dispatcher->simulateBurst(
            $request->input('strategy'),
            (int) $request->input('count', 20)
        );

        return response()->json([
            'ok'         => true,
            'nodes'      => $result['nodes'],
            'dispatches' => $result['dispatches'],
            'log'        => $result['log'],
        ]);
    }

    // ── Node toggle ──────────────────────────────────────────────────────────
    /**
     * POST /dispatcher/node/toggle
     * Body: { node_id: string }
     */
    public function toggleNode(Request $request): JsonResponse
    {
        $request->validate(['node_id' => 'required|string']);

        $nodes = $this->dispatcher->toggleNodeOffline($request->input('node_id'));

        return response()->json(['ok' => true, 'nodes' => $nodes]);
    }

    // ── Random chaos: take random node offline ───────────────────────────────
    /**
     * POST /dispatcher/node/chaos
     */
    public function chaosNode(): JsonResponse
    {
        $nodes = $this->dispatcher->randomOfflineNode();
        return response()->json(['ok' => true, 'nodes' => $nodes]);
    }

    // ── Inject latency spike (Requirement 5) ─────────────────────────────────
    /**
     * POST /dispatcher/node/latency-spike
     * Body: { node_id: string }
     */
    public function injectLatencySpike(Request $request): JsonResponse
    {
        $request->validate(['node_id' => 'required|string']);
        $nodes = $this->dispatcher->injectLatencySpike($request->input('node_id'));
        return response()->json(['ok' => true, 'nodes' => $nodes]);
    }

    // ── Inject CPU spike (Requirement 4) ─────────────────────────────────────
    /**
     * POST /dispatcher/node/cpu-spike
     * Body: { node_id: string }
     */
    public function injectCPUSpike(Request $request): JsonResponse
    {
        $request->validate(['node_id' => 'required|string']);
        $nodes = $this->dispatcher->injectCPUSpike($request->input('node_id'));
        return response()->json(['ok' => true, 'nodes' => $nodes]);
    }

    // ── Poll: fetch current node state (for live dashboard refresh) ──────────
    /**
     * GET /dispatcher/poll
     * Called every 2 seconds by Alpine.js setInterval to keep metrics fresh.
     */
    public function poll(): JsonResponse
    {
        return response()->json([
            'nodes' => $this->dispatcher->getNodes(),
            'log'   => $this->dispatcher->getDispatchLog(),
        ]);
    }

    // ── Reset cluster ────────────────────────────────────────────────────────
    /**
     * POST /dispatcher/reset
     */
    public function reset(): JsonResponse
    {
        $nodes = $this->dispatcher->initializeNodes();
        return response()->json(['ok' => true, 'nodes' => $nodes]);
    }

    // ── Strategy metadata list ───────────────────────────────────────────────
    private function strategyList(): array
    {
        return [
            ['id' => 'round_robin',              'label' => 'Round Robin',               'badge' => 'O(1)'],
            ['id' => 'weighted_round_robin',      'label' => 'Weighted Round Robin',      'badge' => 'O(ΣW)'],
            ['id' => 'smooth_round_robin',        'label' => 'Smooth Round Robin',        'badge' => 'O(N)'],
            ['id' => 'consistent_hashing',        'label' => 'Consistent Hashing',        'badge' => 'O(log N)'],
            ['id' => 'adaptive_feedback',         'label' => 'Adaptive Feedback',         'badge' => 'O(N)'],
            ['id' => 'latency_based',             'label' => 'Latency-Based',             'badge' => 'O(N)'],
            ['id' => 'performance_based',         'label' => 'Performance-Based',         'badge' => 'O(N)'],
            ['id' => 'server_mesh',               'label' => 'Service Mesh',              'badge' => 'O(N)'],
            ['id' => 'idle_join_queue',           'label' => 'Idle Join Queue',           'badge' => 'O(1)'],
            ['id' => 'least_connections',         'label' => 'Least Connections',         'badge' => 'O(N)'],
            ['id' => 'weighted_least_connections', 'label' => 'Weighted Least Connections', 'badge' => 'O(N)'],
        ];
    }
    public function clearLog(): JsonResponse
    {
        $this->dispatcher->clearDispatchLog();
        return response()->json(['ok' => true]);
    }
}
