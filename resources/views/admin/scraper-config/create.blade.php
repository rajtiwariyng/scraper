@extends('admin.layouts.app')

@section('title', $config ? 'Edit Scraper URL' : 'Add Scraper URL')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0">
        <i class="fas fa-{{ $config ? 'edit' : 'plus' }} me-2"></i>
        {{ $config ? 'Edit Scraper URL' : 'Add Scraper URL' }}
    </h2>
    <a href="{{ route('admin.scraper-config.index') }}" class="btn btn-secondary">
        <i class="fas fa-arrow-left me-1"></i> Back
    </a>
</div>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card">
            <div class="card-body">

                @if($errors->any())
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if(session('error'))
                    <div class="alert alert-danger">{{ session('error') }}</div>
                @endif

                <form method="POST" action="{{ $config
                    ? route('admin.scraper-config.update', $config->id)
                    : route('admin.scraper-config.store') }}">
                    @csrf
                    @if($config) @method('PUT') @endif

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Platform <span class="text-danger">*</span></label>
                        <select name="platform" class="form-select" required>
                            <option value="">-- Select Platform --</option>
                            @foreach($platforms as $key => $label)
                                <option value="{{ $key }}"
                                    {{ old('platform', $config?->platform) === $key ? 'selected' : '' }}>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Category URL <span class="text-danger">*</span></label>
                        <input type="url" name="category_url" class="form-control"
                               value="{{ old('category_url', $config?->category_url) }}"
                               placeholder="https://www.amazon.in/s?k=printer..."
                               required>
                        <small class="text-muted">Full category/search page URL to scrape products from.</small>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Category Label <span class="text-muted fw-normal">(optional)</span></label>
                        <input type="text" name="category" class="form-control"
                               value="{{ old('category', $config?->category) }}"
                               placeholder="e.g. printer, laptop, cartridge">
                        <small class="text-muted">Human-readable label. Auto-detected from URL if left blank.</small>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Status <span class="text-danger">*</span></label>
                        <select name="status" class="form-select" required>
                            <option value="active"  {{ old('status', $config?->status ?? 'active') === 'active'   ? 'selected' : '' }}>Active — included in scraping</option>
                            <option value="inactive" {{ old('status', $config?->status) === 'inactive' ? 'selected' : '' }}>Inactive — skipped during scraping</option>
                        </select>
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="fas fa-save me-1"></i>
                            {{ $config ? 'Update URL' : 'Add URL' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="alert alert-info mt-3">
            <strong><i class="fas fa-lightbulb me-1"></i> Tips:</strong>
            <ul class="mb-0 mt-1">
                <li>Use a <strong>category/search listing page</strong>, not an individual product page.</li>
                <li>Only <strong>Active</strong> URLs are scraped when you run the scraper.</li>
                <li>You can add multiple URLs per platform.</li>
            </ul>
        </div>
    </div>
</div>
@endsection
