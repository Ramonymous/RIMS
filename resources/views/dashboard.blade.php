<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Computed;
use Mary\Traits\Toast;
use App\Models\Receiving;
use App\Models\Outgoing;
use App\Models\Singlepart;
use App\Models\Movement;
use App\Models\RequestList;

new
#[Layout('components.layouts.app')]
#[Title('Dashboard')]
class extends Component {
    use Toast;

    public bool $isSubscribed = false;
    public bool $notificationsSupported = false;

    public function mount(): void
    {
        $this->notificationsSupported = true;

        // ✅ SOURCE OF TRUTH = DATABASE
        $this->isSubscribed = auth()->user()
            ->pushSubscriptions()
            ->exists();
    }

    /* ===================== PUSH ===================== */

    public function saveSubscription(array $subscription): void
    {
        try {
            auth()->user()->updatePushSubscription(
                $subscription['endpoint'],
                $subscription['keys']['p256dh'] ?? null,
                $subscription['keys']['auth'] ?? null
            );

            $this->isSubscribed = true;
            $this->success('Notifikasi berhasil diaktifkan');
        } catch (\Throwable $e) {
            $this->isSubscribed = false;
            $this->error('Gagal menyimpan subscription');
        }
    }

    public function unsubscribe(): void
    {
        try {
            auth()->user()->pushSubscriptions()->delete();

            $this->isSubscribed = false;

            // 🔔 trigger event ke JS untuk unsubscribe browser
            $this->dispatch('push-unsubscribed');

            $this->success('Notifikasi dinonaktifkan');
        } catch (\Throwable $e) {
            $this->error('Gagal menonaktifkan notifikasi');
        }
    }

    #[Computed]
    public function totalRequestsToday()
    {
        return RequestList::where('status', '!=', 'fulfilled')
            ->whereDate('created_at', today())
            ->count();
    }

    #[Computed]
    public function activePartsCount()
    {
        return Singlepart::where('is_active', true)->count();
    }

    #[Computed]
    public function lowStockItems()
    {
        return Singlepart::where('is_active', true)
            ->where('stock', '<=', 10)
            ->count();
    }

    #[Computed]
    public function pendingReceivings()
    {
        return Receiving::whereIn('status', ['draft', 'pending'])
            ->get()
            ->unique('receiving_number')
            ->count();
    }

    #[Computed]
    public function pendingOutgoings()
    {
        return Outgoing::whereNull('dispatched_at')
            ->get()
            ->unique('outgoing_number')
            ->count();
    }

    #[Computed]
    public function totalMovementsToday()
    {
        return Movement::whereDate('created_at', today())->count();
    }

    #[Computed]
    public function inMovementsToday()
    {
        return Movement::where('type', 'in')
            ->whereDate('created_at', today())
            ->sum('qty') ?? 0;
    }

    #[Computed]
    public function outMovementsToday()
    {
        return Movement::where('type', 'out')
            ->whereDate('created_at', today())
            ->sum('qty') ?? 0;
    }

    #[Computed]
    public function recentMovements()
    {
        return Movement::with(['part:id,part_number,part_name', 'user:id,name'])
            ->latest()
            ->limit(10)
            ->get();
    }

    #[Computed]
    public function recentRequests()
    {
        return RequestList::with(['part:id,part_number,part_name', 'request:id,destination,requested_at'])
            ->latest()
            ->limit(5)
            ->get();
    }

    #[Computed]
    public function recentReceivings()
    {
        return Receiving::with(['part:id,part_number,part_name', 'receivedBy:id,name'])
            ->latest()
            ->limit(5)
            ->get()
            ->unique('receiving_number')
            ->values();
    }

    #[Computed]
    public function lowStockAlerts()
    {
        return Singlepart::where('is_active', true)
            ->where('stock', '<=', 10)
            ->orderBy('stock')
            ->limit(5)
            ->get();
    }

    #[Computed]
    public function requestStatusBreakdown()
    {
        return [
            'pending' => RequestList::where('status', 'pending')->count(),
            'fulfilled' => RequestList::where('status', 'fulfilled')->count(),
            'urgent' => RequestList::where('is_urgent', true)->where('status', '!=', 'fulfilled')->count(),
        ];
    }
}; ?>

<div class="min-h-screen">
    <x-header title="Dashboard" subtitle="Real-time inventory management overview" separator progress-indicator />

    <div class="max-w-7xl mx-auto space-y-6">

        @if (session('error'))
            <x-alert title="Akses Ditolak" description="{{ session('error') }}" icon="o-exclamation-circle" class="alert-error" dismissible />
        @endif
        
        <!-- Notification Card -->
        <x-card shadow class="bg-white dark:bg-gradient-to-br dark:from-slate-950 dark:via-slate-900 dark:to-indigo-950 dark:bg-white/5 backdrop-blur-xl border border-gray-300 dark:border-white/10 rounded-2xl">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <div class="p-3 bg-gradient-to-br from-indigo-500/20 to-purple-500/20 rounded-xl border border-indigo-400/30">
                        <x-icon name="o-bell" class="w-8 h-8 text-indigo-600 dark:text-indigo-300" />
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Notifikasi Push</h3>
                        <p class="text-sm text-gray-600 dark:text-gray-300">
                            <span x-data="{ subscribed: @entangle('isSubscribed') }">
                                <span x-show="subscribed" class="text-emerald-600 dark:text-emerald-300">✓ Aktif - Anda akan menerima notifikasi</span>
                                <span x-show="!subscribed" class="text-amber-600 dark:text-amber-300">Dapatkan notifikasi untuk permintaan part baru</span>
                            </span>
                        </p>
                    </div>
                </div>
                <div x-data="notificationHandler()" x-init="init()">
                    <template x-if="!supported">
                        <div class="px-4 py-2 rounded-lg bg-red-500/20 text-red-700 dark:text-red-300 text-sm border border-red-400/30">
                            Browser tidak mendukung notifikasi
                        </div>
                    </template>
                    <template x-if="supported && !isSubscribed">
                        <x-button 
                            icon="o-bell-alert" 
                            class="btn-primary shadow-lg shadow-indigo-500/30" 
                            @click="subscribe()"
                            x-bind:disabled="loading"
                            label="Aktifkan Notifikasi" 
                        />
                    </template>
                    <template x-if="supported && isSubscribed">
                        <x-button 
                            icon="o-bell-slash" 
                            class="btn-outline border-red-400/50 text-red-600 dark:text-red-300 hover:bg-red-500/10" 
                            wire:click="unsubscribe"
                            wire:confirm="Yakin ingin menonaktifkan notifikasi?"
                            label="Nonaktifkan" 
                        />
                    </template>
                </div>
            </div>
        </x-card>

        <!-- Key Metrics Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <!-- Requests Today -->
            <x-stat
                class="bg-blue-50 dark:bg-gradient-to-br dark:from-blue-950 dark:via-slate-900 dark:to-blue-900 dark:bg-white/5 backdrop-blur-xl border border-blue-200 dark:border-blue-400/20 rounded-2xl"
                title="Permintaan Hari Ini"
                :value="$this->totalRequestsToday"
                icon="o-clipboard-document-list"
                description="Request sedang diproses" />
            
            <!-- Active Parts -->
            <x-stat
                class="bg-emerald-50 dark:bg-gradient-to-br dark:from-emerald-950 dark:via-slate-900 dark:to-emerald-900 dark:bg-white/5 backdrop-blur-xl border border-emerald-200 dark:border-emerald-400/20 rounded-2xl"
                title="Part Tersedia"
                :value="$this->activePartsCount"
                icon="o-cube"
                description="Jenis part aktif" />
            
            <!-- Low Stock Items -->
            <a href="/parts">
                <x-stat
                    class="bg-amber-50 dark:bg-gradient-to-br dark:from-amber-950 dark:via-slate-900 dark:to-orange-900 dark:bg-white/5 backdrop-blur-xl border border-amber-200 dark:border-amber-400/20 rounded-2xl cursor-pointer hover:border-amber-300 dark:hover:border-amber-400/40 transition-all"
                    title="Stock Rendah"
                    :value="$this->lowStockItems"
                    icon="o-exclamation-triangle"
                    description="Perlu reorder" />
            </a>
            
            <!-- Pending Movements -->
            <x-stat
                class="bg-purple-50 dark:bg-gradient-to-br dark:from-purple-950 dark:via-slate-900 dark:to-purple-900 dark:bg-white/5 backdrop-blur-xl border border-purple-200 dark:border-purple-400/20 rounded-2xl"
                title="Gerakan Hari Ini"
                :value="$this->totalMovementsToday"
                icon="o-arrow-path"
                description="Masuk & Keluar" />
        </div>

        <!-- Movement Summary & Request Status -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <!-- Movement Summary -->
            <div class="lg:col-span-2 space-y-4">
                <x-card shadow class="bg-white dark:bg-gradient-to-br dark:from-slate-950 dark:via-slate-900 dark:to-indigo-950 dark:bg-white/5 backdrop-blur-xl border border-gray-200 dark:border-white/10 rounded-2xl">
                    <x-slot:title>Ringkasan Gerakan Hari Ini</x-slot:title>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="p-4 rounded-xl border border-green-500/20 bg-green-500/5">
                            <div class="flex items-center gap-2 mb-2">
                                <x-icon name="o-arrow-down-tray" class="w-5 h-5 text-green-600 dark:text-green-400" />
                                <span class="text-sm text-gray-700 dark:text-gray-300">Barang Masuk</span>
                            </div>
                            <div class="text-3xl font-bold text-green-600 dark:text-green-400">{{ $this->inMovementsToday }}</div>
                            <p class="text-xs text-gray-600 dark:text-gray-400 mt-2">Total qty penerimaan</p>
                        </div>
                        <div class="p-4 rounded-xl border border-red-500/20 bg-red-500/5">
                            <div class="flex items-center gap-2 mb-2">
                                <x-icon name="o-arrow-up-tray" class="w-5 h-5 text-red-600 dark:text-red-400" />
                                <span class="text-sm text-gray-700 dark:text-gray-300">Barang Keluar</span>
                            </div>
                            <div class="text-3xl font-bold text-red-600 dark:text-red-400">{{ $this->outMovementsToday }}</div>
                            <p class="text-xs text-gray-600 dark:text-gray-400 mt-2">Total qty pengeluaran</p>
                        </div>
                    </div>
                </x-card>

                <!-- Pending Batch Operations -->
                <div class="grid grid-cols-2 gap-4">
                    <a href="/receivings">
                        <x-card class="bg-emerald-50 dark:bg-gradient-to-br dark:from-slate-950 dark:via-slate-900 dark:to-indigo-950 dark:bg-white/5 backdrop-blur-xl border border-emerald-200 dark:border-white/10 rounded-2xl hover:border-emerald-300 dark:hover:border-emerald-400/40 transition-all cursor-pointer h-full">
                            <div class="flex items-center gap-3">
                                <div class="p-3 bg-emerald-500/10 rounded-lg">
                                    <x-icon name="o-inbox-arrow-down" class="w-6 h-6 text-emerald-600 dark:text-emerald-400" />
                                </div>
                                <div>
                                    <p class="text-sm text-gray-700 dark:text-gray-300">Penerimaan</p>
                                    <p class="text-2xl font-bold text-emerald-600 dark:text-emerald-400">{{ $this->pendingReceivings }}</p>
                                    <p class="text-xs text-gray-600 dark:text-gray-400">menunggu</p>
                                </div>
                            </div>
                        </x-card>
                    </a>
                    <a href="/outgoings">
                        <x-card class="bg-amber-50 dark:bg-gradient-to-br dark:from-slate-950 dark:via-slate-900 dark:to-indigo-950 dark:bg-white/5 backdrop-blur-xl border border-amber-200 dark:border-white/10 rounded-2xl hover:border-amber-300 dark:hover:border-amber-400/40 transition-all cursor-pointer h-full">
                            <div class="flex items-center gap-3">
                                <div class="p-3 bg-amber-500/10 rounded-lg">
                                    <x-icon name="o-archive-box" class="w-6 h-6 text-amber-600 dark:text-amber-400" />
                                </div>
                                <div>
                                    <p class="text-sm text-gray-700 dark:text-gray-300">Pengeluaran</p>
                                    <p class="text-2xl font-bold text-amber-600 dark:text-amber-400">{{ $this->pendingOutgoings }}</p>
                                    <p class="text-xs text-gray-600 dark:text-gray-400">menunggu</p>
                                </div>
                            </div>
                        </x-card>
                    </a>
                </div>
            </div>

            <!-- Request Status Breakdown -->
            <x-card shadow class="bg-white dark:bg-gradient-to-br dark:from-slate-950 dark:via-slate-900 dark:to-indigo-950 dark:bg-white/5 backdrop-blur-xl border border-gray-300 dark:border-white/10 rounded-2xl">
                <x-slot:title>Status Permintaan</x-slot:title>
                <div class="space-y-4">
                    <div>
                        <div class="flex justify-between items-center mb-2">
                            <span class="text-sm text-gray-700 dark:text-gray-300">Pending</span>
                            <span class="font-semibold text-yellow-600 dark:text-yellow-400">{{ $this->requestStatusBreakdown['pending'] }}</span>
                        </div>
                        <div class="w-full h-2 bg-slate-700 rounded-full overflow-hidden">
                            <div class="h-full bg-gradient-to-r from-yellow-500 to-yellow-400 rounded-full" 
                                 style="width: {{ $this->requestStatusBreakdown['pending'] > 0 ? '100%' : '0%' }}"></div>
                        </div>
                    </div>
                    <div>
                        <div class="flex justify-between items-center mb-2">
                            <span class="text-sm text-gray-700 dark:text-gray-300">Fulfilled</span>
                            <span class="font-semibold text-emerald-600 dark:text-emerald-400">{{ $this->requestStatusBreakdown['fulfilled'] }}</span>
                        </div>
                        <div class="w-full h-2 bg-slate-700 rounded-full overflow-hidden">
                            <div class="h-full bg-gradient-to-r from-emerald-500 to-emerald-400 rounded-full" 
                                 style="width: {{ $this->requestStatusBreakdown['fulfilled'] > 0 ? '100%' : '0%' }}"></div>
                        </div>
                    </div>
                    <div>
                        <div class="flex justify-between items-center mb-2">
                            <span class="text-sm text-gray-700 dark:text-gray-300">Urgent</span>
                            <span class="font-semibold text-red-600 dark:text-red-400">{{ $this->requestStatusBreakdown['urgent'] }}</span>
                        </div>
                        <div class="w-full h-2 bg-slate-700 rounded-full overflow-hidden">
                            <div class="h-full bg-gradient-to-r from-red-500 to-red-400 rounded-full" 
                                 style="width: {{ $this->requestStatusBreakdown['urgent'] > 0 ? '100%' : '0%' }}"></div>
                        </div>
                    </div>
                </div>
            </x-card>
        </div>

        <!-- Low Stock Alerts -->
        @if ($this->lowStockAlerts->count() > 0)
        <x-card shadow class="bg-red-100 dark:bg-gradient-to-br dark:from-red-950 dark:via-slate-900 dark:to-orange-950 dark:bg-white/5 backdrop-blur-xl border border-red-300 dark:border-red-400/20 rounded-2xl">
            <x-slot:title class="text-red-300">🚨 Peringatan Stock Rendah</x-slot:title>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-3">
                @foreach ($this->lowStockAlerts as $part)
                <div class="p-3 rounded-lg border border-red-500/30 bg-red-500/5">
                    <p class="font-mono text-sm font-semibold text-red-700 dark:text-red-300 truncate">{{ $part->part_number }}</p>
                    <p class="text-xs text-gray-600 dark:text-gray-400 truncate">{{ $part->part_name }}</p>
                    <div class="mt-2 flex items-center justify-between">
                        <span class="text-lg font-bold text-red-600 dark:text-red-400">{{ $part->stock }}</span>
                        <span class="text-xs text-gray-500">unit</span>
                    </div>
                </div>
                @endforeach
            </div>
        </x-card>
        @endif

        <!-- Quick Actions -->
        <x-card title="Aksi Cepat" shadow class="bg-white dark:bg-gradient-to-br dark:from-slate-950 dark:via-slate-900 dark:to-indigo-950 dark:bg-white/5 backdrop-blur-xl border border-gray-300 dark:border-white/10 rounded-2xl">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <a href="/requests" class="block p-6 rounded-xl border border-white/10 bg-gradient-to-br from-blue-500/10 to-indigo-500/10 hover:from-blue-500/20 hover:to-indigo-500/20 transition-all hover:-translate-y-1">
                    <x-icon name="o-plus-circle" class="w-10 h-10 text-blue-600 dark:text-blue-300 mb-3" />
                    <h4 class="font-semibold text-gray-900 dark:text-white mb-1">Buat Permintaan</h4>
                    <p class="text-sm text-gray-600 dark:text-gray-300">Request part baru</p>
                </a>
                <a href="/request-list" class="block p-6 rounded-xl border border-white/10 bg-gradient-to-br from-purple-500/10 to-pink-500/10 hover:from-purple-500/20 hover:to-pink-500/20 transition-all hover:-translate-y-1">
                    <x-icon name="o-list-bullet" class="w-10 h-10 text-purple-600 dark:text-purple-300 mb-3" />
                    <h4 class="font-semibold text-gray-900 dark:text-white mb-1">Lihat Permintaan</h4>
                    <p class="text-sm text-gray-600 dark:text-gray-300">Monitor request aktif</p>
                </a>
                <a href="/receivings" class="block p-6 rounded-xl border border-white/10 bg-gradient-to-br from-emerald-500/10 to-teal-500/10 hover:from-emerald-500/20 hover:to-teal-500/20 transition-all hover:-translate-y-1">
                    <x-icon name="o-arrow-down-tray" class="w-10 h-10 text-emerald-600 dark:text-emerald-300 mb-3" />
                    <h4 class="font-semibold text-gray-900 dark:text-white mb-1">Penerimaan Part</h4>
                    <p class="text-sm text-gray-600 dark:text-gray-300">Input barang masuk</p>
                </a>
                <a href="/outgoings" class="block p-6 rounded-xl border border-white/10 bg-gradient-to-br from-amber-500/10 to-orange-500/10 hover:from-amber-500/20 hover:to-orange-500/20 transition-all hover:-translate-y-1">
                    <x-icon name="o-arrow-up-tray" class="w-10 h-10 text-amber-600 dark:text-amber-300 mb-3" />
                    <h4 class="font-semibold text-gray-900 dark:text-white mb-1">Pengeluaran Part</h4>
                    <p class="text-sm text-gray-600 dark:text-gray-300">Supply ke line produksi</p>
                </a>
            </div>
        </x-card>

        <!-- Recent Activity Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <!-- Recent Movements -->
            <div class="lg:col-span-2">
                <x-card shadow class="bg-white dark:bg-gradient-to-br dark:from-slate-950 dark:via-slate-900 dark:to-indigo-950 dark:bg-white/5 backdrop-blur-xl border border-gray-300 dark:border-white/10 rounded-2xl h-full">
                    <x-slot:title>Gerakan Stok Terbaru</x-slot:title>
                    <div class="space-y-2 max-h-96 overflow-y-auto">
                        @forelse ($this->recentMovements as $movement)
                        <div class="flex items-center justify-between p-3 rounded-lg border border-gray-200 dark:border-white/5 hover:border-gray-300 dark:hover:border-white/10 transition-all">
                            <div class="flex items-center gap-3 flex-1 min-w-0">
                                <div class="p-2 rounded-lg {{ $movement->type === 'in' ? 'bg-green-500/10' : 'bg-red-500/10' }}">
                                    <x-icon :name="$movement->type === 'in' ? 'o-arrow-down' : 'o-arrow-up'" 
                                           :class="'w-4 h-4 ' . ($movement->type === 'in' ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400')" />
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="font-mono text-sm font-semibold truncate text-gray-900 dark:text-white">{{ $movement->part->part_number }}</p>
                                    <p class="text-xs text-gray-600 dark:text-gray-400">{{ $movement->user->name }}</p>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="font-semibold {{ $movement->type === 'in' ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                    {{ $movement->type === 'in' ? '+' : '-' }}{{ $movement->qty }}
                                </p>
                                <p class="text-xs text-gray-600 dark:text-gray-400">{{ $movement->created_at->format('H:i') }}</p>
                            </div>
                        </div>
                        @empty
                        <p class="text-center text-gray-600 dark:text-gray-400 py-4">Tidak ada gerakan hari ini</p>
                        @endforelse
                    </div>
                </x-card>
            </div>

            <!-- Recent Requests -->
            <x-card shadow class="bg-white dark:bg-gradient-to-br dark:from-slate-950 dark:via-slate-900 dark:to-indigo-950 dark:bg-white/5 backdrop-blur-xl border border-gray-200 dark:border-white/10 rounded-2xl">
                <x-slot:title>Permintaan Terbaru</x-slot:title>
                <div class="space-y-2 max-h-96 overflow-y-auto">
                    @forelse ($this->recentRequests as $request)
                    <div class="p-3 rounded-lg border border-gray-200 dark:border-white/5 hover:border-gray-300 dark:hover:border-white/10 transition-all">
                        <div class="flex items-start justify-between mb-2">
                            <p class="font-mono text-sm font-semibold text-blue-600 dark:text-blue-300 truncate flex-1">{{ $request->part->part_number }}</p>
                            <span class="badge badge-sm {{ $request->status === 'pending' ? 'badge-warning' : 'badge-success' }}">{{ ucfirst($request->status) }}</span>
                        </div>
                        <p class="text-xs text-gray-600 dark:text-gray-400 truncate mb-2">{{ $request->part->part_name }}</p>
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-gray-600 dark:text-gray-500">Qty: {{ $request->quantity }}</span>
                            <span class="text-gray-600 dark:text-gray-500">{{ $request->request?->requested_at?->format('H:i') ?? '-' }}</span>
                        </div>
                    </div>
                    @empty
                    <p class="text-center text-gray-600 dark:text-gray-400 py-4">Tidak ada permintaan</p>
                    @endforelse
                </div>
            </x-card>
        </div>

        <!-- Recent Receivings -->
        <x-card shadow class="bg-white dark:bg-gradient-to-br dark:from-slate-950 dark:via-slate-900 dark:to-indigo-950 dark:bg-white/5 backdrop-blur-xl border border-gray-300 dark:border-white/10 rounded-2xl">
            <x-slot:title>Penerimaan Terbaru</x-slot:title>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">No. Penerimaan</th>
                            <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">Part Number</th>
                            <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">Qty</th>
                            <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">Penerima</th>
                            <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">Status</th>
                            <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">Waktu</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->recentReceivings as $receiving)
                        <tr class="border-b border-gray-200 dark:border-white/5 hover:bg-gray-50 dark:hover:bg-white/5 transition-all">
                            <td class="py-3 px-4 font-mono text-blue-600 dark:text-blue-300">{{ $receiving->receiving_number }}</td>
                            <td class="py-3 px-4 font-mono text-sm text-gray-900 dark:text-white">{{ $receiving->part->part_number }}</td>
                            <td class="py-3 px-4 font-semibold text-gray-900 dark:text-white">{{ $receiving->quantity }}</td>
                            <td class="py-3 px-4 text-gray-700 dark:text-gray-400">{{ $receiving->receivedBy->name }}</td>
                            <td class="py-3 px-4">
                                <span class="badge badge-sm {{ $receiving->status === 'completed' ? 'badge-success' : ($receiving->status === 'pending' ? 'badge-warning' : 'badge-info') }}">
                                    {{ ucfirst($receiving->status) }}
                                </span>
                            </td>
                            <td class="py-3 px-4 text-gray-600 dark:text-gray-400 text-xs">{{ $receiving->created_at->format('d/m H:i') }}</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="py-6 text-center text-gray-600 dark:text-gray-400">Tidak ada data penerimaan</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>
    </div>
</div>

@push('scripts')
<script>
function notificationHandler() {
    return {
        supported: false,
        isSubscribed: @entangle('isSubscribed'),
        loading: false,

        async init() {
            this.supported = 'serviceWorker' in navigator && 'PushManager' in window;
            if (!this.supported) return;

            await this.checkSubscription();

            // 🔔 listen dari Livewire (unsubscribe server)
            Livewire.on('push-unsubscribed', () => {
                this.unsubscribeClient();
            });
        },

        // 🔧 bersihin subscription browser yang nyangkut
        async checkSubscription() {
            try {
                const registration = await navigator.serviceWorker.ready;
                const subscription = await registration.pushManager.getSubscription();

                // browser punya subscription tapi DB tidak
                if (subscription && !this.isSubscribed) {
                    await subscription.unsubscribe();
                }
            } catch (error) {
                console.error('Error checking subscription:', error);
            }
        },

        async unsubscribeClient() {
            try {
                const registration = await navigator.serviceWorker.ready;
                const subscription = await registration.pushManager.getSubscription();
                if (subscription) {
                    await subscription.unsubscribe();
                }
            } catch (e) {
                console.error('Client unsubscribe failed:', e);
            }
        },

        async subscribe() {
            this.loading = true;

            try {
                let registration = await navigator.serviceWorker.getRegistration();
                if (!registration) {
                    registration = await navigator.serviceWorker.register('/sw.js');
                    await navigator.serviceWorker.ready;
                }

                const permission = await Notification.requestPermission();
                if (permission !== 'granted') {
                    alert('Izin notifikasi ditolak');
                    return;
                }

                const vapidPublicKey = '{{ config('webpush.vapid.public_key') }}';

                const subscription = await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: this.urlBase64ToUint8Array(vapidPublicKey)
                });

                await this.$wire.saveSubscription(subscription.toJSON());

            } catch (error) {
                console.error('Subscription failed:', error);
                alert('Gagal mengaktifkan notifikasi');
            } finally {
                this.loading = false;
            }
        },

        urlBase64ToUint8Array(base64String) {
            const padding = '='.repeat((4 - base64String.length % 4) % 4);
            const base64 = (base64String + padding)
                .replace(/-/g, '+')
                .replace(/_/g, '/');

            const rawData = window.atob(base64);
            return Uint8Array.from([...rawData].map(c => c.charCodeAt(0)));
        }
    };
}
</script>
@endpush
