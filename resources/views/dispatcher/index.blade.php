<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <meta name="csrf-token" content="{{ csrf_token() }}"/>
    <title>LB Dispatcher — Live Visualizer</title>

    {{-- Tailwind CDN (production: use compiled build) --}}
    <script src="https://cdn.tailwindcss.com"></script>

    {{-- Alpine.js v3 (defer ensures DOM is parsed first) --}}
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    {{-- Mini-Canvas sparkline library --}}
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    <script>
        // Tailwind config: extend with custom design tokens
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        // "Control Room" dark palette — inspired by the slides' aesthetic
                        panel:     '#0d1117',  // deep background
                        surface:   '#161b22',  // card backgrounds
                        border:    '#21262d',  // subtle dividers
                        accent:    '#e05c2e',  // burnt orange — matches slide accent
                        accentAlt: '#f0883e',  // lighter orange for hovers
                        glow:      '#58a6ff',  // electric blue — active indicators
                        healthy:   '#3fb950',  // green
                        warn:      '#d29922',  // amber
                        danger:    '#f85149',  // red
                        offline:   '#484f58',  // grey
                    },
                    fontFamily: {
                        mono: ['JetBrains Mono', 'Fira Code', 'monospace'],
                        display: ['Inter', 'system-ui', 'sans-serif'],
                    },
                    animation: {
                        'pulse-fast': 'pulse 0.8s cubic-bezier(0.4,0,0.6,1) infinite',
                        'slide-in':   'slideIn 0.3s ease-out',
                        'ping-slow':  'ping 2s cubic-bezier(0,0,0.2,1) infinite',
                    },
                    keyframes: {
                        slideIn: {
                            '0%':   { opacity: '0', transform: 'translateX(-8px)' },
                            '100%': { opacity: '1', transform: 'translateX(0)' },
                        }
                    }
                }
            }
        }
    </script>

    <style>
        /* Custom scrollbar for the log panel */
        .log-scroll::-webkit-scrollbar { width: 4px; }
        .log-scroll::-webkit-scrollbar-track { background: #161b22; }
        .log-scroll::-webkit-scrollbar-thumb { background: #e05c2e; border-radius: 2px; }

        /* Latency sparkline canvas */
        .sparkline-canvas { image-rendering: crisp-edges; }

        /* Node card glow effects */
        .node-healthy  { box-shadow: 0 0 0 1px #3fb95033, 0 0 16px #3fb95011; }
        .node-warning  { box-shadow: 0 0 0 1px #d2992233, 0 0 20px #d2992222; }
        .node-danger   { box-shadow: 0 0 0 1px #f8514988, 0 0 24px #f8514944; animation: dangerPulse 1s ease infinite; }
        .node-offline  { box-shadow: none; filter: grayscale(0.7) brightness(0.6); }
        .node-isolated { box-shadow: 0 0 0 2px #f85149, 0 0 30px #f8514966; animation: dangerPulse 0.7s ease infinite; }

        @keyframes dangerPulse {
            0%, 100% { box-shadow: 0 0 0 2px #f85149, 0 0 30px #f8514966; }
            50%       { box-shadow: 0 0 0 2px #f85149, 0 0 50px #f8514999; }
        }

        /* Packet animation: dots flying across on dispatch */
        @keyframes packetFly {
            0%   { transform: translateX(-100%); opacity: 0; }
            20%  { opacity: 1; }
            100% { transform: translateX(200px); opacity: 0; }
        }
        .packet-animate { animation: packetFly 0.6s ease-out forwards; }

        /* Progress bar gradient fill */
        .bar-gradient {
            background: linear-gradient(90deg, #3fb950, #d29922, #f85149);
            background-size: 300% 100%;
        }

        /* Font */
        @import url('https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600&family=Inter:wght@400;500;600;700&display=swap');
        body { font-family: 'Inter', system-ui, sans-serif; }
        .font-mono { font-family: 'JetBrains Mono', monospace; }
    </style>
</head>

<body class="bg-panel text-gray-100 min-h-screen" x-data="dispatcherApp()" x-init="init()">

{{-- ═══════════════════════════════════════════════════════════════════════
     TOP NAV BAR
     ═══════════════════════════════════════════════════════════════════════ --}}
<header class="bg-surface border-b border-border sticky top-0 z-50">
    <div class="max-w-screen-2xl mx-auto px-4 py-3 flex flex-wrap items-center justify-between gap-2">
        <div class="flex items-center gap-3">
            {{-- Animated cluster icon --}}
            <div class="relative w-8 h-8">
                <div class="absolute inset-0 rounded-full bg-accent opacity-20 animate-ping-slow"></div>
                <div class="relative w-8 h-8 rounded-full bg-accent flex items-center justify-center">
                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M5 12h14M12 5l7 7-7 7"/>
                    </svg>
                </div>
            </div>
            <div>
                <h1 class="text-sm font-semibold text-white tracking-wide">DISPATCHER VISUALIZER</h1>
                <p class="text-xs text-gray-500 font-mono">distributed load balancer · 11 strategies</p>
            </div>
        </div>

        {{-- Live clock + status --}}
        <div class="flex items-center gap-4">
            <div class="flex items-center gap-2">
                <div class="w-2 h-2 rounded-full bg-healthy animate-pulse"></div>
                <span class="text-xs text-gray-400 font-mono" x-text="clock">00:00:00</span>
            </div>
            <div class="hidden sm:block text-xs text-gray-500">

                Total dispatched: <span class="text-glow font-mono font-semibold" x-text="totalDispatched">0</span>
            </div>
            <button @click="resetCluster()"
                    class="px-3 py-1.5 text-xs bg-border hover:bg-gray-700 text-gray-300 rounded-md border border-border transition-colors">
                ↺ Reset Cluster
            </button>
        </div>
    </div>
</header>

{{-- ═══════════════════════════════════════════════════════════════════════
     ALERT BANNER — shown when nodes go offline or are isolated
     Requirement 3 & 4: High-contrast status banners
     ═══════════════════════════════════════════════════════════════════════ --}}
<template x-for="alert in alerts" :key="alert.id">
    <div x-show="alert.visible"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 -translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         :class="alert.type === 'danger'
             ? 'bg-danger/20 border-danger text-danger'
             : 'bg-warn/20 border-warn text-warn'"
         class="border-l-4 px-6 py-3 flex items-center justify-between text-sm font-semibold">
        <div class="flex items-center gap-3">
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z"/>
            </svg>
            <span x-text="alert.message"></span>
        </div>
        <button @click="dismissAlert(alert.id)" class="opacity-70 hover:opacity-100 ml-4">✕</button>
    </div>
</template>

{{-- ═══════════════════════════════════════════════════════════════════════
     MAIN LAYOUT: Left controls | Center cluster | Right log
     ═══════════════════════════════════════════════════════════════════════ --}}
<div class="max-w-screen-2xl mx-auto px-4 py-4 grid grid-cols-1 lg:grid-cols-12 gap-4">

    {{-- ─────────────────────────────────────────────────────────────────
         LEFT PANEL: Strategy selector + controls
         ───────────────────────────────────────────────────────────────── --}}
        <div class="col-span-1 lg:col-span-3 space-y-4">

        {{-- Strategy Selector --}}
        <div class="bg-surface border border-border rounded-xl p-4">
            <label class="text-xs text-gray-500 uppercase tracking-widest block mb-2">
                Routing Strategy
            </label>
            <select x-model="strategy"
                    class="w-full bg-panel border border-border rounded-lg px-3 py-2 text-sm text-white
                           focus:outline-none focus:border-accent focus:ring-1 focus:ring-accent/30">
                @foreach ($strategies as $s)
                    <option value="{{ $s['id'] }}">{{ $s['label'] }}</option>
                @endforeach
            </select>

            {{-- Strategy description badge --}}
            <div class="mt-3 p-3 bg-panel rounded-lg border border-border">
                <div class="flex items-center justify-between mb-1">
                    <span class="text-xs text-gray-400 font-mono" x-text="currentStrategy.badge"></span>
                    <span class="text-xs px-2 py-0.5 rounded-full bg-accent/20 text-accent font-mono">
                        ACTIVE
                    </span>
                </div>
                <p class="text-xs text-gray-400 leading-relaxed" x-text="currentStrategy.description"></p>
            </div>
        </div>

        {{-- Dispatch Controls --}}
        <div class="bg-surface border border-border rounded-xl p-4 space-y-3">
            <h3 class="text-xs text-gray-500 uppercase tracking-widest">Dispatch Controls</h3>

            {{-- Single dispatch --}}
            <button @click="singleDispatch()"
                    :disabled="loading"
                    class="w-full flex items-center justify-center gap-2 px-4 py-2.5
                           bg-accent hover:bg-accentAlt disabled:opacity-50
                           text-white text-sm font-semibold rounded-lg transition-colors">
                <svg class="w-4 h-4" :class="{'animate-spin': loading}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                </svg>
                Dispatch Payload
            </button>

            {{-- Burst simulation --}}
            <div class="flex gap-2">
                <input type="number" x-model="burstCount" min="1" max="100" value="20"
                       class="w-16 bg-panel border border-border rounded-lg px-2 py-2 text-sm text-center text-white
                              focus:outline-none focus:border-accent"/>
                <button @click="burstDispatch()"
                        :disabled="loading"
                        class="flex-1 flex items-center justify-center gap-2 px-4 py-2
                               bg-glow/20 hover:bg-glow/30 border border-glow/30
                               text-glow text-sm font-semibold rounded-lg transition-colors disabled:opacity-50">
                    ⚡ Simulate Burst
                </button>
            </div>

            {{-- Burst progress --}}
            <div x-show="burstProgress > 0" class="space-y-1">
                <div class="flex justify-between text-xs text-gray-500">
                    <span>Processing burst...</span>
                    <span x-text="burstProgress + '%'"></span>
                </div>
                <div class="h-1.5 bg-border rounded-full overflow-hidden">
                    <div class="h-full bg-glow rounded-full transition-all duration-200"
                         :style="`width: ${burstProgress}%`"></div>
                </div>
            </div>
        </div>

        {{-- Chaos Engineering --}}
        <div class="bg-surface border border-border rounded-xl p-4 space-y-3">
            <h3 class="text-xs text-gray-500 uppercase tracking-widest">Chaos Engineering</h3>

            <button @click="chaosNode()"
                    class="w-full px-4 py-2 bg-warn/10 hover:bg-warn/20 border border-warn/30
                           text-warn text-sm font-semibold rounded-lg transition-colors">
                ⚠ Random Node Offline
            </button>

            {{-- Per-node injections --}}
            <div class="space-y-2">
                <p class="text-xs text-gray-600">Inject fault on specific node:</p>
                <template x-for="node in nodes" :key="node.id">
                    <div class="flex items-center gap-2 text-xs">
                        <span class="w-16 text-gray-400 truncate" x-text="node.name"></span>
                        <button @click="toggleNode(node.id)"
                                :class="node.offline ? 'bg-healthy/20 text-healthy border-healthy/30' : 'bg-danger/10 text-danger border-danger/30'"
                                class="flex-1 py-1 rounded border text-xs transition-colors hover:opacity-80">
                            <span x-text="node.offline ? '▶ Bring Online' : '◼ Take Offline'"></span>
                        </button>
                        <button @click="injectCPU(node.id)"
                                :disabled="node.offline"
                                title="CPU spike"
                                class="px-2 py-1 rounded border border-danger/30 text-danger/70
                                       hover:text-danger disabled:opacity-30 transition-colors">🔥</button>
                        <button @click="injectLatency(node.id)"
                                :disabled="node.offline"
                                title="Latency spike"
                                class="px-2 py-1 rounded border border-warn/30 text-warn/70
                                       hover:text-warn disabled:opacity-30 transition-colors">⏱</button>
                    </div>
                </template>
            </div>
        </div>

        {{-- Cluster summary stats --}}
        <div class="bg-surface border border-border rounded-xl p-4">
            <h3 class="text-xs text-gray-500 uppercase tracking-widest mb-3">Cluster Health</h3>
            <div class="space-y-2 text-xs">
                <div class="flex justify-between">
                    <span class="text-gray-400">Healthy Nodes</span>
                    <span class="font-mono text-healthy" x-text="healthyCount + ' / ' + nodes.length"></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-400">Isolated Nodes</span>
                    <span class="font-mono text-danger" x-text="isolatedCount"></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-400">Avg Latency</span>
                    <span class="font-mono" :class="avgLatency > 40 ? 'text-danger' : 'text-glow'"
                          x-text="avgLatency.toFixed(1) + ' ms'"></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-400">Avg CPU</span>
                    <span class="font-mono" :class="avgCPU > 70 ? 'text-warn' : 'text-gray-300'"
                          x-text="avgCPU.toFixed(0) + '%'"></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-400">Total Connections</span>
                    <span class="font-mono text-gray-200" x-text="totalConnections"></span>
                </div>
            </div>
        </div>
    </div>

    {{-- ─────────────────────────────────────────────────────────────────
         CENTER PANEL: Node cluster visualizer
         ───────────────────────────────────────────────────────────────── --}}
    <div class="col-span-1 lg:col-span-6 space-y-4">

        {{-- Dispatcher flow diagram (animated on dispatch) --}}
        <div class="bg-surface border border-border rounded-xl p-4">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-sm font-semibold text-white">Live Cluster — Node State</h2>
                <div class="flex items-center gap-2 text-xs text-gray-500">
                    <div class="w-2 h-2 rounded-full bg-healthy"></div> Healthy
                    <div class="w-2 h-2 rounded-full bg-warn ml-2"></div> Warning
                    <div class="w-2 h-2 rounded-full bg-danger ml-2"></div> Danger
                    <div class="w-2 h-2 rounded-full bg-offline ml-2"></div> Offline
                </div>
            </div>

            {{-- Dispatcher → Node flow arrows --}}
            <div class="flex items-stretch gap-3 mb-4">
                {{-- Central dispatcher box --}}
                <div class="flex flex-col items-center justify-center bg-panel border border-accent/30
                             rounded-xl p-3 min-w-[90px] relative overflow-hidden">
                    <div class="absolute inset-0 bg-accent/5 rounded-xl"
                         :class="{'animate-pulse': loading}"></div>
                    <div class="relative z-10 text-center">
                        <div class="w-8 h-8 rounded-full bg-accent/20 border border-accent/40
                                    flex items-center justify-center mx-auto mb-1"
                             :class="{'animate-spin': loading && loadingStyle === 'spin'}">
                            <svg class="w-4 h-4 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M8 9l4-4 4 4m0 6l-4 4-4-4"/>
                            </svg>
                        </div>
                        <p class="text-xs font-semibold text-accent">LB</p>
                        <p class="text-xs text-gray-500 font-mono leading-tight" x-text="strategyShortName"></p>
                    </div>
                </div>

                {{-- Animated routing arrows (1 per node) --}}
                <div class="flex-1 flex flex-col gap-2 justify-center">
                    <template x-for="node in nodes" :key="node.id + '_arrow'">
                        <div class="flex items-center gap-2 h-6 relative overflow-hidden">
                            {{-- Arrow line --}}
                            <div class="flex-1 h-px relative"
                                 :class="node.offline ? 'bg-border' : (lastTarget === node.id ? 'bg-accent' : 'bg-border')">
                                {{-- Arrow head --}}
                                <div class="absolute right-0 top-1/2 -translate-y-1/2 w-0 h-0"
                                     :class="node.offline ? 'border-l-border' : (lastTarget === node.id ? 'border-l-accent' : 'border-l-border')"
                                     style="border-left: 6px solid; border-top: 3px solid transparent; border-bottom: 3px solid transparent;">
                                </div>
                                {{-- Flying packet dot --}}
                                <div x-show="lastTarget === node.id && packetVisible"
                                     class="absolute left-0 top-1/2 -translate-y-1/2 w-3 h-3
                                            rounded-full bg-accent shadow-lg packet-animate">
                                </div>
                            </div>
                            <span class="text-xs text-gray-600 w-12 truncate" x-text="node.name"></span>
                        </div>
                    </template>
                </div>

                {{-- Node cards (compact, right side of flow) --}}
                <div class="flex flex-col gap-2">
                    <template x-for="node in nodes" :key="node.id">
                        <div class="w-10 h-6 rounded flex items-center justify-center text-xs"
                             :class="{
                                 'bg-healthy/20 text-healthy': !node.offline && !node.isolated && node.cpu_pct < 70,
                                 'bg-warn/20 text-warn':       !node.offline && !node.isolated && node.cpu_pct >= 70 && node.cpu_pct < 90,
                                 'bg-danger/20 text-danger':   !node.offline && !node.isolated && node.cpu_pct >= 90,
                                 'bg-offline/20 text-gray-500': node.offline,
                                 'bg-danger/30 text-danger border border-danger animate-pulse': node.isolated,
                             }">
                            <span x-text="node.offline ? '—' : (node.isolated ? '⚠' : '●')"></span>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        {{-- ── NODE CARDS GRID ─────────────────────────────────────────── --}}
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-1 gap-4">
            <template x-for="node in nodes" :key="node.id">
                <div class="bg-surface border rounded-xl p-4 transition-all duration-500 relative overflow-hidden"
                     :class="{
                         'border-healthy/30 node-healthy':  !node.offline && !node.isolated && node.cpu_pct < 70,
                         'border-warn/40 node-warning':     !node.offline && !node.isolated && node.cpu_pct >= 70 && node.cpu_pct < 90,
                         'border-danger/50 node-danger':    !node.offline && !node.isolated && node.cpu_pct >= 90,
                         'border-border node-offline':       node.offline,
                         'border-danger node-isolated':      node.isolated,
                     }">

                    {{-- ISOLATED banner overlay --}}
                    <div x-show="node.isolated"
                         class="absolute inset-0 bg-danger/10 border-2 border-danger rounded-xl
                                flex items-center justify-center z-10 pointer-events-none">
                        <span class="bg-danger text-white text-xs font-bold px-3 py-1 rounded-full
                                     uppercase tracking-widest animate-pulse">
                            ⚠ ISOLATED — CPU OVERLOAD
                        </span>
                    </div>

                    {{-- OFFLINE banner --}}
                    <div x-show="node.offline"
                         class="absolute inset-0 bg-black/60 rounded-xl flex items-center
                                justify-center z-10 pointer-events-none">
                        <span class="bg-offline text-white text-xs font-bold px-3 py-1 rounded-full uppercase tracking-widest">
                            ◼ NODE OFFLINE
                        </span>
                    </div>

                    {{-- LAST TARGET highlight --}}
                    <div x-show="lastTarget === node.id && !node.offline"
                         x-transition:enter="transition duration-150"
                         x-transition:enter-start="opacity-0"
                         x-transition:enter-end="opacity-100"
                         x-transition:leave="transition duration-500"
                         x-transition:leave-start="opacity-100"
                         x-transition:leave-end="opacity-0"
                         class="absolute inset-0 bg-accent/10 rounded-xl pointer-events-none">
                    </div>

                    {{-- Node header --}}
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center gap-2">
                            {{-- Status indicator dot --}}
                            <div class="w-2.5 h-2.5 rounded-full flex-shrink-0"
                                 :class="{
                                     'bg-healthy animate-pulse': !node.offline && !node.isolated && node.cpu_pct < 70,
                                     'bg-warn animate-pulse':    !node.offline && !node.isolated && node.cpu_pct >= 70,
                                     'bg-danger':                node.isolated,
                                     'bg-offline':               node.offline,
                                 }"></div>
                            <span class="font-semibold text-sm" x-text="node.name"></span>
                            <span class="text-xs px-2 py-0.5 rounded-full bg-border text-gray-500 font-mono"
                                  x-text="'w=' + node.weight"></span>
                            <span class="text-xs px-2 py-0.5 rounded bg-border/50 text-gray-600"
                                  x-text="node.region"></span>
                        </div>
                        <div class="flex items-center gap-2">
                            {{-- Last-routed badge --}}
                            <span x-show="lastTarget === node.id"
                                  class="text-xs px-2 py-0.5 rounded-full bg-accent text-white font-semibold animate-pulse-fast">
                                ◀ ROUTED
                            </span>
                            {{-- Served count --}}
                            <span class="text-xs font-mono text-gray-500"
                                  x-text="'✓ ' + node.total_served"></span>
                        </div>
                    </div>

                    {{-- Metric bars --}}
                    <div class="grid grid-cols-3 gap-3 mb-3">
                        {{-- CPU --}}
                        <div>
                            <div class="flex justify-between text-xs mb-1">
                                <span class="text-gray-500">CPU</span>
                                <span class="font-mono"
                                      :class="node.cpu_pct > 90 ? 'text-danger font-bold' : node.cpu_pct > 70 ? 'text-warn' : 'text-gray-300'"
                                      x-text="node.cpu_pct.toFixed(0) + '%'"></span>
                            </div>
                            <div class="h-1.5 bg-border rounded-full overflow-hidden">
                                <div class="h-full rounded-full transition-all duration-700"
                                     :class="node.cpu_pct > 90 ? 'bg-danger' : node.cpu_pct > 70 ? 'bg-warn' : 'bg-healthy'"
                                     :style="`width: ${node.cpu_pct}%`"></div>
                            </div>
                        </div>
                        {{-- Memory --}}
                        <div>
                            <div class="flex justify-between text-xs mb-1">
                                <span class="text-gray-500">MEM</span>
                                <span class="font-mono text-gray-300" x-text="node.memory_pct + '%'"></span>
                            </div>
                            <div class="h-1.5 bg-border rounded-full overflow-hidden">
                                <div class="h-full bg-glow/70 rounded-full transition-all duration-700"
                                     :style="`width: ${node.memory_pct}%`"></div>
                            </div>
                        </div>
                        {{-- Active Connections --}}
                        <div>
                            <div class="flex justify-between text-xs mb-1">
                                <span class="text-gray-500">CONN</span>
                                <span class="font-mono"
                                      :class="node.active_connections > 150 ? 'text-danger font-bold' : node.active_connections > 80 ? 'text-warn' : 'text-gray-300'"
                                      x-text="node.active_connections"></span>
                            </div>
                            <div class="h-1.5 bg-border rounded-full overflow-hidden">
                                <div class="h-full rounded-full transition-all duration-700"
                                     :class="node.active_connections > 150 ? 'bg-danger' : node.active_connections > 80 ? 'bg-warn' : 'bg-glow'"
                                     :style="`width: ${Math.min(100, node.active_connections / 2)}%`"></div>
                            </div>
                        </div>
                    </div>

                    {{-- Bottom stats row --}}
                    <div class="flex items-center gap-4 text-xs">
                        {{-- Latency with sparkline --}}
                        <div class="flex items-center gap-2">
                            <span class="text-gray-500">Latency</span>
                            <span class="font-mono"
                                  :class="node.latency_ms > 45 ? 'text-danger' : node.latency_ms > 25 ? 'text-warn' : 'text-glow'"
                                  x-text="node.latency_ms.toFixed(1) + ' ms'"></span>
                        </div>
                        <div class="flex items-center gap-2 ml-auto">
                            <span class="text-gray-500 ml-2">Err</span>
                            <span class="font-mono"
                                    :class="node.error_count > 0 ? 'text-danger' : 'text-gray-400'"
                                    x-text="node.error_count">
                            </span>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>

    {{-- ─────────────────────────────────────────────────────────────────
         RIGHT PANEL: Dispatch log + latency chart
         ───────────────────────────────────────────────────────────────── --}}
    <div class="col-span-1 lg:col-span-3 space-y-4">


        {{-- Connections bar chart --}}
        <div class="bg-surface border border-border rounded-xl p-4">
            <h3 class="text-xs text-gray-500 uppercase tracking-widest mb-3">
                Active Connections
            </h3>
            <div class="space-y-2 max-h-48 overflow-y-auto lg:max-h-none">

                <template x-for="node in nodes" :key="node.id + '_bar'">
                    <div class="space-y-1" x-show="!node.offline">
                        <div class="flex justify-between text-xs">
                            <span class="text-gray-400" x-text="node.name"></span>
                            <span class="font-mono"
                                  :class="node.active_connections > 150 ? 'text-danger' : 'text-gray-300'"
                                  x-text="node.active_connections"></span>
                        </div>
                        <div class="h-2 bg-border rounded-full overflow-hidden">
                            <div class="h-full rounded-full transition-all duration-500"
                                 :class="{
                                     'bg-healthy': node.active_connections <= 50,
                                     'bg-warn':    node.active_connections > 50 && node.active_connections <= 150,
                                     'bg-danger':  node.active_connections > 150,
                                 }"
                                 :style="`width: ${Math.min(100, node.active_connections / 2)}%`">
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        {{-- Dispatch Event Log --}}
        <div class="bg-surface border border-border rounded-xl p-4 flex flex-col">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-xs text-gray-500 uppercase tracking-widest">Dispatch Log</h3>
                <button @click="clearLog()"
                    class="text-xs px-2 py-1 rounded bg-border hover:bg-gray-700 text-gray-400
                         hover:text-white transition-colors font-mono">
                    ✕ Clear
                </button>
            </div>
            <div class="log-scroll overflow-y-auto max-h-48 md:max-h-72 space-y-1" id="logContainer">
                <template x-for="(entry, i) in log.slice(0, 50)" :key="i">
                    <div class="flex items-start gap-2 text-xs py-1 border-b border-border/50 animate-slide-in"
                         :class="entry.event ? 'bg-danger/10 rounded px-1' : ''">
                        <span class="font-mono text-gray-600 flex-shrink-0 text-xs" x-text="entry.time"></span>
                        <div class="flex-1 min-w-0">
                            <template x-if="entry.event">
                                <span class="font-semibold"
                                      :class="entry.event === 'ISOLATED' ? 'text-danger' : 'text-healthy'"
                                      x-text="entry.event + ': ' + entry.node"></span>
                            </template>
                            <template x-if="!entry.event">
                                <div class="flex items-center gap-1.5 flex-wrap">
                                    <span class="text-gray-500 font-mono text-xs"
                                          x-text="entry.strategy?.replace(/_/g,' ')?.toUpperCase()?.slice(0,6)"></span>
                                    <span class="text-accent font-semibold" x-text="'→ ' + entry.target_name"></span>
                                    <span class="text-gray-600 font-mono" x-text="entry.latency + 'ms'"></span>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
                <div x-show="log.length === 0" class="text-xs text-gray-600 text-center py-4">
                    No dispatches yet. Send a payload to begin.
                </div>
            </div>
        </div>

        {{-- Strategy distribution pie (last 50 dispatches) --}}
        <div class="bg-surface border border-border rounded-xl p-4">
            <h3 class="text-xs text-gray-500 uppercase tracking-widest mb-3">Node Distribution</h3>
            <div class="space-y-1.5">
                <template x-for="node in nodes" :key="node.id + '_dist'">
                    <div class="flex items-center gap-2 text-xs">
                        <span class="w-16 text-gray-400 truncate" x-text="node.name"></span>
                        <div class="flex-1 h-1.5 bg-border rounded-full overflow-hidden">
                            <div class="h-full bg-accent rounded-full transition-all duration-700"
                                 :style="`width: ${totalDispatched > 0 ? (node.total_served / totalDispatched * 100) : 0}%`">
                            </div>
                        </div>
                        <span class="font-mono text-gray-500 w-8 text-right"
                              x-text="totalDispatched > 0 ? (node.total_served / totalDispatched * 100).toFixed(0) + '%' : '0%'">
                        </span>
                    </div>
                </template>
            </div>
        </div>
    </div>
</div>

{{-- ═══════════════════════════════════════════════════════════════════════
     ALPINE.JS APPLICATION STATE & LOGIC
     ═══════════════════════════════════════════════════════════════════════ --}}
<script>
/**
 * dispatcherApp() — Alpine.js component
 * ──────────────────────────────────────
 * Manages all frontend state. Communicates with SimulationController via
 * fetch() JSON calls. Renders sparkline charts via Chart.js.
 *
 * Architecture decisions:
 * - No full-page refreshes: all updates are XHR-driven state mutations
 * - Alpine's reactivity automatically re-renders the DOM on state change
 * - Chart.js instances are keyed by node ID in a Map for efficient updates
 * - Polling (setInterval every 2s) keeps metrics live during burst playback
 */
function dispatcherApp() {
    return {
        // ── State ──────────────────────────────────────────────────────────
        nodes:         @json($nodes),      // Seeded from PHP on first render
        strategy:      'round_robin',
        log:           [],
        alerts:        [],
        alertCounter:  0,
        loading:       false,
        loadingStyle:  'spin',
        burstCount:    20,
        burstProgress: 0,
        lastTarget:    null,
        packetVisible: false,
        totalDispatched: 0,
        clock:         '--:--:--',
        pollInterval:  null,
        chartInstances: {},   // { nodeId: Chart } for sparklines
        latencyChart:  null,

        // ── Strategy metadata ───────────────────────────────────────────────
        strategies: {!! json_encode($strategies) !!},

        // ── Derived / computed properties ────────────────────────────────────
        get currentStrategy() {
            const meta = this.strategies.find(s => s.id === this.strategy) || {};
            const descriptions = {
                round_robin:              'Cycles sequentially A→B→C→A. No state awareness. Best for homogeneous workloads.',
                weighted_round_robin:     'Expands pool by weight: [A,A,A,A,B,B,C]. Higher-weight servers get proportionally more traffic.',
                smooth_round_robin:       'Nginx SWRR: prevents bursting by penalising the chosen server each round. Perfectly smooth distribution.',
                consistent_hashing:       'Maps keys+servers onto a 2³² ring with 150 virtual nodes. Session affinity without a lookup table.',
                adaptive_feedback:        'Scores servers by weight × (1 - error_rate) × (1 - cpu/200). Penalises unhealthy nodes dynamically.',
                latency_based:            'Routes to the server with the lowest EWMA rolling-average response time.',
                performance_based:        'Composite of CPU, latency, and connections (40/30/30 weighting). Best multi-metric policy.',
                server_mesh:              'Simulates Istio circuit-breaker: excludes servers with error_rate > 10%. Routes by min(latency × conns).',
                idle_join_queue:          'Pull-based: servers self-register as idle. Dispatcher only routes to explicitly available nodes.',
                least_connections:        'Always routes to the server with the fewest active connections. Standard nginx `least_conn`.',
                weighted_least_connections: 'Effective load = connections / weight. Accounts for heterogeneous server capacity.',
            };
            return { ...meta, description: descriptions[this.strategy] || '' };
        },

        get strategyShortName() {
            const labels = {
                round_robin: 'RR', weighted_round_robin: 'WRR', smooth_round_robin: 'SWRR',
                consistent_hashing: 'CH', adaptive_feedback: 'AF', latency_based: 'LB',
                performance_based: 'PB', server_mesh: 'SM', idle_join_queue: 'JIQ',
                least_connections: 'LC', weighted_least_connections: 'WLC',
            };
            return labels[this.strategy] || '?';
        },

        get healthyCount()    { return this.nodes.filter(n => !n.offline && !n.isolated).length; },
        get isolatedCount()   { return this.nodes.filter(n => n.isolated).length; },
        get totalConnections(){ return this.nodes.reduce((s, n) => s + n.active_connections, 0); },
        get avgLatency() {
            const online = this.nodes.filter(n => !n.offline);
            if (!online.length) return 0;
            return online.reduce((s, n) => s + n.latency_ms, 0) / online.length;
        },
        get avgCPU() {
            const online = this.nodes.filter(n => !n.offline);
            if (!online.length) return 0;
            return online.reduce((s, n) => s + n.cpu_pct, 0) / online.length;
        },

        // ── Initialisation ──────────────────────────────────────────────────
        async init() {
            this.startClock();
            this.initCharts();
            this.startPolling();
            this.totalDispatched = this.nodes.reduce((s, n) => s + (n.total_served || 0), 0);
        },

        startClock() {
            setInterval(() => {
                this.clock = new Date().toLocaleTimeString('en-GB', { hour12: false });
            }, 1000);
        },

        // ── Polling (live metrics refresh) ─────────────────────────────────
        startPolling() {
            this.pollInterval = setInterval(() => this.pollMetrics(), 2000);
        },

        async pollMetrics() {
            try {
                const res  = await fetch('/dispatcher/poll');
                const data = await res.json();
                this.updateState(data.nodes, data.log);
            } catch (e) {
                // Silently ignore poll errors (transient network issues)
            }
        },

        // ── State update helper ─────────────────────────────────────────────
        updateState(newNodes, newLog) {
            // Check for newly isolated / offline nodes and show alerts
            newNodes.forEach(newNode => {
                const old = this.nodes.find(n => n.id === newNode.id);
                if (!old) return;

                if (!old.offline && newNode.offline) {
                    this.pushAlert('danger', `⚠ ${newNode.name} has gone OFFLINE — traffic redistributed`);
                }
                if (!old.isolated && newNode.isolated) {
                    this.pushAlert('danger', `🔥 ${newNode.name} ISOLATED — CPU ${newNode.cpu_pct.toFixed(0)}% exceeds threshold. Fallback to Least Connections.`);
                }
                if (old.isolated && !newNode.isolated) {
                    this.pushAlert('warn', `✓ ${newNode.name} recovered and rejoined the pool`);
                }
            });

            this.nodes = newNodes;
            if (newLog) this.log = newLog;
            this.totalDispatched = newNodes.reduce((s, n) => s + (n.total_served || 0), 0);
            this.updateCharts();
        },

        // ── Single dispatch ─────────────────────────────────────────────────
        async singleDispatch() {
            this.loading = true;
            try {
                const res  = await this.post('/dispatcher/dispatch', {
                    strategy: this.strategy,
                    hash_key: 'user:' + Math.random().toString(36).slice(2, 8),
                });
                const data = await res.json();
                if (data.ok) {
                    this.showPacketAnimation(data.target?.id);
                    this.updateState(data.nodes, null);
                    if (data.log_entry) this.log.unshift(data.log_entry);
                }
            } finally {
                this.loading = false;
            }
        },

        // ── Burst dispatch ──────────────────────────────────────────────────
        async burstDispatch() {
            this.loading = true;
            this.burstProgress = 0;

            // Simulate progressive progress updates
            const progressInterval = setInterval(() => {
                if (this.burstProgress < 90) this.burstProgress += 10;
            }, 100);

            try {
                const res  = await this.post('/dispatcher/burst', {
                    strategy: this.strategy,
                    count:    parseInt(this.burstCount),
                });
                const data = await res.json();
                if (data.ok) {
                    this.burstProgress = 100;
                    this.updateState(data.nodes, data.log);
                    // Flash last dispatch target
                    if (data.dispatches?.length) {
                        const lastDispatch = data.dispatches.at(-1);
                        if (lastDispatch) this.showPacketAnimation(lastDispatch.id);
                    }
                    this.pushAlert('warn', `⚡ Burst complete: ${this.burstCount} payloads dispatched via ${this.currentStrategy.label}`);
                }
            } finally {
                clearInterval(progressInterval);
                this.loading = false;
                setTimeout(() => this.burstProgress = 0, 1500);
            }
        },

        // ── Node control ─────────────────────────────────────────────────────
        async toggleNode(nodeId) {
            const res  = await this.post('/dispatcher/node/toggle', { node_id: nodeId });
            const data = await res.json();
            if (data.ok) this.updateState(data.nodes, null);
        },

        async chaosNode() {
            const res  = await this.post('/dispatcher/node/chaos', {});
            const data = await res.json();
            if (data.ok) this.updateState(data.nodes, null);
        },

        async injectLatency(nodeId) {
            const res  = await this.post('/dispatcher/node/latency-spike', { node_id: nodeId });
            const data = await res.json();
            if (data.ok) {
                this.updateState(data.nodes, null);
                const node = data.nodes.find(n => n.id === nodeId);
                this.pushAlert('warn', `⏱ Latency spike injected on ${node?.name}: ${node?.latency_ms.toFixed(0)}ms`);
            }
        },

        async injectCPU(nodeId) {
            const res  = await this.post('/dispatcher/node/cpu-spike', { node_id: nodeId });
            const data = await res.json();
            if (data.ok) {
                this.updateState(data.nodes, null);
                const node = data.nodes.find(n => n.id === nodeId);
                this.pushAlert('danger', `🔥 CPU spike injected on ${node?.name}: ${node?.cpu_pct.toFixed(0)}% — isolation may trigger`);
            }
        },

        async resetCluster() {
    const res  = await this.post('/dispatcher/reset', {});
    const data = await res.json();
    if (data.ok) {
        this.updateState(data.nodes, []);
        this.log = [];
        this.alerts = [];
    }
},
        async clearLog() {
            await this.post('/dispatcher/log/clear', {});
            this.log = [];
        },
        // ── Packet animation ────────────────────────────────────────────────
        showPacketAnimation(targetId) {
            this.lastTarget   = targetId;
            this.packetVisible = true;
            setTimeout(() => {
                this.packetVisible = false;
                setTimeout(() => { this.lastTarget = null; }, 2000);
            }, 600);
        },

        // ── Alert management ─────────────────────────────────────────────────
        pushAlert(type, message) {
            const id = ++this.alertCounter;
            this.alerts.push({ id, type, message, visible: true });
            // Auto-dismiss after 6 seconds
            setTimeout(() => this.dismissAlert(id), 6000);
            // Cap at 3 visible alerts
            if (this.alerts.length > 3) this.alerts.shift();
        },

        dismissAlert(id) {
            const alert = this.alerts.find(a => a.id === id);
            if (alert) alert.visible = false;
            setTimeout(() => {
                this.alerts = this.alerts.filter(a => a.id !== id);
            }, 300);
        },

        // ── HTTP helper ───────────────────────────────────────────────────────
        async post(url, body) {
            return fetch(url, {
                method:  'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept':       'application/json',
                },
                body: JSON.stringify(body),
            });
        },
    };
}
</script>

</body>
</html>
