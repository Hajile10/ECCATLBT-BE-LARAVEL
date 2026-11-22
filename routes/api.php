<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\LibraryController as L;

Route::post('/login', [L::class, 'login'])->middleware('throttle:10,1');
Route::post('/register', [L::class, 'registerStudent'])->middleware('throttle:10,1');
Route::get('/catalog', [L::class, 'catalog']);
Route::get('/catalog/{id}', [L::class, 'book']);
Route::get('/research', [L::class, 'research']);
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [L::class, 'me']);
    Route::post('/logout', [L::class, 'logout']);
    Route::get('/dashboard', [L::class, 'dashboard']);
    Route::get('/my-loans', [L::class, 'myLoans']);
    Route::get('/my-loan-extensions', [L::class, 'myLoanExtensions']);
    Route::post('/loans/{id}/extension-requests', [L::class, 'requestLoanExtension']);
    Route::get('/my-reservations', [L::class, 'myReservations']);
    Route::post('/reservations', [L::class, 'requestReservation']);
    Route::post('/reservations/{id}/cancel', [L::class, 'cancelReservation']);
    Route::get('/notifications', [L::class, 'notifications']);
    Route::post('/notifications/read', [L::class, 'readNotifications']);
    Route::get('/research/{id}/download', [L::class, 'downloadResearch']);
    Route::middleware('library.staff')->group(function () {
        Route::post('/books', [L::class, 'saveBook']);
        Route::put('/books/{id}', [L::class, 'saveBook']);
        Route::post('/books/{id}/copies', [L::class, 'addCopy']);
        Route::get('/users', [L::class, 'users']);
        Route::post('/users', [L::class, 'saveUser']);
        Route::get('/reservations', [L::class, 'reservations']);
        Route::post('/reservations/{id}/status', [L::class, 'reservationStatus']);
        Route::get('/loans', [L::class, 'loans']);
        Route::get('/extension-requests', [L::class, 'loanExtensionRequests']);
        Route::post('/extension-requests/{id}/decision', [L::class, 'decideLoanExtension']);
        Route::post('/loans/checkout', [L::class, 'checkout']);
        Route::post('/loans/{id}/return', [L::class, 'returnBook']);
        Route::post('/loans/{id}/renew', [L::class, 'renew']);
        Route::post('/incidents', [L::class, 'incident']);
        Route::get('/incidents', [L::class, 'incidents']);
        Route::get('/reports', [L::class, 'reports']);
        Route::post('/research', [L::class, 'uploadResearch']);
    });
    Route::middleware('library.admin')->group(function () {
        Route::get('/settings', [L::class, 'settings']);
        Route::put('/settings', [L::class, 'updateSettings']);
        Route::get('/audit-logs', [L::class, 'auditLogs']);
        Route::post('/users/import', [L::class, 'importUsers']);
    });
});
