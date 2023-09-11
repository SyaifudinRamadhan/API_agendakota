<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Breakdown;
use App\Models\PkgPricing;
use App\Models\PkgPayment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Str;
use DateTime;
use DateTimeZone;

class EventCtrl extends Controller
{
    public function create(Request $req, $orgId){
        $pkg = PkgPricing::where('id', $req->pkg_id)->where('deleted',  0)->first();
        if(!$pkg){
            return response()->json(["error" => "You have selected package is not found"], 404);
        }
        $rule = [
            'name' => 'required|string',
            'category' => 'required|string',
            'topics' => 'required|string',
            'logo' => 'required|image|max:'.$pkg->max_attachment, //handled
            'desc' => 'required|string',
            'snk' => 'required|string',
            'exe_type' => 'required|string',// handled
            'location' => 'required|string',
            'province' => 'required|string',
            'city' => 'required|string',
            'start_date' => 'required|string',// handled
            'end_date' => 'required|string',// handled
            'start_time' => 'required|string',// handled
            'end_time' => 'required|string',// handled
            'instagram' => 'required|string',
            'twitter' => 'required|string',
            'website' => 'required|string',
            'twn_url' => 'required|string',
        ];
        if($pkg->price != 0){
            $rule += [
                'pay_method' => 'required|string'
            ];
        }
        $validator = Validator::make($req->all(), $rule);
        if($validator->fails()){
            return response()->json($validator->errors(), 403);
        }
        // Must be validated manually
        // 1. date time input 
        // 2. exe_type input (online, offline, hybrid)
        if((intval($req->pay_method) == 14 && !$req->mobile_number) || (intval($req->pay_method) == 15 && !$req->cashtag)){
            return response()->json(["error" => "mobile number is required for pay method with OVO or $"."cashtag is required for pay method with JeniusPay"], 403);
        }
        try {
            $start = new DateTime($req->start_date.' '.$req->start_time, new DateTimeZone('Asia/Jakarta'));
            $end = new DateTime($req->end_date.' '.$req->end_time, new DateTimeZone('Asia/Jakarta'));
        } catch (\Throwable $th) {
            return response()->json(['error' => 'Invalid input date time for start or end date time'], 400);
        }
        if($req->exe_type !== 'online' && $req->exe_type !== 'offline' && $req->exe_type !== 'hybrid'){
            return response()->json(['error' => 'Your input of execution type is not available. Please input "online" or "hybrid" or "offline" for this field'], 400);
        }
        if($start < new DateTime('now', new DateTimeZone('Asia/Jakarta')) || $start > $end){
            return response()->json(["error" => "Start time can't les then current time or greater than end time of event"], 400);
        }
        // $eventSameTime = Event::where('org_id', $orgId)->where('start_date', $start->format('Y-m-d'))->get();
        // if(count($eventSameTime) >= $pkg->event_same_time){
        //     return response()->json(["error" => "You have created ".count($eventSameTime)." events with start date in same day. This package only receive ".$pkg->event_same_time." events in same start date"], 403);
        // }
        $originNameFile = pathinfo($req->file('logo')->getClientOriginalName(), PATHINFO_FILENAME);
        $fileName = $originNameFile.'_'.time().'.'.$req->file('logo')->getClientOriginalExtension();
        $req->file('logo')->storeAs('public/event_banners', $fileName);
        $fileName = "/storage/event_banners/".$fileName;
        $event = Event::create([
            'org_id' => $orgId,
            'slug' => Str::slug($req->name),
            'name' => $req->name,
            'category' => $req->category,
            'topics' => $req->topics,
            'logo' => $fileName,
            'desc' => $req->desc,
            'snk' => $req->snk,
            'exe_type' => $req->exe_type,
            'location' => $req->location,
            'province' => $req->province,
            'city' => $req->city,
            'start_date' => $start->format("Y-m-d"),
            'end_date' => $end->format("Y-m-d"),
            'start_time' => $start->format("H:i:s"),
            'end_time' => $end->format("H:i:s"),
            'is_publish' => 0,
            'instagram' => $req->instagram,
            'twitter' => $req->twitter,
            'website' => $req->website,
            'twn_url' => $req->twn_url,
            'deleted' => 0,
        ]);
        $breakdowns = [];
        if($req->breakdowns && ($req->exe_type === 'online' || $req->exe_type === 'hybrid')){
            foreach ($req->breakdowns as $key => $value) {
                $breakdowns[$key] = Breakdown::create([
                    'event_id' => $event->id,
                    'name' => $value
                ]);
            }
        }
        $objPkgPay = new PkgPayCtrl();
        $paymentData = $objPkgPay->createTrx($event->id, $req->pkg_id, $req->pay_method, $req->mobile_number, $req->cashtag);
        return response()->json([
            "event" => $event,
            "breakdowns" => count($breakdowns) == 0 ? null : $breakdowns,
            "payment_data" => $paymentData
        ], 201);
    }
    public function update(Request $req, $orgId){
        $eventObj = Event::where('id', $req->event_id)->where('org_id', $orgId)->where('deleted', 0);
        if(!$eventObj->first()){
            return response()->json(["error" => "Event data not found"], 404);
        }
        $pkg = $eventObj->first()->payment()->first()->package()->first();
        $validator = Validator::make($req->all(),[
            'category' => 'required|string',
            'topics' => 'required|string',
            'logo' => 'image|max:'.$pkg->max_attachment, //handled
            'desc' => 'required|string',
            'snk' => 'required|string',
            'exe_type' => 'required|string',// handled
            'location' => 'required|string',
            'province' => 'required|string',
            'city' => 'required|string',
            'start_date' => 'required|string',// handled
            'end_date' => 'required|string',// handled
            'start_time' => 'required|string',// handled
            'end_time' => 'required|string',// handled
            'instagram' => 'required|string',
            'twitter' => 'required|string',
            'website' => 'required|string',
            'twn_url' => 'required|string',
        ]);
        if($validator->fails()){
            return response()->json($validator->erros(), 403);
        }
        try {
            $start = new DateTime($req->start_date.' '.$req->start_time, new DateTimeZone('Asia/Jakarta'));
            $end = new DateTime($req->end_date.' '.$req->end_time, new DateTimeZone('Asia/Jakarta'));
        } catch (\Throwable $th) {
            return response()->json(["error" => "Invalid format input date or time"], 400);
        }
        if($req->exe_type !== 'online' && $req->exe_type !== 'offline' && $req->exe_type !== 'hybrid'){
            return response()->json(['error' => 'Your input of execution type is not available. Please input "online" or "hybrid" or "offline" for this field'], 400);
        }
        if($start < new DateTime('now', new DateTimeZone('Asia/Jakarta')) || $start > $end){
            return response()->json(["error" => "Start time can't les then current time or greater than end time of event"], 400);
        }
        // $eventSameTime = Event::where('org_id', $eventObj->first()->org_id)->where('start_date', $start->format('Y-m-d'))->get();
        // if(count($eventSameTime) >= $pkg->event_same_time){
        //     return response()->json(["error" => "You have created ".count($eventSameTime)." events with start date in same day. This package only receive ".$pkg->event_same_time." events in same start date"], 403);
        // }
        $nameFile = $eventObj->first()->logo;
        if($req->hasFile('logo')){
            $originNameFile = pathinfo($req->file('logo')->getClientOriginalName(), PATHINFO_FILENAME);
            $nameFile = $originNameFile.'_'.time().'.'.$req->file('logo')->getClientOriginalExtension();
            $req->file('logo')->storeAs('public/event_banners', $nameFile);
            $nameFile = '/storage/event_banners/'.$nameFile;
            Storage::delete('public/event_banners/'.explode('/', $eventObj->first()->logo)[3]);
        }
        $updated = $eventObj->update([
            'slug' => Str::slug($req->name),
            'name' => $req->name,
            'category' => $req->category,
            'topics' => $req->topics,
            'logo' => $nameFile,
            'desc' => $req->desc,
            'snk' => $req->snk,
            'exe_type' => $req->exe_type,
            'location' => $req->location,
            'province' => $req->province,
            'city' => $req->city,
            'start_date' => $start->format("Y-m-d"),
            'end_date' => $end->format("Y-m-d"),
            'start_time' => $start->format("H:i:s"),
            'end_time' => $end->format("H:i:s"),
            'instagram' => $req->instagram,
            'twitter' => $req->twitter,
            'website' => $req->website,
            'twn_url' => $req->twn_url,
        ]);
        if($req->breakdowns && ($req->exe_type === 'online' || $req->exe_type === 'hybird')){
            Breakdown::where('event_id', $eventObj->first()->id)->delete();
            foreach ($req->breakdowns as $key => $value) {
                Breakdown::create([
                    'event_id' => $eventObj->first()->id,
                    'name' => $value
                ]);
            }
        }
        return response()->json(["updated" => $updated], 202);
    }

    public function getById($eventId){
        $event = Event::where('id', $eventId)->where('deleted', 0)->first();
        if(!$event){
            return response()->json(["error" => "Event data not found"], 404);
        }
        $sessions = $event->sessions()->get();
        if($event->is_publish == 0 || $event->is_publish == 2){
            foreach ($sessions as $session) {
                $session->tickets = $session->tickets()->get();
            }
        }
        $rundowns = $event->rundowns()->get();
        foreach ($rundowns as $rundown) {
            $rundown->guest = $rundown->guests()->get();
        }
        return response()->json([
            "event" => $event,
            "breakdowns" => $event->breakdowns()->get(),
            "rundowns" => $rundowns,
            "sessions" => $sessions,
            "guest" => $event->guests()->get(),
            "sponsors" => $event->sponsors()->get(),
            "exhibitors" => $event->exhs()->get(),
            "handbooks" => $event->handbooks()->get(),
            "receptionists" => $event->receptionists()->get(),
            "organization" => $event->org()->first(),
        ], 200);
    }

    public function getBySlug($slug){
        $event = Event::where('slug', $slug)->where('deleted', 0)->first();
        if(!$event){
            return response()->json(["error" => "Event data not found"], 404);
        }
        $sessions = $event->sessions()->get();
        if($event->is_publish == 0 || $event->is_publish == 2){
            foreach ($sessions as $session) {
                $session->tickets = $session->tickets()->get();
            }
        }
        $rundowns = $event->rundowns()->get();
        foreach ($rundowns as $rundown) {
            $rundown->guest = $rundown->guests()->get();
        }
        return response()->json([
            "event" => $event,
            "breakdowns" => $event->breakdowns()->get(),
            "rundowns" => $rundowns,
            "sessions" => $sessions,
            "guest" => $event->guests()->get(),
            "sponsors" => $event->sponsors()->get(),
            "exhibitors" => $event->exhs()->get(),
            "handbooks" => $event->handbooks()->get(),
            "receptionists" => $event->receptionists()->get(),
            "organization" => $event->org()->first(),
        ], 200);
    }

    public function getByOrg($orgId){
        $events = Event::where('org_id', $orgId)->where('deleted', 0)->get();
        if(count($events) == 0){
            return response()->json(["error" => "Event data not found"], 404);
        }
        $data = ["events" => []];
        foreach ($events as $event) {
            $sessions = $event->sessions()->get();
            if($event->is_publish == 0 || $event->is_publish == 2){
                foreach ($sessions as $session) {
                    $session->tickets = $session->tickets()->get();
                }
            }
            $rundowns = $event->rundowns()->get();
            foreach ($rundowns as $rundown) {
                $rundown->guest = $rundown->guests()->get();
            }
            $data["events"][] = [
                "event" => $event,
                "breakdowns" => $event->breakdowns()->get(),
                "rundowns" => $rundowns,
                "sessions" => $sessions,
                "guest" => $event->guests()->get(),
                "sponsors" => $event->sponsors()->get(),
                "exhibitors" => $event->exhs()->get(),
                "handbooks" => $event->handbooks()->get(),
                "receptionists" => $event->receptionists()->get(),
                "organization" => $event->org()->first(),
            ];
        }
        return response()->json($data, 200);
    }

    public function delete(Request $req, $orgId){
        $eventObj = Event::where('id', $req->event_id)->where('org_id', $orgId)->where('deleted', 0);
        if(!$eventObj->first()){
            return response()->json(["error" => "Event data not fpund"], 404);
        }
        if($event->first()->is_publish == 1 || $event->first()->is_publish == 2){
            return response()->json(["error" => "Operation not allowed to your event. Your event still active"], 402);
        }
        $deleted = $eventObj->update(['deleted' => 1]);
        return response()->json(["deleted" => $deleted], 200);
    }

    public function setPublishState(Request $req, $orgId){
        $event = Event::where('id', $req->event_id)->where('org_id', $orgId)->where('deleted', 0);
        if(!$event->first()){
            return response()->json(["error" => "Event data not found"], 404);
        }
        if($req->code_pub_state !== 1 || $req->code_pub_state !== 2){
            return response()->json(["error" => "Your code publish state have not recognized"], 402);
        }
        if($event->first()->is_publish == 0 || $event->first()->is_publish == 3){
            return response()->json(["error" => "Your event have not allowed to change the publish state"], 403);
        }
        $updated = $event->update([
            "is_publish" => $req->code_pub_state
        ]);
        return response()->json(["updated" => $updated], 200);
    }
}
