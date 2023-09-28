<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use App\Models\Checkin;
use App\Models\Purchase;
use App\Models\Ticket;
use App\Models\Event;
use DateTime;
use DateTimeZone;

class CheckinCtrl extends Controller
{
    public function createByUser(Request $req){
        $validator = Validator::make($req->all(), [
            "event_id" => "required|string"
        ]);
        if($validator->fails()){
            return response()->json($validator->errors(), 403);
        }
        $event = Event::where('id', $req->event_id)->first();
        if(!$event){
            return response()->json(["error" => "Event By ID not found"], 404);
        }
        $purchases = Auth::user()->purchases()->where('created_at', '>=', $event->created_at)->get();
        $checkined = 0;
        $unPaid = 0;
        foreach ($purchases as $purchase) {
            $checkin = Checkin::where('pch_id', $purchase->id)->first();
            if($checkin){
                $checkined += 1;
            }else if($purchase->ticket()->first()->event_id == $event->id && ($purchase->amount == 0 || $purchase->payment()->first()->pay_state == 'SUCCEEDED')){
                $checkin = Checkin::create([
                    'pch_id' => $purchase->id,
                    'event_id' => $event->id,
                    'status' => 1
                ]);
                return response()->json([
                    "checkin_on" => $checkin->created_at,
                    "event" => $event->name,
                    "email" => Auth::user()->email
                ], 201);
            }else if($purchase->ticket()->first()->event_id == $event->id){
                $unPaid += 1;
            }
        }
        if($unPaid > 0){
            return response()->json(["error" => "You have purchased this event ticket, but you haven't yet procressed the transaction"], 403);
        }
        if($checkined > 0){
            return response()->json(["error" => "You have checkined to this event"], 403);
        }
        return response()->json(["error" => "You have not buy a ticket of this event"], 404);
    }

    public function createByOrg(Request $req){
        $validator = Validator::make($req->all(), [
            "purchase_id" => "required|string"
        ]);
        if($validator->fails()){
            return response()->json($validator->errors(), 403);
        }
        $purchase = Purchase::where('id', $req->purchase_id)->first();
        if(!$purchase){
            return response()->json(["error" => "Purchase data not found in this event"], 404);
        }
        if($purchase->amount > 0 && $purchase->payment()->first()->pay_state != 'SUCCEEDED'){
            return response()->json(["error" => "Successfull transaction not found in this purchase"], 404);
        }
        $checkin = Checkin::where('pch_id', $purchase->id)->first();
        if($checkin){
           return response()->json(["error" => "This purchase had checkined"], 403); 
        }
        $checkin = Checkin::create([
            'pch_id' => $purchase->id,
            'event_id' => $req->event->id,
            'status' => 1
        ]);
        return response()->json([
            "checkin" => $checkin
        ], 201);
    }

    public function delete(Request $req){
        $deleted = Checkin::where('id', $req->checkin_id)->delete();
        return response()->json(["deleted" => $deleted], $deleted == 1 ? 202 : 404);
    }

    public function get(Request $req){
        $checkin = Checkin::where('id', $req->checkin_id)->first();
        if(!$checkin){
            return response()->json(["error" => "Checkin data not found"], 404);
        }
        $checkin->purchase = $checkin->pch()->first();
        $checkin->purchase->user = $checkin->purchase->user()->first();
        $checkin->purchase->ticket = $checkin->purchase->ticket()->first();
        return response()->json(["data" => $checkin], 200);
    }

    public function checkins(Request $req){
        $checkins = $req->event->checkins()->get();
        if(count($checkins) == 0){
            return response()->json(["error" => "Checkin data not found"], 404);
        }
        foreach ($checkins as $checkin) {
            $checkin->purchase = $checkin->pch()->first();
            $checkin->purchase->user = $checkin->purchase->user()->first();
            $checkin->purchase->ticket = $checkin->purchase->ticket()->first();
        }
        return response()->json(["data" => $checkins], 200);
    }
}
