<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' - '.config('app.name') : config('app.name') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen font-sans antialiased bg-base-100 dark:bg-gradient-to-br dark:from-slate-950 dark:via-slate-900 dark:to-indigo-950">

    {{-- NAVBAR mobile only --}}
    <x-nav sticky class="lg:hidden">
        <x-slot:brand>
            <x-app-brand />
        </x-slot:brand>
        <x-slot:actions>
            <label for="main-drawer" class="lg:hidden me-3">
                <x-icon name="o-bars-3" class="cursor-pointer" />
            </label>
        </x-slot:actions>
    </x-nav>

    {{-- MAIN --}}
    <x-main>
        {{-- SIDEBAR --}}
        <x-slot:sidebar drawer="main-drawer" collapsible class="bg-base-100 lg:bg-inherit">

            {{-- BRAND --}}
            <x-app-brand class="px-5 mt-3" />

            {{-- MENU --}}
            <x-menu activate-by-route>

                {{-- User --}}
                @if($user = auth()->user())
                    <x-menu-separator />

                    <x-list-item :item="$user" value="name" sub-value="email" no-separator no-hover class="-mx-2 !-my-2 rounded">
                        <x-slot:actions>
                            <x-button icon="o-power" class="btn-circle btn-ghost btn-xs" tooltip-left="logoff" no-wire-navigate link="/logout" />
                        </x-slot:actions>
                    </x-list-item>

                    <x-menu-separator />
                @endif

                <x-menu-item title="Dashboard" icon="o-chart-bar-square" link="/" />
                <x-menu-item title="Daftar Permintaan Part" icon="o-archive-box" link="/request-list" />
                @canany(['manage', 'receiver'])
                <x-menu-item title="Permintaan Part" icon="o-clipboard-document-list" link="/requests" />
                <x-menu-item title="Penerimaan Part" icon="o-inbox-arrow-down" link="/receivings" />
                @endcanany
                <x-menu-item title="Stock Movements" icon="o-arrows-right-left" link="/stock-movements" />
                <x-menu-item title="Telegram Link" icon="o-cube-transparent" link="/telegram-settings" />

                @role('admin')
                <x-menu-sub title="Manage" icon="o-cog-6-tooth">
                    <x-menu-item title="Users" icon="o-wifi" link="/users" />
                    <x-menu-item title="Parts" icon="o-archive-box" link="/parts" />
                </x-menu-sub>
                @endrole

            </x-menu>
        </x-slot:sidebar>

        {{-- The `$slot` goes here --}}
        <x-slot:content>
            {{ $slot }}
        </x-slot:content>
    </x-main>

    {{--  TOAST area --}}
    <x-toast />
    @stack('scripts')
</body>
</html>
