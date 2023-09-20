<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use App\Models\PkgPayment;
use App\Models\Payment;
use App\Models\Event;
use DateTime;
use DateTimeZone;

class WebhookCtrl extends Controller
{
    private function removePayment($orderId){
        $payment = Payment::where('order_id', $orderId);
        if($payment->first()->pay_state != 'EXPIRED' && $payment->first()->pay_state != 'SUCCEEDED'){
            $purchases = $payment->first()->purchases()->get()->groupBy('ticket_id');
            foreach ($purchases as $key => $value) {
                Ticket::where('id', $key)->where('type_price', '!=', 1)->update([
                    "quantity" => intval($value[0]->ticket()->first()->quantity) + count($value)
                ]);
            }
            $payment->update([
                'pay_state' => "EXPIRED"
            ]);
        }
    }
    public function handleWebhookRedirect(Request $req){
        // $pkgPay = null;
        if($req->id){
            // Only for VA trx
            // $pkgPay = PkgPayment::where('token_trx', $req->id);
            // if(!$pkgPay->first()){
            //     Payment::where('token_trx', $req->id)->update([
            //         "pay_state" => "SUCCEEDED"
            //     ]);
            // }else{
            //     $pkgPay->update([
            //         "pay_state" => "SUCCEEDED"
            //     ]);
            // }
            Payment::where('token_trx', $req->id)->update([
                "pay_state" => "SUCCEEDED"
            ]);
        }else{
            // $pkgPay = PkgPayment::where('order_id', $req->data["reference_id"]);
            // if(!$pkgPay->first()){
            //     Payment::where('order_id', $req->data["reference_id"])->update([
            //         "pay_state" => $req->data["status"]
            //     ]);
            // }else{
            //     $pkgPay->update([
            //         "pay_state" => $req->data["status"]
            //     ]);
            // }
            Log::info($req->data);
            if($req->data["status"] == 'SUCCEEDED'){
                Payment::where('order_id', $req->data['reference_id'])->update([
                    "pay_state" => $req->data["status"]
                ]);
            }else{
                if($req->event == 'ewallet.capture' && ($req->data["status"] == "FAILED" || $req->data["status"] == "VOIDED")){
                    $this->removePayment($req->data["reference_id"]);
                }else if($req->event == 'qr.payment' && new DateTime($req->data["expires_at"], new DateTimeZone('UTC')) < new DateTime('now', new DateTimeZone('UTC'))){
                    $this->removePayment($req->data["reference_id"]);
                }
            }
        }
        // if($pkgPay->first()){
        //     Event::where('id', $pkgPay->first()->event_id)->update([
        //         "is_publish" => 2
        //     ]);
        // }
        return response()->json(["message" => "success"], 200);
    }
}
