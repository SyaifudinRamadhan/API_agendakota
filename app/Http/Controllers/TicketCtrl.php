<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Facades\Support\Validator;
use App\Models\Event;
use App\Models\EventSession;
use App\Models\Ticket;
use DateTime;
use DateTimeZone;

class TicketCtrl extends Controller
{
    public function create(Request $req, $orgId, $eventId){
        $validator = Validator::create($req->all(), [
            'session_id' => 'required|string',
            'name' => 'required|string',
            'desc' => 'required|string',
            'type_price' => 'required|string',
            'quantity' => 'required|strinng',
            'start_date' => 'required|string',
            'end_date' => 'required|string',
        ]);
        if($validator->fails()){
            return response()->json($validator->errors(), 403);
        }
        if($req->type_price != '1' || $req->type_price != '2' || $req->type_price != '3'){
            return response()->json(["error" => "Input type price code had not registered"], 404);
        }
        // Type price had divided by three type
        // => Free (1)
        // => Paid (2)
        // => pay as you like - min 10000 (3)
        if($req->type_price == '2' && !$req->price){
            return response()->json(["error" => 'If you have selected code 2 for the type price, please fill the currency field'], 403);
        }
        try {
            $start = new DateTime($req->start_date, new DateTimeZone('Asia/Jakarta'));
            $end = new DateTime($req->end_time, new DateTimeZone('Asia/Jakarta'));
        } catch (\Throwable $th) {
            return response()->json(["error" => "Invalid format input date or time"], 400);
        }
        if($start > $end){
            return response()->json(["error" => "Start date must be lower than end date"], 403);
        }
        $ticket = TIcket::create([
            'session_id' => $req->session_id,
            'event_id' => $req->event->id,
            'name' => $req->name,
            'desc' => $req->desc,
            'type_price' => $req->type_price,
            'price' => $req->price,
            'quantity' => $req->quantity,
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
            'deleted' => 0,
        ]);
        return response()->json(["ticket" => $ticket], 201);
    }

    public function update(Request $req, $orgId, $eventId){
        $validator = Validator::create($req->all(), [
            'ticket_id' => 'required|string',
            'session_id' => 'required|string',
            'name' => 'required|string',
            'desc' => 'required|string',
            'type_price' => 'required|string',
            'quantity' => 'required|strinng',
            'start_date' => 'required|string',
            'end_date' => 'required|string',
        ]);
        if($validator->fails()){
            return response()->json($validator->errors(), 403);
        }
        if($req->type_price != '1' || $req->type_price != '2' || $req->type_price != '3'){
            return response()->json(["error" => "Input type price code had not registered"], 404);
        }
        // Type price had divided by three type
        // => Free (1)
        // => Paid (2)
        // => pay as you like - min 10000 (3)
        if($req->type_price == '2' && !$req->price){
            return response()->json(["error" => 'If you have selected code 2 for the type price, please fill the currency field'], 403);
        }
        try {
            $start = new DateTime($req->start_date, new DateTimeZone('Asia/Jakarta'));
            $end = new DateTime($req->end_time, new DateTimeZone('Asia/Jakarta'));
        } catch (\Throwable $th) {
            return response()->json(["error" => "Invalid format input date or time"], 400);
        }
        if($start > $end){
            return response()->json(["error" => "Start date must be lower than end date"], 403);
        }
        $updated = Ticket::where('id', $req->ticket_id)->where('event_id', $req->event->id)->where('deleted', 0)->update([
            'name' => $req->name,
            'desc' => $req->desc,
            'type_price' => $req->type_price,
            'price' => $req->price,
            'quantity' => $req->quantity,
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
        ]);
        return response()->json(["updated" => $updated], 202);
    }

    public function delete(Request $req, $orgId, $eventId){

    }

    public function get(Request $req, $orgId, $eventId){

    }

    public function getTickets(Request $req, $orgId, $eventId){

    }
}
