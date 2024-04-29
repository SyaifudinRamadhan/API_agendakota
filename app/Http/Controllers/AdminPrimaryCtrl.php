<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Organization;
use App\Models\Withdraw;
use App\Models\BillAccount;

class AdminPrimaryCtrl extends Controller
{
    //------------- Control by admin for primary data -----------------
    public function updateProfileUser(Request $req)
    {
        if (!$req->user_id) {
            return response()->json(["error" => "user id field can't blank"], 403);
        }
        $userCtrl = new UserCtrl();
        return $userCtrl->updateProfile($req, $req->user_id);
    }

    public function updatePasswordUser(Request $req)
    {
        if (!$req->user_id) {
            return response()->json(["error" => "user id field can't blank"], 403);
        }
        $userCtrl = new UserCtrl();
        return $userCtrl->updatePassword($req, $req->user_id);
    }

    public function userDetail(Request $req)
    {
        if (!$req->user_id) {
            return response()->json(["error" => "user id field can't blank"], 403);
        }
        $userCtrl = new UserCtrl();
        return $userCtrl->getUser($req->user_id);
    }

    public function userDelete(Request $req)
    {
        if (!$req->user_id) {
            return response()->json(["error" => "user id field can't blank"], 403);
        }
        if ($req->is_hard) {
            $userCtrl = new UserCtrl();
            return $userCtrl->hardDeleteUser($req->user_id);
        } else {
            $userCtrl = new UserCtrl();
            return $userCtrl->deleteUser($req->user_id);
        }
    }

    public function getBack(Request $req)
    {
        if (!$req->user_id) {
            return response()->json(["error" => "user id field can't blank"], 403);
        }
        $userCtrl = new UserCtrl();
        return $userCtrl->getBack($req->user_id);
    }

    public function setActive(Request $req)
    {
        if (!$req->user_id) {
            return response()->json(["error" => "user id field can't blank"], 403);
        }
        $userCtrl = new UserCtrl();
        return $userCtrl->setActive($req->user_id);
    }

    public function users(Request $req)
    {
        return response()->json(["users" => User::all()], 200);
    }
    // ================================================================
    public function createOrganization(Request $req)
    {
        if (!$req->user_id) {
            return response()->json(["error" => "user id field can't blank"], 403);
        }
        $orgCtrl = new OrgCtrl();
        return $orgCtrl->create($req, $req->user_id);
    }

    public function updateProfileOrg(Request $req)
    {
        $orgCtrl = new OrgCtrl();
        return $orgCtrl->update($req, true);
    }

    public function deleteOrganization(Request $req)
    {
        $orgCtrl = new OrgCtrl();
        return $orgCtrl->delete($req, true);
    }

    public function getBackOrg(Request $req)
    {
        $objOrg = Organization::where('id', $req->org_id);
        if (!$objOrg->first()) {
            return response()->json(["error" => "Organization data not found"], 404);
        }
        $objOrg->update(["deleted" => 0]);
        return response()->json(["updated" => "Organization data has updated"], 202);
    }

    public function organizationDetail(Request $req)
    {
        $orgCtrl = new OrgCtrl();
        return $orgCtrl->getOrg($req->org_id);
    }

    public function organizations(Request $req)
    {
        return response()->json(["organizations" => Organization::with('user')->get()], 200);
    }
    // ==================================================================
    public function listBank(Request $req)
    {
        $wdCtrl = new WithdrawCtrl();
        return $wdCtrl->banks($req);
    }

    public function deleteBank(Request $req)
    {
        $wdCtrl = new WithdrawCtrl();
        return $wdCtrl->deleteAccount($req);
    }

    public function updateBankStatus(Request $req)
    {
        if ($req->status != 1 && $req->status != 0) {
            return response()->json(["error" => "Status code only 0 or 1"], 403);
        }
        $bankAcc = BillAccount::where('id', $req->bank_acc_id)->where('org_id', $req->org->id);
        if (!$bankAcc->first()) {
            return response()->json(["error" => "Bank account data not found"], 404);
        }
        $bankAcc->update(["status" => 1]);
        return response()->json(["message" => "Update status succeeded"], 202);
    }

    public function listWithdraw(Request $req)
    {
        $wdCtrl = new WithdrawCtrl();
        return $wdCtrl->wds($req, true);
    }

    public function withdrawDetail(Request $req)
    {
        $wdCtrl = new WithdrawCtrl();
        return $wdCtrl->getWd($req, true);
    }

    public function deleteWithdraw(Request $req)
    {
        $wdCtrl = new WithdrawCtrl();
        return $wdCtrl->deleteWd($req, true);
    }

    public function updateWithdrawStatus(Request $req)
    {
        $wdCtrl = new WithdrawCtrl();
        return $wdCtrl->changeStateWd($req);
    }
    // =============================================================
    public function getRefunds(Request $req)
    {
        $pchCtrl = new PchCtrl();
        return $pchCtrl->getRefunds($req, true);
    }

    public function getRefund(Request $req)
    {
        $pchCtrl = new PchCtrl();
        return $pchCtrl->getRefund($req, $req->refund_id, true);
    }

    public function considerationRefund(Request $req)
    {
        $pchCtrl = new PchCtrl();
        return $pchCtrl->considerationRefundMain($req, $req->refund_ids, 0, $req->ticket_id, true);
    }

    public function setFinishRefund(Request $req)
    {
        $pchCtrl = new PchCtrl();
        return $pchCtrl->setFinishRefund($req->refund_id);
    }
    // ==============================================================
    public function getLegalities()
    {
        $legalityCtrl = new LegalityDataCtrl();
        return $legalityCtrl->getLegalities();
    }

    public function getLegality(Request $req)
    {
        $legalityCtrl = new LegalityDataCtrl();
        return $legalityCtrl->getLegality($req);
    }

    public function changeLegalityState(Request $req)
    {
        $legalityCtrl = new LegalityDataCtrl();
        return $legalityCtrl->changeState($req);
    }

    public function legalityDelete(Request $req)
    {
        $legalityCtrl = new LegalityDataCtrl();
        return $legalityCtrl->delete($req);
    }
}
