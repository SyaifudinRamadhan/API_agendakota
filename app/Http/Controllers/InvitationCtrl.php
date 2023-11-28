<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use App\Models\User;
use App\Models\Purchase;
use App\Models\Invitation;
use App\Models\Otp;
use App\Mail\InviteEvent;

class InvitationCtrl extends Controller
{
    public function create(Request $req)
    {
        $validator = Validator::make(
            $req->all(), [
            'target_email' => "required|email",
            'purchase_id' => "required|string"
            ]
        );
        if ($validator->fails()) {
            return response()->json($validator->errors(), 403);
        }
        $sender = Auth::user();
        $purchase = Purchase::where('id', $req->purchase_id)->where('user_id', $sender->id)->where('is_mine', true)->first();
        if (!$purchase) {
            return response()->json(["error" =>  "Purchase data not found"], 404);
        }
        if($purchase->checkin()->first()) {
            return response()->json(["error" => "This ticket has checkined"], 403);
        }
        if ($sender->email == $req->target_email) {
            return response()->json(["error" => "You can't invite your self"], 403);
        }
        $newUser = false;
        $otp = "";
        $user = User::where('email', $req->target_email)->first();
        if (!$user) {
            // if (!filter_var($req->email, FILTER_VALIDATE_EMAIL)) {
            //     return response()->json(["error" => "Email is not valid"], 403);
            // }
            $name = explode('@', $req->target_email)[0];
            $user = User::create(
                [
                'f_name' => $name,
                'l_name' => $name,
                'name' => $name,
                'email' => $req->target_email,
                'password' => Hash::make(env('SECRET_PASS_BACKDOOR_OTP_LOGIN')),
                'g_id' => '-',
                'photo' => '/storage/avatars/default.png',
                'is_active' => '0',
                'phone' => '-',
                'linkedin' => '-',
                'instagram' => '-',
                'twitter' => '-',
                'whatsapp' => '-'
                ]
            );
            for ($i = 0; $i < 6; $i++) {
                $otp .= rand(0, 9);
            }
            Otp::create(
                [
                'user_id' => $user->id,
                'otp_code' => $otp
                ]
            );
            $newUser = true;
        }
        Purchase::where('id', $purchase->id)->update(
            [
            'is_mine' => false
            ]
        );
        Invitation::create(
            [
            'user_id' => $sender->id,
            'target_user_id' => $user->id,
            'pch_id' => $purchase->id,
            'response' => 'WAITING'
            ]
        );
        if (!$newUser) {
            Mail::to($user->email)->send(
                new InviteEvent(
                    $user->name,
                    $sender->email,
                    $purchase->ticket()->first()->event()->first()->name,
                    $purchase->ticket()->first()->name
                )
            );
        } else {
            Mail::to($user->email)->send(
                new InviteEvent(
                    $user->email,
                    $sender->email,
                    $purchase->ticket()->first()->event()->first()->name,
                    $purchase->ticket()->first()->name,
                    $otp
                )
            );
        }
        return response()->json(["message" => "invitation has created and sent to target email"], 201);
    }

    public function accept(Request $req)
    {
        $user = Auth::user();
        $invitation = Invitation::where('id', $req->invitation_id)->where('target_user_id', $user->id);
        if (!$invitation->first()) {
            return response()->json(["error" => "Invitation not found"], 404);
        }
        $invitation->update(
            [
            'response' => 'ACCEPTED'
            ]
        );
        Purchase::where('id', $invitation->first()->pch_id)->update(
            [
            'user_id' => $user->id,
            'is_mine' => true
            ]
        );
        return response()->json(["message" => "You have succeeded clain or accept your invitatio"], 202);
    }

    public function delete(Request $req)
    {
        $user = Auth::user();
        $invitation = Invitation::where('id', $req->invitation_id);
        if (!$invitation->first()) {
            return response()->json(["error" => "Invitation not found"], 404);
        }
        if ($invitation->first()->user_id != $user->id && $invitation->first()->target_user_id != $user->id) {
            return response()->json(["error" => "Invitation not found"], 404);
        }
        if ($invitation->first()->response == 'ACCEPTED') {
            return response()->json(["error" => "You can't remove invitation where the status have been ACCEPTED"], 403);
        }
        Purchase::where('id', $invitation->first()->pch_id)->update(
            [
            'is_mine' => true
            ]
        );
        $deleted = $invitation->delete();
        return response()->json(["deleted" => $deleted], 202);
    }

    public function invitationsRcv()
    {
        $invitations = Invitation::where('target_user_id', Auth::user()->id)->where('response', '!=', 'ACCEPTED')->get();
        if (count($invitations) == 0) {
            return response()->json(["error" => "Invitations data not found"], 404);
        }
        foreach ($invitations as $invitation) {
            $invitation->user_receiver = $invitation->userTarget()->first();
            $invitation->purchase = $invitation->purchase()->first();
            $invitation->ticket = $invitation->purchase->ticket()->first();
            $invitation->event = $invitation->ticket->event()->first();
        }
        return response()->json(["invitations" => $invitations], 200);
    }

    public function invitationsSdr()
    {
        $invitations = Invitation::where('user_id', Auth::user()->id)->where('response', '!=', 'ACCEPTED')->get();
        if (count($invitations) == 0) {
            return response()->json(["error" => "Invitations data not found"], 404);
        }
        foreach ($invitations as $invitation) {
            $invitation->user_receiver = $invitation->userTarget()->first();
            $invitation->purchase = $invitation->purchase()->first();
            $invitation->ticket = $invitation->purchase->ticket()->first();
            $invitation->event = $invitation->ticket->event()->first();
        }
        return response()->json(["invitations" => $invitations], 200);
    }
}
