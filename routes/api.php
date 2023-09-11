<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/
// Register route
Route::post('/register', [\App\Http\Controllers\Authenticate::class, 'register']);
Route::post('/login', [\App\Http\Controllers\Authenticate::class, 'login']);
Route::post('/login-w-google', [\App\Http\Controllers\Authenticate::class, 'loginGoogle']);
Route::get('/verify/{subId}', [\App\Http\Controllers\Authenticate::class, 'verify'])->name('verify');
Route::post('/request-reset-pass', [\App\Http\Controllers\Authenticate::class, 'requestResetPass']);
Route::post('/reset-pass', [\App\Http\Controllers\Authenticate::class, 'resetPassword']);

// Public route
Route::get('/org-profile/{orgId}', [\App\Http\Controllers\OrgCtrl::class, 'getOrg']);
Route::get('/verify-invite/{token}', [\App\Http\Controllers\OrgCtrl::class, 'acceptInviteTeam'])->name('accept-invite');
Route::get('/event/{eventId}', [\App\Http\Controllers\EventCtrl::class, 'getById']);
Route::get('/event-slug/{slug}', [\App\Http\Controllers\EventCtrl::class, 'getBySlug']);
Route::get('/event-orgs/{orgId}', [\App\Http\Controllers\EventCtrl::class, 'getByOrg']);
Route::get('/method-trxs', [\App\Http\Controllers\PkgPayCtrl::class, 'listPayMethod']);
Route::post('/webhook-payment-pkg', [\App\Http\Controllers\PkgPayCtrl::class, 'handleWebhookRedirect'])->name('pkg.payment.redirect');

// ================= Route for test only ================
Route::post('/create-trx-pkg/{eventId}', [\App\Http\Controllers\PkgPayCtrl::class, 'createTrxEd']);
Route::get('/get-trx-pkg', [\App\Http\Controllers\PkgPayCtrl::class, 'getTrx']);
// =======================================================

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [\App\Http\Controllers\Authenticate::class, 'logout']);
    Route::put('/update', [\App\Http\Controllers\UserCtrl::class, 'updateProfile']);
    Route::put('/update-password', [\App\Http\Controllers\UserCtrl::class, 'updatePassword']);
    Route::get('/profile', [\App\Http\Controllers\UserCtrl::class, 'getUser']);
    // Route organization
    Route::group(["prefix" => "org"], function () {
        Route::post('/register-org', [\App\Http\Controllers\OrgCtrl::class, 'create']);
        Route::put('/update-org', [\App\Http\Controllers\OrgCtrl::class, 'update']);
        Route::delete('/delete-org', [\App\Http\Controllers\OrgCtrl::class, 'delete']);
        Route::get('/user-orgs', [\App\Http\Controllers\OrgCtrl::class, 'getOrgsByUser']);
        Route::post('/invite-team/{orgId}', [\App\Http\Controllers\OrgCtrl::class, 'inviteTeam']);
        Route::get('/teams/{orgId}', [\App\Http\Controllers\OrgCtrl::class, 'getTeams']);
        Route::delete('/delete-team', [\App\Http\Controllers\OrgCtrl::class, 'deleteTeam']);

        Route::middleware('eventOrganizer')->prefix("{orgId}/event")->group(function () {
            Route::post('/create', [\App\Http\Controllers\EventCtrl::class, 'create']);
            Route::middleware('eventData')->group(function(){
                Route::put('/update', [\App\Http\Controllers\EventCtrl::class, 'update']);
                Route::delete('/delete', [\App\Http\Controllers\EventCtrl::class, 'delete']);
                Route::post('/change-state', [\App\Http\Controllers\EventCtrl::class, 'setPublishState']);
                Route::get('/get-trx-pkg', [\App\Http\Controllers\PkgPayCtrl::class, 'getTrx']);
                Route::post('/renew-trx-pkg', [\App\Http\Controllers\PkgPayCtrl::class, 'renewTransaction']);
                Route::prefix("{eventId}/manage")->group(function(){
                    Route::post('/rundown/create', [\App\Http\Controllers\RundownCtrl::class, 'create']);
                    Route::put('/rundown/update', [\App\Http\Controllers\RundownCtrl::class, 'update']);
                    Route::delete('/rundown/delete', [\App\Http\Controllers\RundownCtrl::class, 'delete']);
                    Route::get('/rundown', [\App\Http\Controllers\RundownCtrl::class, 'get']);
                    Route::get('/rundowns', [\App\Http\Controllers\RundownCtrl::class, 'getRundowns']);
                    Route::post('/session/create', [\App\Http\Controllers\EvtSessionCtrl::class, 'create']);
                    Route::put('/session/update', [\App\Http\Controllers\EvtSessionCtrl::class, 'update']);
                    Route::delete('/session/delete', [\App\Http\Controllers\EvtSessionCtrl::class, 'delete']);
                    Route::get('/session', [\App\Http\Controllers\EvtSessionCtrl::class, 'get']);
                    Route::get('/sessions', [\App\Http\Controllers\EvtSessionCtrl::class, 'getSessions']);
                });
            });
        });
    });
    
});
