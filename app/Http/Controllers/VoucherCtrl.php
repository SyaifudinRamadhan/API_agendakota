<?php

namespace App\Http\Controllers;

use App\Models\Voucher;
use DateTime;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class VoucherCtrl extends Controller
{
    public function create(Request $req)
    {
        $validator = Validator::make($req->all(), [
            "name" => 'required|string',
            "code" => "required|string|unique:vouchers",
            "discount" => "required|numeric",
            "quantity" => "required|numeric",
            "start" => "required|date",
            "end" => "required|date"
        ]);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 403);
        }
        $start = new DateTime($req->start, new DateTimeZone('Asia/Jakarta'));
        $end = new DateTime($req->end, new DateTimeZone('Asia/Jakarta'));
        $endEvent = new DateTime($req->event->end_date, new DateTimeZone('Asia/Jakarta'));
        if ($start > $end || $start > $endEvent || $end >= $endEvent) {
            return response()->json(["error" => "Start time can't greater than end time"], 403);
        }
        $voucher = Voucher::create([
            'event_id' => $req->event->id,
            'name' => $req->name,
            'code' => $req->code,
            'discount' => abs($req->discount),
            'quantity' => abs($req->quantity),
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
        ]);
        return response()->json(["voucher" => $voucher], 201);
    }

    public function update(Request $req)
    {
        $validator = Validator::make($req->all(), [

            "name" => 'required|string',
            "discount" => "required|numeric",
            "quantity" => "required|numeric",
            "start" => "required|date",
            "end" => "required|date"
        ]);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 403);
        }
        $start = new DateTime($req->start, new DateTimeZone('Asia/Jakarta'));
        $end = new DateTime($req->end, new DateTimeZone('Asia/Jakarta'));
        $endEvent = new DateTime($req->event->end_date, new DateTimeZone('Asia/Jakarta'));
        if ($start > $end || $start > $endEvent || $end >= $endEvent) {
            return response()->json(["error" => "Start time can't greater than end time"], 403);
        }
        $voucher = Voucher::where('id', $req->voucher_id)->where('event_id', $req->event->id);
        if (!$voucher->first()) {
            return response()->json(["error" => "Voucher data not found"], 404);
        }
        $updated = $voucher->update([
            'name' => $req->name,
            'code' => $voucher->first()->code,
            'discount' => abs($req->discount),
            'quantity' => abs($req->quantity),
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
        ]);
        return response()->json(["updated" => $updated], 200);
    }

    public function delete(Request $req)
    {
        $deleted = Voucher::where('id', $req->voucher_id)->where('event_id', $req->event->id)->delete();
        return response()->json(["deleted" => $deleted], $deleted == 0 ? 404 : 202);
    }

    public function get(Request $req)
    {
        $voucher = Voucher::where('id', $req->voucher_id)->first();
        if (!$voucher) {
            return response()->json(["error" => "Voucher not found"], 404);
        }
        return response()->json(["voucher" => $voucher], 200);
    }

    public function gets(Request $req)
    {
        $eventId = $req->event_id;
        $eventId == null ? $eventId = $req->event->id : null;
        $vouchers = Voucher::where('event_id', $eventId)->get();
        return response()->json(["vouchers" => $vouchers], count($vouchers) == 0 ? 404 : 200);
    }
}
