<?php

namespace App\Http\Controllers;

use App\Models\CredibilityOrg;
use App\Models\LegalityUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class LegalityDataCtrl extends Controller
{
    public function createFPersonal(Request $req)
    {
        $user = Auth::user();
        if ($user->legality()->first() != null) {
            return response()->json(["error" => "Legality data has existed, you can only update your data's"], 403);
        }
        $validator = Validator::make($req->all(), [
            "name" => 'required|string',
            'personal_tax_id_number' => 'required|string',
            "nic" => "required|string",
            "nic_image" => "required|image|max:2048",
            "tax_image" => "required|image|max:2048"
        ]);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 403);
        }
        $namePhoto = pathinfo($req->file('nic_image')->getClientOriginalName(), PATHINFO_FILENAME);
        $namePhoto .= '_' . time() . '.' . $req->file('nic_image')->getClientOriginalExtension();
        $req->file('nic_image')->storeAs('public/legality_datas', $namePhoto);
        $namePhoto = '/storage/legality_datas/' . $namePhoto;
        $namePhotoTax = pathinfo($req->file('tax_image')->getClientOriginalName(), PATHINFO_FILENAME);
        $namePhotoTax .= '_' . time() . '.' . $req->file('tax_image')->getClientOriginalExtension();
        $req->file('tax_image')->storeAs('public/legality_datas', $namePhotoTax);
        $namePhotoTax = '/storage/legality_datas/' . $namePhotoTax;
        $datas = LegalityUser::create([
            "user_id" => $user->id,
            "name" => $req->name,
            'personal_tax_id_number' => $req->personal_tax_id_number,
            "nic" => $req->nic,
            "nic_images" => $namePhoto,
            "tax_image" => $namePhotoTax
        ]);
        return response()->json(["data" => $datas], 201);
    }

    public function updateFPersonal(Request $req)
    {
        $user = Auth::user();
        $data = $user->legality()->first();
        if (!$data) {
            return $this->createFPersonal($req);
        }
        $validator = Validator::make($req->all(), [
            "name" => 'required|string',
            'personal_tax_id_number' => 'required|string',
            "nic" => "required|string",
            "nic_image" => "image|max:2048",
            "tax_image" => "image|max:2048"
        ]);
        if ($validator->fails()) {
            return response()->json($validator->fails(), 403);
        }
        $namePhoto = $data->nic_images;
        if ($req->hasFile('nic_image')) {
            $namePhoto = pathinfo($req->file('nic_image')->getClientOriginalName(),  PATHINFO_FILENAME);
            $namePhoto .= '_' . time() . '.' . $req->file('nic_image')->getClientOriginalExtension();
            $req->file('nic_image')->storeAs('public/legality_datas', $namePhoto);
            $namePhoto = '/storage/legality_datas/' . $namePhoto;
            Storage::delete('public/legality_datas/' . explode('/', $data->nic_images)[3]);
        }
        $namePhotoTax = $data->tax_image;
        if ($req->hasFile('tax_image')) {
            $namePhotoTax = pathinfo($req->file('tax_image')->getClientOriginalName(),  PATHINFO_FILENAME);
            $namePhotoTax .= '_' . time() . '.' . $req->file('tax_image')->getClientOriginalExtension();
            $req->file('tax_image')->storeAs('public/legality_datas', $namePhotoTax);
            $namePhotoTax = '/storage/legality_datas/' . $namePhotoTax;
            Storage::delete('public/legality_datas/' . explode('/', $data->tax_image)[3]);
        }
        $updated = LegalityUser::where('id', $data->id)->update([
            "name" => $req->name,
            'personal_tax_id_number' => $req->personal_tax_id_number,
            "nic" => $req->nic,
            "nic_images" => $namePhoto,
            "tax_image" => $namePhotoTax
        ]);
        return response()->json(["updated" => $updated], 200);
    }

    public function createFOrg(Request $req)
    {
        $org = Auth::user()->organizations()->where('id', $req->org_id)->first();
        if (!$org) {
            return response()->json(["error" => "Organization not found"], 404);
        }
        if ($org->credibilityData()->first() != null) {
            return response()->json(["error" => "Legality data has existed, you can only update your data's"], 403);
        }
        $validator = Validator::make($req->all(), [
            'business_entity' => "required|string",
            'pic_name' => "required|string",
            'pic_nic' => "required|string",
            'pic_nic_image' => "required|image|max:2048",
            "tax_image" => "required|image|max:2048",
            'company_phone' => "required|string",
            "tax_id_number" => "required|string",
            "company_name" => "required|string",
            "address" => "required|string"
        ]);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 403);
        }
        $nameFile = pathinfo($req->file('pic_nic_image')->getClientOriginalName(), PATHINFO_FILENAME);
        $nameFile .= '_' . time() . '.' . $req->file('pic_nic_image')->getClientOriginalExtension();
        $req->file('pic_nic_image')->storeAs('public/legality_datas', $nameFile);
        $nameFile = '/storage/legality_datas/' . $nameFile;
        $namePhotoTax = pathinfo($req->file('tax_image')->getClientOriginalName(), PATHINFO_FILENAME);
        $namePhotoTax .= '_' . time() . '.' . $req->file('tax_image')->getClientOriginalExtension();
        $req->file('tax_image')->storeAs('public/legality_datas', $namePhotoTax);
        $namePhotoTax = '/storage/legality_datas/' . $namePhotoTax;
        $data = CredibilityOrg::create([
            "org_id" => $org->id,
            "tax_id_number" => $req->tax_id_number,
            "company_name" => $req->company_name,
            "address" => $req->address,
            'business_entity' => $req->business_entity,
            'pic_name' => $req->pic_name,
            'pic_nic' => $req->pic_nic,
            'pic_nic_image' => $nameFile,
            "tax_image" => $namePhotoTax,
            'company_phone' => $req->company_phone
        ]);
        return response()->json(["data" => $data], 201);
    }

    public function updateFOrg(Request $req)
    {
        $org = Auth::user()->organizations()->where('id', $req->org_id)->first();
        if (!$org) {
            return response()->json(["error" => "Organization not found"], 404);
        }
        $data = $org->credibilityData()->first();
        if (!$data) {
            return $this->createFOrg($req);
        }
        $validator = Validator::make($req->all(), [
            'business_entity' => "required|string",
            'pic_name' => "required|string",
            'pic_nic' => "required|string",
            'pic_nic_image' => "image|max:2048",
            "tax_image" => "image|max:2048",
            'company_phone' => "required|string",
            "tax_id_number" => "required|string",
            "company_name" => "required|string",
            "address" => "required|string"
        ]);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 403);
        }
        $nameFile = $data->pic_nic_image;
        if ($req->hasFile('pic_nic_image')) {
            $nameFile = pathinfo($req->file('pic_nic_image')->getClientOriginalName(), PATHINFO_FILENAME);
            $nameFile .= '_' . time() . '.' . $req->file('pic_nic_image')->getClientOriginalExtension();
            $req->file('pic_nic_image')->storeAs('public/legality_datas', $nameFile);
            $nameFile = '/storage/legality_datas/' . $nameFile;
            Storage::delete('public/legality_datas/' . explode('/', $data->pic_nic_image)[3]);
        }
        $namePhotoTax = $data->tax_image;
        if ($req->hasFile('tax_image')) {
            $namePhotoTax = pathinfo($req->file('tax_image')->getClientOriginalName(),  PATHINFO_FILENAME);
            $namePhotoTax .= '_' . time() . '.' . $req->file('tax_image')->getClientOriginalExtension();
            $req->file('tax_image')->storeAs('public/legality_datas', $namePhotoTax);
            $namePhotoTax = '/storage/legality_datas/' . $namePhotoTax;
            Storage::delete('public/legality_datas/' . explode('/', $data->tax_image)[3]);
        }
        $updated = CredibilityOrg::where('id', $data->id)->update([
            "org_id" => $org->id,
            "tax_id_number" => $req->tax_id_number,
            "company_name" => $req->company_name,
            "address" => $req->address,
            'business_entity' => $req->business_entity,
            'pic_name' => $req->pic_name,
            'pic_nic' => $req->pic_nic,
            'pic_nic_image' => $nameFile,
            'company_phone' => $req->company_phone,
            "tax_image" => $namePhotoTax
        ]);
        return response()->json(["updated" => $updated], 202);
    }

    public function getFPersonal()
    {
        $data = Auth::user()->Legality()->first();
        if (!$data) {
            return response()->json(["error" => "Personal legality data not found"], 404);
        }
        return response()->json(["data" => $data], 200);
    }

    public function getFOrg(Request $req)
    {
        $org = Auth::user()->organizations()->where('id', $req->org_id)->first();
        if (!$org) {
            return response()->json(["error" => "Organization data not found"], 404);
        }
        $data = $org->credibilityData()->first();
        if (!$data) {
            return response()->json(["error" => "Organization legality data not found"], 404);
        }
        return response()->json(["data" => $data], 200);
    }
}
