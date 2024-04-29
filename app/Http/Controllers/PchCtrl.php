<?php

namespace App\Http\Controllers;

use App;
use App\Mail\AdminRefundNotification;
use App\Mail\UserRefundNotification;
use App\Models\DailyTicket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\Purchase;
use App\Models\Payment;
use App\Models\RefundData;
use App\Models\Ticket;
use App\Models\ReservedSeat;
use App\Models\Voucher;
use App\Models\DisburstmentRefund;
use App\Models\User;
use Xendit\Xendit;
use DateTime;
use DateTimeZone;
use DateInterval;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Barryvdh\DomPDF\Facade\Pdf;

class PchCtrl extends Controller
{
    private function setTrxEWallet($payId, $code_method, $amount, $mobileNumber = null, $cashtag = null)
    {
        $methods = config('payconfigs.methods');
        if (!$methods["e-wallet"][$code_method]) {
            return response()->json(["error" => "Payment method not found"], 404);
        }
        Xendit::setApiKey(env('XENDIT_API_WRITE'));
        $orderId = uniqid("trx_ewallet", true);
        $params = [
            'reference_id' => $orderId,
            'currency' => 'IDR',
            'amount' => $amount,
            'checkout_method' => 'ONE_TIME_PAYMENT',
            'channel_code' => $methods["e-wallet"][$code_method][0],
        ];

        if ($code_method == "014") {
            if ($mobileNumber == null) {
                return ["error" => "Mobile number is required if you choose the OVO method", "status" => 402];
            }
            $params += [
                'channel_properties' => [
                    'mobile_number' => '+62' . substr($mobileNumber, 2),
                ],
            ];
        } else if ($code_method == "015") {
            if ($cashtag == null) {
                return ["error" => '$' . "Cashtag is required if you choose the Jenius Pay method", "status" => 402];
            }
            $cashtag = preg_replace('/[^a-zA-Z0-9 ]/m', '', $cashtag);
            $params += [
                'channel_properties' => [
                    'cashtag' => '$' . $cashtag,
                ],
            ];
        } else {
            $params += [
                'channel_properties' => [
                    // NOTE : Replace this route with page event mng ReactJS
                    'success_redirect_url' => env("FRONTEND_URL") . "/my-tickets",
                ],
            ];
        }

        $createEWalletCharge = \Xendit\EWallets::createEWalletCharge($params);
        $now = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
        Payment::where('id', $payId)->update(
            [
                'token_trx' => $createEWalletCharge["id"],
                'pay_state' => $createEWalletCharge["status"],
                'order_id' => $orderId,
                'price' => $amount,
                'code_method' => $code_method,
                'pay_links' => $createEWalletCharge['actions']['desktop_web_checkout_url'] . '|' . $createEWalletCharge['actions']['mobile_web_checkout_url'] . '|' . $createEWalletCharge['actions']['mobile_deeplink_checkout_url'],
                'expired' => $now->add(new DateInterval('PT2M'))->format('Y-m-d H:i:s')
            ]
        );
        return ["payment" => $createEWalletCharge, "status" => 201];
    }

    private function setTrxQris($payId, $code_method, $amount)
    {
        if (!config('payconfigs.methods')["qris"][$code_method]) {
            return response()->json(["error" => "Payment method not found"], 404);
        }
        $now24 = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
        $orderId = uniqid("trx_qris", true);
        $curl = curl_init();
        curl_setopt_array(
            $curl,
            [
                CURLOPT_URL => 'https://api.xendit.co/qr_codes',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => "CURL_HTTP_VERSION_1_1",
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => json_encode(
                    [
                        "reference_id" => $orderId,
                        "type" => "DYNAMIC",
                        "currency" => "IDR",
                        "amount" => $amount,
                        "channel_code" => config('payconfigs.methods')["qris"][$code_method][0],
                        "expires_at" => str_replace(' ', 'T', $now24->add(new DateInterval('PT15M'))->format('Y-m-d H:i:s')) . 'Z',
                        // "expires_at" => str_replace(' ', 'T', $now24->add(new DateInterval('PT7H'))->format('Y-m-d H:i:s')) . 'Z',
                    ]
                ),
                CURLOPT_HTTPHEADER => array(
                    'Content-Type: application/json',
                    'api-version: 2022-07-31',
                    'Authorization: ' . 'Basic ' . base64_encode(env('XENDIT_API_WRITE') . ':')
                ),
            ]
        );
        $response = curl_exec($curl);
        curl_close($curl);
        $response = json_decode($response);
        Payment::where('id', $payId)->update(
            [
                'token_trx' => $response->id,
                'pay_state' => "PENDING",
                'order_id' => $orderId,
                'price' => $amount,
                'code_method' => $code_method,
                'expired' => $now24->format('Y-m-d H:i:s'),
                'qr_str' => $response->qr_string
            ]
        );
        return ["payment" => $response, "status" => 201];
    }

    private function setTrxVirAccount($payId, $code_method, $amount)
    {
        $methods = config('payconfigs.methods');
        if (!$methods["VA"][$code_method]) {
            return response()->json(["error" => "Payment method not found"], 404);
        }
        $payment = Payment::where('id', $payId);
        $now24 = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
        $orderId = uniqid("trx_va", true);
        Xendit::setApiKey(env('XENDIT_API_WRITE'));
        $createVA = \Xendit\VirtualAccounts::create(
            [
                "external_id" => $orderId,
                "bank_code" => $methods["VA"][$code_method][0],
                "name" => $payment->first()
                    ->purchases()->get()[0]
                    ->ticket()->first()
                    ->event()->first()
                    ->name,
                "is_single_use" => true,
                "is_closed" => true,
                "expected_amount" => $amount,
                "expiration_date" => str_replace(' ', 'T', $now24->add(new DateInterval('PT15M'))->format('Y-m-d H:i:s')) . 'Z',
                // "expiration_date" => str_replace(' ', 'T', $now24->add(new DateInterval('PT7H'))->format('Y-m-d H:i:s')) . 'Z',
            ]
        );

        $payment->update(
            [
                'token_trx' => $createVA["id"],
                'pay_state' => "PENDING",
                'order_id' => $orderId,
                'price' => $amount,
                'code_method' => $code_method,
                'expired' => $now24->format('Y-m-d H:i:s'),
                'virtual_acc' => $createVA['account_number']
            ]
        );
        return ["payment" => $createVA, "status" => 201];
    }

    public function loadTrxData()
    {
        $now = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
        $payments = Payment::where('pay_state', 'PENDING')->where('expired', '<', $now->format('Y-m-d H:i:s'))->get();
        // Log::info($payments);
        // $test = [];
        foreach ($payments as $payment) {
            $datePayCreate = new DateTime($payment->expired, new DateTimeZone('Asia/Jakarta'));
            $datePayCreate = $datePayCreate->add(new DateInterval('PT1M'));
            // array_push($test, [$datePayCreate, $now]);
            if ($datePayCreate < $now) {
                $purchases = $payment->purchases()->get()->groupBy('ticket_id');
                foreach ($purchases as $key => $value) {
                    Ticket::where('id', $key)->where('type_price', '!=', 1)->where('quantity', '!=', -1)->update(
                        [
                            'quantity' => intval($value[0]->ticket()->first()->quantity) + count($value)
                        ]
                    );
                    foreach ($value as $pch) {
                        if ($pch->amount != 0) {
                            DailyTicket::where('purchase_id', $pch->id)->delete();
                            ReservedSeat::where('pch_id', $pch->id)->delete();
                            Purchase::where('id', $pch->id)->update(["code" => '-']);
                        }
                    }
                }
                Payment::where('id', $payment->id)->update(
                    [
                        'pay_state' => "EXPIRED"
                    ]
                );
            }
        }
        // return $test;
    }

    private function rollbackPurchase($ticket_ids, $payment)
    {
        foreach ($ticket_ids as $key => $value) {
            $ticketObj = Ticket::where('id', $key);
            Ticket::where('id', $key)->where('quantity', '!=', -1)->update(
                [
                    "quantity" => intval($ticketObj->first()->quantity) + $value
                ]
            );
        }
        Payment::where('id', $payment->id)->delete();
    }

    private function basicValidator($req, $ticket_ids, $now)
    {
        // $customPriceTickets = [];
        // $dailyTickets = [];
        // $seatNumberTickets = [];
        // foreach ($ticket_ids as $key => $value) {
        //     $ticket = Ticket::where('id', $key)->where('deleted', 0)->first();
        //     if (!$ticket) {
        //         return ["error" => "Ticket is not found", "code" => 404];
        //     }
        //     // if (intval($ticket->max_purchase) < $value) {
        //     //     return ["error" => "Max purchases is " . $ticket->max_purchase . " / user", "code" => 403];
        //     // }
        //     if ($ticket->type_price == 3 && !$req->custom_prices) {
        //         return ["error" => "Custom prices field is required for custom price ticket", "code" => 403];
        //     }
        //     if ($ticket->type_price != 1 && !$req->pay_method) {
        //         return ["error" => "pay_method code is required", "code" => 403];
        //     }
        //     if (intval($ticket->max_purchase) < $value) {
        //         return ["error" => "Max purchases is " . $ticket->max_purchase . " / user", "code" => 403];
        //     }
        //     $event = $ticket->event()->first();
        //     $startTicket = new DateTime($ticket->start_date, new DateTimeZone('Asia/Jakarta'));
        //     $endTicket = new DateTime($ticket->end_date, new DateTimeZone('Asia/Jakarta'));
        //     if ($event->is_publish == 1 || $event->is_publish >= 3) {
        //         return ["error" => "This has not been published yet or is still in draft form", "code" => 403];
        //     }

        //     $purchases = $ticket->purchases()->where('user_id', Auth::user()->id)->get();

        //     if ($event->single_trx == 1) {
        //         $hasPayments = false;
        //         foreach ($purchases as $pch) {
        //             if ($pch->payment()->first()->pay_state != 'EXPIRED' && $pch->payment()->first()->user_id == Auth::user()->id) {
        //                 $hasPayments = true;
        //                 break;
        //             }
        //         }
        //         if ($hasPayments) {
        //             return ["error" => "The event of this ticket accepted single transaction only", "code" => 403];
        //         }
        //     }
        //     if ($now > new DateTime($event->end_date . ' ' . $event->end_time, new DateTimeZone('Asia/Jakarta'))) {
        //         return ["error" => "This event or event ticket has been expired", "code" => 403];
        //     }
        //     if (($event->category != 'Attraction' && $event->category != 'Daily Activities' && $event->category != 'Tour Travel (recurring)')
        //         && ($startTicket > $now || $endTicket < $now)
        //     ) {
        //         return ['error' => "Ticket " . $ticket->name . " is not yet available", "code" => 403];
        //     }
        //     if (($event->category != 'Attraction' && $event->category != 'Daily Activities' && $event->category != 'Tour Travel (recurring)')
        //         && intval($ticket->quantity) < $value
        //     ) {
        //         return ["error" => "Only " . $ticket->quantity . " tickets left for your selected id", "code" => 403];
        //     }
        //     if (($event->category == 'Attraction' || $event->category == 'Daily Activities' || $event->category == 'Tour Travel (recurring)')
        //         && !$req->visit_dates
        //     ) {
        //         return ["error" => "Visit dates form is required for event with type attraction, daily activities, or tour travel (recurring)", "code" => 403];
        //     }
        //     if (count($event->availableDays()->get()) == 0 && ($event->category == 'Attraction' || $event->category == 'Daily Activities' || $event->category == 'Tour Travel (recurring)')) {
        //         return ["error" => "Tickets not yet available for this event in this date or day", "code" => 404];
        //     }
        //     if (($ticket->seat_number == true || $ticket->seat_number == 1) && !$req->seat_numbers) {
        //         return ["error" => "Set numbers options is required for this ticket", "code" => 403];
        //     }
        //     if ($ticket->type_price == 3) {
        //         $customPriceTickets[$key] = $value;
        //     }
        //     if ($event->category == 'Attraction' || $event->category == 'Daily Activities' || $event->category == 'Tour Travel (recurring)') {
        //         $dailyTickets[$key] = $value;
        //     }
        //     if ($ticket->seat_number == true || $ticket->seat_number == 1) {
        //         $seatNumberTickets[$key] = $value;
        //     }
        // }
        // return [
        //     "customPriceTickets" => $customPriceTickets,
        //     "dailyTickets" => $dailyTickets,
        //     "seatNumberTickets" => $seatNumberTickets
        // ];
        $customPriceTickets = [];
        $dailyTickets = [];
        $seatNumberTickets = [];
        foreach ($ticket_ids as $key => $value) {
            $ticket = Ticket::where('id', $key)->where('deleted', 0)->first();
            if (!$ticket) {
                return ["error" => "Ticket is not found", "code" => 404];
            }
            // if (intval($ticket->max_purchase) < $value) {
            //     return ["error" => "Max purchases is " . $ticket->max_purchase . " / user", "code" => 403];
            // }
            if ($ticket->type_price == 3 && !$req->custom_prices) {
                return ["error" => "Custom prices field is required for custom price ticket", "code" => 403];
            }
            if ($ticket->type_price != 1 && !$req->pay_method) {
                return ["error" => "pay_method code is required", "code" => 403];
            }
            if (intval($ticket->max_purchase) < $value && ($ticket->event()->first()->category != 'Attraction' && $ticket->event()->first()->category != 'Daily Activities' && $ticket->event()->first()->category != 'Tour Travel (recurring)')) {
                return ["error" => "Max purchases is " . $ticket->max_purchase . " / user", "code" => 403];
            }
            $event = $ticket->event()->first();
            if ($event->is_publish == 1 || $event->is_publish >= 3) {
                return ["error" => "This has not been published yet or is still in draft form", "code" => 403];
            }

            $startTicket = new DateTime($ticket->start_date, new DateTimeZone('Asia/Jakarta'));
            $endTicket = new DateTime($ticket->end_date, new DateTimeZone('Asia/Jakarta'));

            $purchases = $ticket->purchases()->where('user_id', Auth::user()->id)->get();

            if ($event->single_trx == 1) {
                $hasPayments = false;
                foreach ($purchases as $pch) {
                    if ($pch->payment()->first()->pay_state != 'EXPIRED' && $pch->payment()->first()->user_id == Auth::user()->id) {
                        $hasPayments = true;
                        break;
                    }
                }
                if ($hasPayments) {
                    return ["error" => "The event of this ticket accepted single transaction only", "code" => 403];
                }
            }
            if ($now > new DateTime($event->end_date . ' ' . $event->end_time, new DateTimeZone('Asia/Jakarta'))) {
                return ["error" => "This event or event ticket has been expired", "code" => 403];
            }
            if (($event->category != 'Attraction' && $event->category != 'Daily Activities' && $event->category != 'Tour Travel (recurring)')
                && ($startTicket > $now || $endTicket < $now)
            ) {
                return ['error' => "Ticket " . $ticket->name . " is not yet available", "code" => 403];
            }
            if (($event->category != 'Attraction' && $event->category != 'Daily Activities' && $event->category != 'Tour Travel (recurring)')
                && intval($ticket->quantity) < $value
            ) {
                return ["error" => "Only " . $ticket->quantity . " tickets left for your selected id", "code" => 403];
            }
            if (($event->category == 'Attraction' || $event->category == 'Daily Activities' || $event->category == 'Tour Travel (recurring)')
                && !$req->visit_dates
            ) {
                return ["error" => "Visit dates form is required for event with type attraction, daily activities, or tour travel (recurring)", "code" => 403];
            }
            if (count($event->availableDays()->get()) == 0 && ($event->category == 'Attraction' || $event->category == 'Daily Activities' || $event->category == 'Tour Travel (recurring)')) {
                return ["error" => "Tickets not yet available for this event in this date or day", "code" => 404];
            }
            if (($ticket->seat_number == true || $ticket->seat_number == 1) && !$req->seat_numbers) {
                return ["error" => "Set numbers options is required for this ticket", "code" => 403];
            }
            if ($ticket->type_price == 3) {
                $customPriceTickets[$key] = $value;
            }
            if ($event->category == 'Attraction' || $event->category == 'Daily Activities' || $event->category == 'Tour Travel (recurring)') {
                $dailyTickets[$key] = $value;
            }
            if ($ticket->seat_number == true || $ticket->seat_number == 1) {
                $seatNumberTickets[$key] = $value;
            }
        }
        return [
            "customPriceTickets" => $customPriceTickets,
            "dailyTickets" => $dailyTickets,
            "seatNumberTickets" => $seatNumberTickets
        ];
    }

    private function voucherValidator($req, $voucher, $now)
    {
        $remainingVoucher = 0;
        if ($req->voucher_code) {
            $start = new DateTime($voucher->start, new DateTimeZone('Asia/Jakarta'));
            $end = new DateTime($voucher->end, new DateTimeZone('Asia/Jakarta'));
            $purchasesVc = Purchase::where('code', $req->voucher_code)->get();
            if (intval($voucher->quantity) <= count($purchasesVc) || $now < $start || $now > $end) {
                return ["error" => "This voucher is no longer available", "code" => 404];
            }
            $remainingVoucher = intval($voucher->quantity) - count($purchasesVc);
        }
        return ["remainingVoucher" => $remainingVoucher];
    }

    private function customPriceValidator($req, $customPriceTickets)
    {
        // $customPrices = [];
        // if ($req->custom_prices && count($customPriceTickets) > 0) {
        //     $customPrices = (array) $req->custom_prices;
        //     if (count($customPrices) == 0 || count($customPrices) != count($customPriceTickets)) {
        //         return ["error" => "Custom prices field is required for custom price ticket or count custom prices key not match with list of ticket id", "code" => 403];
        //     }
        //     foreach ($customPrices as $key => $value) {
        //         if (!array_key_exists($key, $customPriceTickets) || intval($value) < 10000) {
        //             return ["error" => !array_key_exists($key, $customPriceTickets) ? "Custom price ticket id key not match with list of ticket_ids" : "Sorry, minimum transaction of one paid (custom_price field) ticket is IDR Rp. 10.000,-", "code" => 403];
        //         }
        //     }
        // }
        // return ["customPrices" => $customPrices];
        $customPrices = [];
        if ($req->custom_prices && count($customPriceTickets) > 0) {
            $customPrices = (array) $req->custom_prices;
            if (count($customPrices) == 0 || count($customPrices) != count($customPriceTickets)) {
                return ["error" => "Custom prices field is required for custom price ticket or count custom prices key not match with list of ticket id", "code" => 403];
            }
            foreach ($customPrices as $key => $prices) {
                if (!array_key_exists($key, $customPriceTickets)) {
                    return ["error" => "Custom price ticket id key not match with list of ticket_ids", "code" => 403];
                }
                if (count($prices) !== $customPriceTickets[$key]) {
                    return ["error" => "Custom prices list count,not equal with count of quantity tickets", "code" => 403];
                }
                foreach ($prices as $value) {
                    if (intval($value) < 10000) {
                        return ["error" => "Sorry, minimum transaction of one paid (custom_price field) ticket is IDR Rp. 10.000,-", "code" => 403];
                    }
                }
            }
        }
        return ["customPrices" => $customPrices];
    }

    private function visitDatesValidator($req, $dailyTickets, $now)
    {
        // $visitDates = [];
        // if ($req->visit_dates && count($dailyTickets) > 0) {
        //     $visitDates = (array) $req->visit_dates;
        //     if (count($visitDates) == 0 || count($visitDates) != count($dailyTickets)) {
        //         return ["error" => "Visit Date is can't blank if you choose a ticket with daily type or count visit dates key not match with list of ticket id", "code" => 403];
        //     }
        //     foreach ($visitDates as $key => $value) {
        //         if (!array_key_exists($key, $dailyTickets) || (count($value) == 0 || !is_array($value))) {
        //             return ["error" => "Visit Date is can't blank if you choose a ticket with daily type", "code" => 403];
        //         }
        //         $ticket = Ticket::where('id', $key)->first();
        //         foreach ($value as $date) {
        //             try {
        //                 $dateFormat = new DateTime($date, new DateTimeZone('Asia/Jakarta'));
        //             } catch (\Throwable $th) {
        //                 return ["error" => "Invalid date format", "code" => 403];
        //             }
        //             if ($now->format('Y-m-d') > $dateFormat->format('Y-m-d')) {
        //                 return ["error" => "Visit date must be greater than date now", "code" => 403];
        //             }
        //             $availableDay = $ticket->event()->first()->availableDays()->where('day', $dateFormat->format('D'))->first();
        //             if (!$availableDay) {
        //                 return ["error" => "This ticket not yet available for this event in this date or day", "code" => 404];
        //             }
        //             $pchsTcDate = DB::table('purchases')
        //                 ->join('daily_tickets', 'purchases.id', '=', 'daily_tickets.purchase_id')
        //                 ->where('purchases.ticket_id', '=', $key)
        //                 ->where('daily_tickets.visit_date', '=', $dateFormat->format('Y-m-d'))
        //                 ->get();
        //             if ((intval($ticket->limitDaily()->first()->limit_quantity) - count($pchsTcDate)) < $dailyTickets[$key]) {
        //                 return ["error" => "Limit ticket for " . $dateFormat->format('Y-m-d') . " has been reached", "code" => 403];
        //             }
        //             $limitTime = new DateTime($availableDay->max_limit_time, new DateTimeZone('Asia/Jakarta'));
        //             if ($now->format('Y-m-d') == $dateFormat->format('Y-m-d') && $now->format("H:i") >= $limitTime->format("H:i")) {
        //                 return ["error" => "Sorry, this ticket is closed for this time. Please reserve again before " . $limitTime->format("H:i"), "code" => 403];
        //             }
        //         }
        //     }
        // }
        // return ["visitDates" => $visitDates];
        $visitDates = [];
        if ($req->visit_dates && count($dailyTickets) > 0) {
            $visitDates = (array) $req->visit_dates;
            if (count($visitDates) == 0 || count($visitDates) != count($dailyTickets)) {
                return ["error" => "Visit Date is can't blank if you choose a ticket with daily type or count visit dates key not match with list of ticket id", "code" => 403];
            }
            foreach ($visitDates as $key => $value) {
                if (!array_key_exists($key, $dailyTickets) || (count($value) == 0 || !is_array($value))) {
                    return ["error" => "Visit Date is can't blank if you choose a ticket with daily type", "code" => 403];
                }
                if (count($value) !== $dailyTickets[$key]) {
                    return ["error" => "Count of vist dates not equal with quanity of ticket", "code" => 403];
                }
                $ticket = Ticket::where('id', $key)->first();

                foreach (array_count_values($value) as $date => $count) {
                    try {
                        $dateFormat = new DateTime($date, new DateTimeZone('Asia/Jakarta'));
                    } catch (\Throwable $th) {
                        return ["error" => "Invalid date format", "code" => 403];
                    }
                    if ($now->format('Y-m-d') > $dateFormat->format('Y-m-d')) {
                        return ["error" => "Visit date must be greater than date now", "code" => 403];
                    }
                    if (intval($ticket->max_purchase) < $count) {
                        return ["error" => "Max purchases is " . $ticket->max_purchase . " / user", "code" => 403];
                    }
                    $availableDay = $ticket->event()->first()->availableDays()->where('day', $dateFormat->format('D'))->first();
                    if (!$availableDay) {
                        return ["error" => "This ticket not yet available for this event in this date or day", "code" => 404];
                    }
                    $pchsTcDate = DB::table('purchases')
                        ->join('daily_tickets', 'purchases.id', '=', 'daily_tickets.purchase_id')
                        ->where('purchases.ticket_id', '=', $key)
                        ->where('daily_tickets.visit_date', '=', $dateFormat->format('Y-m-d'))
                        ->get();
                    if ((intval($ticket->limitDaily()->first()->limit_quantity) - count($pchsTcDate)) < $count) {
                        return ["error" => "Limit ticket for " . $dateFormat->format('Y-m-d') . " has been reached", "code" => 403];
                    }
                    $limitTime = new DateTime($availableDay->max_limit_time, new DateTimeZone('Asia/Jakarta'));
                    if ($now->format('Y-m-d') == $dateFormat->format('Y-m-d') && $now->format("H:i") >= $limitTime->format("H:i")) {
                        return ["error" => "Sorry, this ticket is closed for this time. Please reserve again before " . $limitTime->format("H:i"), "code" => 403];
                    }
                }
            }
        }
        return ["visitDates" => $visitDates];
    }

    private function seatNumbersValidator($req, $seatNumberTickets, $dailyTickets, $visitDates)
    {
        // $seatNumbers = [];
        // if ($req->seat_numbers && count($seatNumberTickets) > 0) {
        //     $seatNumbers = (array) $req->seat_numbers;
        //     if (count($seatNumbers) == 0 || count($seatNumbers) != count($seatNumberTickets)) {
        //         return ["error" => "Seat number is can't blank if you choose a ticket with seat nummber option", "code" => 403];
        //     }
        //     foreach ($seatNumbers as $key => $value) {
        //         if (!array_key_exists($key, $seatNumberTickets) || count($value) == 0 || !is_array($value)) {
        //             return ["error" => "Seat number is can't blank if you choose a ticket with seat number option", "code" => 403];
        //         }
        //         if (count($value) < $seatNumberTickets[$key] || (array_key_exists($key, $dailyTickets) && (count($visitDates[$key]) * $dailyTickets[$key]) > count($value))) {
        //             return ["error" => "Count of seat nummber must be same as total ticket have selected", "code" => 403];
        //         }
        //         if (array_key_exists($key, $dailyTickets)) {
        //             for ($i = 0; $i < count($visitDates[$key]); $i++) {
        //                 $arrDup = [];
        //                 $indexSeatLoop = $i;
        //                 for ($j = 0; $j < $dailyTickets[$key]; $j++) {
        //                     array_key_exists($value[$indexSeatLoop], $arrDup) ? $arrDup[$value[$indexSeatLoop]]++ : $arrDup[$value[$indexSeatLoop]] = 1;
        //                     if ($arrDup[$value[$indexSeatLoop]] > 1) {
        //                         return ["error" => "You can't reserved same seat number in one ticket on same time / date", "code" => 403];
        //                     }
        //                     $indexSeatLoop += count($visitDates[$key]);
        //                 }
        //             }
        //         } else if (count($value) != count(array_unique($value))) {
        //             return ["error" => "You can't reserved same seat number in one ticket on same time / date", "code" => 403];
        //         }
        //         $ticket = Ticket::where('id', $key)->first();
        //         $ticketLimitation = $ticket->limitDaily()->first();
        //         $indexSeatNumDate = 0;
        //         for ($i = 0; $i < $seatNumberTickets[$key]; $i++) {
        //             $hasPurchased = null;
        //             if (array_key_exists($key, $visitDates)) {
        //                 for ($j = 0; $j < count($visitDates[$key]); $j++) {
        //                     if ($value[$indexSeatNumDate] <= 0 || $value[$indexSeatNumDate] > intval($ticketLimitation->limit_quantity)) {
        //                         return ["error" => "Seat number is only available beetwen 1 to " . $ticketLimitation->limit_quantity, "code" => 404];
        //                     }
        //                     $dateFormat = new DateTime($visitDates[$key][$j], new DateTimeZone('Asia/Jakarta'));
        //                     $hasPurchased = DB::table('purchases')
        //                         ->join('daily_tickets', 'purchases.id', '=', 'daily_tickets.purchase_id')
        //                         ->join('reserved_seats', 'purchases.id', '=', 'reserved_seats.pch_id')
        //                         ->where('purchases.ticket_id', '=', $key)
        //                         ->where('daily_tickets.visit_date', '=', $dateFormat->format('Y-m-d'))
        //                         ->where('reserved_seats.seat_number', '=', $value[$indexSeatNumDate])
        //                         ->get();
        //                     $indexSeatNumDate++;
        //                     if (count($hasPurchased) > 0) {
        //                         return ["error" => "This seat number has reserved. Please choose other seat number", "code" => 404];
        //                     }
        //                 }
        //             } else {
        //                 if ($value[$i] <= 0 || $value[$i] > intval($ticket->quantity)) {
        //                     return ["error" => "Seat number is only available beetwen 1 to " . $ticket->quantity, "code" => 404];
        //                 }
        //                 $hasPurchased = DB::table('purchases')
        //                     ->join('reserved_seats', 'purchases.id', '=', 'reserved_seats.pch_id')
        //                     ->where('purchases.ticket_id', '=', $key)
        //                     ->where('reserved_seats.seat_number', '=', $value[$i])
        //                     ->get();
        //                 if (count($hasPurchased) > 0) {
        //                     return ["error" => "This seat number has reserved. Please choose other seat number", "code" => 404];
        //                 }
        //             }
        //         }
        //     }
        // }
        // return ["seatNumbers" => $seatNumbers];
        $seatNumbers = [];
        if ($req->seat_numbers && count($seatNumberTickets) > 0) {
            $seatNumbers = (array) $req->seat_numbers;
            if (count($seatNumbers) == 0 || count($seatNumbers) != count($seatNumberTickets)) {
                return ["error" => "Seat number is can't blank if you choose a ticket with seat nummber option", "code" => 403];
            }
            foreach ($seatNumbers as $key => $value) {
                if (!array_key_exists($key, $seatNumberTickets) || count($value) == 0 || !is_array($value)) {
                    return ["error" => "Seat number is can't blank if you choose a ticket with seat number option", "code" => 403];
                }
                if (count($value) < $seatNumberTickets[$key] || (array_key_exists($key, $dailyTickets) && count($visitDates[$key]) > count($value))) {
                    return ["error" => "Count of seat nummber must be same as total ticket have selected", "code" => 403];
                }
                // Check duplicated seat number every same date if visitdate available
                if (array_key_exists($key, $dailyTickets)) {
                    foreach (array_count_values($visitDates[$key]) as $val => $count) {
                        if ($count > 1) {
                            $duplicateDatesIndex = array_keys($visitDates[$key], $val);
                            for ($i = 0; $i < count($duplicateDatesIndex); $i++) {
                                for ($j = 0; $j < count($duplicateDatesIndex); $j++) {
                                    if ($value[$duplicateDatesIndex[$i]] === $value[$duplicateDatesIndex[$j]] && $i !== $j) {
                                        return ["error" => "You can't reserved same seat number in one ticket on same time / date", "code" => 403];
                                    }
                                }
                            }
                        }
                    }
                } else if (count($value) != count(array_unique($value))) {
                    return ["error" => "You can't reserved same seat number in one ticket on same time / date", "code" => 403];
                }
                $ticket = Ticket::where('id', $key)->first();
                $ticketLimitation = $ticket->limitDaily()->first();
                for ($i = 0; $i < $seatNumberTickets[$key]; $i++) {
                    $hasPurchased = null;
                    if (array_key_exists($key, $visitDates)) {
                        if ($value[$i] <= 0 || $value[$i] > intval($ticketLimitation->limit_quantity)) {
                            return ["error" => "Seat number is only available beetwen 1 to " . $ticketLimitation->limit_quantity, "code" => 404];
                        }
                        $dateFormat = new DateTime($visitDates[$key][$i], new DateTimeZone('Asia/Jakarta'));
                        $hasPurchased = DB::table('purchases')
                            ->join('daily_tickets', 'purchases.id', '=', 'daily_tickets.purchase_id')
                            ->join('reserved_seats', 'purchases.id', '=', 'reserved_seats.pch_id')
                            ->where('purchases.ticket_id', '=', $key)
                            ->where('daily_tickets.visit_date', '=', $dateFormat->format('Y-m-d'))
                            ->where('reserved_seats.seat_number', '=', $value[$i])
                            ->get();
                    } else {
                        if ($value[$i] <= 0 || $value[$i] > intval($ticket->quantity)) {
                            return ["error" => "Seat number is only available beetwen 1 to " . $ticket->quantity, "code" => 404];
                        }
                        $hasPurchased = DB::table('purchases')
                            ->join('reserved_seats', 'purchases.id', '=', 'reserved_seats.pch_id')
                            ->where('purchases.ticket_id', '=', $key)
                            ->where('reserved_seats.seat_number', '=', $value[$i])
                            ->get();
                    }
                    if (count($hasPurchased) > 0) {
                        return ["error" => "This seat number has reserved. Please choose other seat number", "code" => 404];
                    }
                }
            }
        }
        return ["seatNumbers" => $seatNumbers];
    }

    private function basicCreateData($req, $ticket_ids, $visitDates, $customPrices, $seatNumbers, $voucher, $payment, $remainingVoucher)
    {
        // $totalPay = 0;
        // return ["error" => ["vistDates" => $visitDates, "csPrices" => $customPrices, "seatNumbers" => $seatNumbers], 403];
        // $purchases = [];
        // foreach ($ticket_ids as $key => $value) {
        //     $ticketObj = Ticket::where('id', $key);
        //     $indexVisitDates = 0;
        //     array_key_exists($key, $visitDates) ? $value *= count($visitDates[$key]) : '';
        //     for ($i = 0; $i < $value; $i++) {
        //         $amount = 0;
        //         $voucherCode = '-';
        //         $priceTicket = $ticketObj->first()->type_price == 3 ? $customPrices[$key] : $ticketObj->first()->price;
        //         if ($req->voucher_code) {
        //             if ($voucher->event_id == $ticketObj->first()->event_id && $remainingVoucher > 0) {
        //                 $amount = (intval($priceTicket) - (intval($priceTicket) * (intval($voucher->discount) / 100)));
        //                 $voucherCode = $req->voucher_code;
        //                 $remainingVoucher -= 1;
        //             } else {
        //                 $amount = intval($priceTicket);
        //             }
        //         } else {
        //             $amount = intval($priceTicket);
        //         }
        //         $totalPay += $amount;
        //         $pch = Purchase::create(
        //             [
        //                 'user_id' => Auth::user()->id,
        //                 'pay_id' => $payment->id,
        //                 'ticket_id' => $key,
        //                 'amount' => $amount,
        //                 'code' => $voucherCode,
        //                 'is_mine' => true
        //             ]
        //         );
        //         $purchases[] = $pch;
        //         if (array_key_exists($key, $visitDates)) {
        //             if ($indexVisitDates >= count($visitDates[$key])) {
        //                 $indexVisitDates = 0;
        //             }
        //             $visitDate = new DateTime($visitDates[$key][$indexVisitDates], new DateTimeZone('Asia/Jakarta'));
        //             DailyTicket::create(
        //                 [
        //                     "purchase_id" => $pch->id,
        //                     "visit_date" => $visitDate->format('Y-m-d')
        //                 ]
        //             );
        //             $indexVisitDates++;
        //         }
        //         if (array_key_exists($key, $seatNumbers)) {
        //             ReservedSeat::create(
        //                 [
        //                     "pch_id" => $pch->id,
        //                     "seat_number" => $seatNumbers[$key][$i]
        //                 ]
        //             );
        //         }
        //     }
        //     // update quantity ticket
        //     Ticket::where('id', $key)->where('quantity', '!=', -1)->update(
        //         [
        //             "quantity" => intval($ticketObj->first()->quantity) - $value
        //         ]
        //     );
        // }
        // return [
        //     "totalPay" => $totalPay,
        //     "purchases" => $purchases
        // ];
        $totalPay = 0;
        $purchases = [];
        foreach ($ticket_ids as $key => $value) {
            $ticketObj = Ticket::where('id', $key);
            for ($i = 0; $i < $value; $i++) {
                $amount = 0;
                $voucherCode = '-';
                $priceTicket = $ticketObj->first()->type_price == 3 ? $customPrices[$key][$i] : $ticketObj->first()->price;
                if ($req->voucher_code) {
                    if ($voucher->event_id == $ticketObj->first()->event_id && $remainingVoucher > 0) {
                        $amount = (intval($priceTicket) - (intval($priceTicket) * (intval($voucher->discount) / 100)));
                        $voucherCode = $req->voucher_code;
                        $remainingVoucher -= 1;
                    } else {
                        $amount = intval($priceTicket);
                    }
                } else {
                    $amount = intval($priceTicket);
                }
                $totalPay += $amount;
                $pch = Purchase::create(
                    [
                        'user_id' => Auth::user()->id,
                        'pay_id' => $payment->id,
                        'ticket_id' => $key,
                        'amount' => $amount,
                        'code' => $voucherCode,
                        'is_mine' => true
                    ]
                );
                $purchases[] = $pch;
                if (array_key_exists($key, $visitDates)) {
                    $visitDate = new DateTime($visitDates[$key][$i], new DateTimeZone('Asia/Jakarta'));
                    DailyTicket::create(
                        [
                            "purchase_id" => $pch->id,
                            "visit_date" => $visitDate->format('Y-m-d')
                        ]
                    );
                }
                if (array_key_exists($key, $seatNumbers)) {
                    ReservedSeat::create(
                        [
                            "pch_id" => $pch->id,
                            "seat_number" => $seatNumbers[$key][$i]
                        ]
                    );
                }
            }
            // update quantity ticket
            Ticket::where('id', $key)->where('quantity', '!=', -1)->update(
                [
                    "quantity" => intval($ticketObj->first()->quantity) - $value
                ]
            );
        }
        return [
            "totalPay" => $totalPay,
            "purchases" => $purchases
        ];
    }

    public function create(Request $req)
    {
        $this->loadTrxData();
        $validator = Validator::make(
            $req->all(),
            [
                'ticket_ids' => 'required',
            ]
        );
        if ($validator->fails()) {
            return response()->json(["error" => "Please select one ticket or more for doing a transaction"], 403);
        }
        if ((intval($req->pay_method) == 14 && !$req->mobile_number) || (intval($req->pay_method) == 15 && !$req->cashtag)) {
            return response()->json(["error" => "mobile number is required for pay method with OVO or $" . "cashtag is required for pay method with JeniusPay"], 403);
        }
        $voucher = Voucher::where('code', $req->voucher_code)->first();
        if ($req->voucher_code && !$voucher) {
            return response()->json(["error" => "Voucher code not found"], 404);
        }
        $now = new DateTime('now', new DateTimeZone('Asia/Jakarta'));

        $validatorVouchers = $this->voucherValidator($req, $voucher, $now);
        if (array_key_exists("error", $validatorVouchers)) {
            return response()->json($validatorVouchers, $validatorVouchers["code"]);
        }
        $remainingVoucher = $validatorVouchers["remainingVoucher"];
        $ticket_ids = [];
        foreach ($req->ticket_ids as $ticket_id) {
            if (!array_key_exists($ticket_id, $ticket_ids)) {
                $ticket_ids[$ticket_id] = 1;
            } else {
                $ticket_ids[$ticket_id] += 1;
            }
        }
        $basicvalidator = $this->basicValidator($req, $ticket_ids, $now);
        if (array_key_exists("error", $basicvalidator)) {
            return response()->json($basicvalidator, $basicvalidator["code"]);
        }
        $customPriceTickets = $basicvalidator["customPriceTickets"];
        $dailyTickets = $basicvalidator["dailyTickets"];
        $seatNumberTickets = $basicvalidator["seatNumberTickets"];

        $validatorCustomPrices = $this->customPriceValidator($req, $customPriceTickets);
        if (array_key_exists("error", $validatorCustomPrices)) {
            return response()->json($validatorCustomPrices, $validatorCustomPrices["code"]);
        }
        $customPrices = $validatorCustomPrices["customPrices"];

        $validatorVisitDates = $this->visitDatesValidator($req, $dailyTickets, $now);
        if (array_key_exists("error", $validatorVisitDates)) {
            return response()->json($validatorVisitDates, $validatorVisitDates["code"]);
        }
        $visitDates = $validatorVisitDates["visitDates"];

        $validatorSeatNumbers = $this->seatNumbersValidator($req, $seatNumberTickets, $dailyTickets, $visitDates);
        if (array_key_exists("error", $validatorSeatNumbers)) {
            return response()->json($validatorSeatNumbers, $validatorSeatNumbers["code"]);
        }
        $seatNumbers = $validatorSeatNumbers["seatNumbers"];
        // create trx dummy and get the ID

        $payment = Payment::create(
            [
                'user_id' => Auth::user()->id,
                'token_trx' => '-',
                'pay_state' => 'PENDING',
                'order_id' => '-',
                'price' => 0
            ]
        );
        // return response()->json($this->basicCreateData($req, $ticket_ids, $visitDates, $customPrices, $seatNumbers, $voucher, $payment, $remainingVoucher));
        $mainCreateData = $this->basicCreateData($req, $ticket_ids, $visitDates, $customPrices, $seatNumbers, $voucher, $payment, $remainingVoucher);
        $totalPay = $mainCreateData["totalPay"];
        $purchases = $mainCreateData["purchases"];
        if ($totalPay < 10000 && $totalPay > 0) {
            $this->rollbackPurchase($ticket_ids, $payment);
            return response()->json(["error" => "Sorry, minimal transaction (for non-free ticket) is IDR Rp. 10.000,-. Please remove the voucher code first"], 403);
        }
        // change trx data
        $paymentXendit = null;
        if ($totalPay == 0) {
            $orderId = uniqid('trx_free', true);
            Payment::where('id', $payment->id)->update(
                [
                    'token_trx' => '-',
                    'pay_state' => "SUCCEEDED",
                    'order_id' => $orderId,
                    'price' => 0
                ]
            );
            $paymentXendit["payment"] = Payment::where('id', $payment->id)->first();
        } else {
            try {
                if (intval($req->pay_method) >= 11 && intval($req->pay_method) <= 15) {
                    $paymentXendit = $this->setTrxEWallet($payment->id, $req->pay_method, $totalPay, $req->mobile_number, $req->cashtag);
                } else if (intval($req->pay_method) >= 21 && intval($req->pay_method) <= 22) {
                    $paymentXendit = $this->setTrxQris($payment->id, $req->pay_method, $totalPay);
                } else if (intval($req->pay_method) >= 31 && intval($req->pay_method) <= 41) {
                    $paymentXendit = $this->setTrxVirAccount($payment->id, $req->pay_method, $totalPay);
                } else {
                    $paymentXendit = $this->setTrxVirAccount($payment->id, $req->pay_method, $totalPay);
                }
            } catch (\Throwable $th) {
                $this->rollbackPurchase($ticket_ids, $payment);
                return response()->json(["error" => "Server error. Failed reach xendit server", "msg" => $th], 500);
            }
        }
        return response()->json(
            [
                "payment" => $paymentXendit["payment"],
                "purchases" => $purchases
            ],
            201
        );
    }

    private function validationPurchase($req, $forRefund = false, $userData = null)
    {
        $user = $userData == null ? Auth::user() : $userData;
        $purchase = Purchase::where('id', $req->purchase_id)->where('user_id', $user->id)->first();
        if (!$purchase) {
            return ["error" => "Purchase data not found", "code" => 404];
        }
        if ($purchase->amount != 0 && $purchase->payment()->first()->pay_state != 'SUCCEEDED') {
            return ["error" => "Purchase is not valid or payment is failed", "code" => 403];
        }
        if ($purchase->checkin()->first()) {
            return ["error" => "You have used this ticket, with identified by your checkin data", "code" => 403];
        }
        $ticket = Ticket::where('id', $purchase->ticket_id)->first();
        $event = $ticket->event()->first();
        if ($event->category != 'Attraction' && $event->category != 'Daily Activities' && $event->category != 'Tour Travel (recurring)' && $ticket->seat_number == false && $forRefund == false) {
            return ["error" => "Tickets for this event (which you have purchased) do not have a re-schedule feature", "code" => 403];
        }
        $pchVisitDate = $purchase->visitDate()->first();
        $now = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
        if ($pchVisitDate) {
            $visitDate = new DateTime($pchVisitDate->visit_date, new DateTimeZone('Asia/Jakarta'));
            $limitTime = $event->availableDays()->where('day', $visitDate->format('D'))->first();
            $limitChange = new DateTime($pchVisitDate->visit_date . ' ' . $limitTime->max_limit_time, new DateTimeZone('Asia/Jakarta'));
            if ($now > $limitChange) {
                return ["error" => "You can't change visit date if this ticket has expired", "code" => 403];
            }
        }
        $endEvent = new DateTime($event->end_date . ' ' . $event->end_time, new DateTimeZone('Asia/Jakarta'));
        if ($now > $endEvent && ($event->category != 'Attraction' && $event->category != 'Daily Activities' && $event->category != 'Tour Travel (recurring)')) {
            return ["error" => "You can't refund / reschedule if the event has ended", "code" => 403];
        }
        return [
            "ticket" => $ticket,
            "event" => $event,
            "purchase" => $purchase
        ];
    }

    public function reSchedule(Request $req)
    {
        $resValidate = $this->validationPurchase($req);
        if (array_key_exists("error", $resValidate)) {
            return response()->json(["error" => $resValidate["error"]], $resValidate["code"]);
        }
        $availableRsc = $resValidate["event"]->availableReschedule()->first();
        if (!$availableRsc) {
            return response()->json(["message" => "This ticket not have permission for doing Re-Schedule"], 403);
        }
        $now =  new DateTime('now', new DateTimeZone('Asia/Jakarta'));
        // ============== Check limit re-schedule ===================
        $endDate = null;
        if ($resValidate["ticket"]->quantity == -1) {
            $endDate = $resValidate["purchase"]->visitDate()->first()->visit_date;
        } else {
            $endDate = $resValidate["event"]->end_date;
        }
        $endDate = new DateTime($endDate, new DateTimeZone('Asia/Jakarta'));
        $endDate = $endDate->sub(new DateInterval('P' . $availableRsc->limit_time . 'D'));
        if ($now->format('Y-m-d') > $endDate->format('Y-m-d')) {
            return response()->json(["error" => "You can't doing re-schedule if over from max limit re-schedule time (H-" . $availableRsc->limit_time . ")"], 403);
        }
        // ==========================================================
        $eventCtrl = new EventCtrl();
        $date = null;
        $strDate = $req->visit_date ? $req->visit_date : 'now';
        try {
            $date = new DateTime($strDate, new DateTimeZone('Asia/Jakarta'));
            if ($date->format('Y-m-d') < $now->format('Y-m-d')) {
                return response()->json(["error" => "Invalid seleceted date, selected visit date must be greather equal than now"], 403);
            }
        } catch (\Throwable $th) {
            return response()->json(["error" => "Invalid date format"], 403);
        }
        $ticket = $eventCtrl->coreSeatNumberQtyTicket($resValidate["ticket"], $date);
        $passSeatNumber = false;
        $passVisitDate = false;
        if ($req->seat_number && $ticket->seat_number == true) {
            if (!in_array(intval($req->seat_number), $ticket->available_seat_numbers)) {
                return response()->json(["error" => "The seat number you selected is not available"], 404);
            }
            $passSeatNumber = true;
        }
        if (
            $req->visit_date
            && ($resValidate["event"]->category == 'Attraction' || $resValidate["event"]->category == 'Daily Activities' || $resValidate["event"]->category == 'Tour Travel (recurring)')
        ) {
            $avlSelectedDay = $resValidate["event"]->availableDays()->where('day', $date->format('D'))->get();
            if (count($avlSelectedDay) == 0) {
                return response()->json(["error" => "The day has you selected, is not available", 404]);
            }
            $timeLimit = new DateTime($avlSelectedDay[0]->max_limit_time, new DateTimeZone('Asia/Jakarta'));
            if ($now->format('Y-m-d') == $date->format('Y-m-d') && $now->format('H:i:s') > $timeLimit->format('H:i:s')) {
                return response()->json(["error" => "Limit open time in your choosen day has reached"], 403);
            }
            if ($ticket->quantity == 0) {
                return response()->json(["error" => "Limit quantity in your choosen day has reached"], 403);
            }
            $passVisitDate = true;
        }
        if ($passSeatNumber) {
            ReservedSeat::where('pch_id', $req->purchase_id)->update(
                [
                    'seat_number' => $req->seat_number
                ]
            );
        }
        if ($passVisitDate) {
            DailyTicket::where('purchase_id', $req->purchase_id)->update(
                [
                    'visit_date' => $date->format('Y-m-d')
                ]
            );
        }
        $req->pch_id = $req->purchase_id;
        return $this->get($req);
    }

    public function submitRefund(Request $req)
    {
        if (!is_array($req->purchase_ids)) {
            return response()->json(["error" => "Purchase id field is an array type"], 403);
        }
        if (!$req->message) {
            return response()->json(["error" => "Message field is required for admin consideration"], 403);
        }
        if (!$req->phone_number) {
            return response()->json(["error" => "Phone number field is required for admin consideration"], 403);
        }
        if (!$req->account_number) {
            return response()->json(["error" => "Account number / VA number field is required for admin consideration"], 403);
        }
        if (!$req->bank_code) {
            return response()->json(["error" => "Bank code field is required for admin consideration"], 403);
        }
        if (!$req->account_name) {
            return response()->json(["error" => "Account name field is required for admin consideration"], 403);
        }
        if (!array_key_exists($req->bank_code, config('banks'))) {
            return response()->json(["error" => "Bank code not available"], 404);
        }
        $pchs = [];
        foreach ($req->purchase_ids as $purchaseId) {
            $req->purchase_id = $purchaseId;
            if (Purchase::where('id', $purchaseId)->first()->ticket()->first()->event()->first()->allow_refund == 0) {
                return response()->json(["error" => "Event from this purchase purchase not allowed to create request refund"], 403);
            }
            if (RefundData::where('purchase_id', $purchaseId)->first()) {
                return response()->json(["error" => "Purchase data can't duplicated on refund"], 403);
            }
            $resValidate = $this->validationPurchase($req, true);
            if (array_key_exists("error", $resValidate)) {
                return response()->json(["error" => $resValidate["error"]], $resValidate["code"]);
            }
            array_push($pchs, $resValidate);
        }
        $user = Auth::user();
        foreach ($pchs as $pch) {
            RefundData::create(
                [
                    "purchase_id" => $pch["purchase"]->id,
                    "user_id" => $user->id,
                    "ticket_id" => $pch["ticket"]->id,
                    "event_id" => $pch["event"]->id,
                    "message" => $req->message,
                    "phone_number" => $req->phone_number,
                    "bank_code" => $req->bank_code,
                    "account_name" => $req->account_name,
                    "account_number" => $req->account_number,
                    "nominal" => $pch["purchase"]->amount,
                ]
            );
        }
        Mail::to(config('agendakota.admin_email'))->send(
            new AdminRefundNotification(
                $user->name,
                $user->email,
                $pchs[0]["event"]->name,
                $pchs[0]["purchase"]->id,
                $pchs[0]["ticket"]->name,
                $pchs[0]["ticket"]->id,
                $req->message
            )
        );
        Mail::to($pchs[0]["event"]->org()->first()->user()->first()->email)->send(
            new AdminRefundNotification(
                $user->name,
                $user->email,
                $pchs[0]["event"]->name,
                $pchs[0]["purchase"]->id,
                $pchs[0]["ticket"]->name,
                $pchs[0]["ticket"]->id,
                $req->message
            )
        );

        // add notify emeil to organizer
        return response()->json(["message" => "Your refund requets have sent. Check you email, for view your refund status"], 201);
    }

    public function getRefunds(Request $req, $admin = false)
    {
        $refundDatas = $admin ? RefundData::all() : RefundData::where('event_id', $req->event->id)->get();
        foreach ($refundDatas as $refundData) {
            $refundData->user = $refundData->user()->first();
            $refundData->purchase = $refundData->purchase()->first();
            $refundData->ticket = $refundData->ticket()->first();
            $refundData->event = $refundData->event()->first();
            // $refundData->status = $refundData->purchase ? 'Un Approved' : 'Approved';
        }
        return response()->json(["refund_datas" => $refundDatas], 200);
    }

    public function getRefundsOrg(Request $req)
    {
        return $this->getRefunds($req, false);
    }

    public function getRefund(Request $req, $refundId, $admin = false)
    {
        $refundData = $admin ? RefundData::where('id', $refundId)->first() : RefundData::where('id', $refundId)->where('event_id', $req->event->id)->first();
        if (!$refundData) {
            return response()->json(["error" => "Refund data not found"], 404);
        }
        $refundData->user = $refundData->user()->first();
        $refundData->purchase = $refundData->purchase()->first();
        $refundData->ticket = $refundData->ticket()->first();
        $refundData->event = $refundData->event()->first();
        // $refundData->status = $refundData->purchase ? 'Un Approved' : 'Approved';
        return response()->json(["refund_data" => $refundData], 200);
    }

    public function getRefundOrg(Request $req, $refundId)
    {
        return $this->getRefund($req, $refundId, false);
    }

    private function createDisburstment($disburstment)
    {
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://api.xendit.co/disbursements',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($disburstment),
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'X-IDEMPOTENCY-KEY: ' . time(),
                'Authorization: Basic ' . base64_encode(env('XENDIT_API_WRITE') . ':')
            ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);
        return json_decode($response);
    }

    public function considerationRefundMain(Request $req, $refundIds, $refundPercentage, $ticketId, $admin = false)
    {
        if (!is_array($refundIds)) {
            return response()->json(["error" => "refund ids is an array"], 403);
        }
        $refundDatas = [];
        $resOut = [];
        foreach ($refundIds as $refundId) {
            $refundData = $admin ? RefundData::where('id', $refundId)->where('ticket_id', $ticketId)->first() : RefundData::where('id', $refundId)->where('event_id', $req->event->id)->where('ticket_id', $ticketId)->first();
            if (!$refundData) {
                return response()->json(["error" => "Refund data not found"], 404);
            }
            // if (($admin && $refundData->approve_admin == true) || (!$admin && $refundData->approve_org == true)) {
            //     return response()->json(["error" => "This request has approved"], 403);
            // }
            if ($refundData->approve_admin == true) {
                return response()->json(["error" => "This request has approved"], 403);
            }
            if ($admin && $refundData->approve_org == false) {
                return response()->json(["error" => "Admin can't canhge refund state berfore organizer chnage it first"], 403);
            }
            if (array_key_exists($refundData->user_id, $refundDatas)) {
                array_push($refundDatas[$refundData->user_id], $refundData);
            } else {
                $refundDatas[$refundData->user_id] = [$refundData];
            }
        }
        foreach ($refundDatas as $refundDataByUser) {
            $resValidate = null;
            $user = null;
            $purchaseIds = [];
            $nominal = 0;
            $strRefundId = '';
            foreach ($refundDataByUser as $refundData) {
                $user = $refundData->user()->first();
                $req->purchase_id = $refundData->purchase_id;
                array_push($purchaseIds, $req->purchase_id);
                $resValidate = $this->validationPurchase($req, true, $user);
                if (array_key_exists("error", $resValidate)) {
                    return response()->json(["error" => $resValidate["error"]], $resValidate["code"]);
                }
                if (!$req->approved && $admin) {
                    RefundData::where('id', $refundData->id)->delete();
                } else if (!$req->approved) {
                    RefundData::where('id', $refundData->id)->update(["approve_org" => false]);
                } else {
                    // if ($admin) {
                    //     Purchase::where('id', $req->purchase_id)->delete();
                    // }
                    RefundData::where('id', $refundData->id)->update($admin ? [
                        "approve_admin" => true
                    ] : [
                        "approve_org" => true,
                        "percentage" => $refundPercentage ? $refundPercentage : 100.0
                    ]);
                    $strRefundId .= $refundData->id . '~^&**&^~';
                    $nominal += $refundData->nominal;
                }
            }

            if (!$req->approved) {
                Mail::to($user->email)->send(
                    new UserRefundNotification(
                        'Un Approved / Rejected',
                        $resValidate["event"]->name,
                        $purchaseIds[0],
                        $resValidate["ticket"]->name,
                        $resValidate["ticket"]->id,
                        $refundDataByUser[0]->message
                    )
                );
            } else if ($req->approved && $admin) {
                $res = $this->createDisburstment([
                    "external_id" => $resValidate["ticket"]->id,
                    "amount" => ($nominal * ($refundDataByUser[0]->percentage / 100)),
                    "bank_code" =>  $refundDataByUser[0]->bank_code,
                    "account_holder_name" =>  $refundDataByUser[0]->account_name,
                    "account_number" => $refundDataByUser[0]->account_number,
                    "description" => "Refund payment from event (" . $resValidate["event"]->name . ") and ticket (" . $resValidate["ticket"]->name . ")",
                ]);
                array_push($resOut, $res);
                // info($res);
                // return response()->json(["error" => $res], 404);
                if (isset($res->error_code)) {
                    foreach ($refundDataByUser as $refundData) {
                        RefundData::where('id', $refundData->id)->update([
                            "approve_admin" => false
                        ]);
                    }
                    return response()->json([
                        "error" => "Failed process in xendit API",
                        "message" => $res->message
                    ], $res->error_code);
                }
                DisburstmentRefund::create([
                    'disburstment_id' => $res->id,
                    'str_refund_ids' => $strRefundId
                ]);
                Mail::to($user->email)->send(
                    new UserRefundNotification(
                        'Approved / Accepted',
                        $resValidate["event"]->name,
                        $purchaseIds[0],
                        $resValidate["ticket"]->name,
                        $resValidate["ticket"]->id,
                        $refundDataByUser[0]->message
                    )
                );
            }
        }

        return response()->json(["message" => "Status of refund data has updated", "data" => $resOut], 202);
    }

    public function considerationRefund(Request $req)
    {
        return $this->considerationRefundMain($req, $req->refund_ids, $req->refund_percentage, $req->ticket_id, false);
    }

    public function setFinishRefund($refundIds)
    {
        if (!is_array($refundIds)) {
            return response()->json(["error" => "refund ids is an array"], 403);
        }
        $refundDatas = [];
        foreach ($refundIds as $refundId) {
            $refundData = RefundData::where('id', $refundId)->first();
            if (!$refundData) {
                return response()->json(["error" => "Refund data not found"], 404);
            }
            if ($refundData->purchase()->first() || $refundData->approve_admin == false) {
                return response()->json(["error" => "This data not yet approved. Please approve first, to set finish state"], 403);
            }
            array_push($refundDatas, $refundData);
        }
        foreach ($refundDatas as $refundData) {
            RefundData::where('id', $refundData->id)->update([
                'finish' => true
            ]);
        }
        return response()->json(["message" => "Refund data has set to fisnish transfer"], 202);
    }

    // private function removePayment($orderId)
    // {
    //     $payment = Payment::where('order_id', $orderId);
    //     if ($payment->first()->pay_state != 'EXPIRED' && $payment->first()->pay_state != 'SUCCEEDED') {
    //         $purchases = $payment->first()->purchases()->get()->groupBy('ticket_id');
    //         foreach ($purchases as $key => $value) {
    //             Ticket::where('id', $key)->where('type_price', '!=', 1)->where('quantity', '!=', -1)->update(
    //                 [
    //                     'quantity' => intval($value[0]->ticket()->first()->quantity) + count($value)
    //                 ]
    //             );
    //             foreach ($value as $pch) {
    //                 if ($pch->amount != 0) {
    //                     DailyTicket::where('purchase_id', $pch->id)->delete();
    //                     ReservedSeat::where('pch_id', $pch->id)->delete();
    //                     Purchase::where('id', $pch->id)->update(["code" => '-']);
    //                 }
    //             }
    //         }
    //         $payment->update(
    //             [
    //                 'pay_state' => "EXPIRED"
    //             ]
    //         );
    //     }
    // }

    // private function getTrxEWallet($payment)
    // {
    //     Xendit::setApiKey(env('XENDIT_API_READ'));
    //     $eWalletStatus = \Xendit\EWallets::getEWalletChargeStatus($payment->token_trx);
    //     if ($eWalletStatus["status"] == 'FAILED' || $eWalletStatus["status"] == 'VOIDED') {
    //         $this->removePayment($eWalletStatus['reference_id']);
    //     }
    //     return $eWalletStatus;
    // }

    // private function getTrxQris($payment)
    // {
    //     $curl = curl_init();
    //     curl_setopt_array(
    //         $curl,
    //         [
    //             CURLOPT_URL => 'https://api.xendit.co/qr_codes/' . $payment->token_trx,
    //             CURLOPT_RETURNTRANSFER => true,
    //             CURLOPT_ENCODING => '',
    //             CURLOPT_MAXREDIRS => 10,
    //             CURLOPT_TIMEOUT => 0,
    //             CURLOPT_FOLLOWLOCATION => true,
    //             CURLOPT_HTTP_VERSION => "CURL_HTTP_VERSION_1_1",
    //             CURLOPT_CUSTOMREQUEST => 'GET',
    //             CURLOPT_HTTPHEADER => array(
    //                 'Content-Type: application/json',
    //                 'api-version: 2022-07-31',
    //                 'Authorization: ' . 'Basic ' . base64_encode(env('XENDIT_API_READ') . ':')
    //             ),
    //         ]
    //     );
    //     $response = curl_exec($curl);
    //     curl_close($curl);
    //     $response = json_decode($response);
    //     if ($response->status == 'INACTIVE') {
    //         $this->removePayment($response->reference_id);
    //     }
    //     return $response;
    // }

    // private function getTrxVirAccount($payment)
    // {
    //     Xendit::setApiKey(env('XENDIT_API_READ'));
    //     $vaStatus = \Xendit\VirtualAccounts::retrieve($payment->token_trx);
    //     return $vaStatus;
    // }


    // private function getTrx($paymentData)
    // {
    //     $payment = null;
    //     if (preg_match('/trx_va/i', $paymentData->order_id)) {
    //         $payment = $this->getTrxVirAccount($paymentData);
    //         // $payment["status"] = $paymentData->pay_state;
    //     } else if (preg_match('/trx_ewallet/i', $paymentData->order_id)) {
    //         $payment = $this->getTrxEWallet($paymentData);
    //     } else if (preg_match('/trx_qris/i', $paymentData->order_id)) {
    //         $payment = $this->getTrxQris($paymentData);
    //     } else {
    //         $payment = $paymentData;
    //         $payment->status = $payment->pay_state;
    //     }
    //     // Log::info($payment);
    //     return $payment;
    // }

    private function loadTrxValidation($paymentData)
    {
        $now = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
        $exp = new DateTime($paymentData->expired, new DateTimeZone('Asia/Jakarta'));
        $exp = $exp->add(new DateInterval('PT1M'));
        if ($exp < $now) {
            $purchases = $paymentData->purchases()->get()->groupBy('ticket_id');
            foreach ($purchases as $key => $value) {
                Ticket::where('id', $key)->where('type_price', '!=', 1)->where('quantity', '!=', -1)->update(
                    [
                        'quantity' => intval($value[0]->ticket()->first()->quantity) + count($value)
                    ]
                );
                foreach ($value as $pch) {
                    if ($pch->amount != 0) {
                        DailyTicket::where('purchase_id', $pch->id)->delete();
                        ReservedSeat::where('pch_id', $pch->id)->delete();
                        Purchase::where('id', $pch->id)->update(["code" => '-']);
                    }
                }
            }
            Payment::where('id', $paymentData->id)->update(
                [
                    'pay_state' => "EXPIRED"
                ]
            );
            $paymentData->pay_state = "EXPIRED";
        }
        return $paymentData;
    }

    // This function to purchase by id
    public function get(Request $req)
    {
        $user = Auth::user();
        $purchase = Purchase::where('id', $req->pch_id)->where('user_id', $user->id)->first();
        if (!$purchase) {
            return response()->json(["error" => "This purchase is not found"], 404);
        }
        $purchase->ticket = $purchase->ticket()->first();
        if ($purchase->ticket->quantity == -1) {
            $purchase->ticket->quantity = $purchase->ticket->limitDaily()->first()->limit_quantity;
        }
        $purchase->ticket->event = $purchase->ticket->event()->first();
        $purchase->ticket->event->available_days = $purchase->ticket->event->availableDays()->get();
        $purchase->ticket->event->available_reschedule = $purchase->ticket->event->availableReschedule()->first();
        $purchase->ticket->event->org = $purchase->ticket->event->org()->first();
        $purchase->ticket->event->org->legality = $purchase->ticket->event->org->credibilityData()->first();

        $purchase->visitDate = $purchase->visitDate()->first();
        $purchase->seat_number = $purchase->seatNumber()->first();
        $payData = null;
        if ($purchase->payment()->first()->pay_state == 'PENDING') {
            $payData = $this->loadTrxValidation($purchase->payment()->first());
        } else {
            $payData = $purchase->payment()->first();
            $payData->user = $user;
        }
        return response()->json(
            [
                "purchase" => $purchase,
                "payment" => $payData,
                "qr_str" => $purchase->id . "*~^|-|^~*" . $user->id,
                "ticket" => $purchase->ticket()->first(),
                "event" => $purchase->ticket()->first()->event()->first(),
                "visit_date" => $purchase->visitDate()->first(),
                "seat_number" => $purchase->seatNumber()->first()
            ],
            200
        );
    }

    // thid function to get purchases by trx id
    public function purchases(Request $req)
    {
        $user = Auth::user();
        $paysData = Payment::where('user_id', $user->id)->get();
        if (count($paysData) == 0) {
            return response()->json(["error" => "Payment data not found"], 404);
        }
        $payments = [];
        foreach ($paysData as $payData) {
            $payData->user = $user;
            $purchases = $payData->purchases()->where('user_id', $user->id)->get();
            foreach ($purchases as $purchase) {
                $purchase->ticket = $purchase->ticket()->first();
                if ($purchase->ticket->quantity == -1) {
                    $purchase->ticket->quantity = $purchase->ticket->limitDaily()->first()->limit_quantity;
                }
                $purchase->ticket->event = $purchase->ticket->event()->first();
                $purchase->ticket->event->available_days = $purchase->ticket->event->availableDays()->get();
                $purchase->ticket->event->available_reschedule = $purchase->ticket->event->availableReschedule()->first();
                $purchase->ticket->event->org = $purchase->ticket->event->org()->first();
                $purchase->ticket->event->org->legality = $purchase->ticket->event->org->credibilityData()->first();
                $purchase->visit_date = $purchase->visitDate()->first();
                $purchase->seat_number = $purchase->seatNumber()->first();
                $purchase->qr_str = $purchase->id . "*~^|-|^~*" . $user->id;
                $purchase->event_id = $purchase->ticket->event->id;
                $purchase->event_name = $purchase->ticket->event->name;
                $purchase->checkin = $purchase->checkin()->first();
            }
            $trx = null;
            if ($payData->pay_state == 'PENDING') {
                $trx = $this->loadTrxValidation($payData);
            } else {
                $trx = $payData;
            }
            $payments[] = [
                "payment" => $trx,
                "purchases" => $purchases
            ];
        }
        return response()->json(
            [
                "transactions" => $payments
            ],
            200
        );
    }

    public function downloadTicket(Request $req)
    {
        $user = Auth::user();
        // $user = User::where('id', '9b08d7a9-fa50-4336-86dd-aeda11dd8271')->first();
        $pch = Purchase::where('id', $req->purchase_id)->where('user_id', $user->id)->first();
        if (!$pch) {
            return response()->json(["error" => "Data not found"], 404);
        }
        $start = null;
        $end = null;
        $time = '';
        $visitDate = $pch->visitDate()->first();
        if ($visitDate) {
            $visitDate = new DateTime($visitDate->visit_date, new DateTimeZone('Asia/Jakarta'));
            $start = $visitDate;
            $end = $visitDate;
        } else {
            $event = $pch->event()->first();
            $start = new DateTime($event->start_date . " " . $event->start_time, new DateTimeZone('Asia/Jakarta'));
            $end = new DateTime($event->end_date . " " . $event->end_time, new DateTimeZone('Asia/Jakarta'));
            $time = $start->format("H:i") . ' - ' . $end->format("H:i") . ' WIB';
        }
        $ticket = $pch->ticket()->first();
        $event = $ticket->event()->first();
        $org = $event->org()->first();
        $org->legality = $org->credibilityData()->first();
        $pdf = Pdf::loadView('pdfs.invoice-ticket', [
            'myData' => $user, //√
            'qrStr' => $pch->id . "*~^|-|^~*" . $user->id, //√
            'startDate' => $start->format('d-m-Y'), // Srsing Y-m-d √
            'endDate' => $end->format('d-m-Y'), // String Y-m-d √
            'time' => $time, // String H:i WIB √
            'payment' => $pch->payment()->first(),
            'purchase' => $pch, // √
            'ticket' => $ticket,
            'event' => $event,
            'org' => $org,
            'type' => $req->type  // √
        ])->setPaper('a4', 'portrait');
        return $pdf->download();
    }
}
