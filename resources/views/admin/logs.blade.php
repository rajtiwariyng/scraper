@extends('admin.layouts.app')

@section('title', 'Scraping Logs')

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center">
            <h2 class="mb-0">Scraping Logs</h2>
            <button class="btn btn-outline-primary" onclick="location.reload()">
                <i class="fas fa-sync me-1"></i>Refresh
            </button>
        </div>
    </div>
</div>

{{-- Filters --}}
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="{{ route('admin.logs') }}" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Platform</label>
                <select name="platform" class="form-select">
                    <option value="">All Platforms</option>
                    @foreach($platforms as $p)
                        <option value="{{ $p }}" {{ ($filters['platform'] ?? '') === $p ? 'selected' : '' }}>
                            {{ ucfirst($p) }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="completed" {{ ($filters['status'] ?? '') === 'completed' ? 'selected' : '' }}>Completed</option>
                    <option value="failed"    {{ ($filters['status'] ?? '') === 'failed'    ? 'selected' : '' }}>Failed</option>
                    <option value="started"   {{ ($filters['status'] ?? '') === 'started'   ? 'selected' : '' }}>Started</option>
                    <option value="partial"   {{ ($filters['status'] ?? '') === 'partial'   ? 'selected' : '' }}>Partial</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Time Period</label>
                <select name="days" class="form-select">
                    <option value="">All Time</option>
                    <option value="1"  {{ ($filters['days'] ?? '') === '1'  ? 'selected' : '' }}>Last 24 hours</option>
                    <option value="7"  {{ ($filters['days'] ?? '') === '7'  ? 'selected' : '' }}>Last 7 days</option>
                    <option value="30" {{ ($filters['days'] ?? '') === '30' ? 'selected' : '' }}>Last 30 days</option>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-filter me-1"></i>Apply Filters
                </button>
            </div>
        </form>
    </div>
</div>

{{-- Table --}}
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-list-alt me-2"></i>Scraping Sessions ({{ number_format($logs->total()) }} total)</h5>
        @if(!empty(array_filter($filters)))
            <a href="{{ route('admin.logs') }}" class="btn btn-sm btn-outline-secondary">
                <i class="fas fa-times me-1"></i>Clear Filters
            </a>
        @endif
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date & Time</th>
                        <th>Platform</th>
                        <th>Status</th>
                        <th>Duration</th>
                        <th>Products</th>
                        <th>Changes</th>
                        <th>Errors</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                    <tr>
                        <td>
                            <strong>{{ $log->created_at->format('d M Y') }}</strong><br>
                            <small class="text-muted">{{ $log->created_at->format('H:i:s') }}</small>
                        </td>
                        <td>
                            <span class="badge bg-primary">{{ ucfirst($log->platform) }}</span>
                        </td>
                        <td>
                            @switch($log->status)
                                @case('completed')
                                    <span class="badge bg-success"><i class="fas fa-check me-1"></i>Completed</span>@break
                                @case('failed')
                                    <span class="badge bg-danger"><i class="fas fa-times me-1"></i>Failed</span>@break
                                @case('started')
                                    <span class="badge bg-info"><i class="fas fa-play me-1"></i>Started</span>@break
                                @case('partial')
                                    <span class="badge bg-warning text-dark"><i class="fas fa-exclamation me-1"></i>Partial</span>@break
                                @default
                                    <span class="badge bg-secondary">{{ ucfirst($log->status) }}</span>
                            @endswitch
                        </td>
                        <td>
                            @if($log->duration_seconds)
                                {{ gmdate($log->duration_seconds >= 3600 ? 'H:i:s' : 'i:s', $log->duration_seconds) }}
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="text-center">
                            <strong>{{ number_format($log->products_found ?? 0) }}</strong><br>
                            <small class="text-muted">found</small>
                        </td>
                        <td>
                            <div class="small">
                                @if($log->products_added > 0)
                                    <div class="text-success"><i class="fas fa-plus me-1"></i>{{ $log->products_added }} added</div>
                                @endif
                                @if($log->products_updated > 0)
                                    <div class="text-info"><i class="fas fa-edit me-1"></i>{{ $log->products_updated }} updated</div>
                                @endif
                                @if($log->products_deactivated > 0)
                                    <div class="text-warning"><i class="fas fa-minus me-1"></i>{{ $log->products_deactivated }} deactivated</div>
                                @endif
                                @if(!$log->products_added && !$log->products_updated && !$log->products_deactivated)
                                    <span class="text-muted">No changes</span>
                                @endif
                            </div>
                        </td>
                        <td class="text-center">
                            @if($log->errors_count > 0)
                                <span class="badge bg-danger">{{ $log->errors_count }}</span>
                            @else
                                <i class="fas fa-check text-success"></i>
                            @endif
                        </td>
                        <td class="text-center">
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-outline-secondary" title="View Details"
                                        onclick="showLogDetails(@json($log))">
                                    <i class="fas fa-eye"></i>
                                </button>
                                @if($log->errors_count > 0)
                                    <button class="btn btn-outline-danger" title="View Errors"
                                            onclick="showLogErrors(@json($log))">
                                        <i class="fas fa-exclamation-triangle"></i>
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="8" class="text-center py-5 text-muted">
                            <i class="fas fa-inbox fa-3x mb-3 d-block"></i>
                            No scraping logs found matching your criteria.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($logs->hasPages())
        <div class="card-footer d-flex justify-content-center">
            {{ $logs->links() }}
        </div>
    @endif
</div>

{{-- Details Modal --}}
<div class="modal fade" id="logModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-info-circle me-2"></i>Log Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="logModalBody"></div>
        </div>
    </div>
</div>

{{-- Errors Modal --}}
<div class="modal fade" id="errorModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2 text-danger"></i>Error Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="errorModalBody"></div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function showLogDetails(log) {
    const duration = log.duration_seconds
        ? (log.duration_seconds >= 3600
            ? new Date(log.duration_seconds * 1000).toISOString().substr(11, 8)
            : new Date(log.duration_seconds * 1000).toISOString().substr(14, 5))
        : 'N/A';

    const statusColors = { completed: 'success', failed: 'danger', started: 'info', partial: 'warning' };
    const color = statusColors[log.status] || 'secondary';

    document.getElementById('logModalBody').innerHTML = `
        <div class="row g-3">
            <div class="col-md-6">
                <h6 class="text-muted text-uppercase small mb-2">Session</h6>
                <table class="table table-sm table-borderless">
                    <tr><td class="text-muted" width="140">Log ID</td><td><code>#${log.id}</code></td></tr>
                    <tr><td class="text-muted">Platform</td><td><span class="badge bg-primary">${log.platform}</span></td></tr>
                    <tr><td class="text-muted">Status</td><td><span class="badge bg-${color}">${log.status}</span></td></tr>
                    <tr><td class="text-muted">Date</td><td>${log.created_at}</td></tr>
                    <tr><td class="text-muted">Duration</td><td>${duration}</td></tr>
                </table>
            </div>
            <div class="col-md-6">
                <h6 class="text-muted text-uppercase small mb-2">Results</h6>
                <table class="table table-sm table-borderless">
                    <tr><td class="text-muted" width="140">Products Found</td><td><strong>${(log.products_found ?? 0).toLocaleString()}</strong></td></tr>
                    <tr><td class="text-muted">Added</td><td class="text-success">+${log.products_added ?? 0}</td></tr>
                    <tr><td class="text-muted">Updated</td><td class="text-info">${log.products_updated ?? 0}</td></tr>
                    <tr><td class="text-muted">Deactivated</td><td class="text-warning">${log.products_deactivated ?? 0}</td></tr>
                    <tr><td class="text-muted">Errors</td><td class="${log.errors_count > 0 ? 'text-danger' : 'text-success'}">${log.errors_count ?? 0}</td></tr>
                </table>
            </div>
            ${log.error_message ? `
            <div class="col-12">
                <h6 class="text-muted text-uppercase small mb-2">Last Error</h6>
                <div class="alert alert-danger small mb-0">${log.error_message}</div>
            </div>` : ''}
        </div>`;

    new bootstrap.Modal(document.getElementById('logModal')).show();
}

function showLogErrors(log) {
    let details = log.error_details;
    if (typeof details === 'string') {
        try { details = JSON.parse(details); } catch { details = []; }
    }
    details = details || [];

    let body = '';
    if (details.length === 0) {
        body = `<div class="alert alert-warning mb-0">No structured error details available. Check <code>error_message</code> field.</div>`;
        if (log.error_message) {
            body += `<div class="alert alert-danger mt-2 small">${log.error_message}</div>`;
        }
    } else {
        body = `<div class="alert alert-danger py-2"><i class="fas fa-exclamation-triangle me-2"></i>${details.length} error(s) recorded</div>
        <div class="accordion" id="errAcc">`;
        details.forEach((err, i) => {
            const msg  = err.message || 'Unknown error';
            const time = err.timestamp ? new Date(err.timestamp).toLocaleString() : '';
            const exc  = err.details?.exception ?? '';
            const file = err.details?.file ?? '';
            const line = err.details?.line ?? '';
            body += `
            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button class="accordion-button ${i > 0 ? 'collapsed' : ''} py-2 small" type="button"
                            data-bs-toggle="collapse" data-bs-target="#err${i}">
                        <i class="fas fa-times-circle text-danger me-2"></i>${msg.substring(0, 100)}${msg.length > 100 ? '…' : ''}
                    </button>
                </h2>
                <div id="err${i}" class="accordion-collapse collapse ${i === 0 ? 'show' : ''}">
                    <div class="accordion-body small">
                        <table class="table table-sm table-borderless mb-0">
                            ${time ? `<tr><td class="text-muted" width="100">Time</td><td>${time}</td></tr>` : ''}
                            ${exc  ? `<tr><td class="text-muted">Exception</td><td><code>${exc}</code></td></tr>` : ''}
                            ${file ? `<tr><td class="text-muted">File</td><td><code>${file}${line ? ':' + line : ''}</code></td></tr>` : ''}
                            <tr><td class="text-muted">Message</td><td>${msg}</td></tr>
                        </table>
                    </div>
                </div>
            </div>`;
        });
        body += '</div>';
    }

    document.getElementById('errorModalBody').innerHTML = body;
    new bootstrap.Modal(document.getElementById('errorModal')).show();
}
</script>
@endpush
