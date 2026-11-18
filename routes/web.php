<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\ExtraController;
use App\Http\Controllers\OrderPreviewController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RateController;
use App\Http\Controllers\RentalPeriodController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VisitorController;
use App\Http\Controllers\VehicleCategoryController;
use App\Http\Controllers\VehicleClassController;
use App\Http\Middleware\EnsureUserIsStaff;
use Illuminate\Support\Facades\Route;

// Public pages (Blade)
Route::get('/', [PageController::class, 'home'])->name('home');
Route::get('/vehicles', [PageController::class, 'vehicles'])->name('vehicles.index'); // search results + vehicle choice
Route::get('/locations', [PageController::class, 'locations'])->name('locations');
Route::get('/rent', [PageController::class, 'rent'])->name('rent'); // checkout (Vue), after a vehicle was selected
Route::get('/locale/{locale}', [PageController::class, 'locale'])->name('locale');

// Public JSON API (used by the checkout)
Route::get('/api/branches', [BranchController::class, 'publicIndex']);
Route::post('/api/rental-period/validate', [RentalPeriodController::class, 'validate']);
Route::post('/api/price-quote', QuoteController::class);
Route::post('/api/order-preview', OrderPreviewController::class); // test: validates a checkout, books nothing

// Auth
Route::middleware('guest')->group(function () {
    Route::view('/login', 'auth.login')->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
});
Route::post('/logout', [AuthController::class, 'destroy'])->middleware('auth')->name('logout');

// Back office (staff only)
Route::middleware(['auth', EnsureUserIsStaff::class])->group(function () {
    // JSON API; declared before the /admin catch-all.
    Route::prefix('admin/api')->group(function () {
        Route::apiResource('branches', BranchController::class)->except('show');
        Route::post('branches/{branch}/image', [BranchController::class, 'storeImage']);
        Route::delete('branches/{branch}/image', [BranchController::class, 'destroyImage']);
        Route::apiResource('categories', VehicleCategoryController::class)->except('show');
        Route::apiResource('classes', VehicleClassController::class)->except('show');
        Route::apiResource('extras', ExtraController::class)->except('show');
        Route::get('me', [ProfileController::class, 'show']);
        Route::put('profile', [ProfileController::class, 'update']);
        Route::put('profile/password', [ProfileController::class, 'updatePassword']);
        Route::apiResource('users', UserController::class)->except('show');
        Route::get('bookings', [BookingController::class, 'index']);
        Route::post('bookings/{booking}/confirm', [BookingController::class, 'confirm']);
        Route::post('bookings/{booking}/cancel', [BookingController::class, 'cancel']);
        Route::apiResource('customers', CustomerController::class)->except('show');
        Route::get('visitors', [VisitorController::class, 'index']);
        Route::get('rates', [RateController::class, 'index']);
        Route::put('classes/{class}/rates', [RateController::class, 'update']);
    });

    // Admin SPA (Vue handles routing below /admin)
    Route::get('/admin/{any?}', fn () => view('admin'))->where('any', '.*')->name('admin');
});
