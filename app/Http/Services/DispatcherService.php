<?php

namespace App\Http\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

/**
 * DispatcherService
 * ─────────────────
 * Bridges Laravel routing algorithms to REAL Node.js backend servers.
 * Each node runs server.js which oscillates CPU/latency via sine waves.
 *
 * Flow:
 *   1. Poll each node's GET /stats  → get live CPU, latency, connections
 *   2. Apply chosen routing strategy → pick a target node
 *   3. POST /dispatch to target node → record result
 *   4. Return updated state to Alpine.js frontend
 *
 * Redis keys:
 *   lb:nodes          → cached node state (refreshed every poll)
 *   lb:rr_index       → round-robin cursor
 *   lb:smooth_state   → smooth RR current weights
 *   lb:ch_ring        → consistent hash ring
 *   lb:dispatch_log   → last 200 dispatch events
 *
 * NOTE: active_connections is read directly from each Node.js server's
 * live /stats response — it is the real, authoritative in-flight request
 * count tracked by server.js itself. We do NOT fake or decay it in Redis;
 * that approach caused the counter to drift and never reflect reality.
 */
class DispatcherService
{
    // ── Redis keys ───────────────────────────────────────────────────────────
    private const KEY_NODES        = 'lb:nodes';
    private const KEY_RR_INDEX     = 'lb:rr_index';
    private const KEY_SMOOTH_STATE = 'lb:smooth_state';
    private const KEY_CH_RING      = 'lb:ch_ring';
    private const KEY_DISPATCH_LOG = 'lb:dispatch_log';
    private const KEY_IDLE_QUEUE   = 'lb:idle_queue';

    // ── Thresholds ───────────────────────────────────────────────────────────
    private const CPU_ISOLATION_THRESHOLD  = 90;
    private const CONN_ISOLATION_THRESHOLD = 150;
    private const CH_VNODES_PER_SERVER     = 150;

    /**
     * Node registry — maps node ID to its real HTTP URL (from env vars).
     * Laravel reads these from docker-compose / Railway environment block.
     */
    private function nodeRegistry(): array
    {
        return [
            'server-a' => [
                'id'     => 'server-a',
                'name'   => 'Server A',
                'url'    => env('NODE_SERVER_A', 'http://node-a:3001'),
                'weight' => 4,
                'color'  => 'amber',
                'region' => 'us-east',
            ],
            'server-b' => [
                'id'     => 'server-b',
                'name'   => 'Server B',
                'url'    => env('NODE_SERVER_B', 'http://node-b:3002'),
                'weight' => 5,
                'color'  => 'sky',
                'region' => 'us-east',
            ],
            'server-c' => [
                'id'     => 'server-c',
                'name'   => 'Server C',
                'url'    => env('NODE_SERVER_C', 'http://node-c:3003'),
                'weight' => 2,
                'color'  => 'emerald',
                'region' => 'us-west',
            ],
            'server-d' => [
                'id'     => 'server-d',
                'name'   => 'Server D',
                'url'    => env('NODE_SERVER_D', 'http://node-d:3004'),
                'weight' => 3,
                'color'  => 'violet',
                'region' => 'eu-west',
            ],
            'server-e' => [
                'id'     => 'server-e',
                'name'   => 'Server E',
                'url'    => env('NODE_SERVER_E', 'http://node-e:3005'),
                'weight' => 1,
                'color'  => 'rose',
                'region' => 'eu-west',
            ],
        ];
    }

    // ────────────────────────────────────────────────────────────────────────
    // SAFE NUMERIC CASTING — prevents NaN/null from ever reaching the frontend
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Safely cast any value (null, string, bool, missing key) to an int.
     * Anything non-numeric collapses to the given default instead of NaN.
     */
    private function safeInt(mixed $value, int $default = 0): int
    {
        if ($value === null) return $default;
        if (is_numeric($value)) return (int) $value;
        return $default;
    }

    /**
     * Safely cast any value to a float, same NaN-proofing as safeInt().
     */
    private function safeFloat(mixed $value, float $default = 0.0): float
    {
        if ($value === null) return $default;
        if (is_numeric($value)) return (float) $value;
        return $default;
    }

    // ────────────────────────────────────────────────────────────────────────
    // REAL NODE POLLING — fetches live stats from each Node.js server
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Poll all Node.js servers and merge their live stats with our routing
     * metadata (weight, color, region, isolated flag).
     *
     * Uses Laravel HTTP client with a short timeout so one slow node
     * doesn't block the whole dashboard refresh.
     */
    public function getNodes(): array
    {
        $registry = $this->nodeRegistry();
        $nodes    = [];

        // Load persisted routing state (isolated flags, total_served, etc.)
        $persisted = $this->getPersistedState();

        foreach ($registry as $id => $meta) {
            try {
                // GET /stats from the real Node.js process
                $response = Http::timeout(2)->get($meta['url'] . '/stats');

                if ($response->successful()) {
                    $stats = $response->json() ?? [];

                    $nodes[$id] = array_merge($meta, [
                        // Trust the live server value directly — it's the
                        // real in-flight connection count, not a simulated one.
                        'active_connections' => $this->safeInt($stats['active_connections'] ?? null, 0),
                        'cpu_pct'             => $this->safeFloat($stats['cpu_usage'] ?? null, 0),
                        'latency_ms'          => $this->safeFloat($stats['latency_ms'] ?? null, 0),
                        'memory_pct'          => $this->safeFloat($stats['memory_pct'] ?? null, 0),
                        'total_served'        => $this->safeInt($stats['total_requests'] ?? null, 0),
                        'error_count'         => $this->safeInt($stats['error_count'] ?? null, 0),
                        'success_rate'        => $this->safeFloat($stats['success_rate'] ?? null, 1.0),
                        'offline'             => !($stats['online'] ?? true),
                        'isolated'            => $persisted[$id]['isolated']   ?? false,
                        'queue_depth'         => $persisted[$id]['queue_depth'] ?? 0,
                        'latency_history'     => $persisted[$id]['latency_history']
                            ?? array_fill(0, 20, $this->safeFloat($stats['latency_ms'] ?? null, 20)),
                    ]);

                    // Append to latency sliding window
                    $history   = $nodes[$id]['latency_history'];
                    $history[] = $nodes[$id]['latency_ms'];
                    if (count($history) > 20) array_shift($history);
                    $nodes[$id]['latency_history'] = $history;
                } else {
                    // Node responded with error status — treat as degraded
                    $nodes[$id] = $this->offlineNode($meta, $persisted[$id] ?? []);
                }
            } catch (\Exception $e) {
                // Node unreachable — mark offline
                $nodes[$id] = $this->offlineNode($meta, $persisted[$id] ?? []);
            }
        }

        // Check isolation thresholds on fresh data
        $nodes = $this->checkAndIsolateOverloadedNodes($nodes);

        // Persist routing state back to Redis
        $this->persistState($nodes);

        return array_values($nodes);
    }

    /**
     * Returns a safe "offline" node object when the HTTP call fails.
     * All numeric fields default to 0 — never null, never NaN.
     */
    private function offlineNode(array $meta, array $persisted): array
    {
        return array_merge($meta, [
            'active_connections' => 0,
            'cpu_pct'            => 0,
            'latency_ms'         => 0,
            'memory_pct'         => 0,
            'total_served'       => $this->safeInt($persisted['total_served'] ?? null, 0),
            'error_count'        => $this->safeInt($persisted['error_count'] ?? null, 0),
            'success_rate'       => 0,
            'offline'            => true,
            'isolated'           => false,
            'queue_depth'        => 0,
            'latency_history'    => $persisted['latency_history'] ?? array_fill(0, 20, 0),
        ]);
    }

    // ────────────────────────────────────────────────────────────────────────
    // PERSISTED STATE (Redis) — routing cursors + isolation flags
    // ────────────────────────────────────────────────────────────────────────

    private function getPersistedState(): array
    {
        $raw = Redis::get(self::KEY_NODES);
        if (!$raw) return [];

        $arr   = json_decode($raw, true) ?? [];
        $keyed = [];
        foreach ($arr as $node) {
            $keyed[$node['id']] = $node;
        }
        return $keyed;
    }

    private function persistState(array $nodes): void
    {
        Redis::set(self::KEY_NODES, json_encode(array_values($nodes)));
    }

    private function saveNodes(array $nodes): void
    {
        Redis::set(self::KEY_NODES, json_encode(array_values($nodes)));
    }

    public function getDispatchLog(): array
    {
        $entries = Redis::lRange(self::KEY_DISPATCH_LOG, 0, 49);
        return array_map(fn($e) => json_decode($e, true), $entries);
    }

    public function clearDispatchLog(): void
    {
        Redis::del(self::KEY_DISPATCH_LOG);
    }

    // ────────────────────────────────────────────────────────────────────────
    // INITIALISATION
    // ────────────────────────────────────────────────────────────────────────

    public function initializeNodes(): array
    {
        Redis::del(self::KEY_NODES);
        Redis::del(self::KEY_RR_INDEX);
        Redis::del(self::KEY_SMOOTH_STATE);
        Redis::del(self::KEY_CH_RING);
        Redis::del(self::KEY_DISPATCH_LOG);
        Redis::del(self::KEY_IDLE_QUEUE);

        // Also reset each real node via /chaos/reset
        foreach ($this->nodeRegistry() as $meta) {
            try {
                Http::timeout(2)->post($meta['url'] . '/chaos/reset');
            } catch (\Exception) {
            }
        }

        return $this->getNodes();
    }

    // ────────────────────────────────────────────────────────────────────────
    // MAIN DISPATCH
    // ────────────────────────────────────────────────────────────────────────

    public function dispatch(string $strategy, array $payload = []): array
    {
        $nodes = $this->getNodes();

        $healthy = array_filter($nodes, fn($n) => !$n['offline'] && !$n['isolated']);

        if (empty($healthy)) {
            $nodes   = $this->emergencyRecovery($nodes);
            $healthy = array_filter($nodes, fn($n) => !$n['offline']);
        }

        $targetId = match ($strategy) {
            'round_robin'                => $this->routeRoundRobin(array_values($healthy)),
            'weighted_round_robin'       => $this->routeWeightedRoundRobin($nodes, array_values($healthy)),
            'smooth_round_robin'         => $this->routeSmoothRoundRobin($nodes, array_values($healthy)),
            'consistent_hashing'         => $this->routeConsistentHashing($payload['hash_key'] ?? uniqid()),
            'adaptive_feedback'          => $this->routeAdaptiveFeedback(array_values($healthy)),
            'latency_based'              => $this->routeLatencyBased(array_values($healthy)),
            'performance_based'          => $this->routePerformanceBased(array_values($healthy)),
            'server_mesh'                => $this->routeServerMesh(array_values($healthy)),
            'idle_join_queue'            => $this->routeIdleJoinQueue(array_values($healthy)),
            'least_connections'          => $this->routeLeastConnections(array_values($healthy)),
            'weighted_least_connections' => $this->routeWeightedLeastConnections(array_values($healthy)),
            default                      => $this->routeLeastConnections(array_values($healthy)),
        };

        $target   = $this->findNode($nodes, $targetId);
        $logEntry = $this->dispatchToNode($target, $strategy, $payload);

        // Re-fetch nodes immediately AFTER the dispatch HTTP call completes.
        // Since server.js tracks active_connections itself (incrementing on
        // request start, decrementing on completion), this re-fetch reflects
        // the REAL current state of that node — no Redis simulation needed.
        $nodes = $this->getNodes();

        return [
            'nodes'     => $nodes,
            'target'    => $this->findNode($nodes, $targetId),
            'log_entry' => $logEntry,
        ];
    }

    /**
     * Actually send the payload to the chosen Node.js server.
     */
    private function dispatchToNode(?array $target, string $strategy, array $payload): array
    {
        if (!$target) {
            return ['error' => 'no target', 'strategy' => $strategy];
        }

        $registry = $this->nodeRegistry();
        $url      = $registry[$target['id']]['url'] ?? null;

        $latency = $this->safeFloat($target['latency_ms'] ?? null, 0);

        if ($url) {
            try {
                $res = Http::timeout(5)->post($url . '/dispatch', $payload);
                if ($res->successful()) {
                    $data    = $res->json() ?? [];
                    $latency = $this->safeFloat($data['latency_ms'] ?? null, $latency);
                }
            } catch (\Exception $e) {
                // Node went down mid-dispatch — log it but don't rethrow
            }
        }

        $entry = [
            'time'        => now()->format('H:i:s.v'),
            'strategy'    => $strategy,
            'target'      => $target['id'],
            'target_name' => $target['name'],
            'latency'     => round($latency, 1),
            'cpu'         => $this->safeFloat($target['cpu_pct'] ?? null, 0),
            'payload'     => $payload['type'] ?? 'request',
        ];

        Redis::lPush(self::KEY_DISPATCH_LOG, json_encode($entry));
        Redis::lTrim(self::KEY_DISPATCH_LOG, 0, 199);

        return $entry;
    }

    // ────────────────────────────────────────────────────────────────────────
    // BURST
    // ────────────────────────────────────────────────────────────────────────

    public function simulateBurst(string $strategy, int $count = 20): array
    {
        $hashKeys   = ['user:alice', 'user:bob', 'session:xyz', 'api:v2', 'asset:img'];
        $lastNodes  = [];
        $dispatches = [];

        for ($i = 0; $i < $count; $i++) {
            $payload = [
                'id'       => uniqid('pkt_'),
                'size_kb'  => rand(1, 500),
                'hash_key' => $hashKeys[array_rand($hashKeys)] . ':' . rand(1, 1000),
                'type'     => ['text', 'image', 'api', 'db_join'][rand(0, 3)],
            ];
            $result       = $this->dispatch($strategy, $payload);
            $lastNodes    = $result['nodes'];
            $dispatches[] = $result['target'];
        }

        return [
            'nodes'      => $lastNodes,
            'dispatches' => $dispatches,
            'log'        => $this->getDispatchLog(),
        ];
    }

    // ────────────────────────────────────────────────────────────────────────
    // NODE CONTROL
    // ────────────────────────────────────────────────────────────────────────

    public function toggleNodeOffline(string $nodeId): array
    {
        $registry = $this->nodeRegistry();
        $url      = $registry[$nodeId]['url'] ?? null;

        if ($url) {
            $nodes    = $this->getNodes();
            $current  = collect($nodes)->firstWhere('id', $nodeId);
            $isOnline = !($current['offline'] ?? false);

            try {
                if ($isOnline) {
                    Http::timeout(2)->post($url . '/chaos/offline');
                } else {
                    Http::timeout(2)->post($url . '/chaos/reset');
                }
            } catch (\Exception) {
            }
        }

        return $this->getNodes();
    }

    public function randomOfflineNode(): array
    {
        $nodes  = $this->getNodes();
        $online = array_filter($nodes, fn($n) => !$n['offline'] && !$n['isolated']);
        if (empty($online)) return $nodes;

        $online = array_values($online);
        $target = $online[array_rand($online)];
        return $this->toggleNodeOffline($target['id']);
    }

    public function injectLatencySpike(string $nodeId): array
    {
        $registry = $this->nodeRegistry();
        $url      = $registry[$nodeId]['url'] ?? null;
        if ($url) {
            try {
                Http::timeout(2)->post($url . '/chaos/latency-spike');
            } catch (\Exception) {
            }
        }
        return $this->getNodes();
    }

    public function injectCPUSpike(string $nodeId): array
    {
        $registry = $this->nodeRegistry();
        $url      = $registry[$nodeId]['url'] ?? null;
        if ($url) {
            try {
                Http::timeout(2)->post($url . '/chaos/peak-load');
            } catch (\Exception) {
            }
        }
        return $this->getNodes();
    }

    // ────────────────────────────────────────────────────────────────────────
    // ISOLATION CHECK (Requirement §4)
    // ────────────────────────────────────────────────────────────────────────

    private function checkAndIsolateOverloadedNodes(array $nodes): array
    {
        foreach ($nodes as &$node) {
            if ($node['offline']) continue;

            $cpuOver  = $node['cpu_pct']            > self::CPU_ISOLATION_THRESHOLD;
            $connOver = $node['active_connections'] > self::CONN_ISOLATION_THRESHOLD;

            if ($cpuOver || $connOver) {
                $node['isolated'] = true;
            } elseif (
                $node['isolated']
                && $node['cpu_pct']            < self::CPU_ISOLATION_THRESHOLD  - 10
                && $node['active_connections'] < self::CONN_ISOLATION_THRESHOLD - 30
            ) {
                $node['isolated'] = false; // auto-recover
            }
        }
        unset($node);
        return $nodes;
    }

    private function emergencyRecovery(array $nodes): array
    {
        $isolated = array_filter($nodes, fn($n) => $n['isolated'] && !$n['offline']);
        if (empty($isolated)) return $nodes;

        usort($isolated, fn($a, $b) => $a['cpu_pct'] <=> $b['cpu_pct']);
        $recoverId = array_values($isolated)[0]['id'];

        foreach ($nodes as &$node) {
            if ($node['id'] === $recoverId) $node['isolated'] = false;
        }
        unset($node);
        return $nodes;
    }

    // ────────────────────────────────────────────────────────────────────────
    // 11 ROUTING ALGORITHMS
    // ────────────────────────────────────────────────────────────────────────

    // 1. Round Robin
    private function routeRoundRobin(array $healthy): string
    {
        $index  = (int) Redis::get(self::KEY_RR_INDEX) ?: 0;
        $target = $healthy[$index % count($healthy)]['id'];
        Redis::set(self::KEY_RR_INDEX, ($index + 1) % count($healthy));
        return $target;
    }

    // 2. Weighted Round Robin
    private function routeWeightedRoundRobin(array $allNodes, array $healthy): string
    {
        $expanded = [];
        foreach ($healthy as $node) {
            for ($i = 0; $i < max(1, $node['weight']); $i++) {
                $expanded[] = $node['id'];
            }
        }
        $index  = (int) Redis::get(self::KEY_RR_INDEX) ?: 0;
        $target = $expanded[$index % count($expanded)];
        Redis::set(self::KEY_RR_INDEX, ($index + 1) % count($expanded));
        return $target;
    }

    // 3. Smooth Round Robin (Nginx SWRR)
    private function routeSmoothRoundRobin(array $allNodes, array $healthy): string
    {
        $raw   = Redis::get(self::KEY_SMOOTH_STATE);
        $state = $raw ? json_decode($raw, true) : $this->buildSmoothState($healthy);

        $totalWeight = array_sum(array_column($healthy, 'weight'));

        foreach ($healthy as $node) {
            $id = $node['id'];
            if (!isset($state[$id])) {
                $state[$id] = ['current' => 0, 'effective' => $node['weight']];
            }
            $state[$id]['current'] += $state[$id]['effective'];
        }

        $best   = null;
        $bestCW = PHP_INT_MIN;
        foreach ($healthy as $node) {
            $id = $node['id'];
            if ($state[$id]['current'] > $bestCW) {
                $bestCW = $state[$id]['current'];
                $best   = $id;
            }
        }

        $state[$best]['current'] -= $totalWeight;
        Redis::set(self::KEY_SMOOTH_STATE, json_encode($state));

        return $best;
    }

    private function buildSmoothState(array $nodes): array
    {
        $state = [];
        foreach ($nodes as $node) {
            $state[$node['id']] = ['current' => 0, 'effective' => $node['weight']];
        }
        return $state;
    }

    // 4. Consistent Hashing
    private function routeConsistentHashing(string $key): ?string
    {
        $ring = json_decode(Redis::get(self::KEY_CH_RING) ?? '{}', true);
        if (empty($ring)) {
            $nodes = $this->getNodes();
            $this->buildConsistentHashRing($nodes);
            $ring = json_decode(Redis::get(self::KEY_CH_RING) ?? '{}', true);
        }
        if (empty($ring)) return null;

        $hash = crc32($key) & 0x7FFFFFFF;
        foreach ($ring as $position => $nodeId) {
            if ($position >= $hash) return $nodeId;
        }
        return reset($ring);
    }

    private function buildConsistentHashRing(array $nodes): void
    {
        $ring = [];
        foreach ($nodes as $node) {
            if ($node['offline'] ?? false) continue;
            for ($v = 0; $v < self::CH_VNODES_PER_SERVER; $v++) {
                $pos        = crc32("{$node['id']}#{$v}") & 0x7FFFFFFF;
                $ring[$pos] = $node['id'];
            }
        }
        ksort($ring);
        Redis::set(self::KEY_CH_RING, json_encode($ring));
    }

    // 5. Adaptive Feedback
    private function routeAdaptiveFeedback(array $healthy): string
    {
        $scores = [];
        foreach ($healthy as $node) {
            $scores[$node['id']] =
                ((100 - $node['cpu_pct']) / 100 * 0.4) +
                (1 / max(1.0, $node['latency_ms'])      * 0.35) +
                (1 / ($node['active_connections'] + 1)   * 0.25);
        }
        return $this->weightedRandom($scores);
    }

    // 6. Latency-Based
    private function routeLatencyBased(array $healthy): string
    {
        usort($healthy, fn($a, $b) => $a['latency_ms'] <=> $b['latency_ms']);
        return $healthy[0]['id'];
    }

    // 7. Performance-Based
    private function routePerformanceBased(array $healthy): string
    {
        $best = null;
        $bestScore = -1;
        foreach ($healthy as $node) {
            $score = (100 - $node['cpu_pct']) / max(1.0, $node['latency_ms']);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $node['id'];
            }
        }
        return $best;
    }

    // 8. Server Mesh
    private function routeServerMesh(array $healthy): string
    {
        $weights = [];
        foreach ($healthy as $node) {
            $weights[$node['id']] = 1000 / max(1.0, $node['latency_ms']);
        }
        return $this->weightedRandom($weights);
    }

    // 9. Idle Join Queue
    private function routeIdleJoinQueue(array $healthy): string
    {
        usort($healthy, function ($a, $b) {
            if ($a['queue_depth'] === $b['queue_depth']) {
                return $a['active_connections'] <=> $b['active_connections'];
            }
            return $a['queue_depth'] <=> $b['queue_depth'];
        });
        return $healthy[0]['id'];
    }

    // 10. Least Connections
    private function routeLeastConnections(array $healthy): string
    {
        usort($healthy, fn($a, $b) => $a['active_connections'] <=> $b['active_connections']);
        return $healthy[0]['id'];
    }

    // 11. Weighted Least Connections
    private function routeWeightedLeastConnections(array $healthy): string
    {
        $best = null;
        $bestScore = PHP_FLOAT_MAX;
        foreach ($healthy as $node) {
            $score = $node['active_connections'] / max(1, $node['weight']);
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $node['id'];
            }
        }
        return $best;
    }

    // ── Weighted random helper ───────────────────────────────────────────────
    private function weightedRandom(array $weights): string
    {
        $total  = array_sum($weights);
        $rand   = (float) mt_rand() / mt_getrandmax() * $total;
        $cursor = 0.0;
        foreach ($weights as $id => $w) {
            $cursor += $w;
            if ($rand <= $cursor) return $id;
        }
        return array_key_last($weights);
    }

    private function findNode(array $nodes, string $id): ?array
    {
        foreach ($nodes as $node) {
            if ($node['id'] === $id) return $node;
        }
        return null;
    }
}
