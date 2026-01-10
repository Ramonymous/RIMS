<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Rule;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
 
new
#[Layout('components.layouts.auth')]       //  <-- Here is the `empty` layout
#[Title('Login')]
class extends Component {
 
    #[Rule('required|email')]
    public string $email = '';
 
    #[Rule('required')]
    public string $password = '';
 
    public function mount()
    {
        // It is logged in
        if (auth()->user()) {
            return redirect('/');
        }
    }
 
    public function login()
    {
        $credentials = $this->validate();
 
        if (auth()->attempt($credentials)) {
            request()->session()->regenerate();
 
            return redirect()->intended('/');
        }
 
        $this->addError('email', 'The provided credentials do not match our records.');
    }
}?>

<div class="flex justify-center items-center min-h-screen p-4">
    <div class="w-full max-w-md p-4">
        <div class="mb-10 text-center">
            <x-app-brand />
        </div>
    
        <x-card class="bg-base-50 dark:bg-base-800/80 border border-base-200 dark:border-base-700 rounded-2xl shadow-xl">
            <x-form wire:submit="login">
                <x-input placeholder="E-mail" wire:model="email" icon="o-envelope" class="bg-base-100 dark:bg-base-700 border-base-300 dark:border-base-600 h-12" />
                <x-input placeholder="Password" wire:model="password" type="password" icon="o-key" class="bg-base-100 dark:bg-base-700 border-base-300 dark:border-base-600 h-12 mt-4" />
        
                <x-slot:actions>
                    <div class="flex justify-between items-center w-full">
                        <x-button label="Create an account" class="btn-ghost btn-sm h-10" link="/register" />
                        <x-button label="Login" type="submit" icon="o-paper-airplane" class="btn-primary h-12 min-h-12" spinner="login" />
                    </div>
                </x-slot:actions>
            </x-form>
        </x-card>
    </div>
</div>