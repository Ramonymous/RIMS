<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Mary\Traits\Toast;
use Illuminate\Support\Facades\Auth;

new
#[Layout('components.layouts.app')]
#[Title('Pengaturan Telegram')]
class extends Component
{
    use Toast;

    public function unlinkTelegram(): void
    {
        $user = Auth::user();
        $user->telegram_user_id = null;
        $user->save();

        $this->success('Telegram berhasil diputuskan.');
    }

    public function getTelegramLinkUrl(): string
    {
        $botUsername = config('services.telegram-bot-api.username');
        $userId = Auth::id();
        
        // Encode user ID for security
        $startParam = base64_encode("user_{$userId}");
        
        return "https://t.me/{$botUsername}?start={$startParam}";
    }

    public function isLinked(): bool
    {
        return !empty(Auth::user()->telegram_user_id);
    }
}; ?>

<div class="min-h-screen text-gray-900 dark:text-gray-100/90">
    <x-header title="Pengaturan Telegram" subtitle="Kelola notifikasi Telegram Anda" icon="o-bell" separator />
    
    <div class="max-w-4xl mx-auto space-y-6">
        <x-card shadow class="bg-white dark:bg-gradient-to-br dark:from-slate-950 dark:via-slate-900 dark:to-indigo-950">
            <x-slot:title>
                <div class="flex items-center gap-3">
                    <div class="p-2 bg-blue-500/10 rounded-lg">
                        <svg class="w-6 h-6 text-blue-400" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.894 8.221l-1.97 9.28c-.145.658-.537.818-1.084.508l-3-2.21-1.446 1.394c-.14.18-.357.223-.548.223l.188-2.85 5.18-4.68c.223-.198-.054-.308-.346-.11l-6.4 4.03-2.76-.918c-.6-.187-.612-.6.125-.89l10.782-4.156c.5-.18.943.112.78.89z"/>
                        </svg>
                    </div>
                    <div>
                        <h3 class="font-bold text-lg">Notifikasi Telegram</h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Hubungkan akun Telegram untuk menerima notifikasi</p>
                    </div>
                </div>
            </x-slot:title>

            <div class="space-y-6">
                @if($this->isLinked())
                    <!-- Linked State -->
                    <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-6">
                        <div class="flex items-start justify-between">
                            <div class="flex items-start gap-4">
                                <div class="p-3 bg-emerald-500/20 rounded-full">
                                    <x-icon name="o-check-circle" class="w-6 h-6 text-emerald-400" />
                                </div>
                                <div>
                                    <h4 class="font-semibold text-emerald-700 dark:text-emerald-100 mb-1">Terhubung ke Telegram</h4>
                                    <p class="text-sm text-emerald-700/80 dark:text-emerald-200/80">
                                        Akun Telegram Anda sudah terhubung. Anda akan menerima notifikasi untuk permintaan part baru.
                                    </p>
                                    <div class="mt-3 text-xs text-emerald-600/60 dark:text-emerald-300/60">
                                        Chat ID: {{ auth()->user()->telegram_user_id }}
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="mt-4 pt-4 border-t border-emerald-300/50 dark:border-emerald-500/20">
                            <x-button 
                                wire:click="unlinkTelegram" 
                                icon="o-x-circle" 
                                class="btn-outline btn-sm text-red-600 dark:text-red-400 border-red-600/50 dark:border-red-400/50 hover:bg-red-500/10"
                                wire:confirm="Yakin ingin memutuskan koneksi Telegram?"
                                spinner="unlinkTelegram"
                                label="Putuskan Koneksi"
                            />
                        </div>
                    </div>
                @else
                    <!-- Not Linked State -->
                    <div class="rounded-xl border border-blue-600/50 dark:border-blue-500/30 bg-blue-100/50 dark:bg-blue-500/10 p-6">
                        <div class="flex items-start gap-4 mb-6">
                            <div class="p-3 bg-blue-200/50 dark:bg-blue-500/20 rounded-full">
                                <x-icon name="o-information-circle" class="w-6 h-6 text-blue-600 dark:text-blue-400" />
                            </div>
                            <div>
                                <h4 class="font-semibold text-blue-900 dark:text-blue-100 mb-2">Cara Menghubungkan Telegram</h4>
                                <ol class="text-sm text-blue-800/80 dark:text-blue-200/80 space-y-2 list-decimal list-inside">
                                    <li>Klik tombol "Hubungkan ke Telegram" di bawah</li>
                                    <li>Anda akan diarahkan ke bot Telegram kami</li>
                                    <li>Klik tombol "Start" atau ketik /start</li>
                                    <li>Akun Anda akan terhubung secara otomatis!</li>
                                </ol>
                            </div>
                        </div>
                        
                        <div class="flex items-center gap-3">
                            <x-button 
                                :link="$this->getTelegramLinkUrl()" 
                                external
                                icon="o-link"
                                class="btn-primary"
                                label="Hubungkan ke Telegram"
                            />
                            <span class="text-xs text-gray-600 dark:text-gray-400">Gratis & Aman</span>
                        </div>
                    </div>

                    <!-- Benefits -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="rounded-lg border border-gray-300 dark:border-white/10 bg-purple-50 dark:bg-white/5 p-4">
                            <div class="flex items-center gap-3 mb-2">
                                <div class="p-2 bg-purple-200 dark:bg-purple-500/20 rounded-lg">
                                    <x-icon name="o-bell-alert" class="w-5 h-5 text-purple-700 dark:text-purple-400" />
                                </div>
                                <h5 class="font-semibold text-sm text-gray-900 dark:text-white">Notifikasi Instan</h5>
                            </div>
                            <p class="text-xs text-gray-700 dark:text-gray-400">Terima notifikasi real-time di Telegram</p>
                        </div>

                        <div class="rounded-lg border border-gray-300 dark:border-white/10 bg-emerald-50 dark:bg-white/5 p-4">
                            <div class="flex items-center gap-3 mb-2">
                                <div class="p-2 bg-emerald-200 dark:bg-green-500/20 rounded-lg">
                                    <x-icon name="o-device-phone-mobile" class="w-5 h-5 text-emerald-700 dark:text-green-400" />
                                </div>
                                <h5 class="font-semibold text-sm text-gray-900 dark:text-white">Multi-Device</h5>
                            </div>
                            <p class="text-xs text-gray-700 dark:text-gray-400">Akses dari HP, tablet, atau desktop</p>
                        </div>

                        <div class="rounded-lg border border-gray-300 dark:border-white/10 bg-sky-50 dark:bg-white/5 p-4">
                            <div class="flex items-center gap-3 mb-2">
                                <div class="p-2 bg-sky-200 dark:bg-blue-500/20 rounded-lg">
                                    <x-icon name="o-shield-check" class="w-5 h-5 text-sky-700 dark:text-blue-400" />
                                </div>
                                <h5 class="font-semibold text-sm text-gray-900 dark:text-white">Aman & Privat</h5>
                            </div>
                            <p class="text-xs text-gray-700 dark:text-gray-400">Data Anda terlindungi dengan enkripsi</p>
                        </div>
                    </div>
                @endif

                <!-- Info Box -->
                <div class="rounded-lg border border-gray-300 dark:border-gray-700 bg-gray-100 dark:bg-gray-800/50 p-4">
                    <div class="flex items-start gap-3">
                        <x-icon name="o-question-mark-circle" class="w-5 h-5 text-gray-600 dark:text-gray-400 mt-0.5" />
                        <div class="text-sm text-gray-800 dark:text-gray-300">
                            <p class="font-semibold mb-1">Catatan:</p>
                            <ul class="text-xs text-gray-700 dark:text-gray-400 space-y-1 list-disc list-inside">
                                <li>Anda dapat menghubungkan dan memutuskan koneksi kapan saja</li>
                                <li>Notifikasi web push akan tetap berfungsi secara terpisah</li>
                                <li>Pastikan Anda menggunakan akun Telegram yang aktif</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <x-slot:actions>
                <x-button label="Kembali" icon="o-arrow-left" class="btn-outline" link="/" />
            </x-slot:actions>
        </x-card>
    </div>
</div>
