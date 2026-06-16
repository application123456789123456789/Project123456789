const http = require("http");

const NODE_ID = process.env.NODE_ID || "unknown";
const PORT = parseInt(process.env.PORT || "3000");
const BASE_LATENCY = parseFloat(process.env.BASE_LATENCY || "20");
const BASE_CPU = parseFloat(process.env.BASE_CPU || "20");

console.log(
    `[${NODE_ID}] starting on port ${PORT} | base_latency=${BASE_LATENCY}ms | base_cpu=${BASE_CPU}%`,
);

let activeConnections = 0;
let totalRequests = 0;
let errors = 0;
let isOffline = false;

// ── CPU simulation ───────────────────────────────────────────────────────────
// CPU oscillates continuously using a sine wave + random noise.
// This creates the "goes over and down" wave pattern you want.
//
// Formula: base + (amplitude * sin(time * speed)) + noise
// - base:      the node's configured baseline CPU %
// - amplitude: how far it swings (±20%)
// - speed:     how fast the wave cycles (different per node via NODE_ID hash)
// - noise:     small random jitter each tick (±3%)

let cpuUsage = BASE_CPU;
let cpuPhase = Math.random() * Math.PI * 2; // random starting phase per node
const cpuSpeed = 0.0008 + (NODE_ID.charCodeAt(NODE_ID.length - 1) % 5) * 0.0002;
const cpuAmplitude = 18;

// Latency also oscillates but at a different frequency
let latencyBase = BASE_LATENCY;
let latencyPhase = Math.random() * Math.PI * 2;
const latencySpeed = cpuSpeed * 0.7;

setInterval(() => {
    const now = Date.now();

    // Sine wave component
    const cpuWave = cpuAmplitude * Math.sin(now * cpuSpeed + cpuPhase);
    // Connection pressure component (more connections = higher CPU)
    const connPressure = activeConnections * 0.4;
    // Random noise ±3%
    const noise = Math.random() * 6 - 3;

    cpuUsage = Math.max(
        4,
        Math.min(98, BASE_CPU + cpuWave + connPressure + noise),
    );

    // Latency also waves
    const latWave = 12 * Math.sin(now * latencySpeed + latencyPhase);
    latencyBase = Math.max(
        3,
        Math.min(200, BASE_LATENCY + latWave + (Math.random() * 4 - 2)),
    );
}, 800); // tick every 800ms for smooth animation

// ── HTTP Server ──────────────────────────────────────────────────────────────
const server = http.createServer((req, res) => {
    // While offline, reject everything except chaos endpoints
    if (isOffline && req.url !== "/chaos/reset" && req.url !== "/stats") {
        res.writeHead(503);
        res.end(JSON.stringify({ node: NODE_ID, error: "node offline" }));
        return;
    }

    // ── POST /dispatch ───────────────────────────────────────────────────────
    if (req.method === "POST" && req.url === "/dispatch") {
        activeConnections++;
        totalRequests++;

        // Latency varies around the current wave value
        const latency = Math.max(2, latencyBase + (Math.random() * 10 - 5));

        setTimeout(() => {
            activeConnections = Math.max(0, activeConnections - 1);

            if (Math.random() < 0.04) {
                // 4% error rate
                errors++;
                res.writeHead(500);
                res.end(
                    JSON.stringify({
                        node: NODE_ID,
                        error: "simulated failure",
                    }),
                );
            } else {
                res.writeHead(200, { "Content-Type": "application/json" });
                res.end(
                    JSON.stringify({
                        node: NODE_ID,
                        status: "dispatched",
                        latency_ms: parseFloat(latency.toFixed(1)),
                    }),
                );
            }
        }, latency);
        return;
    }

    // ── GET /stats ───────────────────────────────────────────────────────────
    if (req.method === "GET" && req.url === "/stats") {
        res.writeHead(200, { "Content-Type": "application/json" });
        res.end(
            JSON.stringify({
                node_id: NODE_ID,
                active_connections: activeConnections,
                cpu_usage: parseFloat(cpuUsage.toFixed(1)),
                latency_ms: parseFloat(latencyBase.toFixed(1)),
                memory_pct: parseFloat(
                    (
                        35 +
                        Math.sin(Date.now() * 0.0003) * 15 +
                        Math.random() * 5
                    ).toFixed(1),
                ),
                total_requests: totalRequests,
                error_count: errors,
                success_rate:
                    totalRequests > 0
                        ? parseFloat(
                              (
                                  (totalRequests - errors) /
                                  totalRequests
                              ).toFixed(4),
                          )
                        : 1.0,
                online: !isOffline,
            }),
        );
        return;
    }

    // ── POST /chaos/peak-load ────────────────────────────────────────────────
    // Spikes CPU to >90% and connections to >150 to trigger isolation (req §4)
    if (req.method === "POST" && req.url === "/chaos/peak-load") {
        cpuPhase = 0; // reset wave to peak
        cpuUsage = 95;
        activeConnections = 155;
        res.writeHead(200);
        res.end(
            JSON.stringify({ node: NODE_ID, chaos: "peak-load activated" }),
        );
        return;
    }

    // ── POST /chaos/latency-spike ────────────────────────────────────────────
    if (req.method === "POST" && req.url === "/chaos/latency-spike") {
        latencyBase = 180 + Math.random() * 100; // spike to 180-280ms
        res.writeHead(200);
        res.end(
            JSON.stringify({ node: NODE_ID, chaos: "latency-spike activated" }),
        );
        return;
    }

    // ── POST /chaos/offline ──────────────────────────────────────────────────
    if (req.method === "POST" && req.url === "/chaos/offline") {
        isOffline = true;
        activeConnections = 0;
        res.writeHead(200);
        res.end(JSON.stringify({ node: NODE_ID, chaos: "node taken offline" }));
        return;
    }

    // ── POST /chaos/reset ────────────────────────────────────────────────────
    if (req.method === "POST" && req.url === "/chaos/reset") {
        isOffline = false;
        cpuUsage = BASE_CPU;
        latencyBase = BASE_LATENCY;
        activeConnections = 0;
        errors = 0;
        res.writeHead(200);
        res.end(JSON.stringify({ node: NODE_ID, chaos: "reset" }));
        return;
    }

    res.writeHead(404);
    res.end("not found");
});

server.listen(PORT, "0.0.0.0", () => {
    console.log(`[${NODE_ID}] listening on :${PORT}`);
});
