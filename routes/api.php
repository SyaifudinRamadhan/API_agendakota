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
Route::post('/login-w-otp', [\App\Http\Controllers\Authenticate::class, 'loginWithOtp']);
Route::post('/verify-otp', [\App\Http\Controllers\Authenticate::class, 'verifyOtp']);
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
Route::post('/webhook-payment-pkg', [\App\Http\Controllers\WebhookCtrl::class, 'handleWebhookRedirect'])->name('pkg.payment.redirect');
Route::get('/categories', [\App\Http\Controllers\AdminCtrl::class, 'categories']);
Route::get('/topics', [\App\Http\Controllers\AdminCtrl::class, 'topics']);
Route::get('/org-types', [\App\Http\Controllers\AdminCtrl::class, 'orgTypes']);
Route::get('/cities', [\App\Http\Controllers\AdminCtrl::class, 'cities']);
Route::get('/front-banners', [\App\Http\Controllers\AdminCtrl::class, 'frontBanners']);


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
    // Route Ticket Transaction
    Route::post('/buy-ticket', [\App\Http\Controllers\PchCtrl::class, 'create']);
    Route::get('/get-purchase', [\App\Http\Controllers\PchCtrl::class, 'get']);
    Route::get('/get-purchases', [\App\Http\Controllers\PchCtrl::class, 'purchases']);
    
    Route::post('/checkin', [\App\Http\Controllers\CheckinCtrl::class, 'createByUser']);
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
                    Route::get('/session', [\App\Http\Controllers\EvtSessionCtrl::class, 'get']);
                    Route::get('/sessions', [\App\Http\Controllers\EvtSessionCtrl::class, 'getSessions']);
                    Route::get('/ticket', [\App\Http\Controllers\TicketCtrl::class, 'get']);
                    Route::get('/tickets', [\App\Http\Controllers\TicketCtrl::class, 'getTickets']);
                    Route::middleware('eventSessionData')->group(function(){
                        Route::put('/session/update', [\App\Http\Controllers\EvtSessionCtrl::class, 'update']);
                        Route::delete('/session/delete', [\App\Http\Controllers\EvtSessionCtrl::class, 'delete']);
                        Route::post('/ticket/create', [\App\Http\Controllers\TicketCtrl::class, 'create']);
                        Route::put('/ticket/update', [\App\Http\Controllers\TicketCtrl::class, 'update']);
                        Route::delete('/ticket/delete', [\App\Http\Controllers\TicketCtrl::class, 'delete']);
                    });
                    Route::post('/checkin', [\App\Http\Controllers\CheckinCtrl::class, 'createByOrg']);
                    Route::delete('/checkin/delete', [\App\Http\Controllers\CheckinCtrl::class, 'delete']);
                    Route::get('/checkin/detail', [\App\Http\Controllers\CheckinCtrl::class, 'get']);
                    Route::get('/checkin/report', [\App\Http\Controllers\CheckinCtrl::class, 'checkins']);
                });
            });
        });
        Route::middleware('eventOrganizer')->prefix("{orgId}")->group(function () {
            Route::get('/get-banks-code', [\App\Http\Controllers\WithdrawCtrl::class, 'getBanksCode']);
            Route::post('/add-bank', [\App\Http\Controllers\WithdrawCtrl::class, 'createAccount']);
            Route::delete('/delete-bank', [\App\Http\Controllers\WithdrawCtrl::class, 'deleteAccount']);
            Route::post('/verify-bank', [\App\Http\Controllers\WithdrawCtrl::class, 'verifyAccount']);
            Route::get('/list-banks', [\App\Http\Controllers\WithdrawCtrl::class, 'banks']);
            Route::post('/create-withdraw', [\App\Http\Controllers\WithdrawCtrl::class, 'createWd']);
            Route::delete('/delete-withdraw', [\App\Http\Controllers\WithdrawCtrl::class, 'deleteWd']);
            Route::get('/withdraw-detail', [\App\Http\Controllers\WithdrawCtrl::class, 'getWd']);
            Route::get('/list-withdraw', [\App\Http\Controllers\WithdrawCtrl::class, 'wds']);
            Route::get('/available-withdraw-events', [\App\Http\Controllers\WithdrawCtrl::class, 'availableForWd']);
        });
    });
    
    // Admin Route
    Route::middleware('admin')->prefix('admin')->group(function () {
        // Basic Route
        Route::post('/create-category', [\App\Http\Controllers\AdminCtrl::class, 'createCategory']);
        Route::delete('/delete-category', [\App\Http\Controllers\AdminCtrl::class, 'deleteCategory']);
        Route::post('/set-prio-plus-cat', [\App\Http\Controllers\AdminCtrl::class, 'setPriorityPlusCat']);
        Route::post('/set-prio-min-cat', [\App\Http\Controllers\AdminCtrl::class, 'setPriorityMinCat']);
        Route::post('/create-topic', [\App\Http\Controllers\AdminCtrl::class, 'createTopic']);
        Route::delete('/delete-topic', [\App\Http\Controllers\AdminCtrl::class, 'deleteTopic']);
        Route::post('/create-org-type', [\App\Http\Controllers\AdminCtrl::class, 'createOrgType']);
        Route::delete('/delete-org-type', [\App\Http\Controllers\AdminCtrl::class, 'deleteOrgType']);
        Route::post('/create-city', [\App\Http\Controllers\AdminCtrl::class, 'createCity']);
        Route::delete('/delete-city', [\App\Http\Controllers\AdminCtrl::class, 'deleteCity']);
        Route::post('/set-prio-plus-city', [\App\Http\Controllers\AdminCtrl::class, 'setPriorityPlusCity']);
        Route::post('/set-prio-min-city', [\App\Http\Controllers\AdminCtrl::class, 'setPriorityMinCity']);
        Route::post('/create-fbanner', [\App\Http\Controllers\AdminCtrl::class, 'createFrontBanner']);
        Route::delete('/delete-fbanner', [\App\Http\Controllers\AdminCtrl::class, 'deleteFirstBanner']);
        Route::post('/set-prio-plus-fban', [\App\Http\Controllers\AdminCtrl::class, 'setPriorityPlusFBanner']);
        Route::post('/set-prio-min-fban', [\App\Http\Controllers\AdminCtrl::class, 'setPriorityMinFBanner']);
        Route::post('/create-admin', [\App\Http\Controllers\AdminCtrl::class, 'createAdmin']);
        Route::delete('/delete-admin', [\App\Http\Controllers\AdminCtrl::class, 'deleteAdmin']);
        Route::get('/get-admins', [\App\Http\Controllers\AdminCtrl::class, 'admins']);
        Route::delete('/delete-purchase', [\App\Http\Controllers\AdminCtrl::class, 'deletePch']);
        Route::get('/purchase-detail', [\App\Http\Controllers\AdminCtrl::class, 'pchDetail']);
        Route::get('/purhcases', [\App\Http\Controllers\AdminCtrl::class, 'purchases']);
        Route::delete('/delete-payment', [\App\Http\Controllers\AdminCtrl::class, 'deletePayment']);
        Route::get('/payment-detail', [\App\Http\Controllers\AdminCtrl::class, 'paymentDetail']);
        Route::get('/payments', [\App\Http\Controllers\AdminCtrl::class, 'payments']);

        // Primary admin route
        Route::put('/update-user', [\App\Http\Controllers\AdminPrimaryCtrl::class, 'updateProfileUser']);
        Route::put('/update-user-password', [\App\Http\Controllers\AdminPrimaryCtrl::class, 'updatePasswordUser']);
        Route::get('/user-profile', [\App\Http\Controllers\AdminPrimaryCtrl::class, 'userDetail']);
        Route::get('/users', [\App\Http\Controllers\AdminPrimaryCtrl::class, 'users']);
        Route::post('/create-organization', [\App\Http\Controllers\AdminPrimaryCtrl::class, 'createOrganization']);
        Route::get('/detail-organization', [\App\Http\Controllers\AdminPrimaryCtrl::class, 'organizationDetail']);
        Route::get('/organizations', [\App\Http\Controllers\AdminPrimaryCtrl::class, 'organizations']);
        Route::prefix('org/{orgId}')->group(function () {
            Route::put('/update-org-profile', [\App\Http\Controllers\AdminPrimaryCtrl::class, 'updateProfileOrg']);
            Route::delete('/delete-organization', [\App\Http\Controllers\AdminPrimaryCtrl::class, 'deleteOrganization']);
            Route::get('/list-bank', [\App\Http\Controllers\AdminPrimaryCtrl::class, 'listBank']);
            Route::delete('/delete-bank', [\App\Http\Controllers\AdminPrimaryCtrl::class, 'deleteBank']);
            Route::put('/update-status-bank', [\App\Http\Controllers\AdminPrimaryCtrl::class, 'updateBankStatus']);
            Route::post('event/create', [\App\Http\Controllers\EventCtrl::class, 'create']);
            Route::put('event/update', [\App\Http\Controllers\EventCtrl::class, 'update']);
            Route::delete('event/delete', [\App\Http\Controllers\EventCtrl::class, 'delete']);
            Route::prefix('event/{eventId}/manage')->group(function () {
                Route::middleware('eventData')->group(function(){
                    Route::post('/rundown/create', [\App\Http\Controllers\RundownCtrl::class, 'create']);
                    Route::put('/rundown/update', [\App\Http\Controllers\RundownCtrl::class, 'update']);
                    Route::delete('/rundown/delete', [\App\Http\Controllers\RundownCtrl::class, 'delete']);
                    Route::get('/rundown', [\App\Http\Controllers\RundownCtrl::class, 'get']);
                    Route::get('/rundowns', [\App\Http\Controllers\RundownCtrl::class, 'getRundowns']);
                    Route::post('/session/create', [\App\Http\Controllers\EvtSessionCtrl::class, 'create']);
                    Route::get('/session', [\App\Http\Controllers\EvtSessionCtrl::class, 'get']);
                    Route::get('/sessions', [\App\Http\Controllers\EvtSessionCtrl::class, 'getSessions']);
                    Route::get('/ticket', [\App\Http\Controllers\TicketCtrl::class, 'get']);
                    Route::get('/tickets', [\App\Http\Controllers\TicketCtrl::class, 'getTickets']);
                    Route::middleware('eventSessionData')->group(function(){
                        Route::put('/session/update', [\App\Http\Controllers\EvtSessionCtrl::class, 'update']);
                        Route::delete('/session/delete', [\App\Http\Controllers\EvtSessionCtrl::class, 'delete']);
                        Route::post('/ticket/create', [\App\Http\Controllers\TicketCtrl::class, 'create']);
                        Route::put('/ticket/update', [\App\Http\Controllers\TicketCtrl::class, 'update']);
                        Route::delete('/ticket/delete', [\App\Http\Controllers\TicketCtrl::class, 'delete']);
                    });
                });  
            });
        });
        Route::get('/list-withdraw', [\App\Http\Controllers\AdminPrimaryCtrl::class, 'listWithdraw']);
        Route::get('/detail-withdraw', [\App\Http\Controllers\AdminPrimaryCtrl::class, 'withdrawDetail']);
        Route::delete('/delete-withdraw', [\App\Http\Controllers\AdminPrimaryCtrl::class, 'deleteWithdraw']);
        Route::put('/change-wd-status', [\App\Http\Controllers\AdminPrimaryCtrl::class, 'updateWithdrawStatus']);
    });
});
