<?php

namespace App\Traits;

use Horsefly\EmailTemplate;
use Horsefly\SentEmail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

trait SendEmails
{
    public function sendApplicantWelcomeEmail($to, $name, $category, $applicant_id)
    {
        try {
            $email_template = EmailTemplate::where('slug', 'applicant_welcome_email')->where('is_active', 1)->first();

            if (!$email_template) {
                throw new \Exception('Email template not found.');
            }

            $title = $email_template->title;
            $template = $email_template->template;
            $subject = $email_template->subject;
            $from_email = $email_template->from_email;

            $replace = [$name, 'an Online Portal', $category, $from_email];
            $prev_val = ['(applicant_name)', '(website_name)', '(job_category)', '(from_email)'];

            $newPhrase = str_replace($prev_val, $replace, $template);
            $formattedMessage = nl2br($newPhrase);

            Mail::send([], [], function ($message) use ($formattedMessage, $to, $from_email, $subject) {
                $message->from($from_email, 'Kingsbury Personnel Ltd');
                $message->to($to);
                $message->subject($subject);
                $message->html($formattedMessage);
            });

            $this->saveEmailDB($to, $from_email, $subject, $formattedMessage, $title, $applicant_id);

            return true;
            
        } catch (\Exception $e) {
            Log::error('Email sending failed: ' . $e->getMessage());
            throw new \Exception('Error sending email: ' . $e->getMessage());
        }
    }
    public function saveEmailDB($email_to, $from_email, $emailSubject, $email_body, $email_title, $applicant_id = null, $sale_id = null)
    {
        $user = Auth::user();
        try {
            $sent_email = new SentEmail();
            $sent_email->action_name   = strtolower(str_replace(' ', '_', $email_title));
            $sent_email->sent_from     = $from_email;
            $sent_email->sent_to       = $email_to;
            $sent_email->cc_emails     = '';
            $sent_email->subject       = $emailSubject;
            $sent_email->title         = $email_title;
            $sent_email->template      = $email_body;
            $sent_email->applicant_id  = $applicant_id;
            $sent_email->sale_id       = $sale_id;
            $sent_email->user_id       = $user->id; // safer than Auth::user()->id
            $sent_email->save();

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to save sent email: ' . $e->getMessage(), [
                'to'      => $email_to,
                'subject' => $emailSubject,
                'title'   => $email_title,
                'user_id' => $user->id
            ]);
            return false;
        }
    }
}
