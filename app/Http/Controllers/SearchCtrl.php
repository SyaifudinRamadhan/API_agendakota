<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\ViralCity;
use DateTime;
use DateTimeZone;
use Illuminate\Http\Request;

class SearchCtrl extends Controller
{
    private function countPurchases($event)
    {
        $total = 0;
        foreach ($event->tickets()->get() as $ticket) {
            $total += count($ticket->purchases()->get());
        }
        return $total;
    }

    private function basePopEvents($events)
    {
        for ($i = 0; $i < count($events); $i++) {
            $selectedIndex = $i;
            $selectedValue = $this->countPurchases($events[$selectedIndex]);
            for ($j = $i + 1; $j < count($events); $j++) {
                $toCompare = $this->countPurchases($events[$selectedIndex + $j]);
                if ($selectedValue > $toCompare) {
                    $selectedValue = $toCompare;
                    $selectedIndex = $j;
                }
            }
            if ($selectedIndex != $i) {
                $tmp = $events[$i];
                $events[$i] = $events[$selectedIndex];
                $events[$selectedIndex] = $tmp;
                $tmp = null;
            }
            $events[$i]->available_days = $events[$i]->availableDays()->get();
            $events[$i]->org = $events[$i]->org()->first();
            $events[$i]->tickets = $events[$i]->tickets()->orderBy('price', 'ASC')->get();
        }
        return $events;
    }

    public function popularEvents()
    {
        $now = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
        $events = Event::where('end_date', '>=', $now->format('Y-m-d'))->where('is_publish', 2)->get();
        return response()->json(["events" => $this->basePopEvents($events)], 200);
    }

    public function popularCityEvents()
    {
        $now = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
        $city = ViralCity::first()->city()->first();
        $events = Event::where('end_date', '>=', $now->format('Y-m-d'))->where('city', 'like', '%' . $city->name . '%')->where('is_publish', 2)->get();
        return response()->json(["city" => $city, "events" => $this->basePopEvents($events)], 200);
    }

    public function searchEvents(Request $req)
    {
        $whereClauses = [];
        $whereClauses[] = [
            'is_publish', '=', 2
        ];
        if ($req->event_name) {
            $whereClauses[] = [
                'name', 'like', '%' . $req->event_name . '%'
            ];
        }
        if ($req->category) {
            $whereClauses[] = [
                'category', '=', $req->category
            ];
        }
        if ($req->start_date) {
            $start = new DateTime($req->start_date, new DateTimeZone('Asia/Jakarta'));
            $whereClauses[] = [
                'end_date', '>=', $start->format('Y-m-d')
            ];
        } else {
            $now = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
            $whereClauses[] = [
                'end_date', '>=', $now->format('Y-m-d')
            ];
        }
        if ($req->until_date && $req->start_date && ($req->category != 'Attraction' && $req->category != 'Daily Activities' && $req->category != 'Tour Travel (recurring)')) {
            $end = new DateTime($req->until_date, new DateTimeZone('Asia/Jakarta'));
            $whereClauses[] = [
                'end_date', '<=', $end->format('Y-m-d')
            ];
        }
        if ($req->city) {
            $whereClauses[] = [
                'city', 'like', '%' . $req->city . '%'
            ];
        }
        if ($req->exe_type) {
            $whereClauses[] = [
                'exe_type', '=', $req->exe_type
            ];
        }
        $events = [];
        foreach (Event::where($whereClauses)->get() as $event) {
            $state = true;
            if ($req->org_name && !str_contains($event->org()->first()->name, $req->org_name)) {
                $state = false;
            }
            if ($req->start_price && count($event->tickets()->where('price', '>=', intval($req->start_price))->get()) == 0) {
                $state = false;
            }
            if ($req->until_price && $req->start_price && count($event->tickets()->where('price', '>=', intval($req->until_price))->get()) == 0) {
                $state = false;
            }
            if ($state) {
                $events[] = $event;
            }
        }
        return response()->json(["events" => $this->basePopEvents($events)], 200);
    }
}
