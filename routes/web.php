<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\{
    DivisionController,
    ProfileController,
    LandingController,
    Auth\AuthenticatedSessionController,
    Auth\RegisteredUserController,
    SectionController,
    EmployeeController,
    DocumentController,
    DashboardController
};

// Public routes
Route::get('/', function () {
    return view('landing');
});

Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
Route::post('/login', [AuthenticatedSessionController::class, 'store']);
Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
Route::post('/register', [RegisteredUserController::class, 'store']);

// Publicly accessible document success page (must come before resource route)
Route::get('/documents/success/{code}/{file_name}', [DocumentController::class, 'success'])->name('documents.success');

// Public QR view and manual code entry
Route::get('/documents/view/{code}', [DocumentController::class, 'view'])->name('documents.view');
Route::get('/track', fn() => view('track'));
Route::get('/documents/track', [DocumentController::class, 'viewByCode'])->name('documents.viewByCode');

// QR scanner page
Route::get('/scan', [DocumentController::class, 'scan'])->name('scan.qr');

// Download routes
Route::get('/documents/download/{id}', [DocumentController::class, 'download'])->name('documents.download');
Route::get('/admin/download-logs', [DocumentController::class, 'downloadLogs'])
    ->middleware(['auth'])
    ->name('admin.download.logs');

// AJAX route to get sections by division (was by department)
Route::get('/sections/by-division/{id}', [SectionController::class, 'byDivision']);

// Authenticated routes
Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::resource('divisions', DivisionController::class)->except(['show']);
    Route::resource('sections', SectionController::class);
    Route::resource('employees', EmployeeController::class);
    Route::resource('documents', DocumentController::class); // ⬅️ must come AFTER documents.success route

    // Profile routes
    Route::prefix('profile')->group(function () {
        Route::get('/', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/', [ProfileController::class, 'update'])->name('profile.update');
        Route::delete('/', [ProfileController::class, 'destroy'])->name('profile.destroy');
    });

    // Log document activity
    Route::post('/document/log', [DocumentController::class, 'storeLog'])->name('document.log');
});
