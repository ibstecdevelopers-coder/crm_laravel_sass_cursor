<?php

namespace App\Traits;

use Horsefly\Message;
use Illuminate\Support\Facades\Log;
use Horsefly\SmsTemplate;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Carbon;

trait SendSMS
{
    public function saveSMSDB($sms_to, $message, $moduleType = null, $moduleId = null)
    {
        try {
            $sent_sms = new Message();
            $sent_sms->module_id  = $moduleId;
            $sent_sms->module_type  = $moduleType;
            $sent_sms->user_id       = Auth::id();
            $sent_sms->message       = $message;
            $sent_sms->phone_number  = $sms_to;
            $sent_sms->status  = 'outgoing';
            $sent_sms->msg_id = 'D' . mt_rand(1000000000000, 9999999999999);
            $sent_sms->date = Carbon::now()->toDateString(); // e.g., "2025-06-26"
            $sent_sms->time = Carbon::now()->toTimeString(); // e.g., "16:45:00"
            $sent_sms->save();

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to save sent sms: ' . $e->getMessage(), [
                'to'      => $sms_to,
                'Message' => $message,
                'user_id' => Auth::id(),
            ]);
            return false;
        }
    }

}
