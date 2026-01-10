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

<div class="min-h-screen bg-base-100 dark:bg-base-900">
<x-header title="Dashboard" subtitle="Real-time inventory management overview" separator progress-indicator />

<div class="max-w-7xl mx-auto space-y-6 px-3 sm:px-4 py-2">

    @if (session('error'))
        <x-alert title="Akses Ditolak" description="{{ session('error') }}" icon="o-exclamation-circle" class="alert-error shadow-lg" dismissible />
    @endif
    
    <!-- Notification Card -->
    <x-card shadow class="bg-base-50 dark:bg-base-800/80 border border-base-200 dark:border-base-700 rounded-2xl transition-all">
        <div class="flex flex-col md:flex-row items-center justify-between gap-4">
            <div class="flex items-center gap-4">
                <div class="p-3 bg-primary/10 dark:bg-primary/20 rounded-xl border border-primary/20 dark:border-primary/30">
                    <x-icon name="o-bell" class="w-8 h-8 text-primary dark:text-primary/80" />
                </div>
                <div>
                    <h3 class="text-lg font-bold text-base-content dark:text-base-100">Notifikasi Push</h3>
                    <p class="text-sm text-base-content/70 dark:text-base-300">
                        <span x-data="{ subscribed: @entangle('isSubscribed') }">
                            <span x-show="subscribed" class="text-success font-medium">✓ Aktif - Anda akan menerima notifikasi</span>
                            <span x-show="!subscribed" class="text-warning font-medium">Dapatkan notifikasi untuk permintaan part baru</span>
                        </span>
                    </p>
                </div>
            </div>
            <div x-data="notificationHandler()" x-init="init()" class="w-full md:w-auto">
                <template x-if="!supported">
                    <div class="px-4 py-2 rounded-lg bg-error/10 text-error text-sm border border-error/20">
                        Browser tidak mendukung notifikasi
                    </div>
                </template>
                <template x-if="supported && !isSubscribed">
                    <x-button 
                        icon="o-bell-alert" 
                        class="btn-primary btn-block md:btn-wide shadow-md h-12 min-h-12" 
                        @click="subscribe()"
                        x-bind:disabled="loading"
                        label="Aktifkan Notifikasi" 
                    />
                </template>
                <template x-if="supported && isSubscribed">
                    <x-button 
                        icon="o-bell-slash" 
                        class="btn-outline btn-error btn-block md:btn-wide h-12 min-h-12" 
                        wire:click="unsubscribe"
                        wire:confirm="Yakin ingin menonaktifkan notifikasi?"
                        label="Nonaktifkan" 
                    />
                </template>
            </div>
        </div>
    </x-card>

    <!-- Key Metrics Grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <x-stat
            class="border border-base-200 dark:border-base-700 bg-base-50 dark:bg-base-800/50"
            title="Permintaan Hari Ini"
            :value="$this->totalRequestsToday"
            icon="o-clipboard-document-list"
            description="Request sedang diproses"
            color="text-primary dark:text-primary/80" />
        
        <x-stat
            class="border border-base-200 dark:border-base-700 bg-base-50 dark:bg-base-800/50"
            title="Part Tersedia"
            :value="$this->activePartsCount"
            icon="o-cube"
            description="Jenis part aktif"
            color="text-success dark:text-success/80" />
        
        <a href="/parts" class="block group">
            <x-stat
                class="border border-base-200 dark:border-base-700 bg-base-50 dark:bg-base-800/50 group-hover:bg-warning/5 dark:group-hover:bg-warning/10 transition-colors"
                title="Stock Rendah"
                :value="$this->lowStockItems"
                icon="o-exclamation-triangle"
                description="Perlu reorder segera"
                color="text-warning dark:text-warning/80" />
        </a>
        
        <x-stat
            class="border border-base-200 dark:border-base-700 bg-base-50 dark:bg-base-800/50"
            title="Gerakan Hari Ini"
            :value="$this->totalMovementsToday"
            icon="o-arrow-path"
            description="In & Outbound"
            color="text-purple-600 dark:text-purple-400" />
    </div>

    <!-- Movement Summary & Status -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <x-card title="Ringkasan Gerakan" shadow class="bg-base-50 dark:bg-base-800/50 border border-base-200 dark:border-base-700">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="p-4 rounded-xl border border-success/20 bg-success/5 dark:bg-success/10 flex flex-col items-center sm:items-start">
                        <div class="flex items-center gap-2 mb-2 text-success dark:text-success/80">
                            <x-icon name="o-arrow-down-tray" class="w-5 h-5" />
                            <span class="text-sm font-semibold uppercase tracking-wider">Barang Masuk</span>
                        </div>
                        <div class="text-4xl font-black text-success dark:text-success/80">{{ $this->inMovementsToday }}</div>
                        <p class="text-xs opacity-70 mt-2 font-medium">Unit diterima hari ini</p>
                    </div>
                    <div class="p-4 rounded-xl border border-error/20 bg-error/5 dark:bg-error/10 flex flex-col items-center sm:items-start">
                        <div class="flex items-center gap-2 mb-2 text-error dark:text-error/80">
                            <x-icon name="o-arrow-up-tray" class="w-5 h-5" />
                            <span class="text-sm font-semibold uppercase tracking-wider">Barang Keluar</span>
                        </div>
                        <div class="text-4xl font-black text-error dark:text-error/80">{{ $this->outMovementsToday }}</div>
                        <p class="text-xs opacity-70 mt-2 font-medium">Unit dikeluarkan hari ini</p>
                    </div>
                </div>
            </x-card>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-card class="bg-base-50 dark:bg-base-800/50 border-l-4 border-l-success hover:shadow-lg transition-all cursor-pointer active:scale-[0.99]">
                    <a href="/receivings" class="flex items-center gap-4 p-3">
                        <div class="p-3 bg-success/10 rounded-full text-success dark:text-success/80">
                            <x-icon name="o-inbox-arrow-down" class="w-8 h-8" />
                        </div>
                        <div>
                            <p class="text-sm opacity-70 dark:opacity-80">Penerimaan Pending</p>
                            <p class="text-2xl font-bold text-base-content dark:text-base-100">{{ $this->pendingReceivings }}</p>
                        </div>
                    </a>
                </x-card>
                <x-card class="bg-base-50 dark:bg-base-800/50 border-l-4 border-l-warning hover:shadow-lg transition-all cursor-pointer active:scale-[0.99]">
                    <a href="/outgoings" class="flex items-center gap-4 p-3">
                        <div class="p-3 bg-warning/10 rounded-full text-warning dark:text-warning/80">
                            <x-icon name="o-archive-box" class="w-8 h-8" />
                        </div>
                        <div>
                            <p class="text-sm opacity-70 dark:opacity-80">Pengeluaran Pending</p>
                            <p class="text-2xl font-bold text-base-content dark:text-base-100">{{ $this->pendingOutgoings }}</p>
                        </div>
                    </a>
                </x-card>
            </div>
        </div>

        <!-- Status Breakdown -->
        <x-card title="Status Permintaan" shadow class="bg-base-50 dark:bg-base-800/50 border border-base-200 dark:border-base-700">
            <div class="space-y-6 mt-4">
                @foreach([
                    ['label' => 'Pending', 'val' => 'pending', 'color' => 'from-yellow-500 to-amber-400', 'text' => 'text-warning dark:text-warning/80'],
                    ['label' => 'Fulfilled', 'val' => 'fulfilled', 'color' => 'from-success to-teal-400', 'text' => 'text-success dark:text-success/80'],
                    ['label' => 'Urgent', 'val' => 'urgent', 'color' => 'from-error to-rose-400', 'text' => 'text-error dark:text-error/80']
                ] as $item)
                <div>
                    <div class="flex justify-between items-center mb-2">
                        <span class="text-sm font-medium text-base-content/80 dark:text-base-300">{{ $item['label'] }}</span>
                        <span class="font-bold {{ $item['text'] }}">{{ $this->requestStatusBreakdown[$item['val']] }}</span>
                    </div>
                    <div class="w-full h-2.5 bg-base-200 dark:bg-base-700 rounded-full overflow-hidden">
                        <div class="h-full bg-gradient-to-r {{ $item['color'] }}" 
                             style="width: {{ $this->requestStatusBreakdown[$item['val']] > 0 ? '100%' : '5%' }}"></div>
                    </div>
                </div>
                @endforeach
            </div>
        </x-card>
    </div>

    <!-- Low Stock Alerts -->
    @if ($this->lowStockAlerts->count() > 0)
    <x-card shadow class="bg-error/5 dark:bg-error/10 border border-error/30 dark:border-error/50 rounded-2xl">
        <x-slot:title class="text-error font-bold">🚨 Peringatan Stock Rendah</x-slot:title>
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3">
            @foreach ($this->lowStockAlerts as $part)
            <div class="p-3 rounded-xl border border-error/20 bg-base-50 dark:bg-base-800 shadow-sm group hover:border-error/50 transition-colors">
                <p class="font-mono text-xs font-bold text-error truncate">{{ $part->part_number }}</p>
                <p class="text-[10px] text-base-content/60 dark:text-base-400 truncate">{{ $part->part_name }}</p>
                <div class="mt-2 flex items-end justify-between">
                    <span class="text-xl font-black text-base-content dark:text-base-100">{{ $part->stock }}</span>
                    <span class="text-[10px] uppercase font-bold opacity-40">Unit</span>
                </div>
            </div>
            @endforeach
        </div>
    </x-card>
    @endif

    <!-- Quick Actions -->
    <x-card title="Aksi Cepat" shadow class="bg-base-50 dark:bg-base-800/50 border border-base-200 dark:border-base-700">
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            @php
                $actions = [
                    ['url' => '/requests', 'icon' => 'o-plus-circle', 'color' => 'text-primary dark:text-primary/80', 'bg' => 'bg-primary/10 dark:bg-primary/20', 'label' => 'Buat Permintaan', 'desc' => 'Request part baru'],
                    ['url' => '/request-list', 'icon' => 'o-list-bullet', 'color' => 'text-purple-600 dark:text-purple-400', 'bg' => 'bg-purple-500/10 dark:bg-purple-500/20', 'label' => 'Monitor', 'desc' => 'Cek request aktif'],
                    ['url' => '/receivings', 'icon' => 'o-arrow-down-tray', 'color' => 'text-success dark:text-success/80', 'bg' => 'bg-success/10 dark:bg-success/20', 'label' => 'Inbound', 'desc' => 'Input barang masuk'],
                    ['url' => '/outgoings', 'icon' => 'o-arrow-up-tray', 'color' => 'text-warning dark:text-warning/80', 'bg' => 'bg-warning/10 dark:bg-warning/20', 'label' => 'Outbound', 'desc' => 'Supply ke line']
                ];
            @endphp
            @foreach($actions as $act)
            <a href="{{ $act['url'] }}" class="group p-4 rounded-xl border border-base-200 dark:border-base-700 hover:bg-base-100 dark:hover:bg-base-700 transition-all active:scale-[0.98]">
                <div class="p-3 {{ $act['bg'] }} rounded-lg w-fit mb-3 group-hover:scale-110 transition-transform">
                    <x-icon name="{{ $act['icon'] }}" class="w-8 h-8 {{ $act['color'] }}" />
                </div>
                <h4 class="font-bold text-base-content dark:text-base-100">{{ $act['label'] }}</h4>
                <p class="text-xs text-base-content/60 dark:text-base-400">{{ $act['desc'] }}</p>
            </a>
            @endforeach
        </div>
    </x-card>

    <!-- Activity Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2">
            <x-card title="Gerakan Stok Terbaru" shadow class="bg-base-50 dark:bg-base-800/50 border border-base-200 dark:border-base-700 h-full">
                <div class="divide-y divide-base-200 dark:divide-base-700 max-h-96 overflow-y-auto pr-2">
                    @forelse ($this->recentMovements as $movement)
                    <div class="flex items-center justify-between py-3 group">
                        <div class="flex items-center gap-3">
                            <div class="p-2 rounded-lg {{ $movement->type === 'in' ? 'bg-success/10 text-success dark:text-success/80' : 'bg-error/10 text-error dark:text-error/80' }}">
                                <x-icon :name="$movement->type === 'in' ? 'o-arrow-down' : 'o-arrow-up'" class="w-4 h-4" />
                            </div>
                            <div>
                                <p class="font-mono text-sm font-bold text-base-content dark:text-base-100">{{ $movement->part->part_number }}</p>
                                <p class="text-xs text-base-content/60 dark:text-base-400">{{ $movement->user->name }}</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="font-bold {{ $movement->type === 'in' ? 'text-success dark:text-success/80' : 'text-error dark:text-error/80' }}">
                                {{ $movement->type === 'in' ? '+' : '-' }}{{ $movement->qty }}
                            </p>
                            <p class="text-[10px] font-medium text-base-content/50 dark:text-base-500">{{ $movement->created_at->format('H:i') }}</p>
                        </div>
                    </div>
                    @empty
                    <div class="text-center opacity-50 py-10">Tidak ada gerakan hari ini</div>
                    @endforelse
                </div>
            </x-card>
        </div>

        <x-card title="Permintaan Terbaru" shadow class="bg-base-50 dark:bg-base-800/50 border border-base-200 dark:border-base-700">
            <div class="space-y-3 max-h-96 overflow-y-auto pr-2">
                @forelse ($this->recentRequests as $request)
                <div class="p-3 rounded-xl bg-base-100 dark:bg-base-700 border border-base-200 dark:border-base-600 hover:border-primary/30 transition-all">
                    <div class="flex items-start justify-between mb-1">
                        <p class="font-mono text-xs font-bold text-primary dark:text-primary/80">{{ $request->part->part_number }}</p>
                        <span class="badge badge-xs {{ $request->status === 'pending' ? 'badge-warning' : 'badge-success' }} font-bold text-[10px]">{{ $request->status }}</span>
                    </div>
                    <p class="text-[10px] text-base-content/60 dark:text-base-400 truncate">{{ $request->part->part_name }}</p>
                    <div class="mt-2 flex items-center justify-between text-[10px] font-bold text-base-content/50 dark:text-base-500">
                        <span>QTY: {{ $request->quantity }}</span>
                        <span>{{ $request->created_at?->diffForHumans() ?? '-' }}</span>
                    </div>
                </div>
                @empty
                <div class="text-center opacity-50 py-10 italic">Kosong</div>
                @endforelse
            </div>
        </x-card>
    </div>
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
