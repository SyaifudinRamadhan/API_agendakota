<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Organization;
use App\Models\User;
use App\Models\Team;
use App\Mail\InviteTeam;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Str;

class OrgCtrl extends Controller
{
    // Create organization (can access admin and user basic)
    public function create(Request $req, $userId = null){
        if($userId == null){
            $userId = Auth::user()->id;
        }else{
            if(!User::where('id', $userId)->first()){
                return response()->json(["error" => "User not found"], 404);
            }
        }
        $validator = Validator::make($req->all(), [
            'type' => 'required|string',
            'name' => 'required|string',
            'interest' => 'required|string',
            'desc' => 'required|string'
        ]);
        if($validator->fails()){
            return response()->json($validator->errors(), 403);
        }
        $org = Organization::create([
            'user_id' => $userId,
            'type' => $req->type,
            'name' => $req->name,
            'slug' => Str::slug($req->name),
            'interest' => $req->interest,
            'desc' => $req->desc,
            'photo' => '/storage/org_avatars/default.png', 
            'banner' => '/storage/org_banners/default.png',
            'email' => '-',
            'linkedin' => '-',
            'instagram' => '-',
            'twitter' => '-',
            'whatsapp' => '-',
            'website' => '-',
            'deleted' => 0,
        ]);
        return response()->json(["data" => $org], 201);
    }

    // Update profile organization (can access admin and user basic)
    public function update(Request $req, $isAdmin = null){
        $validator = Validator::make($req->all(),[
            'org_id' => 'required|string',
            'type' => 'required|string',
            'name' => 'required|string',
            'photo' => 'image|max:2048',
            'banner' => 'image|max:3072',
            'interest' => 'required|string',
            'email' => 'required|string',
            'linkedin' => 'required|string',
            'instagram' => 'required|string',
            'twitter' => 'required|string',
            'whatsapp' => 'required|string',
            'website' => 'required|string',
            'desc' => 'required|string'
        ]);
        if($validator->fails()){
            return response()->json($validator->errors(), 403);
        }
        $orgObj = Organization::where('id', $req->org_id);
        if(!$orgObj->first()){
            return response()->json(["error" => "Data not found or match"], 404);
        }
        if($isAdmin == null){
            $user = Auth::user();
            if($orgObj->first()->user_id != $user->id){
                return response()->json(["error" => "Data not found or match"], 404);
            }
        }
        // handle upload image
        $namePhoto = $orgObj->first()->photo;
        if($req->hasFile('photo')){
            $originName = pathinfo($req->file('photo')->getClientOriginalName(), PATHINFO_FILENAME);
            $namePhoto = $originName.'_'.time().'.'.$req->file('photo')->getClientOriginalExtension();
            $req->file('photo')->storeAs('public/org_avatars', $namePhoto);
            $namePhoto = '/storage/org_avatars/'.$namePhoto;
            if($orgObj->first()->photo != '/storage/org_avatars/default.png'){
                $fileName = explode('/', $orgObj->first()->photo);
                Storage::delete('public/org_avatars/'.$fileName[3]);
            }
        }
        $nameBanner = $orgObj->first()->banner;
        if($req->hasFile('banner')){
            $originalName = pathinfo($req->file('banner')->getClientOriginalName(), PATHINFO_FILENAME);
            $nameBanner = $originalName.'_'.time().'.'.$req->file('banner')->getClientOriginalExtension();
            $req->file('banner')->storeAs('public/org_banners', $nameBanner);
            $nameBanner = '/storage/org_banners/'.$nameBanner;
            if($orgObj->first()->banner != '/storage/org_banners/default.png'){
                $fileName = explode('/', $orgObj->first()->banner);
                Storage::delete('public/org_banners/'.$fileName[3]);
            }
        }
        $updated = $orgObj->update([
            'type' => $req->type,
            'name' => $req->name,
            'slug' => Str::slug($req->name),
            'interest' => $req->interest,
            'desc' => $req->desc,
            'photo' => $namePhoto, 
            'banner' => $nameBanner,
            'email' => $req->email,
            'linkedin' => $req->linkedin,
            'instagram' => $req->instagram,
            'twitter' => $req->twitter,
            'whatsapp' => $req->whatsapp,
            'website' => $req->website,
            'deleted' => 0,
        ]);
        return response()->json(["updated" => $updated], $updated == 0 ? 404 : 200);
    }

    // Delete organiztion (can access admin only)
    public function delete(Request $req, $isAdmin = null){
        $validator = Validator::make($req->all(), ["org_id" => 'required']);
        if($validator->fails()){
            return response()->json($validator->errors(), 403);
        }
        $orgObj = Organization::where('id', $req->org_id);
        if(!$orgObj->first()){
            return response()->json(["error" => "Data not found or match"], 404);
        }
        if($isAdmin == null){
            $user = Auth::user();
            if($orgObj->first()->user_id != $user->id){
                return response()->json(["error" => "Data not found or match"], 404);
            }
        }
        $fixPurchaseActiveEvent = 0;
        $fixPurchaseInActiveEvent = 0;
        foreach ($orgObj->first()->events()->get() as $event) {
            foreach ($event->tickets()->get() as $ticket) {
                foreach ($ticket->purchases()->get() as $purchase) {
                    if(($event->is_publish == 1 || $event->is_publish == 2) && ($purchase->amount == 0 || $purchase->payment()->first()->pay_state != 'EXPIRED')){
                        $fixPurchaseActiveEvent += 1;
                        break;
                    }else if($purchase->amount == 0 || $purchase->payment()->first()->pay_state != 'EXPIRED'){
                        $fixPurchaseInActiveEvent += 1;
                    }
                }
                if($fixPurchaseActiveEvent > 0){
                    break;
                }
            }
            if($fixPurchaseActiveEvent > 0){
                break;
            }
        }
        if($fixPurchaseActiveEvent > 0){
            return response()->json(["error" => "You can't remove this organization, because this organization have active events"], 403);
        }
        $deleted = null;
        if($fixPurchaseInActiveEvent > 0){
            $deleted = $orgObj->update(['deleted' => '1']);
        }else{
            foreach ($orgObj->first()->events()->get() as $event) {
                Storage::delete('public/event_banners/'.explode('/', $event->logo)[3]);
            }
            Storage::delete('public/org_avatars/'.explode($orgObj->first()->photo)[3]);
            Storage::delete('public/org_banners/'.explode($orgObj->first()->banner)[3]);
            $deleted = $orgObj->delete();
        }
        return response()->json(['deleted' => $deleted], 202);
    }

    // Read organization (can access admin and user basic)
    public function getOrg($orgId){
        $org = Organization::where('id', $orgId)->where('deleted', 0)->first();
        if(!$org){
            return response()->json(["error" => "Data not found or match"], 404);
        }
        return response()->json(["organization" => $org], 200);
    }

    public function getOrgsByUser($userId = null){
        if($userId == null){
            $userId = Auth::user()->id;
        }
        $orgs = Organization::where('user_id', $userId)->where('deleted', 0)->get();
        return response()->json(["organizations" => $orgs], count($orgs) == 0 ? 404 : 200);
    }

    // Create teams (admin and user basic)
    public function inviteTeam(Request $req, $orgId){
        $validator = Validator::make($req->all(), [
            "email" => 'required'
        ]);
        if($validator->fails()){
            return response()->json($validator->errors(), 403);
        }
        $org = Organization::where('id', $orgId)->where('deleted', 0)->first();
        if(!$org || Auth::user()->id != $org->user_id){
            return response()->json(["error" => "Data not found or match"], 404);
        }
        $defaultPassword = 'team_'.$org->slug;
        $token = JWT::encode([
            "email" => $req->email,
            "org_id" => $org->id,
            "def_pass" => $defaultPassword
        ], env('JWT_SECRET'), env('JWT_ALG'));
        // send email to target
        Mail::to($req->email)->send(new InviteTeam($req->email, $token, $org->name, $defaultPassword));
        return response()->json(["message" => "Invitation has been sent"], 200);
    }

    // Receive / accept invitation
    public function acceptInviteTeam($token){
        $decoded = '';
        try {
            $decoded = JWT::decode($token, new Key(env('JWT_SECRET'), env('JWT_ALG')));
        } catch (\Throwable $th) {
            return response()->json(["error" => "Signature token is not valid"], 403);
        }
        // check email is registered ? if isn't system will be automatic registered this with default password
        $user = User::where('email', $decoded->email)->first();
        if(!$user){
            $mailSplit = explode("@", $decoded->email);
            $user = User::create([
                'f_name' => $mailSplit[0],
                'l_name' => $mailSplit[0],
                'name' => $mailSplit[0],
                'email' => $decoded->email,
                'password' => $decoded->def_pass,
                'g_id' => '-',
                'photo' => '/storage/avatars/default.png',
                'is_active' => '0',
                'phone' => '-',
                'linkedin' => '-',
                'instagram' => '-',
                'twitter' => '-',
                'whatsapp' => '-'
            ]);
        }
        $team = Team::create([
            'org_id' => $decoded->org_id,
            'user_id' => $user->id
        ]);
        // NOTE : replace with return redirect to react App
        return response()->json(["team" => $team, "user" => $user], 200);
    }

    // Get teams
    public function getTeams($orgId){
        $org = Organization::where('id', $orgId)->where('deleted', 0)->first();
        if(!$org || $org->user_id != Auth::user()->id){
            return response()->json(["error" => "Data not found or not match"], 404);
        }
        $teams = Team::where('org_id', $org->id)->get();
        return response()->json(["teams" => $teams], count($teams) == 0 ? 404 : 200);
    }

    // Delete team (admin and user basic)
    public function deleteTeam(Request $req){
        $team = Team::where('id', $req->team_id);
        $user = Auth::user();
        if(!$team->first()){
            return response()->json(["error" => "Team not found"], 404);
        }
        $org = Organization::where('id', $team->first()->org_id)->where('deleted', 0)->first();
        if($user->id != $org->user_id){
            return response()->json(["error" => "you are isn't an organizer, but only a team member of this organization"], 403);
        }
        $deleted = $team->delete();
        return response()->json(["deleted" => $deleted], 202);
    }
}
