<?php

namespace App\Http\Middleware;

use App\Mail\TwoFactorNoitication;
use App\Models\TwoFactorAuth as ModelsTwoFactorAuth;
use App\Models\User;
use Closure;
use DateInterval;
use DateTime;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Str;
use PhpParser\Node\Expr\Cast\Array_;

class TwoFactorAuth
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */

     /*
        Stucture of user session both for database version

        $_SESSION["{userId}_2FASession"] = [
            "token" => {user token auth},
            "ip_address" => {user ip address from request},
            "otp_code" => {OTP for verify 2FA},
            "state" => {Verification state (pending/verified)},
            "exp_to_verify" => {timestamp for expired OTP to verify}
        ]
     */

    private function generateOtp(Request $req, User $user) {
        $last = ModelsTwoFactorAuth::where('user_id', $user->id)->first();
        Log::info("remove-session-data", $last ? (Array)($last) : []);
        if($last){
            ModelsTwoFactorAuth::where('user_id', $user->id)->delete();
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
        ModelsTwoFactorAuth::create($data);
        Log::info("new-session-data", $data);
        Mail::to($user->email)->send(new TwoFactorNoitication($user, $OTP));
        return response()->json(["message" => "Mohon lakukan verifikasi kembali. Kode OTP telah dikirimkan ke email anda"], 405);
    }

    private function renewSession(String $key, Array $newData) {
        ModelsTwoFactorAuth::where('user_id', $key)->update($newData);
        Log::info("update-session-data", (Array) ModelsTwoFactorAuth::where('user_id', $key)->first());
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header("Authorization");
        $user = Auth::user();
        if($user->two_factor == 0){
            return $next($request);
        }
        $userSession = ModelsTwoFactorAuth::where('user_id', $user->id)->first();
        $userIp = $request->ip();
        if($userSession && $userSession->ip_address === $userIp && $userSession->state == 1 && $userSession->token === $token){
            return $next($request);
        }else if($userSession && $userSession->ip_address === $userIp && $userSession->state == 1){
            $this->renewSession($user->id, [
                "token" => $token
            ]);
            return $next($request);
        }else if($userSession && $userSession->ip_address !== $userIp && $request->query('otp_2fa')){
            if(new DateTime('now', new DateTimeZone('Asia/Jakarta')) <= new DateTime($userSession->exp_to_verify, new DateTimeZone('Asia/Jakarta')) && $userSession->otp_code === $request->otp_2fa && $userSession->state == 0 ){
                $this->renewSession($user->id, [
                    "token" => $token,
                    "ip_address" => $userIp,
                    "state" => true
                ]);
                return $next($request);
            }else if(new DateTime('now', new DateTimeZone('Asia/Jakarta')) > new DateTime($userSession->exp_to_verify, new DateTimeZone('Asia/Jakarta'))){
                return $this->generateOtp($request, $user);
            }else{
                return response()->json(["message" => "Kode autentikasi salah. Coba periksa kembali"], 405);
            }
        }else if($userSession && new DateTime('now', new DateTimeZone('Asia/Jakarta')) <= new DateTime($userSession->exp_to_verify, new DateTimeZone('Asia/Jakarta'))){
            return response()->json(["message" => "Mohon maaf. Kode OTP bisa didapatkan kembali minimal 2 menit dari permintaan terakhir."], 405);
        }else{
            return $this->generateOtp($request, $user);
        }
    }
}
