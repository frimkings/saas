<?php

use App\Http\Controllers\Api\PublicBookingController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Public Eye Exam Booking Route (Saves website requests to online_bookings table)
Route::post('/v1/appointments', [PublicBookingController::class, 'store']);

// Convert Online Booking to Official Patient & Appointment
Route::middleware('auth:sanctum')->post('/v1/online-bookings/{booking}/convert', [PublicBookingController::class, 'convert']);
