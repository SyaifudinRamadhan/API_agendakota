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
Route::get('/event-daily-refresh-date/{eventId}', [\App\Http\Controllers\EventCtrl::class, 'getAvailableSeatNumberDailyTicket']);
Route::get('/ticket-reschedule', [\App\Http\Controllers\EventCtrl::class, 'getQtySeatNumberTicket']);
Route::get('/event-slug/{slug}', [\App\Http\Controllers\EventCtrl::class, 'getBySlug']);
Route::get('/event-orgs/{orgId}', [\App\Http\Controllers\EventCtrl::class, 'getByOrg']);
Route::get('/method-trxs', [\App\Http\Controllers\PkgPayCtrl::class, 'listPayMethod']);
Route::post('/webhook-payment-pkg', [\App\Http\Controllers\WebhookCtrl::class, 'handleWebhookRedirect'])->name('pkg.payment.redirect');
Route::get('/categories', [\App\Http\Controllers\AdminCtrl::class, 'categories']);
Route::get('/topics', [\App\Http\Controllers\AdminCtrl::class, 'topics']);
Route::get('/org-types', [\App\Http\Controllers\AdminCtrl::class, 'orgTypes']);
Route::get('/cities', [\App\Http\Controllers\AdminCtrl::class, 'cities']);
Route::get('/front-banners', [\App\Http\Controllers\AdminCtrl::class, 'frontBanners']);
Route::get('/spotlight', [\App\Http\Controllers\AdminCtrl::class, 'getSpotlight']);
Route::get('/special-day', [\App\Http\Controllers\AdminCtrl::class, 'getSpcDay']);
Route::get('/selected-event', [\App\Http\Controllers\AdminCtrl::class, 'getSlctEvent']);
Route::get('/pop-events', [\App\Http\Controllers\SearchCtrl::class, 'popularEvents']);
Route::get('/pop-city-events', [\App\Http\Controllers\SearchCtrl::class, 'popularCityEvents']);
Route::get('/search', [\App\Http\Controllers\SearchCtrl::class, 'searchEvents']);

// ================= Route for test only ================
// Route::post('/create-trx-pkg/{eventId}', [\App\Http\Controllers\PkgPayCtrl::class, 'createTrxEd']);
// Route::get('/get-trx-pkg', [\App\Http\Controllers\PkgPayCtrl::class, 'getTrx']);
// =======================================================

// Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
//     return $request->user();
// });

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [\App\Http\Controllers\Authenticate::class, 'logout']);
    Route::put('/update', [\App\Http\Controllers\UserCtrl::class, 'updateProfile']);
    Route::put('/update-password', [\App\Http\Controllers\UserCtrl::class, 'updatePassword']);
    Route::get('/profile', [\App\Http\Controllers\UserCtrl::class, 'getUser']);
    // Route Ticket Transaction
    Route::post('/buy-ticket', [\App\Http\Controllers\PchCtrl::class, 'create']);
    Route::post('/purchase-reschedule', [\App\Http\Controllers\PchCtrl::class, 'reSchedule']);
    Route::post('/request-refund', [\App\Http\Controllers\PchCtrl::class, 'submitRefund']);
    Route::get('/get-purchase', [\App\Http\Controllers\PchCtrl::class, 'get']);
    Route::get('/get-purchases', [\App\Http\Controllers\PchCtrl::class, 'purchases']);
    Route::post('/invite-user', [\App\Http\Controllers\InvitationCtrl::class, 'create']);
    Route::post('/invitation-accept', [\App\Http\Controllers\InvitationCtrl::class, 'accept']);
    Route::delete('/invitation-delete', [\App\Http\Controllers\InvitationCtrl::class, 'delete']);
    Route::get('/invitations-received', [\App\Http\Controllers\InvitationCtrl::class, 'invitationsRcv']);
    Route::get('/invitations-sent', [\App\Http\Controllers\InvitationCtrl::class, 'invitationsSdr']);

    Route::post('/checkin', [\App\Http\Controllers\CheckinCtrl::class, 'createByUser']);
    Route::post('/fill-survey', [\App\Http\Controllers\SurveyCtrl::class, 'fillSurveyUser']);
    // Route organization
    Route::group(["prefix" => "org"], function () {
        Route::post('/register-org', [\App\Http\Controllers\OrgCtrl::class, 'create']);
        Route::put('/update-org', [\App\Http\Controllers\OrgCtrl::class, 'update']);
        Route::delete('/delete-org', [\App\Http\Controllers\OrgCtrl::class, 'delete']);
        Route::get('/user-orgs', [\App\Http\Controllers\OrgCtrl::class, 'getOrgsByUser']);
        Route::post('/team/invite/{orgId}', [\App\Http\Controllers\OrgCtrl::class, 'inviteTeam']);
        Route::delete('/team/delete', [\App\Http\Controllers\OrgCtrl::class, 'deleteTeam']);
        Route::get('/team/{orgId}', [\App\Http\Controllers\OrgCtrl::class, 'getTeams']);
        Route::get('/personal-legality', [\App\Http\Controllers\LegalityDataCtrl::class, 'getFPersonal']);
        Route::post('/personal-legality/create', [\App\Http\Controllers\LegalityDataCtrl::class, 'createFPersonal']);
        Route::put('/personal-legality/update', [\App\Http\Controllers\LegalityDataCtrl::class, 'updateFPersonal']);
        Route::get('/org-legality', [\App\Http\Controllers\LegalityDataCtrl::class, 'getFOrg']);
        Route::post('/org-legality/create', [\App\Http\Controllers\LegalityDataCtrl::class, 'createFOrg']);
        Route::put('/org-legality/update', [\App\Http\Controllers\LegalityDataCtrl::class, 'updateFOrg']);

        Route::middleware('eventOrganizer')->prefix("{orgId}/event")->group(function () {
            Route::post('/create', [\App\Http\Controllers\EventCtrl::class, 'create']);
            Route::middleware('eventData')->group(function () {
                Route::put('/update', [\App\Http\Controllers\EventCtrl::class, 'update']);
                Route::delete('/delete', [\App\Http\Controllers\EventCtrl::class, 'delete']);
                Route::post('/change-state', [\App\Http\Controllers\EventCtrl::class, 'setPublishState']);
                // Route::get('/get-trx-pkg', [\App\Http\Controllers\PkgPayCtrl::class, 'getTrx']);
                // Route::post('/renew-trx-pkg', [\App\Http\Controllers\PkgPayCtrl::class, 'renewTransaction']);
                Route::prefix("{eventId}/manage")->group(function () {
                    Route::get('/rundowns', [\App\Http\Controllers\RundownCtrl::class, 'getRundowns']);
                    Route::post('/session/create', [\App\Http\Controllers\EvtSessionCtrl::class, 'create']);
                    Route::get('/session', [\App\Http\Controllers\EvtSessionCtrl::class, 'get']);
                    Route::get('/sessions', [\App\Http\Controllers\EvtSessionCtrl::class, 'getSessions']);
                    Route::get('/ticket', [\App\Http\Controllers\TicketCtrl::class, 'get']);
                    Route::get('/tickets', [\App\Http\Controllers\TicketCtrl::class, 'getTickets']);
                    Route::post('/ticket/create', [\App\Http\Controllers\TicketCtrl::class, 'create']);
                    Route::put('/ticket/update', [\App\Http\Controllers\TicketCtrl::class, 'update']);
                    Route::delete('/ticket/delete', [\App\Http\Controllers\TicketCtrl::class, 'delete']);
                    Route::middleware('eventSessionData')->group(function () {
                        Route::put('/session/update', [\App\Http\Controllers\EvtSessionCtrl::class, 'update']);
                        Route::delete('/session/delete', [\App\Http\Controllers\EvtSessionCtrl::class, 'delete']);
                    });
                    Route::get('/voucher', [\App\Http\Controllers\VoucherCtrl::class, 'get']);
                    Route::get('/vouchers', [\App\Http\Controllers\VoucherCtrl::class, 'gets']);
                    Route::post('/voucher/create', [\App\Http\Controllers\VoucherCtrl::class, 'create']);
                    Route::put('/voucher/update', [\App\Http\Controllers\VoucherCtrl::class, 'update']);
                    Route::delete('/voucher/delete', [\App\Http\Controllers\VoucherCtrl::class, 'delete']);
                    Route::post('/checkin', [\App\Http\Controllers\CheckinCtrl::class, 'createByOrg']);
                    Route::delete('/checkin/delete', [\App\Http\Controllers\CheckinCtrl::class, 'delete']);
                    Route::get('/checkin/detail', [\App\Http\Controllers\CheckinCtrl::class, 'get']);
                    Route::get('/checkin/report', [\App\Http\Controllers\CheckinCtrl::class, 'checkins']);
                    Route::get('/user-surveys', [\App\Http\Controllers\SurveyCtrl::class, 'getSurvey']);
                    Route::post('/mail/send', [\App\Http\Controllers\MailBroadcastCtrl::class, 'create']);
                    Route::post('/mail/resend', [\App\Http\Controllers\MailBroadcastCtrl::class, 'resendMail']);
                    Route::delete('/mail/delete', [\App\Http\Controllers\MailBroadcastCtrl::class, 'delete']);
                    Route::get('/mail/gets', [\App\Http\Controllers\MailBroadcastCtrl::class, 'gets']);
                });
            });
        });
        Route::middleware('eventOrganizer')->prefix("{orgId}")->group(function () {
            Route::get('/get-banks-code', [\App\Http\Controllers\WithdrawCtrl::class, 'getBanksCode']);
            Route::post('/bank/add', [\App\Http\Controllers\WithdrawCtrl::class, 'createAccount']);
            Route::delete('/bank/delete', [\App\Http\Controllers\WithdrawCtrl::class, 'deleteAccount']);
            Route::post('/bank/verify', [\App\Http\Controllers\WithdrawCtrl::class, 'verifyAccount']);
            Route::get('/bank/list', [\App\Http\Controllers\WithdrawCtrl::class, 'banks']);
            Route::post('/withdraw/create', [\App\Http\Controllers\WithdrawCtrl::class, 'createWd']);
            Route::delete('/withdraw/delete', [\App\Http\Controllers\WithdrawCtrl::class, 'deleteWd']);
            Route::get('/withdraw/detail', [\App\Http\Controllers\WithdrawCtrl::class, 'getWd']);
            Route::get('/withdraw/list', [\App\Http\Controllers\WithdrawCtrl::class, 'wds']);
            Route::get('/withdraw/available', [\App\Http\Controllers\WithdrawCtrl::class, 'availableForWd']);
        });
    });

    // Admin Route
    Route::middleware('admin')->prefix('admin')->group(function () {
        // Basic Route
        Route::post('/category/create', [\App\Http\Controllers\AdminCtrl::class, 'createCategory']);
        Route::delete('/category/delete', [\App\Http\Controllers\AdminCtrl::class, 'deleteCategory']);
        Route::post('/category/set-prio-plus', [\App\Http\Controllers\AdminCtrl::class, 'setPriorityPlusCat']);
        Route::post('/category/set-prio-min', [\App\Http\Controllers\AdminCtrl::class, 'setPriorityMinCat']);
        Route::post('/topic/create', [\App\Http\Controllers\AdminCtrl::class, 'createTopic']);
        Route::delete('/topic/delete', [\App\Http\Controllers\AdminCtrl::class, 'deleteTopic']);
        Route::post('/org-type/create', [\App\Http\Controllers\AdminCtrl::class, 'createOrgType']);
        Route::delete('/org-type/delete', [\App\Http\Controllers\AdminCtrl::class, 'deleteOrgType']);
        Route::post('/city/create', [\App\Http\Controllers\AdminCtrl::class, 'createCity']);
        Route::delete('/city/delete', [\App\Http\Controllers\AdminCtrl::class, 'deleteCity']);
        Route::post('/city/set-prio-plus', [\App\Http\Controllers\AdminCtrl::class, 'setPriorityPlusCity']);
        Route::post('/city/set-prio-min', [\App\Http\Controllers\AdminCtrl::class, 'setPriorityMinCity']);
        Route::post('/city/set-viral-state', [\App\Http\Controllers\AdminCtrl::class, 'setViralCity']);
        Route::post('/fbanner/create', [\App\Http\Controllers\AdminCtrl::class, 'createFrontBanner']);
        Route::delete('/fbanner/delete', [\App\Http\Controllers\AdminCtrl::class, 'deleteFirstBanner']);
        Route::post('/fbanner/set-prio-plus', [\App\Http\Controllers\AdminCtrl::class, 'setPriorityPlusFBanner']);
        Route::post('/fbanner/set-prio-min', [\App\Http\Controllers\AdminCtrl::class, 'setPriorityMinFBanner']);
        Route::post('/admin/create', [\App\Http\Controllers\AdminCtrl::class, 'createAdmin']);
        Route::delete('/admin/delete', [\App\Http\Controllers\AdminCtrl::class, 'deleteAdmin']);
        Route::get('/admins', [\App\Http\Controllers\AdminCtrl::class, 'admins']);
        Route::delete('/purchase/delete', [\App\Http\Controllers\AdminCtrl::class, 'deletePch']);
        Route::get('/purchase/detail', [\App\Http\Controllers\AdminCtrl::class, 'pchDetail']);
        Route::get('/purhcases', [\App\Http\Controllers\AdminCtrl::class, 'purchases']);
        Route::delete('/payment/delete', [\App\Http\Controllers\AdminCtrl::class, 'deletePayment']);
        Route::get('/payment/detail', [\App\Http\Controllers\AdminCtrl::class, 'paymentDetail']);
        Route::get('/payments', [\App\Http\Controllers\AdminCtrl::class, 'payments']);

        //Home controller data
        Route::post('/spotlight/create', [\App\Http\Controllers\AdminCtrl::class, 'createSpotlight']);
        Route::put('/spotlight/update', [\App\Http\Controllers\AdminCtrl::class, 'updateSpotlight']);
        Route::post('/spotlight/set-view', [\App\Http\Controllers\AdminCtrl::class, 'setViewSpotlight']);
        Route::delete('/spotlight/delete', [\App\Http\Controllers\AdminCtrl::class, 'deleteSpotlight']);
        Route::post('/spotlight/event/add', [\App\Http\Controllers\AdminCtrl::class, 'addEventSpotlight']);
        Route::delete('/spotlight/event/delete', [\App\Http\Controllers\AdminCtrl::class, 'deleteEventSpotlight']);
        Route::post('/spotlight/event/set-prio-plus', [\App\Http\Controllers\AdminCtrl::class, 'addPrioEventSpotlight']);
        Route::post('/spotlight/event/set-prio-min', [\App\Http\Controllers\AdminCtrl::class, 'minPrioEventSpotlight']);
        Route::get('/spotlight', [\App\Http\Controllers\AdminCtrl::class, 'getSpotlight']);
        Route::get('/spotlights', [\App\Http\Controllers\AdminCtrl::class, 'listSpotlights']);

        Route::post('/special-day/create', [\App\Http\Controllers\AdminCtrl::class, 'createSpcDay']);
        Route::put('/special-day/update', [\App\Http\Controllers\AdminCtrl::class, 'updateSpcDay']);
        Route::post('/special-day/set-view', [\App\Http\Controllers\AdminCtrl::class, 'setViewSpcDay']);
        Route::delete('/special-day/delete', [\App\Http\Controllers\AdminCtrl::class, 'deleteSpcDay']);
        Route::post('/special-day/event/add', [\App\Http\Controllers\AdminCtrl::class, 'addEventSpcDay']);
        Route::delete('/special-day/event/delete', [\App\Http\Controllers\AdminCtrl::class, 'deleteEventSpcDay']);
        Route::post('/special-day/event/set-prio-plus', [\App\Http\Controllers\AdminCtrl::class, 'addPrioEventSpcDay']);
        Route::post('/special-day/event/set-prio-min', [\App\Http\Controllers\AdminCtrl::class, 'minPrioEventSpcDay']);
        Route::get('/special-day', [\App\Http\Controllers\AdminCtrl::class, 'getSpcDay']);
        Route::get('/special-days', [\App\Http\Controllers\AdminCtrl::class, 'listSpcDays']);

        Route::post('/selected-event/create', [\App\Http\Controllers\AdminCtrl::class, 'createSlctEvent']);
        Route::put('/selected-event/update', [\App\Http\Controllers\AdminCtrl::class, 'updateSlctEvent']);
        Route::post('/selected-event/set-view', [\App\Http\Controllers\AdminCtrl::class, 'setViewSlctEvent']);
        Route::delete('/selected-event/delete', [\App\Http\Controllers\AdminCtrl::class, 'deleteSlctEvent']);
        Route::post('/selected-event/event/add', [\App\Http\Controllers\AdminCtrl::class, 'addEventSlctEvent']);
        Route::delete('/selected-event/event/delete', [\App\Http\Controllers\AdminCtrl::class, 'deleteEventSlctEvent']);
        Route::post('/selected-event/event/set-prio-plus', [\App\Http\Controllers\AdminCtrl::class, 'addPrioEventSlctEvent']);
        Route::post('/selected-event/event/set-prio-min', [\App\Http\Controllers\AdminCtrl::class, 'minPrioEventSlctEvent']);
        Route::get('/selected-event', [\App\Http\Controllers\AdminCtrl::class, 'getSlctEvent']);
        Route::get('/selected-events', [\App\Http\Controllers\AdminCtrl::class, 'listSlctEvents']);

        // Primary admin route
        Route::put('/user/update', [\App\Http\Controllers\AdminPrimaryCtrl::class, 'updateProfileUser']);
        Route::put('/user/update-password', [\App\Http\Controllers\AdminPrimaryCtrl::class, 'updatePasswordUser']);
        Route::get('/user/profile', [\App\Http\Controllers\AdminPrimaryCtrl::class, 'userDetail']);
        Route::get('/users', [\App\Http\Controllers\AdminPrimaryCtrl::class, 'users']);
        Route::post('/organization/create', [\App\Http\Controllers\AdminPrimaryCtrl::class, 'createOrganization']);
        Route::get('/organization/detail', [\App\Http\Controllers\AdminPrimaryCtrl::class, 'organizationDetail']);
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
                Route::middleware('eventData')->group(function () {
                    Route::get('/rundowns', [\App\Http\Controllers\RundownCtrl::class, 'getRundowns']);
                    Route::post('/session/create', [\App\Http\Controllers\EvtSessionCtrl::class, 'create']);
                    Route::get('/session', [\App\Http\Controllers\EvtSessionCtrl::class, 'get']);
                    Route::get('/sessions', [\App\Http\Controllers\EvtSessionCtrl::class, 'getSessions']);
                    Route::get('/ticket', [\App\Http\Controllers\TicketCtrl::class, 'get']);
                    Route::get('/tickets', [\App\Http\Controllers\TicketCtrl::class, 'getTickets']);
                    Route::post('/ticket/create', [\App\Http\Controllers\TicketCtrl::class, 'create']);
                    Route::put('/ticket/update', [\App\Http\Controllers\TicketCtrl::class, 'update']);
                    Route::delete('/ticket/delete', [\App\Http\Controllers\TicketCtrl::class, 'delete']);
                    Route::middleware('eventSessionData')->group(function () {
                        Route::put('/session/update', [\App\Http\Controllers\EvtSessionCtrl::class, 'update']);
                        Route::delete('/session/delete', [\App\Http\Controllers\EvtSessionCtrl::class, 'delete']);
                    });
                    Route::get('/voucher', [\App\Http\Controllers\VoucherCtrl::class, 'get']);
                    Route::get('/vouchers', [\App\Http\Controllers\VoucherCtrl::class, 'gets']);
                    Route::post('/voucher/create', [\App\Http\Controllers\VoucherCtrl::class, 'create']);
                    Route::put('/voucher/update', [\App\Http\Controllers\VoucherCtrl::class, 'update']);
                    Route::delete('/voucher/delete', [\App\Http\Controllers\VoucherCtrl::class, 'delete']);
                });
            });
        });
        Route::get('/list-withdraw', [\App\Http\Controllers\AdminPrimaryCtrl::class, 'listWithdraw']);
        Route::get('/detail-withdraw', [\App\Http\Controllers\AdminPrimaryCtrl::class, 'withdrawDetail']);
        Route::delete('/delete-withdraw', [\App\Http\Controllers\AdminPrimaryCtrl::class, 'deleteWithdraw']);
        Route::put('/change-wd-status', [\App\Http\Controllers\AdminPrimaryCtrl::class, 'updateWithdrawStatus']);
        Route::get('/refunds', [\App\Http\Controllers\AdminPrimaryCtrl::class, 'getRefunds']);
        Route::get('/refund', [\App\Http\Controllers\AdminPrimaryCtrl::class, 'getRefund']);
        Route::post('/refund/chamge-state', [\App\Http\Controllers\AdminPrimaryCtrl::class, 'considerationRefund']);
    });
});
