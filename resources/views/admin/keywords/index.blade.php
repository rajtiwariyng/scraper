@extends('admin.layouts.app')

@section('title', 'Keywords Management')

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-6">
            <h1 class="h3 mb-0">Keywords Management</h1>
        </div>
        <div class="col-md-6 text-end">
            <a href="{{ route('admin.export', ['module' => 'keywords', 'format' => 'xlsx'] + request()->query()) }}"
               class="btn btn-success">
                <i class="fas fa-file-excel"></i> Export Excel
            </a>

            <a href="{{ route('admin.export', ['module' => 'keywords', 'format' => 'csv'] + request()->query()) }}"
               class="btn btn-secondary">
                <i class="fas fa-file-csv"></i> Export CSV
            </a>

            <a href="{{ route('admin.keywords.create') }}" class="btn btn-primary">
                <i class="fas fa-plus"></i> Add Keyword
            </a>
        </div>
    </div>
    {{-- Export History start--}}
        <div class="accordion mt-4" id="exportAccordion">
            <div class="accordion-item">
                <h2 class="accordion-header" id="headingExports">
                    <button class="accordion-button collapsed" type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#collapseExports"
                            aria-expanded="false"
                            aria-controls="collapseExports">
                        <strong>Recent Exports</strong>
                        <span class="ms-2 text-muted">
                            ({{ $exports->count() }})
                        </span>
                    </button>
                </h2>

                <div id="collapseExports"
                     class="accordion-collapse collapse"
                     aria-labelledby="headingExports"
                     data-bs-parent="#exportAccordion">

                    <div class="accordion-body p-0">
                        <table class="table table-bordered mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>File</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                    <th>Created</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($exports as $export)
                                    <tr>
                                        <td>{{ $export->file_name }}</td>

                                        <td>
                                            @if($export->status === 'completed')
                                                <span class="badge bg-success">Completed</span>
                                            @elseif($export->status === 'failed')
                                                <span class="badge bg-danger">Failed</span>
                                            @else
                                                <span class="badge bg-warning">Processing</span>
                                            @endif
                                        </td>

                                        <td>
                                            @if($export->status === 'completed')
                                                <a href="{{ asset('storage/'.$export->file_path) }}"
                                                   class="btn btn-sm btn-primary">
                                                   Download
                                                </a>
                                            @elseif($export->status === 'failed')
                                                <span class="text-danger">Error</span>
                                            @else
                                                <span class="text-muted">Please wait</span>
                                            @endif
                                        </td>

                                        <td>{{ $export->created_at->diffForHumans() }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center text-muted">
                                            No exports yet
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                </div>
            </div>
        </div>
    {{-- Export History end--}}
    {{-- Statistics --}}
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <h6 class="text-muted">Total Keywords</h6>
                    <h3>{{ number_format($stats['total_keywords']) }}</h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <h6 class="text-muted">Active</h6>
                    <h3>{{ number_format($stats['active_keywords']) }}</h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <h6 class="text-muted">Inactive</h6>
                    <h3>{{ number_format($stats['inactive_keywords']) }}</h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <h6 class="text-muted">Total Rankings</h6>
                    <h3>{{ number_format($stats['total_rankings']) }}</h3>
                </div>
            </div>
        </div>
    </div>

    {{-- Filters --}}
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.keywords.index') }}">
                <div class="row g-3">
                    <div class="col-md-3">
                        <select name="platform" class="form-select">
                            <option value="">All Platforms</option>
                            @foreach($platforms as $platform)
                                <option value="{{ $platform }}" {{ request('platform') == $platform ? 'selected' : '' }}>
                                    {{ ucfirst($platform) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="status" class="form-select">
                            <option value="">All Status</option>
                            <option value="active" {{ request('status') == 'active' ? 'selected' : '' }}>Active</option>
                            <option value="inactive" {{ request('status') == 'inactive' ? 'selected' : '' }}>Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <input type="text" name="search" class="form-control" placeholder="Search keywords..." value="{{ request('search') }}">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search"></i> Search
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- Keywords Table --}}
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Platform</th>
                            <th>Keyword</th>
                            <th>Status</th>
                            <th>Rankings Count</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($keywords as $keyword)
                            <tr>
                                <td>{{ $keyword->id }}</td>
                                <td><span class="badge bg-primary">{{ ucfirst($keyword->platform) }}</span></td>
                                <td><strong>{{ $keyword->keyword }}</strong></td>
                                <td>
                                    @if($keyword->status)
                                        <span class="badge bg-success">Active</span>
                                    @else
                                        <span class="badge bg-secondary">Inactive</span>
                                    @endif
                                </td>
                                <td>{{ $keyword->rankings_count }}</td>
                                <td>{{ $keyword->created_at->format('d-m-Y') }}</td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="{{ route('admin.keywords.edit', $keyword) }}" class="btn btn-warning">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="{{ route('admin.keywords.rankings', $keyword) }}" class="btn btn-info">
                                            <i class="fas fa-chart-line"></i>
                                        </a>
                                        <form action="{{ route('admin.keywords.destroy', $keyword) }}" method="POST" class="d-inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-danger" onclick="return confirm('Are you sure?')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-4">No keywords found</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Pagination --}}
            <div class="mt-3">
                {{ $keywords->links() }}
            </div>
        </div>
    </div>
</div>
@endsection