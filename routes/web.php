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
    DashboardController,
    DocumentLogController,
    NotificationController // <-- Import the new controller
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

// Publicly accessible document success page
Route::get('/documents/success/{code}/{file_name}', [DocumentController::class, 'success'])->name('documents.success');

// Public QR view and manual code entry
Route::get('/documents/view/{code}', [DocumentController::class, 'view'])->name('documents.view');
Route::get('/documents/qr/download/{file_name}', [DocumentController::class, 'downloadQr'])
    ->name('documents.qrcode.download');

Route::get('/track', fn() => view('track'));
Route::get('/documents/track', [DocumentController::class, 'viewByCode'])->name('documents.viewByCode');

// QR scanner page
Route::get('/scan', [DocumentController::class, 'scan'])->name('scan.qr');

// AJAX route to get sections by division
Route::get('/sections/by-division/{id}', [SectionController::class, 'byDivision']);

// Authenticated routes
Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::resource('divisions', DivisionController::class)->except(['show']);
    Route::resource('sections', SectionController::class);
    Route::resource('employees', EmployeeController::class);
    Route::resource('documents', DocumentController::class);

    // Route for viewing a file
    Route::get('/documents/{id}/file/{action}', [DocumentController::class, 'accessFile'])
        ->where('action', 'view|download')
        ->name('documents.accessFile');

    // Route for receiving and downloading a file
    Route::post('/documents/{id}/receive', [DocumentController::class, 'receiveFile'])->name('documents.receiveFile');

    // Route to mark a document as "ended"
    Route::patch('/documents/{id}/end', [DocumentController::class, 'end'])->name('documents.end');
    
    // Admin-only route to see download history
    Route::get('/admin/download-logs', [DocumentController::class, 'downloadLogs'])->name('admin.download.logs');

    // Profile management
    Route::prefix('profile')->group(function () {
        Route::get('/', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/', [ProfileController::class, 'update'])->name('profile.update');
        Route::delete('/', [ProfileController::class, 'destroy'])->name('profile.destroy');
    });

    // Document activity logging
    Route::post('/document/log', [DocumentLogController::class, 'store'])->name('document.log');

    // View document logs
    Route::get('/documents/{document}/logs', [DocumentLogController::class, 'index'])->name('documents.logs');
    
    // NEW: Notification routes
    Route::get('/notifications/{id}/read', [NotificationController::class, 'read'])->name('notifications.read');
    Route::get('/notifications/mark-all-read', [NotificationController::class, 'markAllRead'])->name('notifications.markAllRead');
});
