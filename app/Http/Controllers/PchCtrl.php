<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\Purchase;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\Event;
use App\Models\EventSession;
use App\Models\Voucher;
use Xendit\Xendit;
use DateTime;
use DateTimeZone;
use DateInterval;

class PchCtrl extends Controller
{
    private function setTrxEWallet($payId, $code_method, $amount){
        $methods = config('payconfigs.methods');
        if(!$methods["e-wallet"][$code_method]){
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
        if($code_method == "014"){
            if($mobileNumber == null){
                return ["error" => "Mobile number is required if you choose the OVO method", "status" => 402];
            }
            $params += [
                'channel_properties' => [
                    'mobile_number' => '+62'.substr($mobileNumber, 2),
                ],
            ];
        }else if($code_method == "015"){
            if($cashtag == null){
                return ["error" => '$'."Cashtag is required if you choose the Jenius Pay method", "status" => 402];
            }
            $cashtag = preg_replace('/[^a-zA-Z0-9 ]/m', '', $cashtag);
            $params += [
                'channel_properties' => [
                    'cashtag' => '$'.$cashtag,
                ],
            ];
        }else{
            $params += [
                'channel_properties' => [
                    // NOTE : Replace this route with page event mng ReactJS
                    'success_redirect_url' => route('pkg.payment.redirect'),
                ],
            ];
        }
        // dd($params);
        $createEWalletCharge = \Xendit\EWallets::createEWalletCharge($params);
        Payment::where('id', $payId)->update([
            'token_trx' => $createEWalletCharge["id"],
            'pay_state' => $createEWalletCharge["status"],
            'order_id' => $orderId,
            'price' => $amount
        ]);
        return ["payment" => $createEWalletCharge, "status" => 201];
    }

    private function setTrxQris($payId, $code_method, $amount){
        if(!config('payconfigs.methods')["qris"][$code_method]){
            return response()->json(["error" => "Payment method not found"], 404);
        }
        $now24 = new DateTime('now', new DateTimeZone('UTC'));
        $orderId = uniqid("trx_qris", true);
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => 'https://api.xendit.co/qr_codes',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => "CURL_HTTP_VERSION_1_1",
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode([
                "reference_id" => $orderId,
                "type" => "DYNAMIC",
                "currency" => "IDR",
                "amount" => $amount,
                "channel_code" => config('payconfigs.methods')["qris"][$code_method][0],
                "expires_at" => str_replace(' ','T',$now24->add(new DateInterval('PT24H'))->format('Y-m-d H:i:s')).'Z',
            ]),
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'api-version: 2022-07-31',
                'Authorization: '.'Basic '.base64_encode(env('XENDIT_API_WRITE').':')
            ),
        ]);
        $response = curl_exec($curl);
        curl_close($curl);
        $response = json_decode($response);
        Payment::where('id', $payId)->update([
            'token_trx' => $response->id,
            'pay_state' => "PENDING",
            'order_id' => $orderId,
            'price' => $amount
        ]);
        return ["payment" => $response, "status" => 201];
    }

    private function setTrxVirAccount($payId, $code_method, $amount){
        $methods = config('payconfigs.methods');
        if(!$methods["VA"][$code_method]){
            return response()->json(["error" => "Payment method not found"], 404);
        }
        $payment = Payment::where('id', $payId);
        $now24 = new DateTime('now', new DateTimeZone('UTC'));
        $orderId = uniqid("trx_va", true);
        Xendit::setApiKey(env('XENDIT_API_WRITE'));
        $createVA = \Xendit\VirtualAccounts::create([
            "external_id" => $orderId,
            "bank_code" => $methods["VA"][$code_method][0],
            "name" => $payment->first()
                        ->purchases()->get()[0]
                        ->ticket()->first()
                        ->session()->first()
                        ->event()->first()
                        ->name,
            "is_single_use" => true,
            "is_closed" => true,
            "expected_amount" => $amount,
            "expiration_date"=> str_replace(' ','T',$now24->add(new DateInterval('PT24H'))->format('Y-m-d H:i:s')).'Z',
        ]);

        $payment->update([
            'token_trx' => $createVA["id"],
            'pay_state' => "PENDING",
            'order_id' => $orderId,
            'price' => $amount
        ]);
        return ["payment" => $createVA, "status" => 201];
    }

    public function loadTrxData(){
        $now = new DateTime('now');
        $payments = Payment::where('pay_state', 'PENDING')->where('created_at', '<', $now->format('Y-m-d'))->get();
        foreach ($payments as $payment) {
            $datePayCreate = new DateTime($payment->ceated_at);
            $datePayCreate = $datePayCreate->add(new DateInterval('PT24H'));
            if($datePayCreate < $now){
                $purchases = $payment->purchases()->get()->groupBy('ticket_id');
                foreach ($purchases as $key => $value) {
                        Ticket::where('id', $key)->where('type_price', '!=', 1)->update([
                            'quantity' => intval($value[0]->ticket()->first()->quantity) + count($value)
                        ]);
                }
                Payment::where($payment->id)->update([
                    'pay_state' => "EXPIRED"
                ]);
            }
        }
    }

    public function create(Request $req){
        $this->loadTrxData();
        $validator = Validator::make($req->all(), [
            'ticket_ids' => 'required',
        ]);
        if($validator->fails()){
            return response()->json(["error" => "Please select one ticket or more for doing a transaction"], 403);
        }
        if((intval($req->pay_method) == 14 && !$req->mobile_number) || (intval($req->pay_method) == 15 && !$req->cashtag)){
            return response()->json(["error" => "mobile number is required for pay method with OVO or $"."cashtag is required for pay method with JeniusPay"], 403);
        }
        $voucher = Voucher::where('code', $req->voucher_code)->first();
        if($req->voucher_code && !$voucher){
            return response()->json(["error" => "Voucher code not found"], 404);
        }
        if($req->voucher_code){
            $now = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
            $start = new DateTime($voucher->start, new DateTimeZone('Asia/Jakarta'));
            $end = new DateTime($voucher->end, new DateTimeZone('Asia/Jakarta'));
            if($voucher->quantity == 0 || $now < $start || $now > $end){
                return response()->json(["error" => "This voucher is no longer available"], 404);
            }
        }
        $ticket_ids = [];
        foreach ($req->ticket_ids as $ticket_id) {
            if(!array_key_exists($ticket_id, $ticket_ids)){
                $ticket_ids[$ticket_id] = 1;
            }else{
                $ticket_ids[$ticket_id] += 1;
            }
        }
        $customPriceTickets = [];
        foreach ($ticket_ids as $key => $value) {
            $ticket = Ticket::where('id', $key)->where('deleted', 0)->first();
            if(!$ticket){
                return response()->json(["error" => "Ticket is not found"], 404);
            }
            if($ticket->type_price == 3 && !$req->custom_prices){
                return response()->json(["error" => "Custom prices field is required for custom price ticket"], 403);
            }
            if($ticket->type_price != 1 && !$req->pay_method){
                return response()->json(["error" => "pay_method code is required"], 403);
            }
            if($ticket->quantity < $value){
                return response()->json(["error" => "Only ".$ticket->quantity." tickets left for your selected id"], 403);
            }
            $pubState = $ticket->session()->first()->event()->first()->is_publish;
            if($pubState != 2 && $pubState != 1){
                return response()->json(["error" => "This event has not yet been published"], 403);
            }
            if($ticket->type_price == 3){
                $customPriceTickets[$key] = $value;
            }
        }
        $customPrices = null;
        if($req->custom_prices && count($customPriceTickets) > 0){
            $customPrices = (array) $req->custom_prices;
            if(count($customPrices) == 0){
                return response()->json(["error" => "Custom prices field is required for custom price ticket"], 403);
            }
            foreach ($customPrices as $key => $value) {
                if(!array_key_exists($key, $customPriceTickets) || intval($value) < 10000){
                    return response()->json(["error" => !array_key_exists($key, $customPriceTickets) ? "Custom price ticket id key not match with list of ticket_ids" : "Sorry, minimum transaction of one paid (custom_price field) ticket is IDR Rp. 10.000,-"], 403);
                }
            }
        }
        $totalPay = 0;
        $purchases = [];
        // create trx dummy and get the ID
        $payment = Payment::create([
            'user_id' => Auth::user()->id,
            'token_trx' => '-',
            'pay_state' => 'PENDING',
            'order_id' => '-',
            'price' => 0
        ]);
        foreach ($ticket_ids as $key => $value) {
            $ticketObj = Ticket::where('id', $key);
            for ($i=0; $i < $value; $i++) {
                $amount = 0; 
                $voucherCode = '-';
                $priceTicket = $ticketObj->first()->type_price == 3 ? $customPrices[$key] : $ticketObj->first()->price;
                if($req->voucher_code){
                    if($voucher->event_id == $ticketObj->first()->event_id){
                        $amount = (intval($priceTicket) - (intval($priceTicket)*(intval($voucher->discount)/100)));
                        $voucherCode = $req->voucher_code;
                    }else{
                        $amount = intval($priceTicket);
                    }
                }else{
                    $amount = intval($priceTicket);
                }
                $totalPay += $amount;
                $purchases[] = Purchase::create([
                    'user_id' => Auth::user()->id,
                    'pay_id' => $payment->id,
                    'ticket_id' => $key,
                    'amount' => $amount,
                    'code' => $voucherCode
                ]);
            }
            // update quantity ticket
            $ticketObj->update([
                "quantity" => $ticketObj->first()->quantity - $value
            ]);
        }
        if($totalPay < 10000 && $totalPay > 0){
            foreach ($ticket_ids as $key => $value) {
                Ticket::where('id', $key)->update([
                    "quantity" => $ticketObj->first()->quantity + $value
                ]);
            }
            Purchase::whare('pay_id', $payment->id)->delete();
            Payment::where('id', $payment->id)->delete();
            return response()->json(["error" => "Sorry, minimal transaction (for non-free ticket) is IDR Rp. 10.000,-. Please remove the voucher code first"], 403);
        }
        // change trx data
        $paymentXendit = null;
        if($totalPay == 0){
            $orderId = uniqid('trx_free', true);
            Payment::where('id', $payment->id)->update([
                'token_trx' => '-',
                'pay_state' => "SUCCEEDED",
                'order_id' => $orderId,
                'price' => 0
            ]);
            $paymentXendit["payment"] = Payment::where('id', $payment->id)->first();
        }else{
            if(intval($req->pay_method) >= 11 && intval($req->pay_method) <= 15){
                $paymentXendit = $this->setTrxEWallet($payment->id, $req->pay_method, $totalPay);
            }else if(intval($req->pay_method) >= 21 && intval($req->pay_method) <= 22){
                $paymentXendit = $this->setTrxQris($payment->id, $req->pay_method, $totalPay);
            }else if(intval($req->pay_method) >= 31 && intval($req->pay_method) <= 41){
                $paymentXendit = $this->setTrxVirAccount($payment->id, $req->pay_method, $totalPay);
            }else{
                $paymentXendit = $this->setTrxVirAccount($payment->id, $req->pay_method, $totalPay);
            }
        }
        return response()->json([
            "payment" => $paymentXendit["payment"],
            "purchases" => $purchases
        ], 201);
    }

    private function removePayment($orderId){
        $payment = Payment::where('order_id', $orderId);
        if($payment->first()->pay_state != 'EXPIRED' && $payment->first()->pay_state != 'SUCCEEDED'){
            $purchases = $payment->first()->purchases()->get()->groupBy('ticket_id');
            foreach ($purchases as $key => $value) {
                Ticket::where('id', $key)->where('type_price', '!=', 1)->update([
                    'quantity' => intval($value[0]->ticket()->first()->quantity) + count($value)
                ]);
            }
            $payment->update([
                'pay_state' => "EXPIRED"
            ]);
        }
    }

    private function getTrxEWallet($payment){
        Xendit::setApiKey(env('XENDIT_API_READ'));
        $eWalletStatus = \Xendit\EWallets::getEWalletChargeStatus($payment->token_trx);
        if($eWalletStatus["status"] == 'FAILED' || $eWalletStatus["status"] == 'VOIDED'){
            $this->removePayment($eWalletStatus['reference_id']);
        }
        return $eWalletStatus;
    }

    private function getTrxQris($payment){
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => 'https://api.xendit.co/qr_codes/'.$payment->token_trx,
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
                'Authorization: '.'Basic '.base64_encode(env('XENDIT_API_READ').':')
            ),
        ]);
        $response = curl_exec($curl);
        curl_close($curl);
        $response = json_decode($response);
        if($response->status == 'INACTIVE'){
            $this->removePayment($response->reference_id);
        }
        return $response;
    }

    private function getTrxVirAccount($payment){
        Xendit::setApiKey(env('XENDIT_API_READ'));
        $vaStatus = \Xendit\VirtualAccounts::retrieve($payment->token_trx);
        return $vaStatus;
    }

    private function getTrx($paymentData){
        $payment = null;
        if(preg_match('/trx_va/i', $paymentData->order_id)){
            $payment = $this->getTrxVirAccount($paymentData);
            $payment["status"] = $paymentData->pay_state;
        }else if(preg_match('/trx_ewallet/i', $paymentData->order_id)){
            $payment = $this->getTrxEWallet($paymentData);
        }else if(preg_match('/trx_qris/i', $paymentData->order_id)){
            $payment = $this->getTrxQris($paymentData);
        }else{
            $payment = $paymentData;
            $payment->status = $payment->pay_state;
        }
        Log::info($payment);
        return $payment;
    }

    // This function to purchase by id
    public function get(Request $req){
        $purchase = Purchase::where('id', $req->pch_id)->first();
        if(!$purchase){
            return response()->json(["error" => "This purchase is not found"], 404);
        }
        $purchase->ticket = $purchase->ticket()->first();
        $purchase->ticket->start_rundown = $purchase->ticket->session()->first()->startRundown()->first();
        $purchase->ticket->end_rundown = $purchase->ticket->session()->first()->endRundown()->first();
        $purchase->ticket->event = $purchase->ticket->session()->first()->event()->first();
        return response()->json([
            "purchase" => $purchase,
            "payment" => $this->getTrx($purchase->payment()->first())
        ], 200);
    }

    // thid function to get purchases by trx id
    public function purchases(Request $req){
        $paysData = Payment::where('user_id', Auth::user()->id)->get();
        if(count($paysData) == 0){
            return response()->json(["error" => "Payment data not found"], 404);
        }
        $payments = [];
        foreach ($paysData as $payData) {
            $purchases = $payData->purchases()->get();
            foreach ($purchases as $purchase) {
                $purchase->ticket = $purchase->ticket()->first();
                $purchase->ticket->start_rundown = $purchase->ticket->session()->first()->startRundown()->first();
                $purchase->ticket->end_rundown = $purchase->ticket->session()->first()->endRundown()->first();
                $purchase->ticket->event = $purchase->ticket->session()->first()->event()->first();
            }
            $trx = null;
            if($payData->pay_state == 'PENDING'){
                $trx = $this->getTrx($payData);
            }else{
                $trx = $payData;
                $trx->status = $trx->pay_state;
            }
            $payments[] = [
                "payment" => $trx,
                "purchases" => $purchases
            ];
        }
        return response()->json([
            "transactions" => $payments
        ], 200);
    }
}
