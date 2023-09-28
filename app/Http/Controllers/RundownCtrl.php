<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use App\Models\Rundown;
use App\Models\Event;
use DateTime;
use DateTimeZone;

class RundownCtrl extends Controller
{
    public function create(Request $req){
        $validator = Validator::make($req->all(), [
            'start_date' => 'required|string',
            'end_date' => 'required|string',
            'start_time' => 'required|string',
            'end_time' => 'required|string',
            'name' => 'required|string',
            'desc' => 'required|string',
        ]);
        if($validator->fails()){
            return response()->json($validator->errors(), 403);
        }
        try {
            $start = new DateTime($req->start_date.' '.$req->start_time, new DateTimeZone('Asia/Jakarta'));
            $end = new DateTime($req->end_date.' '.$req->end_time, new DateTimeZone('Asia/Jakarta'));
        } catch (\Throwable $th) {
            return response()->json(["error" => "Invalid format input date or time"], 400);
        }
        if(new DateTime($req->event->start_date.' '.$req->event->start_time, new DateTimeZone('Asia/Jakarta')) > $start ||
        new DateTime($req->event->end_date.' '.$req->event->end_time, new DateTimeZone('Asia/Jakarta')) < $end){
            return response()->json(["error" => "Rundown time is over from event time"], 403);
        }else if($start > $end){
            return response()->json(["error" => "Rundown start time must be lower than end time"], 403);
        }
        $data = Rundown::create([
            'event_id' => $req->event->id,
            'start_date' => $start->format("Y-m-d"),
            'end_date' => $end->format("Y-m-d"),
            'start_time' => $start->format("H:i:s"),
            'end_time' => $end->format("H:i:s"),
            'duration'=> ($end->diff($start)->days*24*60 + $end->diff($start)->h*60 + $end->diff($start)->i),
            'name' => $req->name,
            'desc' => $req->desc,
            'deleted' => 0,
        ]);
        return response()->json(["rundown" => $data], 201);
    }

    public function update(Request $req){
        $validator = Validator::make($req->all(), [
            'rundown_id' => 'required|string',
            'start_date' => 'required|string',
            'end_date' => 'required|string',
            'start_time' => 'required|string',
            'end_time' => 'required|string',
            'name' => 'required|string',
            'desc' => 'required|string',
        ]);
        if($validator->fails()){
            return response()->json($validator->errors(), 403);
        }
        try {
            $start = new DateTime($req->start_date.' '.$req->start_time, new DateTimeZone('Asia/Jakarta'));
            $end = new DateTime($req->end_date.' '.$req->end_time, new DateTimeZone('Asia/Jakarta'));
        } catch (\Throwable $th) {
            return response()->json(["error" => "Invalid format input date or time"], 400);
        }
        if(new DateTime($req->event->start_date.' '.$req->event->start_time, new DateTimeZone('Asia/Jakarta')) > $start ||
        new DateTime($req->event->end_date.' '.$req->event->end_time, new DateTimeZone('Asia/Jakarta')) < $end){
            return response()->json(["error" => "Rundown time is over from event time"], 403);
        }else if($start > $end){
            return response()->json(["error" => "Rundown start time must be lower than end time"], 403);
        }
        $data = Rundown::where('id', $req->rundown_id)->where('event_id', $req->event->id)->update([
            'start_date' => $start->format("Y-m-d"),
            'end_date' => $end->format("Y-m-d"),
            'start_time' => $start->format("H:i:s"),
            'end_time' => $end->format("H:i:s"),
            'duration'=> ($end->diff($start)->days*24*60 + $end->diff($start)->h*60 + $end->diff($start)->i),
            'name' => $req->name,
            'desc' => $req->desc,
        ]);
        return response()->json(["updated" => $data], $data == 0 ? 404 : 202);
    }

    public function delete(Request $req){
        $rd = Rundown::where('id', $req->rundown_id)->where('event_id', $req->event->id);
        if(!$rd->first()){
            return response()->json(["error" => "Rundown data not found"], 404);
        }
        if(count($rd->first()->sessionsAsStart()->get()) > 0 || count($rd->first()->sessionsAsEnd()->get()) > 0){
            return response()->json(["error" => "This rundown haven't deleted, because it was using by sessions data"], 403);
        }
        $deleted = $rd->delete();
        return response()->json(["deleted" => $deleted], 202);
    }

    public function get(Request $req){
        $rd = Rundown::where('id', $req->rundown_id)->where('event_id', $req->event->id);
        if(!$rd->first()){
            return response()->json(["error" => "Rundown data not found"], 404);
        }
        return response()->json(["rundown" => $rd->first()], 200);
    }

    public function getRundowns(Request $req){
        $rds = Rundown::where('event_id', $req->event->id)->get();
        return response()->json(["rundowns" => $rds], 200);
    }
}
