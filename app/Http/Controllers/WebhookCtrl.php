<?php

namespace App\Http\Controllers;

use App\Mail\OrganizerTicketNotiffication;
use App\Models\DailyTicket;
use App\Models\DisburstmentRefund;
use App\Models\DisburstmentWd;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use App\Models\PkgPayment;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\RefundData;
use App\Models\ReservedSeat;
use App\Models\Ticket;
use App\Models\Withdraw;
use DateTime;
use DateTimeZone;
use Illuminate\Support\Facades\Mail;

class WebhookCtrl extends Controller
{
    private function removePayment($orderId)
    {
        $payment = Payment::where('order_id', $orderId);
        if ($payment->first()->pay_state != 'EXPIRED' && $payment->first()->pay_state != 'SUCCEEDED') {
            $purchases = $payment->first()->purchases()->get()->groupBy('ticket_id');
            foreach ($purchases as $key => $value) {
                Ticket::where('id', $key)->where('type_price', '!=', 1)->update([
                    "quantity" => intval($value[0]->ticket()->first()->quantity) + count($value)
                ]);
                foreach ($value as $pch) {
                    if ($pch->amount != 0) {
                        DailyTicket::where('purchase_id', $pch->id)->delete();
                        ReservedSeat::where('pch_id', $pch->id)->delete();
                        Purchase::where('id', $pch->id)->update(["code" => '-']);
                    }
                }
            }
            $payment->update([
                'pay_state' => "EXPIRED"
            ]);
        }
    }
    public function handleWebhookRedirect(Request $req)
    {
        // $pkgPay = null;
        if ($req->id) {
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
            $payment = Payment::where('token_trx', $req->id);
            $payment->update([
                "pay_state" => "SUCCEEDED"
            ]);
            $payment = $payment->first();
            if($payment){
                Mail::to($payment->user()->first()->email)->send(new OrganizerTicketNotiffication($payment->id));
            }
        } else {
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
            // Log::info($req->data);
            if ($req->data["status"] == 'SUCCEEDED') {
                $payment = Payment::where('order_id', $req->data['reference_id']);
                $payment->update([
                    "pay_state" => $req->data["status"]
                ]);
                $payment = $payment->first();
                if($payment){
                    Mail::to($payment->user()->first()->email)->send(new OrganizerTicketNotiffication($payment->id));
                }
            } else {
                if ($req->event == 'ewallet.capture' && ($req->data["status"] == "FAILED" || $req->data["status"] == "VOIDED")) {
                    $this->removePayment($req->data["reference_id"]);
                } else if ($req->event == 'qr.payment' && new DateTime($req->data["expires_at"], new DateTimeZone('Asia/Jakarta')) < new DateTime('now', new DateTimeZone('Asia/Jakarta'))) {
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

    function receiveValidationRefund(Request $req)
    {
        $disburstment = DisburstmentRefund::where('disburstment_id', $req->id)->first();
        if ($disburstment) {
            $refundIds = explode('~^&**&^~', $disburstment->str_refund_ids);
            if ($req->status === "FAILED") {
                foreach ($refundIds as $refundId) {
                    if ($refundId !== "") {
                        RefundData::where('id', intval($refundId))->update([
                            "approve_admin" => false
                        ]);
                    }
                }
            } else if ($req->status === "COMPLETED") { {
                    foreach ($refundIds as $refundId) {
                        if ($refundId !== "") {
                            $refund = RefundData::where('id', intval($refundId))->first();
                            Purchase::where('id', $refund->purchase_id)->delete();
                            RefundData::where('id', $refund->id)->update([
                                "finish" => true
                            ]);
                        }
                    }
                }
            }
        } else {
            $disburstment = DisburstmentWd::where('disburstment_id', $req->id)->first();
            if ($req->status === "COMPLETED") {
                Withdraw::where('id', $disburstment->withdraw_id)->update(['finish' => true]);
            }
        }
        return response()->json(["data" => $disburstment], 200);
    }
}
