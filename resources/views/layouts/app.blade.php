<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name'))</title>
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/bootstrap.min.css') }}">
    <link rel="stylesheet" href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css">
    <link rel="stylesheet" href="{{ asset('vendor/select2/select2.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/datatables/dataTables.bootstrap5.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/datatables/buttons.bootstrap5.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/sweetalert2/sweetalert2.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
    @stack('styles')
</head>
<body class="@yield('body-class', 'app-page')">
    @auth
        @hasSection('sidebar')
            <div class="app-shell">
                <aside class="app-sidebar">
                    <div class="d-flex align-items-center justify-content-between gap-2">
                        <a class="app-brand text-decoration-none text-dark" href="{{ route('entry') }}">New ERP</a>
                        <button id="app-sidebar-toggle" class="btn btn-sm btn-outline-secondary" type="button" aria-label="ย่อเมนู" aria-expanded="true" title="ย่อเมนู"><i class="bx bx-menu" aria-hidden="true"></i></button>
                    </div>
                    <nav class="app-sidebar-nav mt-4" aria-label="เมนูหลัก">
                        @yield('sidebar')
                    </nav>
                    <div class="app-sidebar-footer mt-auto">
                        <a class="app-sidebar-profile text-decoration-none" href="{{ route('profile.edit') }}" title="โปรไฟล์ของฉัน">
                            <span class="app-sidebar-profile-icon"><i class="bx bx-user" aria-hidden="true"></i></span>
                            <span class="app-sidebar-profile-name">{{ auth()->user()->name }}</span>
                        </a>
                        <form id="logout-form" class="app-sidebar-logout-form" action="{{ route('logout') }}" method="post">
                            @csrf
                            <button class="btn btn-outline-dark app-sidebar-logout" type="submit" data-busy-text="กำลังออก..." title="ออกจากระบบ">
                                <i class="bx bx-log-out" aria-hidden="true"></i><span>ออกจากระบบ</span>
                            </button>
                        </form>
                    </div>
                </aside>
                <button id="app-sidebar-backdrop" class="app-sidebar-backdrop" type="button" aria-label="ปิดเมนู"></button>
                <div class="app-workspace">
                    @php($selectedProgram = request()->attributes->get('selectedProgram'))
                    @php($selectedBranch = request()->attributes->get('selectedBranch'))
                    @php($selectedWarehouse = request()->attributes->get('selectedWarehouse'))
                    <header class="app-topbar border-bottom">
                        <div class="container-fluid px-3 px-lg-4 py-3 d-flex flex-wrap align-items-center justify-content-between gap-3">
                            <div class="d-flex align-items-center gap-2">
                                <button id="app-sidebar-mobile-toggle" class="btn btn-sm btn-outline-secondary d-lg-none" type="button" aria-label="เปิดเมนู" aria-expanded="false"><i class="bx bx-menu" aria-hidden="true"></i></button>
                                <div class="small">
                                    <span class="text-secondary">โปรแกรม</span>
                                    <span class="fw-semibold ms-1">{{ $selectedProgram?->name }}</span>
                                </div>
                            </div>
                            <a class="btn btn-sm btn-outline-dark" href="{{ route('branches.index') }}">
                                <i class="bx bx-map me-1" aria-hidden="true"></i>
                                @if ($selectedBranch)
                                    {{ $selectedBranch->name }}
                                    <span class="text-secondary ms-1">เปลี่ยน</span>
                                @else
                                    เลือกสาขา
                                @endif
                            </a>
                        </div>
                    </header>
                    @if (session('error') || session('success'))
                        <div class="px-3 px-lg-4 pt-3">
                            <div class="alert {{ session('error') ? 'alert-danger' : 'alert-success' }} mb-0" role="status">
                                {{ session('error') ?: session('success') }}
                            </div>
                        </div>
                    @endif
                    <main class="app-main">
                        @yield('content')
                    </main>
                </div>
            </div>
        @else
            <header class="app-header border-bottom bg-white">
                <div class="container-fluid d-flex align-items-center justify-content-between py-3">
                    <a class="app-brand text-decoration-none text-dark" href="{{ route('entry') }}">New ERP</a>
                    <div class="d-flex align-items-center gap-3">
                        <a class="text-secondary small text-decoration-none" href="{{ route('profile.edit') }}"><i class="bx bx-user me-1" aria-hidden="true"></i>{{ auth()->user()->name }}</a>
                        <form id="logout-form" action="{{ route('logout') }}" method="post">
                            @csrf
                            <button class="btn btn-outline-dark btn-sm" type="submit" data-busy-text="กำลังออก...">
                                <i class="bx bx-log-out me-1" aria-hidden="true"></i>ออกจากระบบ
                            </button>
                        </form>
                    </div>
                </div>
            </header>
            <main>
                @yield('content')
            </main>
            @if (session('error') || session('success'))
                <div class="container pt-3">
                    <div class="alert {{ session('error') ? 'alert-danger' : 'alert-success' }} mb-0" role="status">
                        {{ session('error') ?: session('success') }}
                    </div>
                </div>
            @endif
        @endif
    @else
        <main>
            @yield('content')
        </main>
    @endauth

    <script src="{{ asset('vendor/jquery/jquery-3.7.1.min.js') }}"></script>
    <script src="{{ asset('vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ asset('vendor/select2/select2.min.js') }}"></script>
    <script src="{{ asset('vendor/datatables/dataTables.min.js') }}"></script>
    <script src="{{ asset('vendor/datatables/dataTables.bootstrap5.min.js') }}"></script>
    <script src="{{ asset('vendor/datatables/dataTables.buttons.min.js') }}"></script>
    <script src="{{ asset('vendor/datatables/buttons.bootstrap5.min.js') }}"></script>
    <script src="{{ asset('vendor/datatables/jszip.min.js') }}"></script>
    <script src="{{ asset('vendor/datatables/buttons.html5.min.js') }}"></script>
    <script src="{{ asset('vendor/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('vendor/chartjs/chart.umd.min.js') }}"></script>
    <script src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}"></script>
    <script src="{{ asset('js/datatables.js') }}?v={{ filemtime(public_path('js/datatables.js')) }}"></script>
    @auth
        @include('partials.journal-preview-modal')
    @endauth
    @auth
        <script>
            $(function () {
                window.erpAjaxForm({
                    form: '#logout-form',
                    redirect: true,
                    alert: false
                });
            });
        </script>
    @endauth
    <script>
        (function () {
            var shell = document.querySelector('.app-shell');
            var toggle = document.querySelector('#app-sidebar-toggle');
            var mobileToggle = document.querySelector('#app-sidebar-mobile-toggle');
            var backdrop = document.querySelector('#app-sidebar-backdrop');
            if (!shell || !toggle) return;
            var key = 'erp.sidebar.collapsed';
            var desktop = function () { return window.matchMedia('(min-width: 992px)').matches; };
            var collapseButtons = document.querySelectorAll('.app-sidebar [data-bs-toggle="collapse"]');
            var setMobileOpen = function (open) {
                shell.classList.toggle('app-sidebar-mobile-open', open);
                if (mobileToggle) mobileToggle.setAttribute('aria-expanded', String(open));
            };
            var closeFlyouts = function () {
                document.querySelectorAll('.app-sidebar .collapse.app-sidebar-flyout-open').forEach(function (menu) {
                    menu.classList.remove('app-sidebar-flyout-open', 'show', 'collapsing');
                    menu.style.top = '';
                    menu.style.left = '';
                });
            };
            var positionFlyout = function (menu) {
                var trigger = document.querySelector('[data-bs-target="#' + menu.id + '"]');
                if (!trigger) return;
                var rect = trigger.getBoundingClientRect();
                menu.style.top = Math.max(8, rect.top) + 'px';
                menu.style.left = (rect.right + 8) + 'px';
            };
            var setCollapsed = function (collapsed) {
                shell.classList.toggle('app-sidebar-collapsed', collapsed);
                toggle.setAttribute('aria-expanded', String(!collapsed));
                toggle.setAttribute('aria-label', collapsed ? 'ขยายเมนู' : 'ย่อเมนู');
                toggle.setAttribute('title', collapsed ? 'ขยายเมนู' : 'ย่อเมนู');
                toggle.querySelector('i').className = collapsed ? 'bx bx-menu-alt-right' : 'bx bx-menu';
                if (desktop()) closeFlyouts();
            };
            setCollapsed(localStorage.getItem(key) === '1');
            toggle.addEventListener('click', function () {
                if (!desktop()) {
                    setMobileOpen(false);
                    return;
                }
                var collapsed = !shell.classList.contains('app-sidebar-collapsed');
                localStorage.setItem(key, collapsed ? '1' : '0');
                setCollapsed(collapsed);
            });
            collapseButtons.forEach(function (button) {
                button.addEventListener('click', function (event) {
                    if (!desktop() || !shell.classList.contains('app-sidebar-collapsed')) return;
                    var menu = document.querySelector(button.getAttribute('data-bs-target'));
                    if (!menu) return;
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    localStorage.setItem(key, '0');
                    setCollapsed(false);
                    bootstrap.Collapse.getOrCreateInstance(menu, { toggle: false }).show();
                });
            });
            if (mobileToggle) mobileToggle.addEventListener('click', function () { setMobileOpen(!shell.classList.contains('app-sidebar-mobile-open')); });
            if (backdrop) backdrop.addEventListener('click', function () { setMobileOpen(false); });
            var sidebarNav = document.querySelector('.app-sidebar-nav');
            if (sidebarNav) sidebarNav.addEventListener('click', function (event) {
                if (!desktop() && event.target.closest('a')) setMobileOpen(false);
            });
            window.addEventListener('resize', function () {
                if (desktop()) setMobileOpen(false);
                document.querySelectorAll('.app-sidebar .collapse.app-sidebar-flyout-open').forEach(positionFlyout);
            });
        }());
    </script>
    @stack('scripts')
</body>
</html>
