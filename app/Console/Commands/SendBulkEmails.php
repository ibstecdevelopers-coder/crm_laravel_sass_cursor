<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Horsefly\SentEmail;
use Horsefly\SmtpSetting;
use Horsefly\Setting;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendBulkEmails extends Command
{
    protected $signature = 'emails:send-bulk';
    protected $description = 'Send bulk emails in chunks of 100 with 30-second delays for unsent emails (status=0)';

    public function handle(): void
    {
        Log::debug('SendBulkEmails command started.');
        $this->info('Starting email dispatch for unsent records...');

        $emailNotification = Setting::where('key', 'email_notifications')->first();

        if ($emailNotification && $emailNotification->value == 1) {
            // Get all unsent emails
           $unsentEmails = SentEmail::whereIn('status', ['0', '2'])->get();

            if ($unsentEmails->isEmpty()) {
                $this->info('No unsent emails found.');
                Log::info('No unsent emails found with status=0.');
                return;
            }

            // Load logo as Base64 (once)
            $imagePath = public_path('images/logo-light22.png');
            $base64Image = file_exists($imagePath)
                ? 'data:image/png;base64,' . base64_encode(file_get_contents($imagePath))
                : '';

            // Group emails by their sender (from address)
            $groupedEmails = $unsentEmails->groupBy('sent_from');

            foreach ($groupedEmails as $fromAddress => $emails) {
                $smtp = SmtpSetting::where('from_address', $fromAddress)->first();

                if (!$smtp) {
                    Log::warning("SMTP not found for sender: {$fromAddress}");
                    $this->warn("SMTP not found for sender: {$fromAddress}");
                    continue;
                }

                // Dynamically configure mailer per SMTP
                config([
                    'mail.default' => 'dynamic_smtp',
                    'mail.mailers.dynamic_smtp' => [
                        'transport' => 'smtp',
                        'host' => $smtp->host,
                        'port' => $smtp->port,
                        'encryption' => $smtp->encryption ?? 'tls',
                        'username' => $smtp->username,
                        'password' => $smtp->password,
                        'timeout' => null,
                        // 'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url(env('APP_URL'), PHP_URL_HOST)),
                    ],
                    'mail.from.address' => $smtp->from_address,
                    'mail.from.name' => $smtp->from_name,
                ]);

                $this->info("Using SMTP: {$smtp->from_address} ({$smtp->host})");
                Log::info("Processing batch for SMTP: {$smtp->from_address}");

                // Send in chunks to control memory + delay between chunks
                $emails->chunk(100)->each(function ($chunk) use ($smtp, $base64Image) {
                    foreach ($chunk as $email) {
                        try {
                            $ccEmails = !empty($email->cc_emails)
                                ? array_filter(array_map('trim', explode(',', $email->cc_emails)))
                                : [];

                            Mail::mailer('dynamic_smtp')->send('emails.bulk', [
                                'subject' => $email->subject ?? 'Bulk Email',
                                'template' => $email->template ?? 'This is a bulk email sent via cron job.',
                                'from_address' => $smtp->from_address,
                                'from_name' => $smtp->from_name,
                                'base64Image' => $base64Image,
                            ], function ($message) use ($email, $smtp, $ccEmails) {
                                $message->to($email->sent_to)
                                        ->subject($email->subject ?? 'Bulk Email')
                                        ->from($smtp->from_address, $smtp->from_name);

                                if (!empty($ccEmails)) {
                                    $message->cc($ccEmails);
                                }
                            });

                            $email->update(['status' => '1']);

                            $this->info("Email sent to {$email->sent_to} (SMTP ID: {$smtp->id})");
                            Log::info("Email sent to {$email->sent_to} via SMTP ID {$smtp->id}, Email ID {$email->id}");

                        } catch (\Exception $e) {
                            $this->error("Failed: {$email->sent_to} — {$e->getMessage()}");
                            Log::error("Failed sending Email ID {$email->id}: {$e->getMessage()}");
                        }
                    }

                    $this->info('Waiting 30 seconds before next chunk...');
                    Log::debug('Waiting 30 seconds before next chunk...');
                    sleep(30);
                });
            }

            $this->info('All emails processed successfully.');
            Log::debug('SendBulkEmails command completed.');
        } else {
            Log::info('SendBulkEmails command not completed, because Email Notifications are disabled.');
            $this->warn('Email Notifications are disabled. Please contact your admin.');
            return;
        }
    }

}