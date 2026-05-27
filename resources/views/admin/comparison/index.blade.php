@extends('admin.layouts.app')

@section('title', 'Scraper Comparison')

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-12">
            <h1 class="h3 mb-0">Scraper Comparison</h1>
            <p class="text-muted">Compare data between two weekly scraper runs</p>
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">Select Runs to Compare</h6>
        </div>
        <div class="card-body">
            <form action="{{ route('admin.comparison.compare') }}" method="POST">
                @csrf
                <div class="row">
                    <div class="col-md-5">
                        <div class="mb-3">
                            <label for="current_run">Current Run (Newer)</label>
                            <select name="current_run" id="current_run" class="form-select" required>
                                <option value="">Select Run</option>
                                @foreach($runs as $run)
                                    <option value="{{ $run->scraper_id }}">
                                        #{{ $run->scraper_id }} - {{ ucfirst($run->platform) }} ({{ $run->created_at->format('d M Y H:i') }}) - {{ $run->products_scraped }} products
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div class="mb-3">
                            <label for="previous_run">Previous Run (Older)</label>
                            <select name="previous_run" id="previous_run" class="form-select" required>
                                <option value="">Select Run</option>
                                @foreach($runs as $run)
                                    <option value="{{ $run->scraper_id }}">
                                        #{{ $run->scraper_id }} - {{ ucfirst($run->platform) }} ({{ $run->created_at->format('d M Y H:i') }}) - {{ $run->products_scraped }} products
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <div class="mb-3 w-100">
                            <button type="submit" class="btn btn-primary w-100">Compare Now</button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">Recent Completed Runs</h6>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered" width="100%" cellspacing="0">
                    <thead>
                        <tr>
                            <th>Scraper ID</th>
                            <th>Platform</th>
                            <th>Date</th>
                            <th>Products</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($runs as $run)
                            <tr>
                                <td>#{{ $run->scraper_id }}</td>
                                <td><span class="badge bg-info text-dark">{{ ucfirst($run->platform) }}</span></td>
                                <td>{{ $run->created_at->format('d M Y H:i') }}</td>
                                <td>{{ $run->products_scraped }}</td>
                                <td><span class="badge bg-success">{{ $run->status }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
