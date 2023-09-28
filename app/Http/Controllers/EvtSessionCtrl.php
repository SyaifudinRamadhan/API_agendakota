<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use App\Models\Event;
use App\Models\EventSession;
use App\Models\Rundown;
use App\Models\Ticket;
use DateTime;
use DateTimeZone;

class EvtSessionCtrl extends Controller
{
    private function checkLink($link, $type)
    {
        $linkFor = "";
        if (strpos($link, "zoom.us") == true && $type == '003') {
            $linkFor = "zoom";
        } else if ((strpos($link, "youtube.com") == true || strpos($link, 'youtu.be') == true) && $type == '004') {
            $linkFor = "youtube";
        } else {
            return -1;
        }
        if ($linkFor == "zoom") {
            $e = explode("?", $link);
            if (count($e) == 0) {
                return -1;
            } else {
                $p = explode("/", $e[0]);
                $pass = explode("pwd=", $e[1]);
            }
            if (count($p) == 0) {
                return -1;
            }
            if (count($pass) == 0 || count($pass) == 1) {
                return -1;
            }
            return $link;
        } else if ($linkFor == "youtube") {
            $idVideo = "";
            if (!preg_match('/^.*(youtu.be\/|v\/|u\/\w\/|embed\/|watch\?v=|\&v=|\?v=)([^#\&\?]*).*/', $link)) {
                return -1;
            }
            $urlInput = explode('watch?v=', $link);
            if (count($urlInput) <= 1) {
                $urlInput = explode('youtu.be/', $link);
                if(count($urlInput) <= 1){
                    $urlInput = explode('/embed/', $link);
                }
                $idVideo = $urlInput[1];
                $idVideo = explode('&', $idVideo);
            } else {
                $idVideo = $urlInput[1];
                $idVideo = explode('&', $idVideo);
            }
            return ("https://www.youtube.com/embed/" . $idVideo[0] . '?modestbranding=1&showinfo=0');
        }
    }

    public function create(Request $req){
        $validator = Validator::make($req->all(), [
            "start_rundown_id" => "required|string",
            'end_rundown_id' => "required|string",
            'title' => "required|string",
            'desc' => "required|string",
        ]);
        if($validator->fails()){
            return response()->json($validator->errors(), 403);
        }
        // Method streaming (stream_type) has been divided by 4 categories
        // 1. (001) => Agendakota Conference
        // 2. (002) => Agendakota Stream (RTMP)
        // 3. (003) => Zoom Embeed
        // 4. (004) => Youtube Embeed
        // $sessionCount = EventSession::where('event_id', $req->event->id)->get();
        // if(count($sessionCount) >= $req->event->payment()->first()->package()->first()->session_count){
        //     return response()->json(["error" => "Limit of event session can you are create ".count($sessionCount)." event sessions"], 403);
        // }
        $startRd = Rundown::where('id', $req->start_rundown_id)->where('event_id', $req->event->id)->first();
        $endRd = Rundown::where('id', $req->end_rundown_id)->where('event_id', $req->event->id)->first();
        if(!$startRd || !$endRd){
            return response()->json(["error" => "The selected rundown is not valid"], 404);
        }
        $data = [
            "event_id" => $req->event->id,
            "start_rundown_id" => $req->start_rundown_id,
            "end_rundown_id" => $req->end_rundown_id,
            "title" => $req->title,
            "desc" => $req->desc,
            'deleted' => 0,
        ];
        if($req->event->exe_type == 'online' || $req->event->exe_type == 'hybrid'){
            if(!$req->stream_type){
                return response()->json(["error" => "Stream type is required for online or hybrid event"], 403);
            }
            if($req->stream_type != '001' && $req->stream_type != '002' && $req->stream_type != '003' && $req->stream_type != '004'){
                return response()->json(["error" => "Stream type isn't recognized"], 403);
            }
            if(($req->stream_type == '003' || $req->stream_type == '004') && !$req->url_stream){
                return response()->json(["error" => "For stream type 003 or 004, required url_stream data"], 403);
            }
            if($req->stream_type == '003' || $req->stream_type == '004'){
                $urlStream = $this->checkLink($req->url_stream, $req->stream_type);
                if($urlStream == -1){
                    return response()->json(["error" => "Url stream is not recognized. Please check again"], 403);
                }
                $data += [
                    "link" => $urlStream
                ];
            }else {
                // Using stream data controller to create stream key
                // NOTE : Replace this link with stream key or code stream
                $data += [
                    "link" => $req->url_stream
                ];
            }
        }else{
            $data += [
                "link" => '-'
            ];
        }
        $eventSession = EventSession::create($data);
        return response()->json(["event_session" => $eventSession], 201);
    }

    public function update(Request $req){
        $validator = Validator::make($req->all(), [
            "session_id" => "required|string",
            "start_rundown_id" => "required|string",
            'end_rundown_id' => "required|string",
            'title' => "required|string",
            'desc' => "required|string",
        ]);
        if($validator->fails()){
            return response()->json($validator->errors(), 403);
        }
        // Method streaming (stream_type) has been divided by 4 categories
        // 1. (001) => Agendakota Conference
        // 2. (002) => Agendakota Stream (RTMP)
        // 3. (003) => Zoom Embeed
        // 4. (004) => Youtube Embeed
        $evtSessionObj = EventSession::where('id', $req->session_id)->where('event_id', $req->event->id)->where('deleted', 0);
        if(!$evtSessionObj->first()){
            return response()->json(["error" => "Event session data not found"], 404);
        }
        $startRd = Rundown::where('id', $req->start_rundown_id)->where('event_id', $req->event->id)->first();
        $endRd = Rundown::where('id', $req->end_rundown_id)->where('event_id', $req->event->id)->first();
        if(!$startRd || !$endRd){
            return response()->json(["error" => "The selected rundown is not valid"], 404);
        }
        $data = [
            "start_rundown_id" => $req->start_rundown_id,
            "end_rundown_id" => $req->end_rundown_id,
            "title" => $req->title,
            "desc" => $req->desc,
        ];
        if($req->event->exe_type == 'online' || $req->event->exe_type == 'hybrid'){
            if(!$req->stream_type){
                return response()->json(["error" => "Stream type is required for online or hybrid event"], 403);
            }
            if($req->stream_type != '001' && $req->stream_type != '002' && $req->stream_type != '003' && $req->stream_type != '004'){
                return response()->json(["error" => "Stream type isn't recognized"], 403);
            }
            if(($req->stream_type == '003' || $req->stream_type == '004') && !$req->url_stream){
                return response()->json(["error" => "For stream type 003 or 004, required url_stream data"], 403);
            }
            if($req->stream_type == '003' || $req->stream_type == '004'){
                $urlStream = $this->checkLink($req->url_stream, $req->stream_type);
                if($urlStream == -1){
                    return response()->json(["error" => "Url stream is not recognized. Please check again"], 403);
                }
                $data += [
                    "link" => $urlStream
                ];
            }else {
                // Using stream data controller to create stream key
                // NOTE : Replace this link with stream key or code stream
                $data += [
                    "link" => $req->url_stream
                ];
            }
        }else{
            $data += [
                "link" => '-'
            ];
        }
        $updated = $evtSessionObj->update($data);
        return response()->json(["updated" => $updated], 202);
    }

    public function delete(Request $req){
        $evtSessionObj = EventSession::where('id', $req->session_id)->where('event_id', $req->event->id)->where('deleted', 0);
        if(!$evtSessionObj->first()){
            return response()->json(["error" => "Event session not found"], 404);
        }
        $deleted = '';
        $purchases = 0;
        foreach ($evtSessionObj->first()->tickets()->get() as $ticket) {
            foreach ($ticket->purchases()->get() as $purchase) {
                if($purchase->amount == 0 || $purchase->payment()->first()->pay_state != 'EXPIRED'){
                    $purchases += 1;
                    break;
                }
            }
            if($purchases > 0){
                break;
            }
        }
        if($purchases == 0){
            $deleted = $evtSessionObj->delete();
        }else{
            if(new DateTime('now', new DateTimeZone('Asia/Jakarta')) < new DateTime($req->event->end_date.' '.$req->event->end_time, new DateTimeZone('Asia/Jakarta'))){
            // if($req->event->is_publish == 1 || $req->event->is_publish == 2){
                return response()->json(["error" => "You can't remove this session. Because your event are in progress or this session have linked with selled tickets in active event"], 403);
            }
            $deleted = $evtSessionObj->update(["deleted" => 1]);
            Ticket::where('session_id', $evtSessionObj->first()->id)->update(["deleted" => 1]);
        }
        return response()->json(["deleted" => $deleted], 202);
    }

    public function get(Request $req){
        $eventSession = EventSession::where('id', $req->session_id)->where('event_id', $req->event->id)->where('deleted', 0)->first();
        if(!$eventSession){
            return response()->json(["error" => "Event session not found"], 404);
        }
        return response()->json(["event_session" => $eventSession], 200);
    }

    public function getSessions(Request $req){
        $eventSessions = EventSession::where('event_id', $req->event->id)->where('deleted', 0)->get();
        if(count($eventSessions) == 0){
            return response()->json(["error" => "Event session not found"], 404);
        }
        return response()->json(["event_sessions" => $eventSessions], 200);
    }
}
