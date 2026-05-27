@extends('admin.layouts.app')

@section('title', 'Scraper Configuration')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="fas fa-cog me-2"></i>Scraper Configuration</h2>
    <a href="{{ route('admin.scraper-config.create') }}" class="btn btn-primary">
        <i class="fas fa-plus me-1"></i> Add URL
    </a>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif
@if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show">
        {{ session('error') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

<!-- Stats -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card text-center">
            <div class="card-body py-3">
                <h4 class="mb-0">{{ $stats['total'] }}</h4>
                <small class="text-muted">Total URLs</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center border-success">
            <div class="card-body py-3">
                <h4 class="mb-0 text-success">{{ $stats['active'] }}</h4>
                <small class="text-muted">Active</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center border-secondary">
            <div class="card-body py-3">
                <h4 class="mb-0 text-secondary">{{ $stats['inactive'] }}</h4>
                <small class="text-muted">Inactive</small>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="{{ route('admin.scraper-config.index') }}" class="row g-3">
            <div class="col-md-4">
                <select name="platform" class="form-select">
                    <option value="">All Platforms</option>
                    @foreach(['amazon','amazon_jp','flipkart','vijaysales','croma','reliancedigital','blinkit','bigbasket','zepto'] as $p)
                        <option value="{{ $p }}" {{ $filterPlatform == $p ? 'selected' : '' }}>{{ ucfirst($p) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="">All Status</option>
                    <option value="active" {{ $filterStatus == 'active' ? 'selected' : '' }}>Active</option>
                    <option value="inactive" {{ $filterStatus == 'inactive' ? 'selected' : '' }}>Inactive</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-outline-primary w-100">Filter</button>
            </div>
            @if($filterPlatform || $filterStatus)
                <div class="col-md-2">
                    <a href="{{ route('admin.scraper-config.index') }}" class="btn btn-outline-secondary w-100">Clear</a>
                </div>
            @endif
        </form>
    </div>
</div>

<!-- Table -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Platform</th>
                        <th>Category</th>
                        <th>URL</th>
                        <th>Status</th>
                        <th>Total Runs</th>
                        <th>Last Run</th>
                        <th width="130">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($configs as $config)
                    <tr>
                        <td>
                            <span class="badge bg-secondary">{{ strtoupper($config->platform) }}</span>
                        </td>
                        <td>{{ $config->category ?? '—' }}</td>
                        <td>
                            <a href="{{ $config->category_url }}" target="_blank"
                               class="text-decoration-none text-truncate d-inline-block" style="max-width:380px;"
                               title="{{ $config->category_url }}">
                                {{ $config->category_url }}
                            </a>
                        </td>
                        <td>
                            <form action="{{ route('admin.scraper-config.toggle', $config->id) }}" method="POST" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-sm {{ $config->status === 'active' ? 'btn-success' : 'btn-secondary' }}">
                                    {{ $config->status === 'active' ? 'Active' : 'Inactive' }}
                                </button>
                            </form>
                        </td>
                        <td>{{ number_format($config->total_runs ?? 0) }}</td>
                        <td>{{ $config->last_run_at ? $config->last_run_at->diffForHumans() : 'Never' }}</td>
                        <td>
                            <a href="{{ route('admin.scraper-config.edit', $config->id) }}"
                               class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-edit"></i>
                            </a>
                            <form action="{{ route('admin.scraper-config.destroy', $config->id) }}" method="POST"
                                  class="d-inline" onsubmit="return confirm('Delete this URL?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="text-center py-4 text-muted">
                            No scraper URLs configured.
                            <a href="{{ route('admin.scraper-config.create') }}">Add one now.</a>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="p-3">
            {{ $configs->appends(request()->query())->links() }}
        </div>
    </div>
</div>

<div class="alert alert-info mt-4">
    <i class="fas fa-info-circle me-1"></i>
    The scraper reads <strong>active</strong> URLs from this table. Toggle a URL to <strong>Inactive</strong> to skip it during scraping without deleting it.
</div>
@endsection
