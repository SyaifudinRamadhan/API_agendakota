<?php

namespace App\Http\Controllers;

use App\Mail\TwoFactorNoitication;
use App\Models\TwoFactorAuth;
use App\Models\User;
use DateInterval;
use DateTime;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class TwoFactorCtrl extends Controller
{
    private function generateOTP (Request $req, User $user) {
        $last = TwoFactorAuth::where('user_id', $user->id)->first();
        Log::info("remove-session-data", $last ? (Array)($last) : []);
        if($last){
            TwoFactorAuth::where('user_id', $user->id)->delete();
        }
        $OTP = Str::password(8, true, true, false);
        $exp = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
        $exp->add(new DateInterval($last && $last->state == 0 ? 'PT10M' : 'PT2M'));
        $data = [
            "user_id" => $user->id,
            "token" => $req->header("Authorization"),
            "ip_address" => "",
            "otp_code" => $OTP,
            "state" => false,
            "exp_to_verify" => $exp
        ];
        TwoFactorAuth::create($data);
        Log::info("new-session-data", $data);
        Mail::to($user->email)->send(new TwoFactorNoitication($user, $OTP));
        return response()->json(["message" => "Mohon lakukan verifikasi kembali. Kode OTP telah dikirimkan ke email anda"], 405);
    }

    public function requestOTP (Request $req){
        $token = $req->header("Authorization");
        $user = Auth::user();
        if($user->two_factor == 0){
            return response()->json(["error" => "Mohon maaf. Permintaan kode OTP hanya untuk yang mengaktifkan saja"]);
        }
        $userSession = TwoFactorAuth::where('user_id', $user->id)->first();
        
        $userIp = explode(".",$req->ip());
        $userIp = count($userIp) === 4 ? $userIp[0].".".$userIp[1].".".$userIp[2] : "";

        if($userSession && $userSession->ip_address === $userIp && $userSession->state == 1 && $userSession->token === $token){
            return response()->json(["message" => "Mohon maaf. Kode OTP bisa didapatkan kembali minimal 2 menit dari permintaan terakhir."], 405);
        }else if($userSession && $userSession->ip_address === $userIp && $userSession->state == 1){
            return response()->json(["message" => "Mohon maaf. Kode OTP bisa didapatkan kembali minimal 2 menit dari permintaan terakhir."], 405);
        }else if(
            $userSession && 
            $userSession->ip_address !== $userIp && 
            $req->query('otp_2fa')
        ){
            if(new DateTime('now', new DateTimeZone('Asia/Jakarta')) <= new DateTime($userSession->exp_to_verify, new DateTimeZone('Asia/Jakarta')) && $userSession->otp_code === $req->otp_2fa && $userSession->state == 0 ){
                return response()->json(["message" => "Mohon maaf. Kode OTP bisa didapatkan kembali minimal 2 menit dari permintaan terakhir."], 405);
            }else if(new DateTime('now', new DateTimeZone('Asia/Jakarta')) > new DateTime($userSession->exp_to_verify, new DateTimeZone('Asia/Jakarta'))){
                Log::info('Re-generate OTP on IF Condition Req-OTP '.$user->id.' '.$req->url());
                return $this->generateOtp($req, $user);
            }else{
                return response()->json(["message" => "Kode autentikasi salah. Coba periksa kembali"], 405);
            }
        }else if($userSession && new DateTime('now', new DateTimeZone('Asia/Jakarta')) <= new DateTime($userSession->exp_to_verify, new DateTimeZone('Asia/Jakarta'))){
            return response()->json(["message" => "Mohon maaf. Kode OTP bisa didapatkan kembali minimal 2 menit dari permintaan terakhir."], 405);
        }else{
            Log::info('Re-generate OTP on ELSE Req-OTP '.$user->id. ' '. $req->url());
            return $this->generateOtp($req, $user);
        }
    }
}
