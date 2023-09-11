<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Mail\Verification;
use App\Mail\ResetPassword;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class Authenticate extends Controller
{
    // ***************** NOTE *****************
    // This auth, using sanctum middleware. Because that, to get user data in all process after middleware isLogin, can be accessed by keyword $req->user or Auth::user().
    // ****************************************

    //Register account
    public function register(Request $req){
        $validator = Validator::make($req->all(), [
            'f_name' => 'required|string',
            'l_name' => 'required|string',
            'name' => 'required|string',
            'email' => 'required|string|unique:users',
            'password' => 'required|string',
            'photo' => 'image|max:2048',
            'phone' => 'required|string',
            'linkedin' => 'required|string',
            'instagram' => 'required|string',
            'twitter' => 'required|string',
            'whatsapp' => 'required|string'
        ]);

        if($validator->fails()){
            return response()->json($validator->errors(), 403);
        }

        // create uniq filename
        $namePhoto = '/storage/avatars/default.png';
        if($req->hasFile('photo')){
            $originName = pathinfo($req->file('photo')->getClientOriginalName(), PATHINFO_FILENAME);
            $namePhoto = $originName.'_'.time().'.'.$req->file('photo')->getClientOriginalExtension();

            // save image data
            $req->file('photo')->storeAs('public/avatars', $namePhoto);
            $namePhoto = '/storage/avatars/'.$namePhoto;
        }

        $user = User::create([
            'f_name' => $req->f_name,
            'l_name' => $req->l_name,
            'name' => $req->name,
            'email' => $req->email,
            'password' => Hash::make($req->password),
            'g_id' => '-',
            'photo' => $namePhoto,
            'is_active' => '0',
            'phone' => $req->phone,
            'linkedin' => $req->linkedin,
            'instagram' => $req->instagram,
            'twitter' => $req->twitter,
            'whatsapp' => $req->whatsapp
        ]);

        $tokenVerify = JWT::encode(['sub' => $user->id], env('JWT_SECRET'), env('JWT_ALG'));

        // Mail handler to verify
        Mail::to($req->email)->send(new Verification($user->name, $tokenVerify));

        return response()->json([
            'data' => $user,
        ], 201);
    }

    //Login account v. standart
    public function login(Request $req){
        if(!Auth::attempt($req->only('email', 'password'))){
            return response()->json(['error'=>'email or password not found'], 404);
        }

        $user = User::where('email', $req->email)->first();

        if(!$user || $user->is_active != '1'){
            return response()->json(['error' => 'Unauthorized, your account is not active. Please confirm your account first'], 401);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'data' => $user,
            'access_token' => $token,
            'token_type' => 'Bearer'
        ], 200);
    }

    //login account v. g_id
    private function registerWithGoogle($credential){
    // This credential is remaked from frontend by google one tap reponse. Not from google response credential
        $payloadAcc = '';
        try {
            $payloadAcc = JWT::decode($credential, new Key(env('JWT_SECRET'), env('JWT_ALG')));
        } catch (\Throwable $th) {
            return response()->json([
                'error' => 'Invalid signature credential',
            ], 403);
        }
        $user = User::create([
            'f_name' => $payloadAcc->given_name,
            'l_name' => $payloadAcc->family_name,
            'name' => $payloadAcc->name,
            'email' => $payloadAcc->email,
            'password' => Hash::make(env('SECRET_PASS_BACKDOOR_GOOGLE_LOGIN')),
            'g_id' => $payloadAcc->sub,
            'photo' => '/storage/avatars/default.png',
            'is_active' => '0',
            'phone' => '-',
            'linkedin' => '-',
            'instagram' => '-',
            'twitter' => '-',
            'whatsapp' => '-'
        ]);
        
        $tokenVerify = JWT::encode(['sub' => $user->id], env('JWT_SECRET'), env('JWT_ALG'));

        // Mail handler to verify
        Mail::to($payloadAcc->email)->send(new Verification($payloadAcc->name, $tokenVerify));

        return response()->json([
            'data' => $user,
        ], 201);
    }

    public function loginGoogle(Request $req){
        $validator = Validator::make($req->all(), [
            'email' => 'required|string',
            'credential' => 'required|string'
        ]);
         // This credential is remaked from frontend by google one tap reponse. Not from google response credential
        if($validator->fails()){
            return response()->json($validator->errors(), 403);
        }

        $user = User::where('email', $req->email)->first();
        if(!$user){
            return $this->registerWithGoogle($req->credential);
        }else{
            $payloadAcc = '';
            try {
                $payloadAcc = JWT::decode($req->credential, new Key(env('JWT_SECRET'), env('JWT_ALG')));
            } catch (\Throwable $th) {
                return response()->json([
                    'error' => 'Invalid signature credential',
                ], 403);
            }

            if($user->g_id != $payloadAcc->sub || $user->is_active != '1'){
                return response()->json(['error' => 'Unauthorized, your account is not active. Or not registered'], 401);
            }

            return response()->json([
                'data' => $user,
                'access_token' => $user->createToken('auth_token')->plainTextToken,
                'token_type' => 'Bearer'
            ], 200);
        }
    }

    //logout account
    public function logout(Request $req){
        Auth::user()->tokens()->delete();
        return response()->json(['message' => 'Logout success'], 200);
    }

    //Verify account
    public function verify($subId){
        $payload = '';
        try {
            $payload = JWT::decode($subId, new Key(env('JWT_SECRET'), env('JWT_ALG')));
        } catch (\Throwable $th) {
            return response()->json([
                'error' => 'Invalid signature credential',
            ], 403);
        }
        $user = User::where('id', $payload->sub)->update([
            'is_active' => '1'
        ]);
        // NOTE : Replace response with redirect to ReactApp
        return response()->json([
            'updated' => $user,
            'message' => $user == 0 ? 'User account not found' : 'User account has been updated'
        ], $user == 0 ? 404 : 200);
    }

    //forget password (send email)
    public function requestResetPass(Request $req){
        $validator = Validator::make($req->all(), [
            'email' => 'required'
        ]);

        if($validator->fails()){
            return response()->json($validator->errors(), 403);
        }
        
        $user = User::where('email', $req->email)->first();
        if(!$user){
            return response()->json(['error' => 'email account not found'],404);
        }
        $tokenReset = JWT::encode(['sub' => $user->id], env('JWT_SECRET'), env('JWT_ALG'));
        
        // Mail handler to send email reset confirm
        Mail::to($user->email)->send(new ResetPassword($user->name, $tokenReset));

        return response()->json([
            'message' => 'We have send confirmation email to reset your password'
        ], 200);
    }

    //reset password
    public function resetPassword(Request $req){
        $validator = Validator::make($req->all(),[
            'token_reset' => 'required',
            'new_password' => 'required',
            'confirm_new_pass' => 'required'
        ]);

        if($validator->fails()){
            return response()->json($validator->fails(), 403);
        }
        if($req->new_password != $req->confirm_new_pass){
            return response()->json(['error' => 'Please check your new password and confirm new password again'], 403);
        }

        $payload = '';
        try {
            $payload = JWT::decode($req->token_reset, new Key(env('JWT_SECRET'), env('JWT_ALG')));
        } catch (\Throwable $th) {
            //throw $th;
            return response()->json(['error' => 'Invalid signature'], 403);
        }
        $user = User::where('id', $payload->sub)->update([
            'password' => Hash::make($req->new_password)
        ]);

        return response()->json([
            'message' => $user == 0 ? 'Failed update password / user not found' : 'Your password has been updated'
        ], $user == 0 ? 404 : 200);
    }
}
