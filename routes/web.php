<?php

use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\PermissionMiddleware;
use App\Http\Controllers\Admin\Auth\LoginController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ScrapingUrlController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ReviewController;
use App\Http\Controllers\Admin\KeywordController;
use App\Http\Controllers\Admin\ScraperController;
use App\Http\Controllers\Admin\ExportController;
use App\Http\Controllers\Admin\ComparisonController;
use App\Http\Controllers\Admin\ScraperConfigurationController;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('login', [LoginController::class, 'login']);
    Route::post('logout', [LoginController::class, 'logout'])->name('logout');
});

Route::middleware(['auth:admin', 'role:super_admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/platform/{platform}', [DashboardController::class, 'platform'])->name('platform');
    Route::get('/logs', [DashboardController::class, 'logs'])->name('logs');
    
    // API endpoints for AJAX requests
    Route::get('/api/stats', [DashboardController::class, 'apiStats'])->name('api.stats');

    Route::get('admins', [AdminUserController::class, 'index'])->name('users.index');
    Route::get('admins/create', [AdminUserController::class, 'create'])->name('users.create');
    Route::post('admins', [AdminUserController::class, 'store'])->name('users.store');
    Route::get('admins/{user}/edit', [AdminUserController::class, 'edit'])->name('users.edit');
    Route::put('admins/{user}', [AdminUserController::class, 'update'])->name('users.update');
    Route::delete('admins/{user}', [AdminUserController::class, 'destroy'])->name('users.destroy');

    Route::resource('roles', RoleController::class);
    //export
    Route::get('export/{module}', [ExportController::class, 'export'])->name('export');
    // /admin/export/keywords
    // /admin/export/products
    // /admin/export/reviews
    // /admin/export/rankings

    Route::prefix('products')->name('products.')->group(function () {
        Route::get('/', [DashboardController::class, 'products'])->name('index');
        Route::get('/export', [ProductController::class, 'export'])->name('export');
        Route::get('/{product}', [ProductController::class, 'show'])->name('show');
        Route::post('/{product}/update-status', [ProductController::class, 'updateIncludeExclude'])->name('update-status');
        Route::post('/bulk-update-status', [ProductController::class, 'bulkUpdateIncludeExclude'])->name('bulk-update-status');
        Route::get('/{product}/reviews', [ProductController::class, 'getReviews'])->name('reviews');
        Route::get('/{product}/rankings', [ProductController::class, 'getRankings'])->name('rankings');
    });

    Route::prefix('scraper-config')->name('scraper-config.')->group(function () {
        Route::get('/', [ScraperConfigurationController::class, 'index'])->name('index');
        Route::get('/create', [ScraperConfigurationController::class, 'create'])->name('create');
        Route::post('/', [ScraperConfigurationController::class, 'store'])->name('store');
        Route::get('/{scraperConfig}/edit', [ScraperConfigurationController::class, 'edit'])->name('edit');
        Route::put('/{scraperConfig}', [ScraperConfigurationController::class, 'update'])->name('update');
        Route::delete('/{scraperConfig}', [ScraperConfigurationController::class, 'destroy'])->name('destroy');
        Route::post('/{scraperConfig}/toggle', [ScraperConfigurationController::class, 'toggleStatus'])->name('toggle');
    });

    Route::prefix('scraping-urls')->name('scraping-urls.')->group(function () {
        Route::get('/', [ScrapingUrlController::class, 'index'])->name('index');
        Route::get('/create', [ScrapingUrlController::class, 'create'])->name('create');
        Route::post('/', [ScrapingUrlController::class, 'store'])->name('store');
        Route::post('/{id}/retry', [ScrapingUrlController::class, 'retry'])->name('retry');
        Route::delete('/{id}', [ScrapingUrlController::class, 'destroy'])->name('destroy');
        Route::post('/bulk-delete', [ScrapingUrlController::class, 'bulkDelete'])->name('bulk-delete');
        Route::post('/bulk-retry', [ScrapingUrlController::class, 'bulkRetry'])->name('bulk-retry');
    });

    // Keywords Management
    Route::prefix('keywords')->name('keywords.')->group(function () {
        Route::get('/', [KeywordController::class, 'index'])->name('index');
        Route::get('/create', [KeywordController::class, 'create'])->name('create');
        Route::post('/', [KeywordController::class, 'store'])->name('store');
        Route::get('/{keyword}/edit', [KeywordController::class, 'edit'])->name('edit');
        Route::put('/{keyword}', [KeywordController::class, 'update'])->name('update');
        Route::delete('/{keyword}', [KeywordController::class, 'destroy'])->name('destroy');
        Route::post('/bulk-create', [KeywordController::class, 'bulkCreate'])->name('bulk-create');
        Route::post('/bulk-update-status', [KeywordController::class, 'bulkUpdateStatus'])->name('bulk-update-status');
        Route::post('/bulk-delete', [KeywordController::class, 'bulkDelete'])->name('bulk-delete');
        Route::get('/{keyword}/rankings', [KeywordController::class, 'rankings'])->name('rankings');
        Route::get('/export', [KeywordController::class, 'export'])->name('export');
    });

    Route::prefix('reviews')->name('reviews.')->group(function () {
        Route::get('/', [ReviewController::class, 'index'])->name('index');
        Route::get('/export', [ReviewController::class, 'export'])->name('export');
        Route::get('/{review}', [ReviewController::class, 'show'])->name('show');
        Route::delete('/{review}', [ReviewController::class, 'destroy'])->name('destroy');
        Route::post('/bulk-delete', [ReviewController::class, 'bulkDelete'])->name('bulk-delete');
    });

    Route::prefix('scraper')->name('scraper.')->group(function () {
        Route::get('/', [ScraperController::class, 'index'])->name('index');
        Route::post('/run', [ScraperController::class, 'runScraper'])->name('run');
        Route::get('/status/{id}', [ScraperController::class, 'getStatus'])->name('status');
        Route::get('/history', [ScraperController::class, 'history'])->name('history');
        Route::get('/{id}', [ScraperController::class, 'show'])->name('show');
        Route::post('/{id}/stop', [ScraperController::class, 'stop'])->name('stop');
        Route::delete('/{id}', [ScraperController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('comparison')->name('comparison.')->group(function () {
        Route::get('/', [ComparisonController::class, 'index'])->name('index');
        Route::post('/compare', [ComparisonController::class, 'compare'])->name('compare');
    });

    


});