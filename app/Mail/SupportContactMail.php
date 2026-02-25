<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SupportContactMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public array $data) {}

    public function build()
    {
        return $this->replyTo($this->data['email'], $this->data['name'])
        ->subject('[Support] ' . ($this->data['subject'] ?? 'Message'))
        ->view('emails.support_contact')
        ->with(['data' => $this->data]);
    }
}