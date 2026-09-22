<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Sistem')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="icon" type="image/x-icon" href="{{ asset('images/logo.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    {{-- Google Fonts: non-blocking, fallback system font sambil menunggu --}}
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap" media="print" onload="this.media='all'">
    <noscript>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap">
    </noscript>
    {{-- Icon fonts: non-blocking agar tidak menahan render halaman --}}
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" media="print" onload="this.media='all'">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" media="print" onload="this.media='all'">
    <noscript>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    </noscript>
    
    {{-- Sidebar CSS --}}
    <link rel="stylesheet" href="{{ asset('css/sidebar.css') }}">
    
    {{-- Sidebar JS - MUST load before Alpine.js to prevent flicker --}}
    <script src="{{ asset('js/sidebar.js') }}"></script>
</head>
<body class="bg-gray-100 overflow-x-hidden h-screen">
    {{-- 
        Alpine Component with separated desktop/mobile logic
        - Desktop: toggles width via CSS class on html element
        - Mobile: toggles visibility via transform
    --}}
    <div x-data="sidebarComponent()" x-init="init()" class="h-full flex overflow-x-hidden">
        
        <!-- Sidebar: No x-cloak, use CSS to control visibility -->
        <div class="sidebar fixed inset-y-0 left-0 z-50 bg-white flex flex-col h-screen lg:static lg:inset-0"
             :class="{ 'mobile-open': mobileOpen }">
            
            <!-- Logo/Brand -->
            <div class="sidebar-header flex items-center justify-between px-6 pt-6 pb-3 border-b border-gray-100 flex-shrink-0">
                <div class="flex items-center gap-2 overflow-hidden">
                    <img src="{{ asset('images/logo.png') }}" alt="Logo" class="sidebar-brand-logo h-[1.875rem] w-auto object-contain flex-shrink-0">
                    {{-- Use CSS-driven visibility instead of x-show --}}
                    <span class="sidebar-text sidebar-brand-text truncate">Sistem</span>
                </div>
            </div>

            <!-- Sidebar Navigation -->
            <nav class="flex-1 overflow-y-auto sidebar-scroll px-6 pt-6">
                @php
                    $menuGroups = config('menu');
                    $canAccess = function ($item) {
                        if (isset($item['permission'])) {
                            return auth()->user()->hasPermission($item['permission']);
                        }
                        if (isset($item['permissions'])) {
                            foreach ($item['permissions'] as $p) {
                                if (auth()->user()->hasPermission($p)) {
                                    return true;
                                }
                            }
                        }
                        return false;
                    };
                @endphp

                @foreach($menuGroups as $group)
                    @php $visible = array_values(array_filter($group['menus'], $canAccess)); @endphp
                    @if(count($visible) > 0)
                        <p class="sidebar-title">{{ $group['title'] }}</p>

                        @foreach($visible as $item)
                            @if(isset($item['children']))
                                @php
                                    $childPatterns = array_column($item['children'], 'route_pattern');
                                    $isOpen = request()->routeIs($childPatterns);
                                @endphp
                                <div class="mb-1" x-data="{ open: {{ $isOpen ? 'true' : 'false' }} }">
                                    <button @click="open = !open" 
                                            class="sidebar-btn w-full justify-between">
                                        <div class="flex items-center gap-3">
                                            <i class="bi {{ $item['icon'] }} sidebar-icon"></i>
                                            <span class="sidebar-text">{{ $item['label'] }}</span>
                                        </div>
                                        <i class="bi bi-chevron-down sidebar-text sidebar-chevron" :class="{ 'rotate-180': open }"></i>
                                    </button>
                                    
                                    <div x-show="open" 
                                         x-transition:enter="transition ease-out duration-200"
                                         x-transition:enter-start="opacity-0 -translate-y-2"
                                         x-transition:enter-end="opacity-100 translate-y-0"
                                         x-transition:leave="transition ease-in duration-150"
                                         x-transition:leave-start="opacity-100 translate-y-0"
                                         x-transition:leave-end="opacity-0 -translate-y-2"
                                         class="sidebar-submenu mt-1 space-y-0.5">
                                        @foreach($item['children'] as $child)
                                            @if($canAccess($child))
                                            <a href="{{ route($child['route']) }}" 
                                               class="{{ request()->routeIs($child['route_pattern']) ? 'active' : '' }}"
                                               title="{{ $child['label'] }}">
                                                <i class="bi {{ $child['icon'] }} sidebar-icon"></i>
                                                <span class="sidebar-text">{{ $child['label'] }}</span>
                                            </a>
                                            @endif
                                        @endforeach
                                    </div>
                                </div>
                            @else
                                <div class="mb-1">
                                    <a href="{{ route($item['route']) }}" 
                                       class="sidebar-link {{ request()->routeIs($item['route_pattern'] ?? $item['route']) ? 'active' : '' }}" 
                                       title="{{ $item['label'] }}">
                                        <i class="bi {{ $item['icon'] }} sidebar-icon"></i>
                                        <span class="sidebar-text">{{ $item['label'] }}</span>
                                    </a>
                                </div>
                            @endif
                        @endforeach
                    @endif
                @endforeach
            </nav>
        </div>

        <!-- Main Content Area -->
        <div class="flex-1 flex flex-col lg:ml-0 overflow-x-hidden max-w-full h-full">
            <!-- Top Navigation Bar -->
            <header class="bg-white shadow-sm border-b border-gray-200">
                <div class="flex items-center justify-between h-14 px-4">
                    <div class="flex items-center space-x-4">
                        <!-- Toggle Button with separated logic -->
                        <button @click="toggle()" 
                                class="p-2 rounded-lg text-gray-600 hover:text-gray-900 hover:bg-gray-100 transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-opacity-50" 
                                :title="getToggleTitle()">
                            <!-- Mobile icon -->
                            <i class="fas text-lg lg:hidden" :class="mobileOpen ? 'fa-xmark' : 'fa-bars'"></i>
                            <!-- Desktop icon -->
                            <i class="fas text-lg hidden lg:inline" :class="isCollapsed ? 'fa-angles-right' : 'fa-angles-left'"></i>
                        </button>
                        
                        <div class="hidden sm:block">
                            <h2 class="text-xl font-extrabold text-sp-navy">@yield('title', 'Dashboard')</h2>
                            <p class="text-sm text-gray-500">Sistem</p>
                        </div>
                        
                        <!-- Mobile Title -->
                        <div class="sm:hidden">
                            <h2 class="text-lg font-extrabold text-sp-navy">@yield('title', 'Dashboard')</h2>
                        </div>
                    </div>
                    
                    <div class="flex items-center space-x-3">
                        <!-- User Dropdown -->
                        <div class="relative" x-data="{ open: false }" @click.away="open = false">
                            <button @click="open = !open" class="flex items-center space-x-2 px-3 py-2 rounded-lg hover:bg-gray-50 transition-colors focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-opacity-50">
                                <div class="w-8 h-8 bg-sp-primary rounded-full flex items-center justify-center">
                                    <i class="fas fa-user text-sm text-white"></i>
                                </div>
                                <div class="text-left hidden sm:block">
                                    <div class="text-sm font-medium text-gray-700">{{ auth()->user()->name }}</div>
                                </div>
                                <i class="fas fa-chevron-down text-xs text-gray-400 transition-transform duration-200" :class="{ 'rotate-180': open }"></i>
                            </button>
                            
                            <!-- Dropdown Menu -->
                            <div x-show="open" 
                                 x-transition:enter="transition ease-out duration-100"
                                 x-transition:enter-start="opacity-0 scale-95"
                                 x-transition:enter-end="opacity-100 scale-100"
                                 x-transition:leave="transition ease-in duration-75"
                                 x-transition:leave-start="opacity-100 scale-100"
                                 x-transition:leave-end="opacity-0 scale-95"
                                 class="absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-lg border border-gray-200 py-1 z-50"
                                 x-cloak>
                                
                                <div class="py-1">
                                    @if(auth()->user()->hasPermission('view_dashboard'))
                                    <a href="{{ route('dashboard') }}" class="flex items-center px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 transition-colors">
                                        <i class="fas fa-home w-4 h-4 mr-2 text-gray-400"></i>
                                        Dashboard
                                    </a>
                                    @endif

                                    <a href="{{ route('profile.index') }}" class="flex items-center px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 transition-colors">
                                        <i class="fas fa-user-circle w-4 h-4 mr-2 text-gray-400"></i>
                                        Profil
                                    </a>
                                    
                                    <div class="border-t border-gray-100 my-1"></div>
                                    
                                    <form method="POST" action="{{ route('logout') }}" class="block">
                                        @csrf
                                        <button type="submit" 
                                                class="flex items-center w-full px-3 py-2 text-sm text-red-600 hover:bg-red-50 transition-colors"
                                                onclick="return confirm('Apakah Anda yakin ingin keluar?')">
                                            <i class="fas fa-sign-out-alt w-4 h-4 mr-2"></i>
                                            Logout
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Page Content -->
            <main class="flex-1 p-4 sm:p-6 lg:p-8 bg-gray-50 overflow-y-auto overflow-x-hidden max-w-full">
                @yield('content')
            </main>
        </div>

        <!-- Mobile Overlay -->
        <div x-show="mobileOpen" 
             @click="mobileOpen = false" 
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             class="fixed inset-0 z-40 bg-black bg-opacity-50 lg:hidden"
             x-cloak>
        </div>
    </div>

    {{-- Load Alpine.js AFTER sidebar component is defined --}}
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    
    <script src="{{ asset('js/chart.umd.min.js') }}"></script>
    <script src="{{ asset('js/toast.js') }}"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function(){
            @if(session('success'))
                window.Toast && Toast.success(@json(session('success')));
            @endif
            @if(session('error'))
                window.Toast && Toast.error(@json(session('error')));
            @endif
            @if($errors->any())
                @foreach($errors->all() as $err)
                    window.Toast && Toast.error(@json($err), { duration: 6000 });
                @endforeach
            @endif
        });
    </script>
    @stack('scripts')
</body>
</html>
