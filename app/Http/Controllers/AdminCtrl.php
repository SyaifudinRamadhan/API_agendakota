<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use App\Models\User;
use App\Models\Category;
use App\Models\Topic;
use App\Models\OrgType;
use App\Models\City;
use App\Models\FrontBanner;
use App\Models\Admin;
use App\Models\Purchase;
use App\Models\Payment;

class AdminCtrl extends Controller
{
    //------------- Control by admin for sekunder data -----------------
    public function createCategory(Request $req){
        $validator = Validator::make($req->all(), [
            "name" => "required|string",
            "photo" => "required|image|max:2048"
        ]);
        if($validator->fails()){
            return response()->json($validator->errors(), 403);
        }
        $fileName = pathinfo($req->file('photo')->getClientOriginalName(), PATHINFO_FILENAME);
        $fileName = $fileName.'_'.time().'.'.$req->file('photo')->getClientOriginalExtension();
        $req->file('photo')->storeAs('public/categories_images', $fileName);
        $fileName = '/storage/categories_images/'.$fileName;
        $category = Category::create([
            'name' => $req->name,
            'photo' => $fileName,
            'priority' => count(Category::all())
        ]);
        return response()->json(["data" => $category], 201);
    }

    public function deleteCategory(Request $req){
        $validator = Validator::make($req->all(), [
            "cat_id" => "required|numeric"
        ]);
        if($validator->fails()){
            return response()->json($validator->errors(), 403);
        }
        $category = Category::where('id',  $req->cat_id);
        $categoryData = $category->first();
        if(!$categoryData){
            return response()->json(["error" => "Data not found"], 404);
        }
        Storage::delete('/public/categories_images/'.explode('/', $categoryData->photo)[3]);
        foreach (Category::where('priority', '>', $categoryData->priority)->get() as $cat) {
            Category::where('id', $cat->id)->update(["priority" => intval($cat->priority) - 1]);
        }
        $category->delete();
        return response()->json(["categories" => Category::orderBy('priority', 'DESC')->get()], 202);
    }

    public function setPriorityPlusCat(Request $req){
        $validator = Validator::make($req->all(), [
            "cat_id" => "required|numeric"
        ]);
        if($validator->fails()){
            return response()->json($validator->errors(), 403);
        }
        $category = Category::where('id', $req->cat_id);
        $categoryData = $category->first();
        if(!$categoryData){
            return response()->json(["error" => "Data not found"], 404);
        }
        if((count(Category::all()) - 1) > $categoryData->priority){
            Category::where('priority', (intval($categoryData->priority) + 1))->update([
                "priority" => $categoryData->priority
            ]);
            $category->update([
                "priority" => intval($categoryData->priority) + 1
            ]);
        }
        return response()->json(["data" => Category::orderBy('priority', 'DESC')->get()], 202);
    }

    public function setPriorityMinCat(Request $req){
        $validator = Validator::make($req->all(), [
            "cat_id" => "required|numeric",
        ]);
        if($validator->fails()){
            return response()->json($validator->errors(), 403);
        }
        $category = Category::where('id', $req->cat_id);
        $categoryData = $category->first();
        if(!$categoryData){
            return response()->json(["error" => "Data not found"], 404);
        }
        if($categoryData->priority > 0){
            Category::where('priority', (intval($categoryData->priority) - 1))->update([
                "priority" => $categoryData->priority
            ]);
            $category->update([
                "priority" => intval($categoryData->priority) - 1
            ]);
        }
        return response()->json(["data" => Category::orderBy('priority', 'DESC')->get()], 202);
    }

    public function categories(Request $req){
        return response()->json(["categories" => Category::orderBy('priority', 'DESC')->get()], 200);
    }
    // ===============================================================
    public function createTopic(Request $req){
        $validator = Validator::make($req->all(), [
            "name" => "required|string"
        ]);
        if($validator->fails()){
            return response()->json($validator->errors(), 403);
        }
        $topic = Topic::create([
            "name" => $req->name
        ]);
        return response()->json(["data" => $topic], 201);
    }

    public function deleteTopic(Request $req){
        $deleted = Topic::where('id', $req->topic_id)->delete();
        return response()->json(["deleted" => $deleted], $deleted == 0 ? 404 : 202);
    }

    public function topics(Request $req){
        return response()->json(["topics" => Topic::all()], 200);
    }
    // ==============================================================
    public function createOrgType(Request $req){
        $validator = Validator::make($req->all(), [
            "name" => "required|string"
        ]);
        if($validator->fails()){
            return response()->json($validator->errors(), 403);
        }
        $orgType = OrgType::create([
            "name" => $req->name
        ]);
        return response()->json(["data" => $orgType], 201);
    }

    public function deleteOrgType(Request $req){
        $deleted = OrgType::where('id', $req->org_type_id)->delete();
        return response()->json(["deleted" => $deleted], $deleted == 0 ? 404 : 202);
    }

    public function orgTypes(Request $req){
        return response()->json(["org_types" => OrgType::all()], 200);
    }
    // ==============================================================
    public function createCity(Request $req){
        $validator = Validator::make($req->all(), [
            "name" => "required|string",
            "photo" => "required|image|max:2048"
        ]);
        $fileName = pathinfo($req->file('photo')->getClientOriginalName(), PATHINFO_FILENAME);
        $fileName = $fileName.'_'.time().'.'.$req->file('photo')->getClientOriginalExtension();
        $req->file('photo')->storeAs('public/city_images', $fileName);
        $fileName = '/storage/city_images/'.$fileName;
        $city = City::create([
            "name" => $req->name,
            "photo" => $fileName,
            'priority' => count(City::all())
        ]);
        return response()->json(["data" => $city], 201);
    }

    public function deleteCity(Request $req){
        $validator = Validator::make($req->all(), [
            "city_id" => "required|numeric"
        ]);
        if($validator->fails()){
            return response()->json($validator->errors(), 403);
        }
        $city = City::where('id', $req->city_id);
        $cityData = $city->first();
        if(!$cityData){
            return response()->json(["error" => "Data not found"], 404);
        }
        Storage::delete('public/city_images/'.explode('/',$cityData->photo)[3]);
        foreach (City::where('priority', '>', $cityData->priority)->get() as $ct) {
            City::where('id', $ct->id)->update([
                "priority" => intval($ct->priority) - 1
            ]);
        }
        $city->delete();
        return response()->json(['cities' => City::orderBy('priority', 'DESC')->get()], 202);
    }

    public function setPriorityPlusCity(Request $req){
        $validator = Validator::make($req->all(), [
            "city_id" => "required|numeric"
        ]);
        if($validator->fails()){
            return response()->json($validator->errors(), 403);
        }
        $city = City::where('id', $req->city_id);
        $cityData = $city->first();
        if(!$cityData){
            response()->json(["error" => "Data not found"], 404);
        }
        if($cityData->priority < (count(City::all()) - 1)){
            City::where('priority', (intval($cityData->priority) + 1))->update([
                "priority" => $cityData->priority
            ]);
            $city->update([
                "priority" => intval($cityData->priority) + 1
            ]);
        }
        return response()->json(["cities" => City::orderBy('priority', 'DESC')->get()], 202);
    }

    public function setPriorityMinCity(Request $req){
        $validator = Validator::make($req->all(), [
            "city_id" => "required|numeric"
        ]);
        if($validator->fails()){
            return response()->json($validator->errors(), 403);
        }
        $city = City::where('id', $req->city_id);
        $cityData  = $city->first();
        if(!$cityData){
            return response()->json(["error" => "Data not found"], 404);
        }
        if($cityData->priority > 0){
            City::where('priority', (intval($cityData->priority) - 1))->update([
                "priority" => $cityData->priority
            ]);
            $city->update([
                "priority" => intval($cityData->priority) - 1
            ]);
        }
        return response()->json(["cities" => City::orderBy('priority', 'DESC')->get()], 202);
    }

    public function cities(Request $req){
        return response()->json(["cities" => City::orderBy('priority', 'DESC')->get()], 200);
    }
    // ==============================================================
    public function createFrontBanner(Request $req){
        $validator = Validator::make($req->all(), [
            "name" => "required|string",
            "url" => "required|active_url",
            "banner" => "required|image|max:2048"
        ]);
        if($validator->fails()){
            return response()->json($validator->errors(), 403);
        }
        $fileName = pathinfo($req->file('banner')->getClientOriginalName(), PATHINFO_FILENAME);
        $fileName = $fileName.'_'.time().'.'.$req->file('banner')->getClientOriginalExtension();
        $req->file('banner')->storeAs('public/banner_images', $fileName);
        $fileName = '/storage/banner_images/'.$fileName;
        $banner = FrontBanner::create([
            "name" => $req->name,
            "url" => $req->url,
            "photo"  => $fileName,
            "priority" => count(FrontBanner::all())
        ]);
        return response()->json(["data" => $banner], 201);
    }

    public function deleteFirstBanner(Request $req){
        $validator = Validator::make($req->all(), [
            "f_banner_id" => "required|numeric"
        ]);
        if($validator->fails()){
            return response()->json($validator->errors(), 403);
        }
        $fBanner = FrontBanner::where('id', $req->f_banner_id);
        $fBannerData = $fBanner->first();
        if(!$fBannerData){
            return response()->json(["error" => "Data not found"], 404);
        }
        Storage::delete('/public/banner_images/'.explode('/', $fBannerData->photo)[3]);
        foreach (FrontBanner::where('priority', '>', $fBannerData->priority)->get() as $fBan) {
            FrontBanner::where('id', $fBan->id)->update(["priority" => intval($fBan->priority) - 1]);
        }
        $fBanner->delete();
        return response()->json(["f_banners" => FrontBanner::orderBy('priority', 'DESC')->get()], 202);
    }

    public function setPriorityPlusFBanner(Request $req){
        $validator = Validator::make($req->all(), [
            "f_banner_id" => "required|numeric"
        ]);
        if($validator->fails()){
            return response()->json($validator->errors(), 403);
        }
        $fBanner = FrontBanner::where('id', $req->f_banner_id);
        $fBannerData = $fBanner->first();
        if(!$fBannerData){
            return response()->json(["error" => "Data not found"], 404);
        }
        if($fBannerData->priority < (count(FrontBanner::all()) - 1)){
            FrontBanner::where('priority', (intval($fBannerData->priority) + 1))->update([
                "priority" => $fBannerData->priority
            ]);
            $fBanner->update([
                "priority" => intval($fBannerData->priority) + 1
            ]);
        }
        return response()->json(["f_banners" => FrontBanner::orderBy('priority', 'DESC')->get()], 202);
    }

    public function setPriorityMinFBanner(Request $req){
        $validator = Validator::make($req->all(), [
            "f_banner_id" => "required|numeric"
        ]);
        if($validator->fails()){
            return response()->json($validator->errors(), 403);
        }
        $fBanner = FrontBanner::where('id', $req->f_banner_id);
        $fBannerData = $fBanner->first();
        if(!$fBanner){
            return response()->json(["error" => "Data not found"], 404);
        }
        if($fBannerData->priority > 0){
            FrontBanner::where('priority', (intval($fBannerData->priority) - 1))->update([
                "priority" => $fBannerData->priority
            ]);
            $fBanner->update([
                "priority" => intval($fBannerData->priority) - 1
            ]);
        }
        return response()->json(["f_banners" => FrontBanner::orderBy('priority', 'DESC')->get()], 202);
    }

    public function frontBanners(Request $req){
        return response()->json(["f_banners" => FrontBanner::orderBy('priority', 'DESC')->get()], 200);
    }
    // ==============================================================
    public function createAdmin(Request $req){
        $validator = Validator::make($req->all(), [
            "user_id" => "required|string"
        ]);
        if($validator->fails()){
            return response()->json($validator->errors(), 403);
        }
        $user = User::where('id', $req->user_id)->first();
        if(!$user){
            return response()->json(["error" => "Data not found"], 404);
        }
        if($user->admin()->first()){
            return response()->json(["error" => "User statuses is already an admin"], 403);
        }
        $admin = Admin::create([
            "user_id" => $req->user_id
        ]);
        $admin->user = $user;
        return response()->json(["data" => $admin], 201);
    }

    public function deleteAdmin(Request $req){
        $validator = Validator::make($req->all(), [
            "admin_id" => "required|string"
        ]);
        if($validator->fails()){
            return response()->json($validator->errors(), 403);
        }
        $admin = Admin::where('id', $req->admin_id);
        $adminData = $admin->first();
        if(!$adminData){
            return response()->json(["error" => "Admin data not found"], 404);
        }
        if(Auth::user()->id == $adminData->user_id){
            return response()->json(["error" => "You can't remove admin status from your own account"], 403);
        }
        $deleted = $admin->delete();
        return response()->json(["deleted" => $deleted], 202);
    }

    public function admins(Request $req){
        $admins = Admin::all();
        foreach ($admins as $admin) {
            $admin->user = $admin->user()->first();
        }
        return response()->json(["admins" => $admins], 200);
    }
    // ===============================================================
    public function deletePch(Request $req){
        $validator = Validator::make($req->all(), [
            "pch_id" => "required|string"
        ]);
        if($validator->fails()){
            return response()->json($validator->errors(), 403);
        }
        $purchase = Purchase::where('id', $req->pch_id);
        $pchData = $purchase->first();
        if(!$pchData){
            return response()->json(["error" => "Purchase data not found"], 404);
        }
        if($pchData->amount != 0 && $pchData->payment()->first()->pay_state == "SUCCEEDED"){
            return response()->json(["error" => "This transaction status is succeeded. You can't remove this transaction"], 403);
        }
        $deleted = $purchase->delete();
        return response()->json(["deleted" => $deleted], 202);
    }

    public function pchDetail(Request $req){
        $purchase = Purchase::where('id', $req->pch_id)->first();
        if(!$purchase){
            $purchase = Purchase::where('pay_id', $req->pay_id)->first();
            if(!$purchase){
                return response()->json(["error" => "Purchase data not found"], 404);
            }
        }
        $purchase->user = $purchase->user()->first();
        $purchase->payment = $purchase->payment()->first();
        $purchase->ticket = $purchase->ticket()->first();
        return response()->json(["purchase" => $purchase], 200);
    }

    public function purchases(Request $req){
        $purchases = Purchase::all();
        foreach ($purchases as $purchase) {
            $purchase->user = $purchase->user()->first();
            $purchase->payment = $purchase->payment()->first();
            $purchase->ticket = $purchase->ticket()->first();
        }
        return response()->json(["purchases" => $purchases], 200);
    }
    // ===============================================================
    public function deletePayment(Request $req){
        $payment = Payment::where('id', $req->pay_id);
        $payData = $payment->first();
        if(!$payData){
            return response()->json(["error" => "Payment data not found"], 404);
        }
        if($payData->pay_state == "SUCCEEDED"){
            return response()->json(["error" => "This transaction status is succeeded. You can't remove this transaction"], 403);
        }
        foreach ($payData->purchases()->get as $purchase) {
            if($purchase->amount == 0){
                return response()->json(["error" => "This transaction status is succeeded. You can't remove this transaction"], 403);
            }
        }
        $deleted = $payment->delete();
        return response()->json(["delted" => $deleted], 202);
    }

    public function paymentDetail(Request $req){
        $payment = Payment::where('id', $req->pay_id)->first();
        if(!$payment){
            return response()->json(["error" => "Payment data not found"], 404);
        }
        $payment->user = $payment->user()->first();
        return response()->json(["payment" => $payment], 200);
    }

    public function payments(Request $req){
        $payemnts = Payment::all();
        foreach ($payemnts as $payment) {
            $payment->user = $payment->user()->first();
        }
        return response()->json(["payments" => $payemnts], 200);
    }
}
