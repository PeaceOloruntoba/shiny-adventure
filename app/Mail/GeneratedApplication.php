<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;

class GeneratedApplication extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public string $name;
    public string $bodyText;
    public ?string $docxPath;
    public ?string $pdfPath;

    public function __construct(string $name, string $body, ?string $docxPath = null, ?string $pdfPath = null)
    {
        $this->name = $name;
        $this->bodyText = $body;
        $this->docxPath = $docxPath;
        $this->pdfPath = $pdfPath;
    }

    public function build()
    {
        $mail = $this->subject(__('messages.email_subject'))
            ->view('emails.application')
            ->with([
                'name' => $this->name,
                'bodyText' => $this->bodyText,
            ]);

        if ($this->docxPath && file_exists($this->docxPath)) {
            $mail->attach($this->docxPath, [
                'as' => 'application_' . now()->format('Ymd_His') . '.docx',
                'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ]);
        }

        if ($this->pdfPath && file_exists($this->pdfPath)) {
            $mail->attach($this->pdfPath, [
                'as' => 'application_' . now()->format('Ymd_His') . '.pdf',
                'mime' => 'application/pdf',
            ]);
        }

        return $mail;
    }
}
