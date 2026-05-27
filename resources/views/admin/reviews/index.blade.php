@extends('admin.layouts.app')

@section('title', 'Reviews Management')

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-6">
            <h1 class="h3 mb-0">Reviews Management</h1>
            <p class="text-muted">Total: {{ number_format($totalCount) }} reviews</p>
        </div>
        <div class="col-md-6 text-end">
            <a href="{{ route('admin.reviews.export', request()->query()) }}" class="btn btn-success">
                <i class="fas fa-download"></i> Export CSV
            </a>
        </div>
    </div>

    {{-- Statistics Cards --}}
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <h6 class="text-muted">Total Reviews</h6>
                    <h3>{{ number_format($stats['total_reviews']) }}</h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <h6 class="text-muted">With Images</h6>
                    <h3>{{ number_format($stats['with_images']) }}</h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <h6 class="text-muted">Verified Purchases</h6>
                    <h3>{{ number_format($stats['verified_purchases']) }}</h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <h6 class="text-muted">Average Rating</h6>
                    <h3>{{ $stats['average_rating'] }} ⭐</h3>
                </div>
            </div>
        </div>
    </div>

    {{-- Filters --}}
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-filter"></i> Filters</h5>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('admin.reviews.index') }}">
                <div class="row g-3">
                    <div class="col-md-2">
                        <label class="form-label">Platform</label>
                        <select name="platform" class="form-select">
                            <option value="">All</option>
                            @foreach($platforms as $platform)
                                <option value="{{ $platform }}" {{ request('platform') == $platform ? 'selected' : '' }}>
                                    {{ ucfirst($platform) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Brand</label>
                        <select name="brand" class="form-select">
                            <option value="">All</option>
                            @foreach($brands as $brand)
                                <option value="{{ $brand }}" {{ request('brand') == $brand ? 'selected' : '' }}>
                                    {{ $brand }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Rating</label>
                        <select name="rating" class="form-select">
                            <option value="">All</option>
                            @foreach($ratings as $rating)
                                <option value="{{ $rating }}" {{ request('rating') == $rating ? 'selected' : '' }}>
                                    {{ $rating }} Stars
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Has Images</label>
                        <select name="has_images" class="form-select">
                            <option value="">All</option>
                            <option value="yes" {{ request('has_images') == 'yes' ? 'selected' : '' }}>Yes</option>
                            <option value="no" {{ request('has_images') == 'no' ? 'selected' : '' }}>No</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Verified</label>
                        <select name="verified_purchase" class="form-select">
                            <option value="">All</option>
                            <option value="yes" {{ request('verified_purchase') == 'yes' ? 'selected' : '' }}>Yes</option>
                            <option value="no" {{ request('verified_purchase') == 'no' ? 'selected' : '' }}>No</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="keyword" class="form-label">Keyword in Review</label>
                        <input type="text" class="form-control" id="keyword" name="keyword" 
                               placeholder="Search in review text..." value="{{ request('keyword') }}">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search"></i> Filter
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- Reviews Table --}}
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Platform</th>
                            <th>Brand</th>
                            <th>SKU</th>
                            <th>Reviewer</th>
                            <th>Rating</th>
                            <th>Review</th>
                            <th>Date</th>
                            <th>Images</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($reviews as $review)
                            <tr>
                                <td><span class="badge bg-primary">{{ ucfirst($review->platform) }}</span></td>
                                <td>{{ $review->product->brand ?? 'N/A' }}</td>
                                <td><code>{{ $review->product->sku ?? 'N/A' }}</code></td>
                                <td>{{ $review->reviewer_name ?? 'Anonymous' }}</td>
                                <td><span class="badge bg-warning">{{ $review->rating }} ⭐</span></td>
                                <td>{{ \Str::limit($review->review_text, 50) }}</td>
                                <td>{{ $review->review_date ? \Carbon\Carbon::parse($review->review_date)->format('d-m-Y') : 'N/A' }}</td>
                                <td>
                                    @if($review->review_images && $review->review_images != '[]')
                                        <span class="badge bg-success">Yes</span>
                                    @else
                                        <span class="badge bg-secondary">No</span>
                                    @endif
                                </td>
                                <td>
                                    <a href="{{ route('admin.reviews.show', $review) }}" class="btn btn-sm btn-info">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center py-4">No reviews found</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Pagination --}}
            <div class="mt-3">
                {{ $reviews->links() }}
            </div>
        </div>
    </div>
</div>
@endsection