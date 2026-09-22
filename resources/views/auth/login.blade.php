@extends('layouts.auth')

@section('title', 'Login - Sistem')

@section('content')
<div class="min-h-screen w-full flex">
    <!-- Left Side - Login Form -->
    <div class="w-full lg:w-1/2 flex items-center justify-center p-8 bg-gray-50">
        <div class="w-full max-w-md">
            <!-- Mobile Logo -->
            <div class="lg:hidden flex flex-col items-center mb-8">
                <div class="rounded-2xl border border-green-200 bg-white shadow-md p-4 mb-3">
                    <img src="{{ asset('images/logo.png') }}" alt="Logo" class="h-16 object-contain" />
                </div>
                <h2 class="text-2xl font-bold text-gray-800">Sistem</h2>
                <p class="text-sm text-gray-600">Sistem Starterpack</p>
            </div>

            <!-- Login Card -->
            <div class="bg-white rounded-2xl shadow-xl p-8">
                <!-- Title -->
                <div class="mb-8">
                    <h1 class="text-3xl font-bold text-gray-800 mb-2">Selamat Datang</h1>
                    <p class="text-gray-600">Silakan login untuk melanjutkan</p>
                </div>

                <!-- Form -->
                <form action="{{ route('login') }}" method="POST" class="space-y-5">
                    @csrf

                    <!-- Email atau Username -->
                    <div>
                        <label for="login" class="block text-sm font-semibold text-gray-700 mb-2">
                            <i class="fas fa-user mr-2 text-green-600"></i>Email atau Username
                        </label>
                        <input 
                            id="login" 
                            name="login" 
                            type="text" 
                            required 
                            placeholder="Masukkan email atau username Anda" 
                            value="{{ old('login') }}"
                            class="w-full rounded-xl border-2 border-gray-200 px-4 py-3 bg-white text-gray-900 shadow-sm outline-none transition focus:ring-4 focus:ring-green-500/20 focus:border-green-600 hover:border-gray-300"
                        />
                    </div>

                    <!-- Password -->
                    <div>
                        <label for="password" class="block text-sm font-semibold text-gray-700 mb-2">
                            <i class="fas fa-lock mr-2 text-green-600"></i>Password
                        </label>
                        <div class="relative">
                            <input 
                                id="password" 
                                name="password" 
                                type="password" 
                                required 
                                placeholder="Masukkan password Anda"
                                class="w-full rounded-xl border-2 border-gray-200 px-4 py-3 pr-12 bg-white text-gray-900 shadow-sm outline-none transition focus:ring-4 focus:ring-green-500/20 focus:border-green-600 hover:border-gray-300"
                            />
                            <button 
                                type="button" 
                                id="togglePassword" 
                                aria-label="Show password" 
                                class="absolute inset-y-0 right-0 px-4 text-gray-400 hover:text-green-600 focus:outline-none transition"
                            >
                                <i class="fa-solid fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Remember & Forgot -->
                    <div class="flex items-center justify-between">
                        <label class="flex items-center">
                            <input type="checkbox" name="remember" class="rounded border-gray-300 text-green-600 shadow-sm focus:ring-green-500">
                            <span class="ml-2 text-sm text-gray-600">Ingat saya</span>
                        </label>
                        <a href="#" class="text-sm font-medium text-green-600 hover:text-green-700">Lupa password?</a>
                    </div>

                    <!-- Error Messages -->
                    @if ($errors->any())
                    <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded-lg">
                        <div class="flex items-start">
                            <i class="fas fa-exclamation-circle text-red-500 mt-0.5 mr-3"></i>
                            <div class="flex-1">
                                <p class="text-sm font-semibold text-red-800 mb-1">Terjadi kesalahan:</p>
                                <ul class="list-disc list-inside text-sm text-red-700 space-y-1">
                                    @foreach ($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    </div>
                    @endif

                    <!-- Submit Button -->
                    <button 
                        type="submit" 
                        class="w-full rounded-xl bg-gradient-to-r from-green-600 to-green-700 text-white font-bold py-3.5 shadow-lg hover:shadow-xl transition-all duration-200 transform hover:-translate-y-0.5 focus:outline-none focus:ring-4 focus:ring-green-500/50"
                    >
                        <i class="fas fa-sign-in-alt mr-2"></i>
                        Masuk
                    </button>
                </form>

                <!-- Footer -->
                <div class="mt-6 text-center text-sm text-gray-500">
                    <p>© {{ date('Y') }} Sistem. All rights reserved.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Side - Branding -->
    <div class="hidden lg:flex lg:w-1/2 bg-gradient-to-br from-green-600 via-green-700 to-green-800 relative overflow-hidden">
        <!-- Decorative Elements -->
        <div class="absolute inset-0 opacity-10">
            <div class="absolute top-0 left-0 w-96 h-96 bg-white rounded-full -translate-x-1/2 -translate-y-1/2"></div>
            <div class="absolute bottom-0 right-0 w-96 h-96 bg-white rounded-full translate-x-1/2 translate-y-1/2"></div>
        </div>

        <!-- Content -->
        <div class="relative z-10 flex flex-col items-center justify-center w-full px-12 text-white">
            <!-- Logo -->
            <div class="mb-8 bg-white rounded-3xl shadow-2xl p-8">
                <img src="{{ asset('images/logo.png') }}" alt="Logo" class="h-32 w-32 object-contain" />
            </div>

            <!-- App Name -->
            <h1 class="text-5xl font-bold mb-4 text-center">Sistem</h1>
            <p class="text-xl text-green-100 text-center max-w-md">
                Sistem Starterpack
            </p>
        </div>
    </div>
</div>

<script>
    (function(){
        const toggleBtn = document.getElementById('togglePassword');
        const pwd = document.getElementById('password');
        if (toggleBtn && pwd) {
            toggleBtn.addEventListener('click', function(){
                const isHidden = pwd.type === 'password';
                pwd.type = isHidden ? 'text' : 'password';
                this.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
                this.innerHTML = isHidden ? '<i class="fa-solid fa-eye-slash"></i>' : '<i class="fa-solid fa-eye"></i>';
            });
        }
    })();
</script>
@endsection