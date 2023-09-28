<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use App\Models\Organization;
use App\Models\Withdraw;
use App\Models\Event;
use App\Models\Purchase;
use App\Models\BillAccount;
use App\Models\Otp;
use App\Models\Ticket;
use DateTime;
use DateTimeZone;
use DateInterval;

class WithdrawCtrl extends Controller
{
    //management bill account
    public function getBanksCode(){
        return response()->json(["banks" => config('banks')], 200);
    }

    public function createAccount(Request $req){
        $validator = Validator::make($req->all(), [
            'bank_name' => 'required|string',
            'acc_number' => 'required|string',
        ]);
        if($validator->fails()){
            return response()->json($validator->errors(), 403);
        }
        if(!array_key_exists($req->bank_name, config('banks'))){
            $req->bank_name = '---';
        };
        $data = BillAccount::create([
            'org_id' => $req->org->id,
            'bank_name' => $req->bank_name,
            'acc_number' => $req->acc_number,
            'status' => 0,
            'deleted' => 0
        ]);
        $auth = new Authenticate();
        $msg = $auth->generateOtp(Auth::user()->email, false, [
            'code_bank' => $data->bank_name,
            'acc_number' => $data->acc_number
        ]);
        $data->icon = '/storage/images/bank-icons/'.$data->bank_name.'.png';
        $data->bank_name = config('banks')[$data->bank_name];
        return response()->json([
            "data" => $data,
            "message" => $msg["message"]
        ], 201);
    }

    public function deleteAccount(Request $req){
        $validator = Validator::make($req->all(), [
            "bank_acc_id" => "required|string"
        ]);
        if($validator->fails()){
            return response()->json($validator->errors(), 403);
        }
        $bankAcc = BillAccount::where('id', $req->bank_acc_id)->where('org_id', $req->org->id);
        if(!$bankAcc->first()){
            return response()->json(["error" => "Bank account data not found"], 404);
        }
        // $deleted = $bankAcc->update(['deleted' => 1]);
        foreach ($bankAcc->first()->withdraws()->get() as $wd) {
            if($wd->status != 1){
                $wd->event()->update([
                    "is_publish" => intval($wd->event()->first()->is_publish) - 3
                ]);
            }
        }
        $deleted = $bankAcc->delete();
        return response()->json(["deleted" => $deleted], 202);
    }

    public function verifyAccount(Request $req){
        $validator = Validator::make($req->all(), [
            'bank_acc_id' => 'required|string',
            'otp' => 'required|string'
        ]);
        if($validator->fails()){
            return response()->json($validator->errors(), 403);
        }
        $bankAcc = BillAccount::where('id', $req->bank_acc_id)->where('org_id', $req->org->id);
        if(!$bankAcc->first()){
            return response()->json(["error" => "Bank account data not found"], 404);
        }
        $user = Auth::user();
        $otp = $user->otp()->first();
        if(!$otp){
            return response()->json(["error" => "OTP not found for this user"], 404);
        }
        if($otp->otp_code != $req->otp){
            return response()->json(["error" => "OTP code is not valid"], 403);
        }
        $bankAcc->update(["status" => 1]);
        return response()->json(["message" => "verification successfull"], 202);
    }

    public function banks(Request $req){
        $bankAccs = $req->org->billAccs()->get();
        if(count($bankAccs) == 0){
            return response()->json(["error" => "Bank Account data not found"], 404);
        }
        foreach ($bankAccs as $bankAcc) {
            $bankAcc->icon = '/storage/images/bank-icons/'.$bankAcc->bank_name.'.png';
            $bankAcc->bank_name = config('banks')[$bankAcc->bank_name];
        }
        return response()->json(["data" => $bankAccs], 200);
    }
    // maanagement withdraw
    public function createWd(Request $req){
        $validator = Validator::make($req->all(), [
            "event_id" => "required|string",
            "bank_acc_id" => "required|string"
        ]);
        if($validator->fails()){
            return response()->json($validator->errors(), 403);
        }
        $now = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
        $event = Event::where('id', $req->event_id)->where('org_id', $req->org->id)->where('is_publish', "<", 3)->where('end_date', "<", $now->format('Y-m-d'));
        $eventData = $event->first();
        if(!$eventData){
            return response()->json(["error" => "Event data not found or cannot add to withdraw"], 404);
        }
        $bankAcc = BillAccount::where('id', $req->bank_acc_id)->where('org_id', $req->org->id)->where('status', 1)->first();
        if(!$bankAcc){
            return response()->json(["error" => "Bank account data not found"], 404);
        }
        $amount = 0;
        $tickets = Ticket::where('event_id', $eventData->id)->where('type_price', '!=', 1)->get();
        foreach ($tickets as $ticket) {
            foreach ($ticket->purchases()->get() as $purchase) {
                if($purchase->payment()->first()->pay_state == 'SUCCEEDED'){
                    $amount += (intval($purchase->amount) - (intval($purchase->amount)*(floatval(config('agendakota.commission'))/100)));
                }
            }
        }
        $amount -= intval(config('agendakota.profit_plus'));
        $wd = Withdraw::create([
            'event_id' => $eventData->id,
            'org_id' => $req->org->id,
            'bill_acc_id' => $bankAcc->id,
            'nominal' => $amount,
            'status' => 0
        ]);
        $event->update([
            "is_publish" => intval($eventData->is_publish) + 3
        ]);
        return response()->json(["data" => $wd, "event" => $eventData, "bank" => $bankAcc], 201);
    }

    public function deleteWd(Request $req, $isAdmin = false){
        $validator = Validator::make($req->all(), [
            "wd_id" => "required|string"
        ]);
        if($validator->fails()){
            return response()->json($validator->errors(), 403);
        }
        $wdObj = null;
        if($isAdmin){
            $wdObj = Withdraw::where('id', $req->wd_id);
        }else{
            $wdObj = Withdraw::where('id', $req->wd_id)->where('org_id', $req->org->id);
        }
        $wdData = $wdObj->first();
        if(!$wdData){
            return response()->json(["error" => "Withdraw data not found"], 404);
        }
        if($wdData->status != 1){
            $wdData->event()->update([
                "is_publish" => intval($wdData->event()->first()->is_publish) - 3
            ]);
        }
        $deleted = $wdObj->delete();
        return response()->json(["deleted" => $deleted], 202);
    }

    public function getWd(Request $req, $isAdmin = false){
        $validator = Validator::make($req->all(), [
            "wd_id" => "required|string"
        ]);
        if($validator->fails()){
            return response()->json($validator->errors(), 403);
        }
        $wd = null;
        if($isAdmin){
            $wd = Withdraw::where('id', $req->wd_id)->first();
        }else{
            $wd = Withdraw::where('id', $req->id)->where('org_id', $req->org->id)->first();
        }
        if(!$wd){
            return response()->json(["error" => "Withdraw data not found"], 404);
        }
        $wd->event = $wd->event()->first();
        $wd->organization = $wd->organization()->first();
        $wd->bank = $wd->billAcc()->first();
        return response()->json(["data" => $wd], 200);
    }

    public function wds(Request $req, $isAdmin = false){
        $wds = null;
        if($isAdmin){
            $wds = Withdraw::all();
        }else{
            $wds = Withdraw::where('org_id', $req->org->id)->get();
        }
        if(count($wds) == 0){
            return response()->json(["error" => "Withdraw data's not found"], 404);
        }
        foreach ($wds as $wd) {
            $wd->event = $wd->event()->first();
            $wd->organization = $wd->organization()->first();
            $wd->bank = $wd->billAcc()->first();
        }
        return response()->json(["data" => $wds], 200);
    }

    public function availableForWd(Request $req){
        $events = [];
        $totalAmount = 0;
        $now = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
        foreach (Event::where('org_id', $req->org->id)->where('is_publish', '<', 3)->where('end_date', '<', $now->format('Y-m-d'))->get() as $event) {
            $tickets = Ticket::where('event_id', $event->id)->where('type_price', '!=', 1)->get();
            $amount = 0;
            foreach ($tickets as $ticket) {
                foreach ($ticket->purchases()->get() as $purchase) {
                    if($purchase->payment()->first()->pay_state == 'SUCCEEDED'){
                        $amount += (intval($purchase->amount) - (intval($purchase->amount)*(floatval(config('agendakota.commission'))/100)));
                    }
                }
            }
            $amount -= intval(config('agendakota.profit_plus'));
            $totalAmount += $amount;
            $events[] = [
                "event" => $event,
                "amount" => $amount
            ];
        }
        return response()->json(["data" => $events, "total_amount" => $totalAmount], 200);
    }

    public function changeStateWd(Request $req){
        // For Admin privillege
        $validator = Validator::make($req->all(), [
            "wd_id" => "required|string",
            "state" => "required|number"
        ]);
        if($validator->fails()){
            return response()->json($validator->errors(), 403);
        }
        // Status number :
        // 1 => Accept
        // 0 => Pending
        // -1 => Reject
        if($req->state != 1 && $req->state != 0 && $req->state != -1){
            return response()->json(["error" => "State code is not recognized"], 403);
        }
        $wdObj = Withdraw::where('id', $req->wd_id);
        if(!$wdObj->first()){
            return response()->json(["error" => "Data withdraw not found", 404]);
        }
        if($req->state == 1){
            $wdObj->first()->event()->update([
                "is_publish" => 3
            ]);
        }
        $updated = $wdObj->update(["status" => $req->state]);
        return response()->json(["updated" => $updated], 202);
    }
}
