<?php

namespace App\Http\Controllers;

use App\Models\CustomFieldSurvey;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class SurveyCtrl extends Controller
{
    public function fillSurveyUser(Request $req)
    {
        $user = Auth::user();
        $validator = Validator::make($req->all(), [
            "event_id" => "required|string",
            "survey_ans" => "required|array"
        ]);
        if ($validator->fails()) {
            return response()->json($validator->errors());
        }
        $event = Event::where('id', $req->event_id)->first();
        if (!$event) {
            return response()->json(["error" => "Event data not found"], 404);
        }
        if ($event->custom_fields == null || $event->custom_fields == '') {
            return response()->json(["error" => "Event haven't survey fields"], 404);
        }
        $customFields = explode('|', $event->custom_fields);
        if (!is_array($req->survey_ans)) {
            return response()->json(["error" => "Field survey ans only accept array type"], 403);
        }
        if (count($req->survey_ans) != count($customFields)) {
            return response()->json(["error" => "Total of all answear not match with total of survey quentions"], 403);
        }
        $ansSurvey = null;
        foreach ($req->survey_ans as $ans) {
            if ($ansSurvey == null) $ansSurvey = $ans;
            else $ansSurvey = $ansSurvey . '|' . $ans;
        }
        CustomFieldSurvey::create([
            "user_id" => $user->id,
            "event_id" => $event->id,
            "question_str" => $event->custom_fields,
            "survey_datas" => $ansSurvey
        ]);
        return response()->json(["message" => "Thank you for filling out the survey"], 201);
    }

    public function getSurvey(Request $req)
    {
        $surveyDatas = [];
        foreach ($req->event->surveys()->get() as $data) {
            $data->user = $data->user()->first();
            $data->survey_datas = explode('|', $data->survey_datas);
            $data->question_str = explode('|', $data->question_str);
            $surveyDatas[] = $data;
        }
        return response()->json(["data" => $surveyDatas], 200);
    }
}
