<nav class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h4 class="mb-0">Aethyrtech</h4>
        <small class="text-light opacity-75">Data Monitoring Dashboard</small>
    </div>

    <ul class="nav flex-column sidebar-nav">

        {{-- Main --}}
        <li class="nav-item">
            <a class="nav-link {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}"
               href="{{ route('admin.dashboard') }}">
                <i class="fas fa-tachometer-alt me-2"></i>Dashboard
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ request()->routeIs('admin.products.*') ? 'active' : '' }}"
               href="{{ route('admin.products.index') }}">
                <i class="fas fa-boxes me-2"></i>Products
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ request()->routeIs('admin.reviews.*') ? 'active' : '' }}"
               href="{{ route('admin.reviews.index') }}">
                <i class="fas fa-star me-2"></i>Reviews
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ request()->routeIs('admin.keywords.*') ? 'active' : '' }}"
               href="{{ route('admin.keywords.index') }}">
                <i class="fas fa-tags me-2"></i>Keywords
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ request()->routeIs('admin.comparison.*') ? 'active' : '' }}"
               href="{{ route('admin.comparison.index') }}">
                <i class="fas fa-balance-scale me-2"></i>Comparison
            </a>
        </li>

        {{-- Scraper --}}
        <li class="nav-item mt-2">
            <small class="px-3 text-light opacity-50 text-uppercase" style="font-size:.68rem;letter-spacing:.08em;">Scraper</small>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ request()->routeIs('admin.scraper.*') ? 'active' : '' }}"
               href="{{ route('admin.scraper.index') }}">
                <i class="fas fa-robot me-2"></i>Scraper Runs
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ request()->routeIs('admin.scraper-config.*') ? 'active' : '' }}"
               href="{{ route('admin.scraper-config.index') }}">
                <i class="fas fa-cog me-2"></i>Scraper Config
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ request()->routeIs('admin.scraping-urls.*') ? 'active' : '' }}"
               href="{{ route('admin.scraping-urls.index') }}">
                <i class="fas fa-link me-2"></i>Scrape Queue
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ request()->routeIs('admin.logs') ? 'active' : '' }}"
               href="{{ route('admin.logs') }}">
                <i class="fas fa-scroll me-2"></i>Scraping Logs
            </a>
        </li>

        {{-- Platforms (collapsible) --}}
        @php
            $onPlatform = request()->routeIs('admin.platform');
        @endphp
        <li class="nav-item mt-2">
            <small class="px-3 text-light opacity-50 text-uppercase" style="font-size:.68rem;letter-spacing:.08em;">Platforms</small>
        </li>
        <li class="nav-item">
            <a class="nav-link d-flex align-items-center {{ $onPlatform ? '' : 'collapsed' }}"
               data-bs-toggle="collapse" href="#platformsCollapse"
               aria-expanded="{{ $onPlatform ? 'true' : 'false' }}">
                <i class="fas fa-store me-2"></i>
                <span>All Platforms</span>
                <i class="fas fa-chevron-down ms-auto small"></i>
            </a>
            <div class="collapse {{ $onPlatform ? 'show' : '' }}" id="platformsCollapse">
                <ul class="nav flex-column ps-3 pt-1 pb-1">

                    @php
                    $platformIcons = [
                        'amazon'          => ['icon' => 'fab fa-amazon',         'color' => '#FF9900'],
                        'amazon_jp'       => ['icon' => 'fab fa-amazon',         'color' => '#FF9900'],
                        'flipkart'        => ['icon' => 'fas fa-shopping-cart',  'color' => '#2874F0'],
                        'vijaysales'      => ['icon' => 'fas fa-store',          'color' => '#E31837'],
                        'croma'           => ['icon' => 'fas fa-microchip',      'color' => '#6CC04A'],
                        'reliancedigital' => ['icon' => 'fas fa-bolt',           'color' => '#0071BC'],
                        'blinkit'         => ['icon' => 'fas fa-shipping-fast',  'color' => '#F8C300'],
                        'bigbasket'       => ['icon' => 'fas fa-shopping-basket','color' => '#84C225'],
                        'zepto'           => ['icon' => 'fas fa-clock',          'color' => '#9B2DE5'],
                    ];
                    @endphp

                    @foreach(config('scraper.platforms', []) as $key => $platform)
                    @php $pi = $platformIcons[$key] ?? ['icon' => 'fas fa-store', 'color' => '#6c757d']; @endphp
                    <li class="nav-item">
                        <a class="nav-link py-1 {{ request()->route('platform') === $key ? 'active' : '' }}"
                           href="{{ route('admin.platform', $key) }}">
                            <i class="{{ $pi['icon'] }} me-2" style="color:{{ $pi['color'] }};width:14px;text-align:center;"></i>
                            {{ $platform['name'] }}
                        </a>
                    </li>
                    @endforeach

                </ul>
            </div>
        </li>

        {{-- Admin --}}
        @role('super_admin|admin')
        <li class="nav-item mt-2">
            <small class="px-3 text-light opacity-50 text-uppercase" style="font-size:.68rem;letter-spacing:.08em;">Admin</small>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ request()->routeIs('admin.users.*') ? 'active' : '' }}"
               href="{{ route('admin.users.index') }}">
                <i class="fas fa-users-cog me-2"></i>Manage Users
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ request()->routeIs('admin.roles.*') ? 'active' : '' }}"
               href="{{ route('admin.roles.index') }}">
                <i class="fas fa-user-shield me-2"></i>Manage Roles
            </a>
        </li>
        @endrole

    </ul>
</nav>
