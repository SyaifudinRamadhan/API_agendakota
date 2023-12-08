<?php

namespace App\Http\Controllers;

use App\Models\CredibilityOrg;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class LegalityDataCtrl extends Controller
{
    public function create(Request $req)
    {
        $legalityData = $req->org->credibilityData()->first();
        if ($legalityData) {
            return $this->update($req);
        }
        $arrayValidator = [
            "type_legality" => "required|string",
            "pic_name" => "required|string",
            "nic_number" => "required|string",
            "nic_image" => "required|image|max:2048",
            "tax_id_number" => "required|string",
            "tax_image" => "required|image|max:2048"
        ];
        if ($req->type_legality === 'perusahaan') {
            $arrayValidator += [
                "company_name" => "required|string",
                "business_entity" => "required|string"
            ];
        }
        $validator = Validator::make($req->all(), $arrayValidator);
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
        $datas = CredibilityOrg::create([
            "org_id" => $req->org->id,
            "type_legality" => $req->type_legality,
            "tax_id_number" => $req->tax_id_number,
            "tax_image" => $namePhotoTax,
            "company_name" => $req->company_name ? $req->company_name : " ",
            "address" => " ",
            'business_entity' => $req->business_entity ? $req->business_entity : " ",
            'pic_name' => $req->pic_name,
            'pic_nic' => $req->nic_number,
            'pic_nic_image' => $namePhoto,
            'company_phone' => " ",
            'status' => false,
        ]);
        return response()->json(["data" => $datas], 201);
    }

    public function update(Request $req)
    {
        $dataLegality = CredibilityOrg::where('org_id', $req->org->id);
        if (!$dataLegality->first()) {
            return $this->create($req);
        }
        $arrayValidator = [
            "type_legality" => "required|string",
            "pic_name" => "required|string",
            "nic_number" => "required|string",
            "nic_image" => "image|max:2048",
            "tax_id_number" => "required|string",
            "tax_image" => "image|max:2048"
        ];
        if ($req->type_legality === 'perusahaan') {
            $arrayValidator += [
                "company_name" => "required|string",
                "business_entity" => "required|string"
            ];
        }
        $validator = Validator::make($req->all(), $arrayValidator);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 403);
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
        $dataLegality->update([
            "org_id" => $req->org->id,
            "type_legality" => $req->type_legality,
            "tax_id_number" => $req->tax_id_number,
            "tax_image" => $namePhotoTax,
            "company_name" => $req->company_name ? $req->company_name : " ",
            "address" => " ",
            'business_entity' => $req->business_entity ? $req->business_entity : " ",
            'pic_name' => $req->pic_name,
            'pic_nic' => $req->nic_number,
            'pic_nic_image' => $namePhoto,
            'company_phone' => " ",
            'status' => false,
        ]);
        return response()->json(["data" => $dataLegality->first()], 202);
    }

    public function getLegality(Request $req)
    {
        $data = null;
        if ($req->org) {
            $data = $req->org->credibilityData()->first();
        } else {
            $org = Organization::where('id', $req->org_id)->first();
            if (!$org) {
                return response()->json(["error" => "Organization data not found"], 404);
            }
            $data = $org->credibilityData()->first();
        }
        return response()->json(["data" => $data], 200);
    }

    // Access by admin only
    public function getLegalities()
    {
        $orgs = Organization::all();
        $verifiedLegalities = [];
        $unverifiedLegalities = [];
        foreach ($orgs as $org) {
            $org->legality_data = $org->credibilityData()->first();
            if ($org->legality_data && $org->legality_data->status === true) {
                $verifiedLegalities[] = $org;
            } else if ($org->legality_data && $org->legality_data->status === false) {
                $unverifiedLegalities[] = $org;
            }
        }
        return response()->json(["verified" => $verifiedLegalities, "unveried" => $unverifiedLegalities], 200);
    }

    public function changeState(Request $req)
    {
        $data = CredibilityOrg::where('id', $req->id);
        if (!$data->first()) {
            return response()->json(["error" => "Data not found"], 404);
        }
        $data->update([
            "status" => $req->status == true ? true : false
        ]);
        return response()->json(["data" => $data->first()], 202);
    }
}
