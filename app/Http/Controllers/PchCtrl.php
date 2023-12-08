<?php

namespace App\Http\Controllers;

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
use Xendit\Xendit;
use DateTime;
use DateTimeZone;
use DateInterval;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

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
                    'success_redirect_url' => route('pkg.payment.redirect'),
                ],
            ];
        }
        // dd($params);
        $createEWalletCharge = \Xendit\EWallets::createEWalletCharge($params);
        Payment::where('id', $payId)->update(
            [
                'token_trx' => $createEWalletCharge["id"],
                'pay_state' => $createEWalletCharge["status"],
                'order_id' => $orderId,
                'price' => $amount
            ]
        );
        return ["payment" => $createEWalletCharge, "status" => 201];
    }

    private function setTrxQris($payId, $code_method, $amount)
    {
        if (!config('payconfigs.methods')["qris"][$code_method]) {
            return response()->json(["error" => "Payment method not found"], 404);
        }
        $now24 = new DateTime('now', new DateTimeZone('UTC'));
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
                        "expires_at" => str_replace(' ', 'T', $now24->add(new DateInterval('PT24H'))->format('Y-m-d H:i:s')) . 'Z',
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
                'price' => $amount
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
        $now24 = new DateTime('now', new DateTimeZone('UTC'));
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
                "expiration_date" => str_replace(' ', 'T', $now24->add(new DateInterval('PT24H'))->format('Y-m-d H:i:s')) . 'Z',
            ]
        );

        $payment->update(
            [
                'token_trx' => $createVA["id"],
                'pay_state' => "PENDING",
                'order_id' => $orderId,
                'price' => $amount
            ]
        );
        return ["payment" => $createVA, "status" => 201];
    }

    public function loadTrxData()
    {
        $now = new DateTime('now');
        $payments = Payment::where('pay_state', 'PENDING')->where('created_at', '<', $now->format('Y-m-d'))->get();
        foreach ($payments as $payment) {
            $datePayCreate = new DateTime($payment->ceated_at);
            $datePayCreate = $datePayCreate->add(new DateInterval('PT24H'));
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
                Payment::where($payment->id)->update(
                    [
                        'pay_state' => "EXPIRED"
                    ]
                );
            }
        }
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
            $event = $ticket->event()->first();
            $startTicket = new DateTime($ticket->start_date, new DateTimeZone('Asia/Jakarta'));
            $endTicket = new DateTime($ticket->end_date, new DateTimeZone('Asia/Jakarta'));
            if ($event->is_publish == 1 || $event->is_publish >= 3) {
                return ["error" => "This has not been published yet or is still in draft form", "code" => 403];
            }
            $purchases = $ticket->purchases()->where('user_id', Auth::user()->id)->get();
            if ((intval($ticket->max_purchase) - count($purchases)) < $value) {
                return ["error" => "Max purchases is " . $ticket->max_purchase . " / user", "code" => 403];
            }
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
        $customPrices = [];
        if ($req->custom_prices && count($customPriceTickets) > 0) {
            $customPrices = (array) $req->custom_prices;
            if (count($customPrices) == 0 || count($customPrices) != count($customPriceTickets)) {
                return ["error" => "Custom prices field is required for custom price ticket or count custom prices key not match with list of ticket id", "code" => 403];
            }
            foreach ($customPrices as $key => $value) {
                if (!array_key_exists($key, $customPriceTickets) || intval($value) < 10000) {
                    return ["error" => !array_key_exists($key, $customPriceTickets) ? "Custom price ticket id key not match with list of ticket_ids" : "Sorry, minimum transaction of one paid (custom_price field) ticket is IDR Rp. 10.000,-", "code" => 403];
                }
            }
        }
        return ["customPrices" => $customPrices];
    }

    private function visitDatesValidator($req, $dailyTickets, $now)
    {
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
                $ticket = Ticket::where('id', $key)->first();
                foreach ($value as $date) {
                    try {
                        $dateFormat = new DateTime($date, new DateTimeZone('Asia/Jakarta'));
                    } catch (\Throwable $th) {
                        return ["error" => "Invalid date format", "code" => 403];
                    }
                    if ($now->format('Y-m-d') > $dateFormat->format('Y-m-d')) {
                        return ["error" => "Visit date must be greater than date now", "code" => 403];
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
                    if ((intval($ticket->limitDaily()->first()->limit_quantity) - count($pchsTcDate)) < $dailyTickets[$key]) {
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
                if (count($value) < $seatNumberTickets[$key] || (array_key_exists($key, $dailyTickets) && (count($visitDates[$key]) * $dailyTickets[$key]) > count($value))) {
                    return ["error" => "Count of seat nummber must be same as total ticket have selected", "code" => 403];
                }
                if (array_key_exists($key, $dailyTickets)) {
                    for ($i = 0; $i < count($visitDates[$key]); $i++) {
                        $arrDup = [];
                        $indexSeatLoop = $i;
                        for ($j = 0; $j < $dailyTickets[$key]; $j++) {
                            array_key_exists($value[$indexSeatLoop], $arrDup) ? $arrDup[$value[$indexSeatLoop]]++ : $arrDup[$value[$indexSeatLoop]] = 1;
                            if ($arrDup[$value[$indexSeatLoop]] > 1) {
                                return ["error" => "You can't reserved same seat number in one ticket on same time / date", "code" => 403];
                            }
                            $indexSeatLoop += count($visitDates[$key]);
                        }
                    }
                } else if (count($value) != count(array_unique($value))) {
                    return ["error" => "You can't reserved same seat number in one ticket on same time / date", "code" => 403];
                }
                $ticket = Ticket::where('id', $key)->first();
                $ticketLimitation = $ticket->limitDaily()->first();
                $indexSeatNumDate = 0;
                for ($i = 0; $i < $seatNumberTickets[$key]; $i++) {
                    $hasPurchased = null;
                    if (array_key_exists($key, $visitDates)) {
                        for ($j = 0; $j < count($visitDates[$key]); $j++) {
                            if ($value[$indexSeatNumDate] <= 0 || $value[$indexSeatNumDate] > intval($ticketLimitation->limit_quantity)) {
                                return ["error" => "Seat number is only available beetwen 1 to " . $ticketLimitation->limit_quantity, "code" => 404];
                            }
                            $dateFormat = new DateTime($visitDates[$key][$j], new DateTimeZone('Asia/Jakarta'));
                            $hasPurchased = DB::table('purchases')
                                ->join('daily_tickets', 'purchases.id', '=', 'daily_tickets.purchase_id')
                                ->join('reserved_seats', 'purchases.id', '=', 'reserved_seats.pch_id')
                                ->where('purchases.ticket_id', '=', $key)
                                ->where('daily_tickets.visit_date', '=', $dateFormat->format('Y-m-d'))
                                ->where('reserved_seats.seat_number', '=', $value[$indexSeatNumDate])
                                ->get();
                            $indexSeatNumDate++;
                            if (count($hasPurchased) > 0) {
                                return ["error" => "This seat number has reserved. Please choose other seat number", "code" => 404];
                            }
                        }
                    } else {
                        if ($value[$i] <= 0 || $value[$i] > intval($ticket->quantity)) {
                            return ["error" => "Seat number is only available beetwen 1 to " . $ticket->quantity, "code" => 404];
                        }
                        $hasPurchased = DB::table('purchases')
                            ->join('reserved_seats', 'purchases.id', '=', 'reserved_seats.pch_id')
                            ->where('purchases.ticket_id', '=', $key)
                            ->where('reserved_seats.seat_number', '=', $value[$i])
                            ->get();
                        if (count($hasPurchased) > 0) {
                            return ["error" => "This seat number has reserved. Please choose other seat number", "code" => 404];
                        }
                    }
                }
            }
        }
        return ["seatNumbers" => $seatNumbers];
    }

    private function basicCreateData($req, $ticket_ids, $visitDates, $customPrices, $seatNumbers, $voucher, $payment, $remainingVoucher)
    {
        $totalPay = 0;
        $purchases = [];
        foreach ($ticket_ids as $key => $value) {
            $ticketObj = Ticket::where('id', $key);
            $indexVisitDates = 0;
            array_key_exists($key, $visitDates) ? $value *= count($visitDates[$key]) : '';
            for ($i = 0; $i < $value; $i++) {
                $amount = 0;
                $voucherCode = '-';
                $priceTicket = $ticketObj->first()->type_price == 3 ? $customPrices[$key] : $ticketObj->first()->price;
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
                    if ($indexVisitDates >= count($visitDates[$key])) {
                        $indexVisitDates = 0;
                    }
                    $visitDate = new DateTime($visitDates[$key][$indexVisitDates], new DateTimeZone('Asia/Jakarta'));
                    DailyTicket::create(
                        [
                            "purchase_id" => $pch->id,
                            "visit_date" => $visitDate->format('Y-m-d')
                        ]
                    );
                    $indexVisitDates++;
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
                return response()->json(["error" => "Serer error. Failed reach xendit server"], 500);
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
        $resValidate = $this->validationPurchase($req, true);
        if (array_key_exists("error", $resValidate)) {
            return response()->json(["error" => $resValidate["error"]], $resValidate["code"]);
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
        $user = Auth::user();
        RefundData::create(
            [
                "purchase_id" => $resValidate["purchase"]->id,
                "user_id" => $user->id,
                "message" => $req->message,
                "phone_number" => $req->phone_number,
                "account_number" => $req->account_number,
                "nominal" => $resValidate["purchase"]->amount,
                "ticket_name" => $resValidate["ticket"]->name,
                "event_name" => $resValidate["event"]->name
            ]
        );
        Mail::to('syaifudinramadhan@gmail.com')->send(
            new AdminRefundNotification(
                $user->name,
                $user->email,
                $resValidate["event"]->name,
                $resValidate["purchase"]->id,
                $resValidate["ticket"]->name,
                $resValidate["ticket"]->id,
                $req->message
            )
        );
        return response()->json(["message" => "Your refund requets have sent. Check you email, for view your refund status"], 201);
    }

    public function getRefunds()
    {
        $refundDatas = RefundData::all();
        foreach ($refundDatas as $refundData) {
            $refundData->user = $refundData->user()->first();
            $refundData->purchase = $refundData->purchase()->first();
            if ($refundData->purchase) {
                $refundData->ticket = $refundData->purchase->ticket()->first();
                $refundData->event = $refundData->ticket->event()->first();
            }
            $refundData->status = $refundData->purchase ? 'Un Approved' : 'Approved';
        }
        return response()->json(["refund_datas" => $refundDatas->groupBy('user_id')], 200);
    }

    public function getRefund($refundId)
    {
        $refundData = RefundData::where('id', $refundId)->first();
        if (!$refundData) {
            return response()->json(["error" => "Refund data not found"], 404);
        }
        $refundData->user = $refundData->user()->first();
        $refundData->purchase = $refundData->purchase()->first();
        if ($refundData->purchase) {
            $refundData->ticket = $refundData->purchase->ticket()->first();
            $refundData->event = $refundData->ticket->event()->first();
        }
        $refundData->status = $refundData->purchase ? 'Un Approved' : 'Approved';
        return response()->json(["refund_data" => $refundData], 200);
    }

    public function considerationRefund(Request $req, $refundId)
    {
        $refundData = RefundData::where('id', $refundId)->first();
        if (!$refundData) {
            return response()->json(["error" => "Refund data not found"], 404);
        }
        $purchase = $refundData->purchase()->first();
        if (!$purchase) {
            return response()->json(["error" => "Purchase data has removed or this request has approved"], 403);
        }
        $req->purchase_id = $purchase->id;
        $user = $refundData->user()->first();
        $resValidate = $this->validationPurchase($req, true, $user);
        if (array_key_exists("error", $resValidate)) {
            return response()->json(["error" => $resValidate["error"]], $resValidate["code"]);
        }
        if (!$req->approved) {
            Mail::to($user->email)->send(
                new UserRefundNotification(
                    'Un Approved / Rejected',
                    $resValidate["event"]->name,
                    $purchase->id,
                    $resValidate["ticket"]->name,
                    $resValidate["ticket"]->id,
                    $refundData->message
                )
            );
            RefundData::where('id', $refundData->id)->delete();
            return response()->json(["message" => "Refund data has removed"], 202);
        }
        Purchase::where('id', $purchase->id)->delete();
        Mail::to($user->email)->send(
            new UserRefundNotification(
                'Approved / Accepted',
                $resValidate["event"]->name,
                $purchase->id,
                $resValidate["ticket"]->name,
                $resValidate["ticket"]->id,
                $refundData->message
            )
        );
        return response()->json(["message" => "Refund data has apporoved"], 202);
    }

    private function removePayment($orderId)
    {
        $payment = Payment::where('order_id', $orderId);
        if ($payment->first()->pay_state != 'EXPIRED' && $payment->first()->pay_state != 'SUCCEEDED') {
            $purchases = $payment->first()->purchases()->get()->groupBy('ticket_id');
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
            $payment->update(
                [
                    'pay_state' => "EXPIRED"
                ]
            );
        }
    }

    private function getTrxEWallet($payment)
    {
        Xendit::setApiKey(env('XENDIT_API_READ'));
        $eWalletStatus = \Xendit\EWallets::getEWalletChargeStatus($payment->token_trx);
        if ($eWalletStatus["status"] == 'FAILED' || $eWalletStatus["status"] == 'VOIDED') {
            $this->removePayment($eWalletStatus['reference_id']);
        }
        return $eWalletStatus;
    }

    private function getTrxQris($payment)
    {
        $curl = curl_init();
        curl_setopt_array(
            $curl,
            [
                CURLOPT_URL => 'https://api.xendit.co/qr_codes/' . $payment->token_trx,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => "CURL_HTTP_VERSION_1_1",
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_HTTPHEADER => array(
                    'Content-Type: application/json',
                    'api-version: 2022-07-31',
                    'Authorization: ' . 'Basic ' . base64_encode(env('XENDIT_API_READ') . ':')
                ),
            ]
        );
        $response = curl_exec($curl);
        curl_close($curl);
        $response = json_decode($response);
        if ($response->status == 'INACTIVE') {
            $this->removePayment($response->reference_id);
        }
        return $response;
    }

    private function getTrxVirAccount($payment)
    {
        Xendit::setApiKey(env('XENDIT_API_READ'));
        $vaStatus = \Xendit\VirtualAccounts::retrieve($payment->token_trx);
        return $vaStatus;
    }

    private function getTrx($paymentData)
    {
        $payment = null;
        if (preg_match('/trx_va/i', $paymentData->order_id)) {
            $payment = $this->getTrxVirAccount($paymentData);
            $payment["status"] = $paymentData->pay_state;
        } else if (preg_match('/trx_ewallet/i', $paymentData->order_id)) {
            $payment = $this->getTrxEWallet($paymentData);
        } else if (preg_match('/trx_qris/i', $paymentData->order_id)) {
            $payment = $this->getTrxQris($paymentData);
        } else {
            $payment = $paymentData;
            $payment->status = $payment->pay_state;
        }
        Log::info($payment);
        return $payment;
    }

    // This function to purchase by id
    public function get(Request $req)
    {
        $user = Auth::user();
        $purchase = Purchase::where('id', $req->pch_id)->where('user_id', $user->id)->where('is_mine', true)->first();
        if (!$purchase) {
            return response()->json(["error" => "This purchase is not found"], 404);
        }
        $purchase->ticket = $purchase->ticket()->first();
        $purchase->ticket->event = $purchase->ticket->event()->first();
        $purchase->visitDate = $purchase->visitDate()->first();
        $purchase->seat_number = $purchase->seatNumber()->first();
        $payData = null;
        if ($purchase->payment()->first()->pay_state == 'PENDING') {
            $payData = $this->getTrx($purchase->payment()->first());
        } else {
            $payData = $purchase->payment()->first();
            $payData->status = $payData->pay_state;
        }
        return response()->json(
            [
                "purchase" => $purchase,
                "payment" => $payData,
                "qr_str" => $purchase->id . "~^|-|^~" . $user->id,
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
            $purchases = $payData->purchases()->where('user_id', $user->id)->where('is_mine', true)->get();
            foreach ($purchases as $purchase) {
                $purchase->ticket = $purchase->ticket()->first();
                $purchase->ticket->event = $purchase->ticket->event()->first();
                $purchase->visit_date = $purchase->visitDate()->first();
                $purchase->seat_number = $purchase->seatNumber()->first();
                $purchase->qr_str = $purchase->id . "~^|-|^~" . $user->id;
                $purchase->event_id = $purchase->ticket->event->id;
                $purchase->event_name = $purchase->ticket->event->name;
            }
            $trx = null;
            if ($payData->pay_state == 'PENDING') {
                $trx = $this->getTrx($payData);
            } else {
                $trx = $payData;
                $trx->status = $trx->pay_state;
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
}
