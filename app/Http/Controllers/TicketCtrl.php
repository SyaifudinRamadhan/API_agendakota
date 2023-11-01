<?php

namespace App\Http\Controllers;

use App\Models\DailyTicketLimit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Ticket;
use DateTime;
use DateTimeZone;

class TicketCtrl extends Controller
{
    public function create(Request $req)
    {
        $validator = Validator::make($req->all(), [
            'name' => 'required|string',
            'desc' => 'required|string',
            'type_price' => 'required|string',
            'max_purchase' => 'required|numeric'
        ]);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 403);
        }
        if ($req->type_price != '1' && $req->type_price != '2' && $req->type_price != '3') {
            return response()->json(["error" => "Input type price code had not registered"], 404);
        }
        // Type price had divided by three type
        // => Free (1)
        // => Paid (2)
        // => pay as you like - min 10000 (3)
        if ($req->type_price == '2' && !$req->price) {
            return response()->json(["error" => 'If you have selected code 2 for the type price, please fill the currency field'], 403);
        }
        if ($req->type_price == '2' && intval($req->price) < 10000) {
            return response()->json(["error" => "Sorry. Minimal transaction is IDR Rp. 10.000,-"], 403);
        }
        $start = null;
        $end = null;
        if ($req->event->category != 'Attraction' && $req->event->category != 'Daily Activities' && $req->event->category != 'Tour Travel (recurring)') {
            if (!$req->quantity || !$req->start_date || !$req->end_date) {
                return response()->json(["error" => "Filed quantity, start_date, and end_date is required for this category"], 403);
            }
            try {
                $start = new DateTime($req->start_date, new DateTimeZone('Asia/Jakarta'));
                $end = new DateTime($req->end_date, new DateTimeZone('Asia/Jakarta'));
            } catch (\Throwable $th) {
                return response()->json(["error" => "Invalid format input date or time"], 400);
            }
            $endEvent = new DateTime($req->event->end_date . ' ' . $req->event->end_time, new DateTimeZone('Asia/Jakarta'));
            if ($start > $end || $start >= $endEvent || $end >= $endEvent) {
                return response()->json(["error" => "Start date must be lower than end date or end date of the event"], 403);
            }
        } else {
            if (!$req->daily_limit_qty) {
                return response()->json(["error" => "Ticket for daily event, must be have a limit ticket quantity per days"], 403);
            }
            $req->quantity = -1;
            $start = new DateTime($req->event->start_date, new DateTimeZone('Asia/Jakarta'));
            $end = new DateTime($req->event->end_date, new DateTimeZone('Asia/Jakarta'));
        }
        $ticket = Ticket::create([
            'event_id' => $req->event->id,
            'name' => $req->name,
            'desc' => $req->desc,
            'type_price' => $req->type_price,
            'price' => $req->type_price == 2 ? abs($req->price) : 0,
            'quantity' => $req->quantity,
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
            'seat_number' => $req->enable_seat_number == true ? true : false,
            'max_purchase' => abs($req->max_purchase),
            'deleted' => 0,
        ]);
        if (($req->event->category == 'Attraction' || $req->event->category == 'Daily Activities' || $req->event->category == 'Tour Travel (recurring)')) {
            DailyTicketLimit::create([
                'ticket_id' => $ticket->id,
                'limit_quantity' => abs($req->daily_limit_qty)
            ]);
        }
        return response()->json(["ticket" => $ticket], 201);
    }

    public function update(Request $req)
    {
        $validator = Validator::make($req->all(), [
            'ticket_id' => 'required|string',
            'name' => 'required|string',
            'desc' => 'required|string',
            'type_price' => 'required|string',
            'max_purchase' => 'required|numeric'
        ]);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 403);
        }
        if ($req->type_price != '1' && $req->type_price != '2' && $req->type_price != '3') {
            return response()->json(["error" => "Input type price code had not registered"], 404);
        }
        // Type price had divided by three type
        // => Free (1)
        // => Paid (2)
        // => pay as you like - min 10000 (3)
        if ($req->type_price == '2' && !$req->price) {
            return response()->json(["error" => 'If you have selected code 2 for the type price, please fill the currency field'], 403);
        }
        if ($req->type_price == '2' && intval($req->price) < 10000) {
            return response()->json(["error" => "Sorry. Minimal transaction is IDR Rp. 10.000,-"], 403);
        }
        $start = null;
        $end = null;
        if ($req->event->category != 'Attraction' && $req->event->category != 'Daily Activities' && $req->event->category != 'Tour Travel (recurring)') {
            if (!$req->quantity || !$req->start_date || !$req->end_date) {
                return response()->json(["error" => "Filed quntity, start_date, and end_date is required for this category"], 403);
            }
            try {
                $start = new DateTime($req->start_date, new DateTimeZone('Asia/Jakarta'));
                $end = new DateTime($req->end_date, new DateTimeZone('Asia/Jakarta'));
            } catch (\Throwable $th) {
                return response()->json(["error" => "Invalid format input date or time"], 400);
            }
            $endEvent = new DateTime($req->event->end_date . ' ' . $req->event->end_time, new DateTimeZone('Asia/Jakarta'));
            if ($start > $end || $start >= $endEvent || $end >= $endEvent) {
                return response()->json(["error" => "Start date must be lower than end date or end date of the event"], 403);
            }
        } else {
            if (!$req->daily_limit_qty) {
                return response()->json(["error" => "Ticket for daily event, must be have a limit ticket quantity per days"], 403);
            }
            $req->quantity = -1;
            $start = new DateTime($req->event->start_date, new DateTimeZone('Asia/Jakarta'));
            $end = new DateTime($req->event->end_date, new DateTimeZone('Asia/Jakarta'));
        }
        $updated = Ticket::where('id', $req->ticket_id)->where('event_id', $req->event->id)->where('deleted', 0)->update([
            'name' => $req->name,
            'desc' => $req->desc,
            'type_price' => $req->type_price,
            'price' => $req->price ? abs($req->price) : 0,
            'quantity' => $req->quantity,
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
            'seat_number' => $req->enable_seat_number == true ? true : false,
            'max_purchase' => abs($req->max_purchase)
        ]);
        DailyTicketLimit::where('ticket_id', $req->ticket_id)->delete();
        if (($req->event->category == 'Attraction' || $req->event->category == 'Daily Activities' || $req->event->category == 'Tour Travel (recurring)') && $updated > 0) {
            DailyTicketLimit::create([
                'ticket_id' => $req->ticket_id,
                'limit_quantity' => abs($req->daily_limit_qty)
            ]);
        }
        return response()->json(["updated" => $updated], $updated == 0 ? 404 : 202);
    }

    public function delete(Request $req)
    {
        $ticket = Ticket::where('id', $req->ticket_id)->where('event_id', $req->event->id)->where('deleted', 0);
        if (!$ticket->first()) {
            return response()->json(["error" => "Ticket data not found"], 404);
        }
        if (count($ticket->first()->purchases()->get()) <= 0) {
            $deleted = $ticket->delete();
        } else {
            if (
                $ticket->first()->type_price == 1 &&
                (new DateTime('now', new DateTimeZone('Asia/Jakarta')) < new DateTime($req->event->end_date . ' ' . $req->event->end_time, new DateTimeZone('Asia/Jakarta'))) &&
                ($req->event->category != 'Attraction' && $req->event->category != 'Daily Activities' && $req->event->category != 'Tour Travel (recurring)')
            ) {
                return response()->json(["error" => "You can't remove this ticket. Because your event are in progress and this ticket have linked with selled active tickets"], 403);
            }
            if ($ticket->first()->type_price == 1) {
                $deleted = $ticket->update(["deleted" => 1]);
            } else {
                $fixPurchases = 0;
                foreach ($ticket->first()->purchases()->get() as $purchase) {
                    if ($purchase->payment()->first()->pay_state != 'EXPIRED') {
                        $fixPurchases += 1;
                        break;
                    }
                }
                if (
                    $fixPurchases > 0 &&
                    (new DateTime('now', new DateTimeZone('Asia/Jakarta')) < new DateTime($req->event->end_date . ' ' . $req->event->end_time, new DateTimeZone('Asia/Jakarta'))) &&
                    ($req->event->category != 'Attraction' && $req->event->category != 'Daily Activities' && $req->event->category != 'Tour Travel (recurring)')
                ) {
                    return response()->json(["error" => "You can't remove this ticket. Because your event are in progress and this ticket have linked with selled active tickets"], 403);
                }
                if ($fixPurchases > 0) {
                    $deleted = $ticket->update(["deleted" => 1]);
                } else {
                    $deleted = $ticket->delete();
                }
            }
        }
        return response()->json(["deleted" => $deleted], 202);
    }

    public function get(Request $req)
    {
        $ticket = Ticket::where('id', $req->ticket_id)->where('event_id', $req->event->id)->where('deleted', 0)->first();
        if (!$ticket) {
            return response()->json(["error" => "Ticket data not found"], 404);
        }
        if ($ticket->type_price != 1) {
            $purchases = [];
            foreach ($ticket->purchases()->get() as $purchase) {
                if ($purchase->payment()->first()->pay_state != 'EXPIRED') {
                    $purchases[] = $purchase;
                }
            }
            $ticket->purchases = $purchases;
        } else {
            $ticket->purchases = $ticket->purchases()->get();
        }
        if (($req->event->category == 'Attraction' || $req->event->category == 'Daily Activities' || $req->event->category == 'Tour Travel (recurring)')) {
            $ticket->limit_daily = $ticket->limitDaily()->first();
        }
        return response()->json(["ticket" => $ticket], 200);
    }

    public function getTickets(Request $req)
    {
        $tickets = Ticket::where('event_id', $req->event->id)->where('deleted', 0)->get();
        if (count($tickets) == 0) {
            return response()->json(["error" => "Tickets data not found for this event"], 404);
        }
        foreach ($tickets as $ticket) {
            $purchases = [];
            foreach ($ticket->purchases()->get() as $purchase) {
                if ($ticket->type_price != 1) {
                    if ($purchase->payment()->first()->pay_state != 'EXPIRED') {
                        $purchase->user = $purchase->user()->first();
                        $purchase->payment = $purchase->payment()->first();
                        $purchase->checkin = $purchase->checkin()->first();
                        $purchase->visitDate = $purchase->visitDate()->first();
                        $purchase->seatNumber = $purchase->seatNumber()->first();
                        $purchases[] = $purchase;
                    }
                } else {
                    $purchase->user = $purchase->user()->first();
                    $purchase->payment = $purchase->payment()->first();
                    $purchase->checkin = $purchase->checkin()->first();
                    $purchase->visitDate = $purchase->visitDate()->first();
                    $purchase->seatNumber = $purchase->seatNumber()->first();
                    $purchases[] = $purchase;
                }
            }
            $ticket->purchases = $purchases;
            if (($req->event->category == 'Attraction' || $req->event->category == 'Daily Activities' || $req->event->category == 'Tour Travel (recurring)')) {
                $ticket->limit_daily = $ticket->limitDaily()->first();
            }
        }
        return response()->json(["tickets" => $tickets], 200);
    }
}
