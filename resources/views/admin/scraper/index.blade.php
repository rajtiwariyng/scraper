@extends('admin.layouts.app')

@section('title', 'Scraper Management')

@section('content')
<div class="container-fluid">
    <h1 class="h3 mb-4">Scraper Management</h1>

    {{-- Statistics --}}
    <div class="row mb-4">
        <div class="col-md-2">
            <div class="card">
                <div class="card-body">
                    <h6 class="text-muted">Total Runs</h6>
                    <h3>{{ number_format($stats['total_runs']) }}</h3>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card">
                <div class="card-body">
                    <h6 class="text-muted">Successful</h6>
                    <h3 class="text-success">{{ number_format($stats['successful_runs']) }}</h3>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card">
                <div class="card-body">
                    <h6 class="text-muted">Failed</h6>
                    <h3 class="text-danger">{{ number_format($stats['failed_runs']) }}</h3>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card">
                <div class="card-body">
                    <h6 class="text-muted">Running Now</h6>
                    <h3 class="text-warning">{{ number_format($stats['running_now']) }}</h3>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <h6 class="text-muted">Total Products Scraped</h6>
                    <h3>{{ number_format($stats['total_products_scraped']) }}</h3>
                </div>
            </div>
        </div>
    </div>

    {{-- Manual Scraper Trigger --}}
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-play"></i> Run Scraper Manually</h5>
        </div>
        <div class="card-body">
            <form id="scraperForm">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Platform</label>
                        <select name="platform" class="form-select" required>
                            <option value="">Select Platform</option>
                            @foreach($platforms as $platform)
                                <option value="{{ $platform }}">{{ ucfirst($platform) }}</option>
                            @endforeach
                            <option value="all">All Platforms</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Type</label>
                        <select name="type" class="form-select" required>
                            <option value="products">Products</option>
                            <option value="reviews">Reviews</option>
                            <option value="rankings">Rankings</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Limit</label>
                        <input type="number" name="limit" class="form-control" value="100" min="1" max="1000">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-play"></i> Run Scraper
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- Platform Status --}}
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-server"></i> Platform Status</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Platform</th>
                            <th>Status</th>
                            <th>Last Run</th>
                            <th>Next Scheduled</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($platformStats as $platform => $stat)
                            <tr>
                                <td><strong>{{ ucfirst($platform) }}</strong></td>
                                <td>
                                    @if($stat['is_running'])
                                        <span class="badge bg-warning">Running</span>
                                    @else
                                        <span class="badge bg-secondary">Idle</span>
                                    @endif
                                </td>
                                <td>
                                    @if($stat['last_run'])
                                        {{ $stat['last_run']->completed_at->format('d-m-Y H:i') }}
                                        <br>
                                        <small class="text-muted">
                                            {{ $stat['last_run']->products_scraped }} products in {{ $stat['last_run']->duration_human }}
                                        </small>
                                    @else
                                        <span class="text-muted">Never</span>
                                    @endif
                                </td>
                                <td>
                                    @if($stat['next_scheduled'])
                                        {{ $stat['next_scheduled']->next_scheduled_run->format('d-m-Y H:i') }}
                                    @else
                                        <span class="text-muted">Not scheduled</span>
                                    @endif
                                </td>
                                <td>
                                    @if($stat['is_running'])
                                        <button class="btn btn-sm btn-warning" disabled>
                                            <i class="fas fa-spinner fa-spin"></i> Running…
                                        </button>
                                    @else
                                        <button class="btn btn-sm btn-primary" onclick="runPlatformScraper('{{ $platform }}')">
                                            <i class="fas fa-play"></i> Run Now
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Recent Runs --}}
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-history"></i> Recent Runs</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Platform</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Products</th>
                            <th>Duration</th>
                            <th>Triggered By</th>
                            <th>Started At</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recentRuns as $run)
                            <tr>
                                <td><span class="badge bg-primary">{{ ucfirst($run->platform) }}</span></td>
                                <td><span class="badge bg-info">{{ ucfirst($run->type) }}</span></td>
                                <td>
                                    @if($run->status == 'completed')
                                        <span class="badge bg-success">Completed</span>
                                    @elseif($run->status == 'running')
                                        <span class="badge bg-warning">Running</span>
                                    @elseif($run->status == 'failed')
                                        <span class="badge bg-danger">Failed</span>
                                    @else
                                        <span class="badge bg-secondary">{{ ucfirst($run->status) }}</span>
                                    @endif
                                </td>
                                <td>{{ $run->products_scraped }}</td>
                                <td>{{ $run->duration_human }}</td>
                                <td>{{ $run->triggered_by ?? 'System' }}</td>
                                <td>{{ $run->started_at ? $run->started_at->format('d-m-Y H:i') : 'N/A' }}</td>
                                <td>
                                    @if($run->status === 'running')
                                        <button class="btn btn-sm btn-danger"
                                                onclick="stopScraper({{ $run->id }}, this)">
                                            <i class="fas fa-stop-circle me-1"></i>Stop
                                        </button>
                                    @else
                                        <span class="text-muted small">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center py-4">No recent runs</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
document.getElementById('scraperForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const data = Object.fromEntries(formData);
    
    fetch('{{ route("admin.scraper.run") }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify(data)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('Scraper started successfully!', 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast(data.message || 'Failed to start scraper', 'danger');
        }
    })
    .catch(error => {
        showToast('Request failed — check console for details', 'danger');
        console.error(error);
    });
});

function stopScraper(id, btn) {
    showConfirm('Stop this scraper run?', () => {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Stopping…';
        doStopScraper(id, btn);
    }, 'Stop', 'btn-danger');
}

function doStopScraper(id, btn) {
    fetch(`/admin/scraper/${id}/stop`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        }
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast('Scraper stopped', 'warning');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast(data.message || 'Could not stop scraper', 'danger');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-stop-circle me-1"></i>Stop';
        }
    })
    .catch(() => {
        showToast('Request failed', 'danger');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-stop-circle me-1"></i>Stop';
    });
}

function runPlatformScraper(platform) {
    showConfirm(`Start scraper for ${platform}?`, () => doRunPlatformScraper(platform), 'Start', 'btn-success');
}

function doRunPlatformScraper(platform) {
    fetch('{{ route("admin.scraper.run") }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify({
            platform: platform,
            type: 'products',
            limit: 100
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('Scraper started successfully!', 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast(data.message || 'Failed to start scraper', 'danger');
        }
    });
}
</script>
@endpush