<?php

namespace App\Http\Controllers\Api;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use \Kreait\Firebase\Contract\Messaging;
use Illuminate\Support\Facades\Validator;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\ApnsConfig;
use Error;

class LoginController extends Controller
{

  public function login(Request $request)
  {
    $validator = Validator::make($request->all(), [
      'avatar' => 'required',
      'name' => 'required',
      'type' => 'required',
      'open_id' => 'required',
      'email' => 'max:50',
      'phone' => 'max:30',
    ]);
    if ($validator->fails()) {
      return ["code" => -1, "data" => "", "msg" => $validator->errors()->first()];
    }
    try {
      //1:email，2:google,  3:facebook,4 apple,5 phone
      $validated = $validator->validated();

      $map = [];
      $map["type"] = $validated["type"];
      $map["open_id"] = $validated["open_id"];

      $res = DB::table("users")->select("avatar", "name", "description", "type", "token", "access_token", "online")->where($map)->first();
      if (empty($res)) {
        $validated["token"] = md5(uniqid() . rand(10000, 99999));
        $validated["created_at"] = Carbon::now();
        $validated["access_token"] = md5(uniqid() . rand(1000000, 9999999));
        $validated["expire_date"] = Carbon::now()->addDays(30);
        $user_id = DB::table("users")->insertGetId($validated);
        $user_res = DB::table("users")->select("avatar", "name", "description", "type", "access_token", "token", "online")->where("id", "=", $user_id)->first();
        return ["code" => 0, "data" => $user_res, "msg" => "success"];
      }

      $access_token = md5(uniqid() . rand(1000000, 9999999));
      $expire_date = Carbon::now()->addDays(30);
      DB::table("users")->where($map)->update(["access_token" => $access_token, "expire_date" => $expire_date]);
      $res->access_token = $access_token;

      return ["code" => 0, "data" => $res, "msg" => "success"];
    } catch (Exception $e) {
      return ["code" => -1, "data" => "", "msg" => $e];
    }
  }

  public function get_profile(Request $request)
  {
    $token = $request->user_token;
    $res = DB::table("users")->select("avatar", "name", "description", "online")->where("token", "=", $token)->first();

    return ["code" => 0, "data" => $res, "msg" => "success"];
  }

  public function update_profile(Request $request)
  {
    $token = $request->user_token;

    $validator = Validator::make($request->all(), [
      'online' => 'required',
      'description' => 'required',
      'name' => 'required',
      'avatar' => 'required',
    ]);
    if ($validator->fails()) {
      return ["code" => -1, "data" => "", "msg" => $validator->errors()->first()];
    }
    try {

      $validated = $validator->validated();

      $map = [];
      $map["token"] = $token;

      $res = DB::table("users")->where($map)->first();
      if (!empty($res)) {

        $validated["updated_at"] = Carbon::now();
        DB::table("users")->where($map)->update($validated);

        return ["code" => 0, "data" => "", "msg" => "success"];
      }

      return ["code" => -1, "data" => "", "msg" => "error"];
    } catch (Exception $e) {
      return ["code" => -1, "data" => "", "msg" => "error"];
    }
  }

  public function bind_fcmtoken(Request $request)
  {
    $token = $request->user_token;
    $fcmtoken = $request->input("fcmtoken");

    if (empty($fcmtoken)) {
      return ["code" => -1, "data" => "", "msg" => "error"];
    }

    DB::table("users")->where("token", "=", $token)->update(["fcmtoken" => $fcmtoken]);

    return ["code" => 0, "data" => "", "msg" => "success"];
  }

  public function contact(Request $request)
  {
    $token = $request->user_token;
    $res = DB::table("users")->select("avatar", "name", "description", "online", "token")->where("token", "!=", $token)->get();
    return ["code" => 0, "data" => $res, "msg" => "success"];
  }
  public function send_notice(Request $request)
  {
    $user_token = $request->user_token;
    $user_avatar = $request->user_avatar;
    $user_name = $request->user_name;
    $to_token = $request->input("to_token");
    $to_name = $request->input("to_name");
    $to_avatar = $request->input("to_avatar");
    $call_type = $request->input("call_type");
    $doc_id = $request->input("doc_id");
    if (empty($doc_id)) {
      $doc_id = "";
    }
    ////1. voice 2. video 3. text, 4.cancel
    $res = DB::table("users")->select("avatar", "name", "token", "fcmtoken")->where("token", "=", $to_token)->first();
    if (empty($res)) {
      return ["code" => -1, "data" => "", "msg" => "user not exist"];
    }

    $deviceToken = $res->fcmtoken;

    if (!empty($deviceToken)) {

      $messaging = app('firebase.messaging');
      if ($call_type == "cancel") {
        $message = CloudMessage::fromArray([
          'token' => $deviceToken, // optional
          'data' => [
            'token' => $user_token,
            'avatar' => $user_avatar,
            'name' => $user_name,
            'doc_id' => $doc_id,
            'call_type' => $call_type,
          ]
        ]);

        $messaging->send($message);
      } else if ($call_type == "voice") {

        $message = CloudMessage::fromArray([
          'token' => $deviceToken, // optional
          'data' => [
            'token' => $user_token,
            'avatar' => $user_avatar,
            'name' => $user_name,
            'doc_id' => $doc_id,
            'call_type' => $call_type,
          ],
          'android' => [
            "priority" => "high",
            "notification" => [
              "channel_id" => "com.dbestech.chatty.call",
              'title' => "Voice call made by " . $user_name,
              'body' => "Please click to answer the voice call",
            ]
          ],
          'apns' => [
            // https://firebase.google.com/docs/reference/fcm/rest/v1/projects.messages#apnsconfig
            'headers' => [
              'apns-priority' => '10',
            ],
            'payload' => [
              'aps' => [
                'alert' => [
                  'title' => "Voice call made by " . $user_name,
                  'body' => "Please click to answer the video call",
                ],
                'mutable-content' => 1,
                'content-available' => 1,
                'badge' => 1,
                'sound' => 'task_cancel.caf'
              ],
            ],
          ],
        ]);

        $messaging->send($message);
      } else if ($call_type == "video") {
        $message = CloudMessage::fromArray([
          'token' => $deviceToken, // optional
          'data' => [
            'token' => $user_token,
            'avatar' => $user_avatar,
            'name' => $user_name,
            'doc_id' => $doc_id,
            'call_type' => $call_type,
          ],
          'android' => [
            "priority" => "high",
            "notification" => [
              "channel_id" => "com.dbestech.chatty.call",
              'title' => "Video call made by " . $user_name,
              'body' => "Please click to answer the video call",
            ]
          ],
          'apns' => [
            // https://firebase.google.com/docs/reference/fcm/rest/v1/projects.messages#apnsconfig
            'headers' => [
              'apns-priority' => '10',
            ],
            'payload' => [
              'aps' => [
                'alert' => [
                  'title' => "Video call made by " . $user_name,
                  'body' => "Please click to answer the video call",
                ],
                'mutable-content' => 1,
                'content-available' => 1,
                'badge' => 1,
                'sound' => 'task_cancel.caf'
              ],
            ],
          ],
        ]);

        $messaging->send($message);
      } else if ($call_type == "text") {

        $message = CloudMessage::fromArray([
          'token' => $deviceToken, // optional
          'data' => [
            'token' => $user_token,
            'avatar' => $user_avatar,
            'name' => $user_name,
            'doc_id' => $doc_id,
            'call_type' => $call_type,
          ],
          'android' => [
            "priority" => "high",
            "notification" => [
              "channel_id" => "com.dbestech.chatty.message",
              'title' => "Message made by " . $user_name,
              'body' => "Please click to answer the Message",
            ]
          ],
          'apns' => [
            // https://firebase.google.com/docs/reference/fcm/rest/v1/projects.messages#apnsconfig
            'headers' => [
              'apns-priority' => '10',
            ],
            'payload' => [
              'aps' => [
                'alert' => [
                  'title' => "Message made by " . $user_name,
                  'body' => "Please click to answer the Message",
                ],
                'mutable-content' => 1,
                'content-available' => 1,
                'badge' => 1,
                'sound' => 'ding.caf'
              ],
            ],
          ],
        ]);

        $messaging->send($message);
      }

      return ["code" => 0, "data" => "", "msg" => "success"];
    } else {
      return ["code" => -1, "data" => "", "msg" => "fcmtoken empty"];
    }
  }
  public function send_notice_test()
  {
    $deviceToken = "cDxhuwCoQNiGkKKZTTh9te:APA91bF9_E3FpFYZ1Tf_5NSjTd858ANeBDmA0IwpqFv093FaE6kjFI9v15RBc3amgDH018E3QKzNmwsd4hThlv8xUpUSKGTNHPflnbVkKimgeMQUTQ-WyuBFz1luC75mq0kqjK32jiMx";
    $messaging = app('firebase.messaging');
    $message = CloudMessage::fromArray([
      'token' => $deviceToken, // optional
      'data' => [
        'token' => "test",
        'avatar' => "test",
        'name' => "test",
        'call_type' => "test",
      ],
      'android' => [
        "priority" => "high",
        "notification" => [
          "channel_id" => "com.dbestech.chatty.message",
          'title' => "Message made by ",
          'body' => "Please click to answer the Message",
        ]
      ],
      'apns' => [
        // https://firebase.google.com/docs/reference/fcm/rest/v1/projects.messages#apnsconfig
        'headers' => [
          'apns-priority' => '10',
        ],
        'payload' => [
          'aps' => [
            'alert' => [
              'title' => "Message made by ",
              'body' => "Please click to answer the Message",
            ],
            'badge' => 1,
            'sound' => 'ding.caf'
          ],
        ],
      ],
    ]);

    $messaging->send($message);
  }

  public function upload_photo(Request $request)
  {

    $file = $request->file('file');

    try {
      $extension = $file->getClientOriginalExtension();

      $fullFileName = uniqid() . '.' . $extension;
      $timedir = date("Ymd");
      $file->storeAs($timedir, $fullFileName,  ['disk' => 'public']);

      $url = env('APP_URL') . '/uploads/' . $timedir . '/' . $fullFileName;
      return ["code" => 0, "data" => $url, "msg" => "success"];
    } catch (Exception $e) {
      return ["code" => -1, "data" => "", "msg" => "error"];
    }
  }
}
