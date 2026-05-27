@extends('admin.layouts.app')

@section('title', 'Comparison Results')

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-8">
            <h1 class="h3 mb-0">Comparison Results</h1>
            <p class="text-muted">Comparing #{{ $current_run->scraper_id }} vs #{{ $previous_run->scraper_id }} ({{ ucfirst($current_run->platform) }})</p>
        </div>
        <div class="col-md-4 text-right">
            <a href="{{ route('admin.comparison.index') }}" class="btn btn-secondary">Back to Selection</a>
        </div>
    </div>

    {{-- Stats Overview --}}
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">New SKUs</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">{{ $stats['new_count'] }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-danger shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Missing SKUs</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">{{ $stats['missing_count'] }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Changed Data</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">{{ $stats['changed_count'] }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Total Current</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">{{ $stats['total_current'] }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Tabs for different changes --}}
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <ul class="nav nav-tabs card-header-tabs" id="comparisonTabs" role="tablist">
                <li class="nav-item">
                    <a class="nav-link active" id="changed-tab" data-bs-toggle="tab" href="#changed" role="tab">Data Changes ({{ $stats['changed_count'] }})</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="new-tab" data-bs-toggle="tab" href="#new" role="tab">New SKUs ({{ $stats['new_count'] }})</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="missing-tab" data-bs-toggle="tab" href="#missing" role="tab">Missing SKUs ({{ $stats['missing_count'] }})</a>
                </li>
            </ul>
        </div>
        <div class="card-body">
            <div class="tab-content" id="comparisonTabsContent">
                {{-- Data Changes Tab --}}
                <div class="tab-pane fade show active" id="changed" role="tabpanel">
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>SKU</th>
                                    <th>Product Title</th>
                                    <th>Changes Found</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($changed_products as $sku => $data)
                                    <tr>
                                        <td><code>{{ $sku }}</code></td>
                                        <td>{{ $data['product']->title }}</td>
                                        <td>
                                            <table class="table table-sm table-borderless mb-0">
                                                @foreach($data['changes'] as $field => $change)
                                                    <tr>
                                                        <td width="120"><strong>{{ $change['label'] }}:</strong></td>
                                                        <td>
                                                            <span class="text-danger"><del>{{ is_array($change['old']) ? 'Array' : $change['old'] }}</del></span>
                                                            <i class="fas fa-arrow-right mx-2"></i>
                                                            <span class="text-success">{{ is_array($change['new']) ? 'Array' : $change['new'] }}</span>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </table>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="text-center">No data changes found between these runs.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- New SKUs Tab --}}
                <div class="tab-pane fade" id="new" role="tabpanel">
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>SKU</th>
                                    <th>Title</th>
                                    <th>Price</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($new_products as $product)
                                    <tr>
                                        <td><code>{{ $product->sku }}</code></td>
                                        <td>{{ $product->title }}</td>
                                        <td>{{ $product->currency_code }} {{ $product->price }}</td>
                                        <td><a href="{{ route('admin.products.show', $product->id) }}" class="btn btn-sm btn-primary">View</a></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-center">No new SKUs found.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- Missing SKUs Tab --}}
                <div class="tab-pane fade" id="missing" role="tabpanel">
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>SKU</th>
                                    <th>Title</th>
                                    <th>Last Price</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($missing_products as $product)
                                    <tr>
                                        <td><code>{{ $product->sku }}</code></td>
                                        <td>{{ $product->title }}</td>
                                        <td>{{ $product->currency_code }} {{ $product->price }}</td>
                                        <td><a href="{{ route('admin.products.show', $product->id) }}" class="btn btn-sm btn-primary">View</a></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-center">No missing SKUs found.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
