<?php

namespace App\Http\Controllers;

use App\Models\AvailableDayTicketSell;
use Illuminate\Http\Request;
use App\Models\Event;
use App\Models\Breakdown;
use App\Models\LimitReschedule;
use App\Models\Organization;
use App\Models\Ticket;
use DateInterval;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use DateTime;
use DateTimeZone;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class EventCtrl extends Controller
{
    /* ===================== NOTE (IMPORTANT !!!) ===================
      -> Category event is divided by 2 group 
      -> First, group of event by end time or expire time 
      -> Second, group of event daily. This group containing three event categories :
        1. Attraction
        2. Daily Activities 
        3. Tour Travel (recurring)
      -> The first group, is the left of the existing list of categories 
    =================================================================*/
    public function create(Request $req, $orgId)
    {
        // $pkg = PkgPricing::where('id', $req->pkg_id)->where('deleted',  0)->first();
        // if(!$pkg){
        //     return response()->json(["error" => "You have selected package is not found"], 404);
        // }
        $rule = [
            'name' => 'required|string',
            'category' => 'required|string',
            'topics' => 'required|string',
            'logo' => 'required|image|max:2048',
            'desc' => 'required|string',
            'snk' => 'required|string',
            'exe_type' => 'required|string', // handled
            'location' => 'required|string',
            'province' => 'required|string',
            'city' => 'required|string',
            'instagram' => 'required|string',
            'twitter' => 'required|string',
            'website' => 'required|string',
            'twn_url' => 'required|string',
            'seat_map' => 'image|max:2048',
            'available_days' => 'array',
            'daily_limit_times' => 'array',
            'visibility' => 'required|boolean'
        ];
        // if($pkg->price != 0){
        //     $rule += [
        //         'pay_method' => 'required|string'
        //     ];
        // }
        $validator = Validator::make($req->all(), $rule);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 403);
        }
        // Must be validated manually
        // 1. date time input 
        // 2. exe_type input (online, offline, hybrid)
        // if((intval($req->pay_method) == 14 && !$req->mobile_number) || (intval($req->pay_method) == 15 && !$req->cashtag)){
        //     return response()->json(["error" => "mobile number is required for pay method with OVO or $"."cashtag is required for pay method with JeniusPay"], 403);
        // }
        $start = null;
        $end = null;
        if ($req->exe_type !== 'online' && $req->exe_type !== 'offline' && $req->exe_type !== 'hybrid') {
            return response()->json(['error' => 'Your input of execution type is not available. Please input "online" or "hybrid" or "offline" for this field'], 400);
        }
        if (($req->category == 'Attraction' || $req->category == 'Tour Travel' || $req->category == 'Daily Activities' || $req->category == 'Tour Travel (recurring)') && $req->exe_type != 'offline') {
            return response()->json(['error' => 'Event with category ' . $req->category . ' only available for offline type'], 403);
        }
        $limitTime = [];
        if (($req->category != 'Attraction' && $req->category != 'Daily Activities' && $req->category != 'Tour Travel (recurring)')) {
            if (!$req->start_date || !$req->end_date || !$req->start_time || !$req->end_time) {
                return response()->json(['error' => 'Date time or field start_date, start_time, end_date, and end_time is required for this event category'], 403);
            }
            try {
                $start = new DateTime($req->start_date . ' ' . $req->start_time, new DateTimeZone('Asia/Jakarta'));
                $end = new DateTime($req->end_date . ' ' . $req->end_time, new DateTimeZone('Asia/Jakarta'));
            } catch (\Throwable $th) {
                return response()->json(['error' => 'Invalid input date time for start or end date time'], 400);
            }
            if ($start < new DateTime('now', new DateTimeZone('Asia/Jakarta')) || $start >= $end) {
                return response()->json(["error" => "Start time can't les then current time or greater than end time of event"], 400);
            }
        } else {
            if (!$req->daily_limit_times) {
                return response()->json(["error" => "Ticket for daily event, must be have a max limit time available per days"], 403);
            }
            if (count($req->available_days) != count($req->daily_limit_times)) {
                return response()->json(["error" => "Count of available days is not match with daily limit time"], 403);
            }
            foreach ($req->daily_limit_times as $key => $daily_limit_time) {
                try {
                    $limitTime[$key] = new DateTime($daily_limit_time, new DateTimeZone('Asia/Jakarta'));
                } catch (\Throwable $th) {
                    return response()->json(["error" => "Format daily limit time is H:i (hour:minute)"], 403);
                }
                if ($limitTime[$key]->format("H:i") != $daily_limit_time) {
                    return response()->json(["error" => "Format daily limit time is H:i (hour:minute)"], 403);
                }
            }
            $start = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
            $end = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
            $end->add(new DateInterval('P1Y'));
        }
        // $eventSameTime = Event::where('org_id', $orgId)->where('start_date', $start->format('Y-m-d'))->get();
        // if(count($eventSameTime) >= $pkg->event_same_time){
        //     return response()->json(["error" => "You have created ".count($eventSameTime)." events with start date in same day. This package only receive ".$pkg->event_same_time." events in same start date"], 403);
        // }
        $originNameFile = pathinfo($req->file('logo')->getClientOriginalName(), PATHINFO_FILENAME);
        $fileName = $originNameFile . '_' . time() . '.' . $req->file('logo')->getClientOriginalExtension();
        $req->file('logo')->storeAs('public/event_banners', $fileName);
        $fileName = "/storage/event_banners/" . $fileName;
        $fields = null;
        if ($req->custom_fields) {
            foreach ($req->custom_fields as $value) {
                if ($fields == null) $fields = $value;
                else $fields = $fields . '|' . $value;
            }
        }
        $seatMapImage = null;
        if ($req->hasFile('seat_map')) {
            $seatMapImage = pathinfo($req->file('seat_map')->getClientOriginalName(), PATHINFO_FILENAME);
            $seatMapImage .= '_' . time() . '.' . $req->file('seat_map')->getClientOriginalExtension();
            $req->file('seat_map')->storeAs('public/seat_maps' . $seatMapImage);
            $seatMapImage = '/storage/seat_maps/' . $seatMapImage;
        }
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
            'is_publish' => 1,
            'instagram' => $req->instagram,
            'twitter' => $req->twitter,
            'website' => $req->website,
            'twn_url' => $req->twn_url,
            'custom_fields' => $fields,
            'single_trx' => $req->single_trx == false ? false : true,
            'seat_map' => $seatMapImage,
            'visibility' => $req->visibility,
            'deleted' => 0,
        ]);
        $breakdowns = [];
        if ($req->breakdowns && ($event->exe_type == 'online' || $event->exe_type == 'hybrid')) {
            foreach ($req->breakdowns as $key => $value) {
                $breakdowns[$key] = Breakdown::create([
                    'event_id' => $event->id,
                    'name' => $value
                ]);
            }
        }
        $availableDays = [];
        if (($req->category == 'Attraction' || $req->category == 'Daily Activities' || $req->category == 'Tour Travel (recurring)') && $req->available_days) {
            foreach ($req->available_days as $key => $avd) {
                $availableDays[] = AvailableDayTicketSell::create([
                    "event_id" => $event->id,
                    "day" => $avd,
                    "max_limit_time" => $limitTime[$key]->format('H:i')
                ]);
            }
        }
        $limitReschedule = null;
        if ($req->limit_reschedule) {
            $limitReschedule = LimitReschedule::create([
                'event_id' => $event->id,
                'limit_time' => $req->limit_reschedule
            ]);
        }
        // $objPkgPay = new PkgPayCtrl();
        // $paymentData = $objPkgPay->createTrx($event->id, $req->pkg_id, $req->pay_method, $req->mobile_number, $req->cashtag);
        return response()->json([
            "event" => $event,
            "breakdowns" => count($breakdowns) == 0 ? null : $breakdowns,
            "available_days" => count($availableDays) == 0 ? null : $availableDays,
            "limit_available_reschedule" => $limitReschedule
            // "payment_data" => $paymentData
        ], 201);
    }
    public function update(Request $req, $orgId)
    {
        $eventObj = Event::where('id', $req->event_id)->where('org_id', $orgId)->where('is_publish', '<', 3)->where('deleted', 0);
        if (!$eventObj->first()) {
            return response()->json(["error" => "Event data not found"], 404);
        }
        if ($eventObj->first()->is_publish >= 3) {
            return response()->json(["error" => "This event is not active"], 403);
        }
        // $pkg = $eventObj->first()->payment()->first()->package()->first();
        $validator = Validator::make($req->all(), [
            'category' => 'required|string',
            'topics' => 'required|string',
            'logo' => 'image|max:2048',
            'desc' => 'required|string',
            'snk' => 'required|string',
            'exe_type' => 'required|string', // handled
            'location' => 'required|string',
            'province' => 'required|string',
            'city' => 'required|string',
            'instagram' => 'required|string',
            'twitter' => 'required|string',
            'website' => 'required|string',
            'twn_url' => 'required|string',
            'seat_map' => 'image|max:2048',
            'available_days' => 'array',
            'daily_limit_times' => 'array',
            'visibility' => 'required|boolean'
        ]);
        if ($validator->fails()) {
            return response()->json($validator->erros(), 403);
        }
        if ($req->exe_type !== 'online' && $req->exe_type !== 'offline' && $req->exe_type !== 'hybrid') {
            return response()->json(['error' => 'Your input of execution type is not available. Please input "online" or "hybrid" or "offline" for this field'], 400);
        }
        if (($req->category == 'Attraction' || $req->category == 'Tour Travel' || $req->category == 'Daily Activities' || $req->category == 'Tour Travel (recurring)') && $req->exe_type != 'offline') {
            return response()->json(["error" => 'Event with category ' . $req->category . 'only avilable for offline type'], 403);
        }
        $start = null;
        $end = null;
        $limitTime = [];
        if ($req->category != 'Attraction' && $req->category != 'Daily Activities' && $req->category != 'Tour Travel (recurring)') {
            if (!$req->start_date || !$req->start_time || !$req->end_date || !$req->end_time) {
                return response()->json(["error" => "Date time or field start_date, start_time, end_date, and end_time is required for this event category"], 403);
            }
            try {
                $start = new DateTime($req->start_date . ' ' . $req->start_time, new DateTimeZone('Asia/Jakarta'));
                $end = new DateTime($req->end_date . ' ' . $req->end_time, new DateTimeZone('Asia/Jakarta'));
            } catch (\Throwable $th) {
                return response()->json(["error" => "Invalid format input date or time"], 400);
            }
            if ($start < new DateTime('now', new DateTimeZone('Asia/Jakarta')) || $start >= $end) {
                return response()->json(["error" => "Start time can't les then current time or greater than end time of event"], 400);
            }
        } else {
            if (!$req->daily_limit_times) {
                return response()->json(["error" => "Ticket for daily event, must be have a max limit time available per days"], 403);
            }
            if (count($req->available_days) != count($req->daily_limit_times)) {
                return response()->json(["error" => "Count of available days is not match with daily limit time"], 403);
            }
            foreach ($req->daily_limit_times as $key => $daily_limit_time) {
                try {
                    $limitTime[$key] = new DateTime($daily_limit_time, new DateTimeZone('Asia/Jakarta'));
                } catch (\Throwable $th) {
                    return response()->json(["error" => "Format daily limit time is H:i (hour:minute)"], 403);
                }
                if ($limitTime[$key]->format("H:i") != $daily_limit_time) {
                    return response()->json(["error" => "Format daily limit time is H:i (hour:minute)"], 403);
                }
            }
            $start = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
            $end = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
            $end->add(new DateInterval('P1Y'));
        }
        // $eventSameTime = Event::where('org_id', $eventObj->first()->org_id)->where('start_date', $start->format('Y-m-d'))->get();
        // if(count($eventSameTime) >= $pkg->event_same_time){
        //     return response()->json(["error" => "You have created ".count($eventSameTime)." events with start date in same day. This package only receive ".$pkg->event_same_time." events in same start date"], 403);
        // }
        $nameFile = $eventObj->first()->logo;
        if ($req->hasFile('logo')) {
            $originNameFile = pathinfo($req->file('logo')->getClientOriginalName(), PATHINFO_FILENAME);
            $nameFile = $originNameFile . '_' . time() . '.' . $req->file('logo')->getClientOriginalExtension();
            $req->file('logo')->storeAs('public/event_banners', $nameFile);
            $nameFile = '/storage/event_banners/' . $nameFile;
            Storage::delete('public/event_banners/' . explode('/', $eventObj->first()->logo)[3]);
        }
        $fields = null;
        if ($req->custom_fields) {
            foreach ($req->custom_fields as $value) {
                if ($fields == null) $fields = $value;
                else $fields = $fields . '|' . $value;
            }
        }
        $seatMapImage = $eventObj->first()->seat_map;
        if ($req->hasFile('seat_map')) {
            if ($seatMapImage != null) {
                Storage::delete('public/seat_maps/' . explode('/', $seatMapImage)[3]);
            }
            $seatMapImage = pathinfo($req->file('seat_map')->getClientOriginalName(), PATHINFO_FILENAME);
            $seatMapImage .= '_' . time() . '.' . $req->file('seat_map')->getClientOriginalExtension();
            $req->file('seat_map')->storeAs('public/seat_maps', $seatMapImage);
            $seatMapImage = '/storage/seat_maps/' . $seatMapImage;
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
            'custom_fields' => $fields,
            'single_trx' => $req->single_trx == false ? false : true,
            'seat_map' => $seatMapImage,
            'visibility' => $req->visibility
        ]);
        Breakdown::where('event_id', $eventObj->first()->id)->delete();
        if ($req->breakdowns && ($req->exe_type == 'online' || $req->exe_type == 'hybrid')) {
            foreach ($req->breakdowns as $key => $value) {
                Breakdown::create([
                    'event_id' => $eventObj->first()->id,
                    'name' => $value
                ]);
            }
        }
        if ($req->exe_type == 'offline' && ($req->category == 'Attraction' || $req->category == 'Daily Activities' || $req->category == 'Tour Travel (recurring)')) {
            Ticket::where('event_id', $eventObj->first()->id)->update([
                'start_date' => $start->format("Y-m-d"),
                'end_date' => $end->format("Y-m-d"),
            ]);
        }
        AvailableDayTicketSell::where('event_id', $eventObj->first()->id)->delete();
        if (($req->category == 'Attraction' || $req->category == 'Daily Activities' || $req->category == 'Tour Travel (recurring)') && $req->available_days) {
            foreach ($req->available_days as $key => $avd) {
                AvailableDayTicketSell::create([
                    "event_id" => $eventObj->first()->id,
                    "day" => $avd,
                    "max_limit_time" => $limitTime[$key]->format('H:i')
                ]);
            }
        }
        if ($req->limit_reschedule) {
            LimitReschedule::where('event_id', $eventObj->first()->id)->update([
                'limit_time' => $req->limit_reschedule
            ]);
        }
        return response()->json(["updated" => $updated], 202);
    }

    private function resetDateDailyType($event)
    {
        $now = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
        $eventEnd = new DateTime($event->end_date . ' ' . $event->end_time, new DateTimeZone('Asia/Jakarta'));
        if (($event->category != 'Attraction' && $event->category != 'Daily Activities' && $event->category != 'Tour Travel (recurring)') || $now < $eventEnd) {
            return $event;
        }
        $end =  new DateTime('now', new DateTimeZone('Asia/Jakarta'));
        $end->add(new DateInterval('P1Y'));
        Event::where('id', $event->id)->update([
            'start_date' => $now->format("Y-m-d"),
            'end_date' => $end->format("Y-m-d"),
            'start_time' => $now->format("H:i:s"),
            'end_time' => $end->format("H:i:s"),
        ]);
        Ticket::where('event_id', $event->id)->update([
            'start_date' => $now->format("Y-m-d"),
            'end_date' => $end->format("Y-m-d"),
        ]);
        return Event::where('id', $event->id)->first();
    }

    public function coreSeatNumberQtyTicket($ticket, $date)
    {
        if ($ticket->seat_number == 1 || $ticket->seat_number == true) {
            $allSeatNumber = null;
            $reservedSeats = null;
            if ($ticket->quantity == -1) {
                $allSeatNumber = range(1, intval($ticket->limitDaily()->first()->limit_quantity));
                $reservedSeats = DB::table('purchases')
                    ->join('reserved_seats', 'reserved_seats.pch_id', '=', 'purchases.id')
                    ->join('daily_tickets', 'daily_tickets.purchase_id', '=', 'purchases.id')
                    ->select(['reserved_seats.seat_number'])
                    ->where('purchases.ticket_id', '=', $ticket->id)
                    ->where('daily_tickets.visit_date', '=', $date->format('Y-m-d'))
                    ->get();
            } else {
                $allSeatNumber = range(0, intval($ticket->quantity));
                $reservedSeats = DB::table('purchases')
                    ->join('reserved_seats', 'reserved_seats.pch_id', '=', 'purchases.id')
                    ->select(['reserved_seats.seat_number'])
                    ->where('purchases.ticket_id', '=', $ticket->id)
                    ->get();
            }
            foreach ($reservedSeats as $rsvSeat) {
                unset($allSeatNumber[$rsvSeat->seat_number]);
            }
            $ticket->available_seat_numbers = $allSeatNumber;
        }
        if ($ticket->quantity == -1) {
            $purchases = DB::table('purchases')
                ->join('daily_tickets', 'daily_tickets.purchase_id', '=', 'purchases.id')
                ->where('purchases.ticket_id', '=', $ticket->id)
                ->where('daily_tickets.visit_date', '=', $date->format('Y-m-d'))
                ->get();
            $ticket->quantity = intval($ticket->limitDaily()->first()->limit_quantity) - count($purchases);
        }
        return $ticket;
    }

    private function getAvailableSeatNumberQty($event, $date)
    {
        $tickets = null;
        if (
            count($event->availableDays()->get()) == 0 ||
            (count($event->availableDays()->get()) > 0 &&
                count($event->availableDays()->where('day', $date->format('D'))->get()) > 0
            )
        ) {
            $tickets = $event->tickets()->get();
            foreach ($tickets as $ticket) {
                $ticket = $this->coreSeatNumberQtyTicket($ticket, $date);
            }
        }
        return $tickets;
    }

    public function getQtySeatNumberTicket(Request $req)
    {
        $date = null;
        $strDate = $req->visit_date ? $req->visit_date : 'now';
        try {
            $date = new DateTime($strDate, new DateTimeZone('Asia/Jakarta'));
            $now =  new DateTime('now', new DateTimeZone('Asia/Jakarta'));
            if ($date->format('Y-m-d') < $now->format('Y-m-d')) {
                return response()->json(["error" => "Invalid selected date, selected visit date must be greather equal than now"], 403);
            }
        } catch (\Throwable $th) {
            return response()->json(["error" => "Invalid date format"], 403);
        }
        $ticket = Ticket::where('id', $req->ticket_id)->first();
        if (!$ticket) {
            return response()->json(["error" => "Ticket data not found"], 404);
        }
        $availableDays = $ticket->event()->first()->availableDays()->get();
        $ticket = $this->coreSeatNumberQtyTicket($ticket, $date);
        return response()->json(["ticket" => $ticket, "available_day" => $availableDays], 200);
    }

    public function getAvailableSeatNumberDailyTicket(Request $req, $eventId)
    {
        $pchCtrl = new PchCtrl();
        $pchCtrl->loadTrxData();
        $event = null;
        if ($req->org) {
            $event = Event::where('id', $eventId)->where('is_publish', '<', 3)->where('deleted', 0)->first();
        } else {
            $event = Event::where('id', $eventId)->where('is_publish', 2)->where('deleted', 0)->first();
        }
        if (!$event) {
            return response()->json(["error" => "Event data not found"], 404);
        }
        $event = $this->resetDateDailyType($event);
        if ($event->category != 'Attraction' && $event->category != 'Daily Activities' && $event->category != 'Tour Travel (recurring)') {
            return response()->json(["error" => "This event type is not a daily event / ticket"], 403);
        }
        $customFields = [];
        if ($event->custom_fields != null || $event->custom_fields != '') {
            $customFields = explode('|', $event->custom_fields);
        }
        $event->custom_fields = $customFields;
        $date = null;
        try {
            $date = new DateTime($req->visit_date, new DateTimeZone('Asia/Jakarta'));
        } catch (\Throwable $th) {
            return response()->json(["error" => "Invalid date format"], 403);
        }
        if ($date < new DateTime($event->end_date . ' ' . $event->end_time, new DateTimeZone('Asia/Jakarta'))) {
            $event->tickets = $this->getAvailableSeatNumberQty($event, $date);
        }
        return response()->json([
            "event" => $event,
            "available_days" => $event->availableDays()->get(),
            "available_reschedule" => $event->availableReschedule()->first(),
            "breakdowns" => $event->breakdowns()->get(),
            "guest" => $event->guests()->get(),
            "sponsors" => $event->sponsors()->get(),
            "exhibitors" => $event->exhs()->get(),
            "handbooks" => $event->handbooks()->get(),
            "receptionists" => $event->receptionists()->get(),
            "organization" => $event->org()->first(),
            "vouchers" => new DateTime('now', new DateTimeZone('Asia/Jakarta')) < new DateTime($event->end_date . ' ' . $event->end_time, new DateTimeZone('Asia/Jakarta')) ? $event->vouchers()->get() : [],
        ], 200);
    }

    public function getById($eventId)
    {
        $pchCtrl = new PchCtrl();
        $pchCtrl->loadTrxData();
        $event = null;
        if ($req->org) {
            $event = Event::where('id', $eventId)->where('is_publish', '<', 3)->where('deleted', 0)->first();
        } else {
            $event = Event::where('id', $eventId)->where('is_publish', 2)->where('deleted', 0)->first();
        }
        if (!$event) {
            return response()->json(["error" => "Event data not found"], 404);
        }
        $event = $this->resetDateDailyType($event);
        $customFields = [];
        if ($event->custom_fields != null || $event->custom_fields != '') {
            $customFields = explode('|', $event->custom_fields);
        }
        $event->custom_fields = $customFields;
        $date = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
        if ($date < new DateTime($event->end_date . ' ' . $event->end_time, new DateTimeZone('Asia/Jakarta'))) {
            $event->tickets = $this->getAvailableSeatNumberQty($event, $date);
        }
        return response()->json([
            "event" => $event,
            "available_days" => $event->availableDays()->get(),
            "available_reschedule" => $event->availableReschedule()->first(),
            "breakdowns" => $event->breakdowns()->get(),
            "guest" => $event->guests()->get(),
            "sponsors" => $event->sponsors()->get(),
            "exhibitors" => $event->exhs()->get(),
            "handbooks" => $event->handbooks()->get(),
            "receptionists" => $event->receptionists()->get(),
            "organization" => $event->org()->first(),
            "vouchers" => new DateTime('now', new DateTimeZone('Asia/Jakarta')) < new DateTime($event->end_date . ' ' . $event->end_time, new DateTimeZone('Asia/Jakarta')) ? $event->vouchers()->get() : [],
        ], 200);
    }

    public function getBySlug($slug)
    {
        $pchCtrl = new PchCtrl();
        $pchCtrl->loadTrxData();
        $event = null;
        if ($req->org) {
            $event = Event::where('slug', $slug)->where('is_publish', '<', 3)->where('deleted', 0)->first();
        } else {
            $event = Event::where('slug', $slug)->where('is_publish', 2)->where('deleted', 0)->first();
        }
        if (!$event) {
            return response()->json(["error" => "Event data not found"], 404);
        }
        $event = $this->resetDateDailyType($event);
        $customFields = [];
        if ($event->custom_fields != null || $event->custom_fields != '') {
            $customFields = explode('|', $event->custom_fields);
        }
        $event->custom_fields = $customFields;
        $date = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
        if ($date < new DateTime($event->end_date . ' ' . $event->end_time, new DateTimeZone('Asia/Jakarta'))) {
            $event->tickets = $this->getAvailableSeatNumberQty($event, $date);
        }
        return response()->json([
            "event" => $event,
            "available_days" => $event->availableDays()->get(),
            "available_reschedule" => $event->availableReschedule()->first(),
            "breakdowns" => $event->breakdowns()->get(),
            "guest" => $event->guests()->get(),
            "sponsors" => $event->sponsors()->get(),
            "exhibitors" => $event->exhs()->get(),
            "handbooks" => $event->handbooks()->get(),
            "receptionists" => $event->receptionists()->get(),
            "organization" => $event->org()->first(),
            "vouchers" => new DateTime('now', new DateTimeZone('Asia/Jakarta')) < new DateTime($event->end_date . ' ' . $event->end_time, new DateTimeZone('Asia/Jakarta')) ? $event->vouchers()->get() : [],
        ], 200);
    }

    public function getByOrg(Request $req, $orgId)
    {
        $pchCtrl = new PchCtrl();
        $pchCtrl->loadTrxData();
        if (!Organization::where('id', $orgId)->first()) {
            return response()->json(["error" => "Organization data not found"], 404);
        }
        $events = null;
        if ($req->org) {
            $events = Event::where('org_id', $orgId)->where('is_publish', '<', 3)->where('deleted', 0)->get();
        } else {
            $events = Event::where('org_id', $orgId)->where('is_publish', 2)->where('deleted', 0)->get();
        }
        if (count($events) == 0) {
            return response()->json(["error" => "Event data not found"], 404);
        }
        $data = ["events" => []];
        foreach ($events as $event) {
            $event = $this->resetDateDailyType($event);
            $customFields = [];
            if ($event->custom_fields != null || $event->custom_fields != '') {
                $customFields = explode('|', $event->custom_fields);
            }
            $event->custom_fields = $customFields;
            $date = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
            if ($date < new DateTime($event->end_date . ' ' . $event->end_time, new DateTimeZone('Asia/Jakarta'))) {
                $event->tickets = $this->getAvailableSeatNumberQty($event, $date);
            }
            $data["events"][] = [
                "event" => $event,
                "available_days" => $event->availableDays()->get(),
                "available_reschedule" => $event->availableReschedule()->first(),
                "breakdowns" => $event->breakdowns()->get(),
                "guest" => $event->guests()->get(),
                "sponsors" => $event->sponsors()->get(),
                "exhibitors" => $event->exhs()->get(),
                "handbooks" => $event->handbooks()->get(),
                "receptionists" => $event->receptionists()->get(),
                "organization" => $event->org()->first(),
                "vouchers" => new DateTime('now', new DateTimeZone('Asia/Jakarta')) < new DateTime($event->end_date . ' ' . $event->end_time, new DateTimeZone('Asia/Jakarta')) ? $event->vouchers()->get() : [],
            ];
        }
        return response()->json($data, 200);
    }

    public function delete(Request $req, $orgId)
    {
        $eventObj = Event::where('id', $req->event_id)->where('org_id', $orgId)->where('deleted', 0);
        if (!$eventObj->first()) {
            return response()->json(["error" => "Event data not found"], 404);
        }
        $fixPurchases = 0;
        foreach ($eventObj->first()->tickets()->get() as $ticket) {
            foreach ($ticket->purchases()->get() as $purchase) {
                if ($purchase->amount == 0 || $purchase->payment()->first()->pay_state != 'EXPIRED') {
                    $fixPurchases += 1;
                    break;
                }
            }
            if ($fixPurchases > 0) {
                break;
            }
        }
        $deleted = null;
        if ($fixPurchases == 0) {
            Storage::delete('public/event_banners/' . explode('/', $eventObj->first()->logo)[3]);
            $deleted = $eventObj->delete();
        } else {
            if ((new DateTime('now', new DateTimeZone('Asia/Jakarta')) < new DateTime($eventObj->first()->end_date . ' ' . $eventObj->first()->end_time, new DateTimeZone('Asia/Jakarta'))) &&
                ($eventObj->first()->category != 'Attraction' && $eventObj->first()->category != 'Daily Activities' && $eventObj->first()->category != 'Tour Travel (recurring)')
            ) {
                // if($eventObj->first()->is_publish == 1 || $eventObj->first()->is_publish == 2){
                return response()->json(["error" => "Operation not allowed to your event. Your event still active"], 402);
            }
            Ticket::where('event_id', $eventObj->first()->id)->update(["deleted" => 1]);
            $deleted = $eventObj->update(['deleted' => 1]);
        }
        return response()->json(["deleted" => $deleted], 200);
    }

    public function setPublishState(Request $req, $orgId)
    {
        // Event statuses
        // 1 => un-publish
        // 2 => published
        // 3 => ended
        // 4 => pending withdraw (first status code 1)
        // 5 => pending withdraw (first status code 2)
        $event = Event::where('id', $req->event_id)->where('org_id', $orgId)->where('deleted', 0);
        if (!$event->first()) {
            return response()->json(["error" => "Event data not found"], 404);
        }
        if ($req->code_pub_state != 1 && $req->code_pub_state != 2) {
            return response()->json(["error" => "Your code publish state have not recognized"], 402);
        }
        if ($event->first()->is_publish == 0 || $event->first()->is_publish >= 3) {
            return response()->json(["error" => "Your event have not allowed to change the publish state"], 403);
        }
        // if (!Auth::user()->legality()->first()) {
        //     return response()->json(["error" => "Please fill your legality data first"], 403);
        // }
        if (!$req->org->credibilityData()->first()) {
            return response()->json(["error" => "Please fill your legality data first"], 403);
        }
        $updated = $event->update([
            "is_publish" => $req->code_pub_state
        ]);
        return response()->json(["updated" => $updated], 200);
    }
}
