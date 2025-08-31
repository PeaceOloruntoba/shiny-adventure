<?php

namespace App\Jobs;

use App\Mail\GeneratedApplication;
use App\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class GenerateApplication implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $applicationId;

    public function __construct(int $applicationId)
    {
        $this->applicationId = $applicationId;
        $this->onQueue('default');
    }

    protected bool $debug = false;
    public int $timeout = 300; // seconds, worker will kill if longer
    public int $tries = 3;     // retry a few times on failure
    public int $backoff = 30;  // seconds between retries

    public function handle(): void
    {
        $this->debug = (bool) (env('AI_DEBUG_LOG') ?? false);
        $application = Application::find($this->applicationId);
        if (!$application) {
            Log::error('GenerateApplication: Application not found', ['id' => $this->applicationId]);
            return;
        }

        $meta = $application->meta ?? [];
        $storedFiles = $meta['files'] ?? [];
        $storedImages = $meta['images'] ?? [];

        $name = $application->name;
        $email = $application->email;
        $notes = $application->notes ?? '';

        // Mark processing
        $this->updateMeta($application, ['status' => 'processing']);

        $fileUrls = array_map(fn($p) => Storage::disk('public')->url($p), $storedFiles);
        $imageUrls = array_map(fn($p) => Storage::disk('public')->url($p), $storedImages);

        $prompt = $this->buildPrompt($name, $notes, count($storedImages), array_map(fn($p) => basename($p), $storedFiles), $fileUrls, $imageUrls);
        $this->dlog('Prompt built', [
            'chars' => strlen($prompt),
            'preview' => mb_substr($prompt, 0, 1000),
        ]);

        Log::info('GenerateApplication: OpenAI request starting (job)', [
            'app_id' => $application->id,
            'model' => 'gpt-4o-mini',
            'prompt_chars' => strlen($prompt),
        ]);

        // Upload only user-provided files (resume + job image). Do not upload templates.
        $fileIds = [];
        try {
            $fileIds = array_merge(
                $this->uploadRelativeFilesToOpenAI($storedFiles),
                $this->uploadRelativeFilesToOpenAI($storedImages)
            );
            Log::info('GenerateApplication: OpenAI files uploaded', ['count' => count($fileIds)]);
            $this->dlog('Uploaded files to OpenAI', [
                'files_count' => count($storedFiles),
                'images_count' => count($storedImages),
                'all_file_ids' => $fileIds,
            ]);
        } catch (\Throwable $e) {
            Log::warning('GenerateApplication: OpenAI file upload failed', ['message' => $e->getMessage()]);
        }

        // Run assistant
        $resultText = $this->generateWithAssistant($prompt, $fileIds);
        // Persist OpenAI context for cleanup
        if ($this->lastThreadId) {
            $this->updateMeta($application, ['openai.thread_id' => $this->lastThreadId]);
        }

        if (!$resultText) {
            Log::warning('GenerateApplication: Assistant returned no text, using fallback');
            $resultText = $this->fallbackLetter($name, $notes);
        }
        // Ensure we have valid HTML (assistant may return plain text)
        $resultText = $this->ensureHtml($resultText, $name);

        // HTML-only mode: skip fetching/creating DOCX/PDF artifacts.
        $timestamp = now()->format('Ymd_His');
        $safeSlug = Str::slug($name) ?: 'application';
        $baseDir = "generated/{$safeSlug}_{$timestamp}";
        $docxPath = null;
        $pdfPath = null;

        // Update application
        $application->body = $resultText; // HTML content expected
        $application->docx_path = null; // no docx in HTML-only mode
        $this->updateMeta($application, [ 'pdf_rel' => null, 'status' => 'ready' ]);
        $application->save();

        // Email
        try {
            $mailable = new GeneratedApplication(
                name: $name,
                body: $resultText,
                docxPath: null,
                pdfPath: null,
            );
            $this->dlog('Prepared email (HTML-only)', [
                'to' => $email,
                'has_docx' => false,
                'has_pdf' => false,
            ]);
            Mail::to($email)->send($mailable);
            Log::info('GenerateApplication: Email sent from job (HTML-only)', ['app_id' => $application->id]);
        } catch (\Throwable $e) {
            Log::error('GenerateApplication: Mail send failed', ['message' => $e->getMessage()]);
        }
    }

    // ---- Helpers (duplicated minimal versions for job) ----

    protected ?string $lastThreadId = null;
    protected ?string $lastRunId = null;

    /**
     * Merge changes into the application's meta array and persist in-memory
     */
    protected function updateMeta(Application $application, array $changes): void
    {
        try {
            $meta = $application->meta ?? [];
            foreach ($changes as $k => $v) {
                data_set($meta, $k, $v);
            }
            $application->meta = $meta;
        } catch (\Throwable $e) {
            Log::warning('GenerateApplication: updateMeta failed', ['error' => $e->getMessage()]);
        }
    }

    protected function buildPrompt(string $name, string $notes, int $imageCount, array $fileNames, array $fileUrls = [], array $imageUrls = []): string
    {
        $fileList = empty($fileNames) ? 'none' : implode(', ', $fileNames);
        $notes = trim($notes);
        $urlBlock = '';
        if (!empty($fileUrls) || !empty($imageUrls)) {
            $urlBlock = "\nYou can access the user's uploaded files via these URLs:\n" .
                (empty($fileUrls) ? '' : ('Files:\n- '.implode("\n- ", $fileUrls).'\n')) .
                (empty($imageUrls) ? '' : ('Images:\n- '.implode("\n- ", $imageUrls).'\n'));
        }

        return <<<PROMPT
You are an expert career assistant. Generate a concise, personalized COVER LETTER as COMPLETE, SELF-CONTAINED HTML only.

Content requirements:
- Professional, friendly, confident tone.
- 3–6 short paragraphs; tailored to the candidate and job context from the uploaded RESUME and JOB IMAGE.
- Use real details extracted from the attachments (employer/company, role, technologies, achievements) when available.
- Do NOT invent placeholders like [Company Name] or meta instructions. If specific details are not present, omit that line gracefully.
- End with a strong closing and contact lines.

Inputs:
- Candidate name: {$name}
- Notes from user: "{$notes}"
- Uploaded files (names only): {$fileList}
- Number of uploaded images (job screenshot): {$imageCount}
{$urlBlock}

Formatting and output:
- Return ONLY valid HTML, with inline CSS styles suitable for direct display and PDF conversion.
- Avoid Markdown, code fences, or explanatory text. Output the HTML document string only, no backticks.
- Match the following A4 PAGE LAYOUT with HEADER BACKGROUND and SIGNATURE SPACE:

STRUCTURE (top-to-bottom):
- Header banner with background (use a dark gradient by default, or an image if explicitly provided): height ~40mm, contains candidate name and date in white text.
- Contact block
- Date
- Recipient block
- Subject line in bold (e.g., Application for <Role>)
- Body paragraphs (concise, tailored)
- Signature area: printed-sign whitespace then sender name and contact lines

BASELINE CSS (inline styles with similar values):
- @page size A4; margins ~20mm. Outer container width ~170mm.
- Body: font-family: Arial, sans-serif; color:#111; line-height:1.55; font-size:14px; margin:0;
- Header: height:40mm; border-radius:6px; background: linear-gradient(135deg,#1f2937 0%,#111827 60%,#0b1220 100%); color:#fff; padding:10mm;
- Subject: font-weight:700; font-size:16px; margin:0 0 12px 0;
- Paragraphs: margin:0 0 10px 0;
- Signature block: margin-top:10mm; padding-top:6mm; border-top:1px solid #e5e7eb;
- Signature whitespace: height:18mm; display:block;

IMPORTANT:
- Use only inline CSS, no external stylesheets.
- Do not include placeholder tokens or meta commentary.
- Ensure the final output is a single, valid HTML document that renders correctly as an A4 PDF.
PROMPT;
    }

    protected function getOrCreateAssistantId(): ?string
    {
        $apiKey = config('services.openai.key') ?? env('OPENAI_API_KEY') ?? env('OPEN_API_KEY');
        if (!$apiKey) { return null; }
        $assistantId = env('OPENAI_ASSISTANT_ID');
        if ($assistantId) { return $assistantId; }

        try {
            $payload = [
                'model' => 'gpt-4o-mini',
                'name' => 'ShinyAdventure HTML Cover Letter Assistant',
                'instructions' => 'You generate concise, personalized job application letters as self-contained HTML using details from user inputs and uploaded files/images. Use file_search to extract factual data (employer, role, technologies, achievements) from the resume and job screenshot. Return only valid HTML with inline CSS. Do not include placeholders or meta commentary.',
                'tools' => [ ['type' => 'file_search'] ],
            ];
            $this->dlog('Creating assistant', ['payload' => $payload]);
            $res = Http::timeout(30)->retry(3, 1000)
                ->withToken($apiKey)
                ->withHeaders(['OpenAI-Beta' => 'assistants=v2'])
                ->acceptJson()
                ->post('https://api.openai.com/v1/assistants', $payload);
            if ($res->successful()) {
                $id = $res->json('id');
                Log::info('OpenAI assistant created (job)', ['assistant_id' => $id]);
                $this->dlog('Assistant create response', ['status' => $res->status(), 'body' => $res->json()]);
                return $id;
            }
            $this->dlog('Assistant create failed', ['status' => $res->status(), 'body' => $res->body()]);
        } catch (\Throwable $e) {
            Log::error('Assistant create failed (job)', ['message' => $e->getMessage()]);
        }
        return null;
    }

    protected function generateWithAssistant(string $prompt, array $fileIds): ?string
    {
        $apiKey = config('services.openai.key') ?? env('OPENAI_API_KEY') ?? env('OPEN_API_KEY');
        if (!$apiKey) { return null; }
        try {
            $this->dlog('Creating thread');
            $threadRes = Http::timeout(30)->retry(3, 1000)
                ->withToken($apiKey)
                ->withHeaders(['OpenAI-Beta' => 'assistants=v2'])
                ->acceptJson()
                ->post('https://api.openai.com/v1/threads', []);
            if (!$threadRes->successful()) { return null; }
            $threadId = $threadRes->json('id');
            $this->lastThreadId = $threadId;
            $this->dlog('Thread created', ['thread_id' => $threadId, 'status' => $threadRes->status(), 'body' => $threadRes->json()]);

            $msgPayload = [ 'role' => 'user', 'content' => $prompt ];
            if (!empty($fileIds)) {
                $attachments = [];
                foreach ($fileIds as $fid) {
                    $attachments[] = ['file_id' => $fid, 'tools' => [['type' => 'file_search']]];
                }
                $msgPayload['attachments'] = $attachments;
            }
            $this->dlog('Posting message', [ 'payload_preview' => [
                'content_chars' => strlen($prompt),
                'attachments' => isset($msgPayload['attachments']) ? count($msgPayload['attachments']) : 0,
            ]]);
            $msgRes = Http::timeout(60)->retry(3, 1000)
                ->withToken($apiKey)
                ->withHeaders(['OpenAI-Beta' => 'assistants=v2'])
                ->acceptJson()
                ->post("https://api.openai.com/v1/threads/{$threadId}/messages", $msgPayload);
            if (!$msgRes->successful()) { return null; }
            $this->dlog('Message posted', ['status' => $msgRes->status(), 'body' => $msgRes->json()]);

            $assistantId = $this->getOrCreateAssistantId();
            if (!$assistantId) { return null; }
            $runPayload = [ 'assistant_id' => $assistantId ];
            if (!empty($fileIds)) { $runPayload['tools'] = [['type' => 'file_search']]; }

            $this->dlog('Starting run', ['payload' => $runPayload]);
            $runRes = Http::timeout(60)->retry(3, 1000)
                ->withToken($apiKey)
                ->withHeaders(['OpenAI-Beta' => 'assistants=v2'])
                ->acceptJson()
                ->post("https://api.openai.com/v1/threads/{$threadId}/runs", $runPayload);
            if (!$runRes->successful()) { return null; }
            $runId = $runRes->json('id');
            $this->lastRunId = $runId;
            $this->dlog('Run started', ['run_id' => $runId, 'status' => $runRes->status(), 'body' => $runRes->json()]);

            $deadline = now()->addMinutes(5);
            while (now()->lt($deadline)) {
                sleep(5);
                $getRun = Http::timeout(30)->retry(3, 1000)
                    ->withToken($apiKey)
                    ->withHeaders(['OpenAI-Beta' => 'assistants=v2'])
                    ->acceptJson()
                    ->get("https://api.openai.com/v1/threads/{$threadId}/runs/{$runId}");
                if (!$getRun->successful()) { continue; }
                $status = $getRun->json('status');
                $this->dlog('Run poll', ['status' => $status, 'step' => $getRun->json()]);
                if (in_array($status, ['completed', 'failed', 'cancelled', 'expired'])) {
                    if ($status !== 'completed') { return null; }
                    break;
                }
            }

            $msgList = Http::timeout(30)->retry(3, 1000)
                ->withToken($apiKey)
                ->withHeaders(['OpenAI-Beta' => 'assistants=v2'])
                ->acceptJson()
                ->get("https://api.openai.com/v1/threads/{$threadId}/messages", ['limit' => 1, 'order' => 'desc']);
            if (!$msgList->successful()) { return null; }
            $messages = $msgList->json('data') ?? [];
            $this->dlog('Fetched messages', ['count' => count($messages)]);
            foreach ($messages as $m) {
                if (($m['role'] ?? '') === 'assistant') {
                    $content = $m['content'][0]['text']['value'] ?? null;
                    $this->dlog('Assistant message', [ 'content_preview' => is_string($content) ? mb_substr($content, 0, 1000) : null ]);
                    if (is_string($content) && trim($content) !== '') { return trim($content); }
                }
            }
        } catch (\Throwable $e) {
            Log::error('Assistants exception (job)', ['message' => $e->getMessage()]);
            $this->dlog('Assistants exception details', ['trace' => $e->getTraceAsString()]);
        }
        return null;
    }

    protected function fetchAssistantOutputFiles(): array
    {
        $apiKey = config('services.openai.key') ?? env('OPENAI_API_KEY') ?? env('OPEN_API_KEY');
        if (!$apiKey || !$this->lastThreadId || !$this->lastRunId) { return []; }
        try {
            $stepsRes = Http::timeout(60)
                ->withToken($apiKey)
                ->withHeaders(['OpenAI-Beta' => 'assistants=v2'])
                ->acceptJson()
                ->get("https://api.openai.com/v1/threads/{$this->lastThreadId}/runs/{$this->lastRunId}/steps", [ 'limit' => 50 ]);
            if (!$stepsRes->successful()) { return []; }
            $out = [];
            $data = $stepsRes->json('data') ?? [];
            $this->dlog('Run steps fetched', ['count' => count($data)]);
            foreach ($data as $step) {
                $details = $step['step_details'] ?? [];
                if (($details['type'] ?? '') === 'tool_calls') {
                    foreach (($details['tool_calls'] ?? []) as $tc) {
                        if (($tc['type'] ?? '') === 'code_interpreter') {
                            foreach (($tc['code_interpreter']['outputs'] ?? []) as $o) {
                                if (($o['type'] ?? '') === 'file_path' && !empty($o['file_id'])) {
                                    $out[] = ['id' => $o['file_id'], 'filename' => $o['file_path']['filename'] ?? null];
                                }
                            }
                        }
                    }
                }
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected function getOpenAIFileMeta(string $fileId): array
    {
        $apiKey = config('services.openai.key') ?? env('OPENAI_API_KEY') ?? env('OPEN_API_KEY');
        if (!$apiKey) { return []; }
        try {
            $res = Http::timeout(30)
                ->withToken($apiKey)
                ->acceptJson()
                ->get("https://api.openai.com/v1/files/{$fileId}");
            if ($res->successful()) {
                $json = $res->json() ?: [];
                $this->dlog('OpenAI file meta', ['file_id' => $fileId, 'meta' => $json]);
                return $json;
            }
        } catch (\Throwable $e) { }
        return [];
    }

    protected function downloadOpenAIFileToPublic(string $fileId, string $baseDir, ?string $desiredName = null): ?string
    {
        $apiKey = config('services.openai.key') ?? env('OPENAI_API_KEY') ?? env('OPEN_API_KEY');
        if (!$apiKey) { return null; }
        try {
            $meta = $this->getOpenAIFileMeta($fileId);
            $filename = $desiredName ?: ($meta['filename'] ?? ("openai_{$fileId}"));
            $contentRes = Http::timeout(120)
                ->withToken($apiKey)
                ->withHeaders(['Accept' => 'application/octet-stream'])
                ->get("https://api.openai.com/v1/files/{$fileId}/content");
            if (!$contentRes->successful()) { $this->dlog('Download failed', ['file_id' => $fileId, 'status' => $contentRes->status()]); return null; }
            $relative = rtrim($baseDir, '/').'/'.$filename;
            $bytes = strlen($contentRes->body());
            Storage::disk('public')->put($relative, $contentRes->body());
            $this->dlog('File saved', ['file_id' => $fileId, 'relative' => $relative, 'bytes' => $bytes]);
            return $relative;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function getCachedTemplateFileIds(): array
    {
        $apiKey = config('services.openai.key') ?? env('OPENAI_API_KEY') ?? env('OPEN_API_KEY');
        if (!$apiKey) { return []; }
        $docxTpl = base_path('doc/Vorlage Zander Rohan.docx');
        $pdfTpl  = base_path('doc/Vorlage Zander Rohan.pdf');
        $existing = array_values(array_filter([$docxTpl, $pdfTpl], fn($p) => is_file($p)));
        if (empty($existing)) { return []; }
        $finger = [];
        foreach ($existing as $p) { $finger[] = basename($p) . '|' . filesize($p) . '|' . filemtime($p); }
        $key = 'openai_template_file_ids_' . sha1(implode(';', $finger));
        return Cache::remember($key, now()->addHours(6), function () use ($existing) {
            $ids = [];
            foreach ($existing as $abs) {
                try {
                    $response = Http::timeout(60)
                        ->withToken(env('OPENAI_API_KEY') ?? env('OPEN_API_KEY'))
                        ->attach('file', file_get_contents($abs), basename($abs))
                        ->asMultipart()
                        ->post('https://api.openai.com/v1/files', [ ['name' => 'purpose', 'contents' => 'assistants'] ]);
                    if ($response->successful()) {
                        $id = $response->json('id'); if ($id) { $ids[] = $id; }
                        $this->dlog('Template uploaded', ['path' => $abs, 'file_id' => $id]);
                    } else {
                        $this->dlog('Template upload failed', ['path' => $abs, 'status' => $response->status(), 'body' => $response->body()]);
                    }
                } catch (\Throwable $e) {}
            }
            return $ids;
        });
    }

    protected function uploadRelativeFilesToOpenAI(array $relativePaths): array
    {
        $apiKey = env('OPENAI_API_KEY') ?? env('OPEN_API_KEY');
        if (!$apiKey) { return []; }
        $ids = [];
        foreach ($relativePaths as $rel) {
            $abs = Storage::disk('public')->path($rel);
            if (!is_file($abs)) { continue; }
            try {
                $response = Http::timeout(60)
                    ->withToken($apiKey)
                    ->attach('file', file_get_contents($abs), basename($abs))
                    ->asMultipart()
                    ->post('https://api.openai.com/v1/files', [ ['name' => 'purpose', 'contents' => 'assistants'] ]);
                if ($response->successful()) { $data = $response->json(); if (!empty($data['id'])) { $ids[] = $data['id']; } $this->dlog('User file uploaded', ['rel' => $rel, 'file_id' => $data['id'] ?? null]); }
            } catch (\Throwable $e) {}
        }
        return $ids;
    }

    protected function generateDocxFallback(string $name, string $email, string $notes, string $body, string $baseDir, string $timestamp): ?string
    {
        try {
            if (class_exists('PhpOffice\\PhpWord\\TemplateProcessor')) {
                $templateCandidates = [ base_path('doc/Vorlage Zander Rohan.docx') ];
                $templatePath = null;
                foreach ($templateCandidates as $cand) { if (is_file($cand)) { $templatePath = $cand; break; } }
                if ($templatePath) {
                    $processor = new \PhpOffice\PhpWord\TemplateProcessor($templatePath);
                    $processor->setValue('name', $name);
                    $processor->setValue('email', $email);
                    $processor->setValue('notes', $notes ?: '-');
                    $processor->setValue('date', now()->format('Y-m-d'));
                    $bodyXml = str_replace(["\r\n", "\n", "\r"], '<w:br/>', $body);
                    $processor->setValue('body', $bodyXml);
                    $tempPath = storage_path("app/tmp_job_{$timestamp}.docx");
                    $processor->saveAs($tempPath);
                    $relative = "$baseDir/application_{$timestamp}.docx";
                    Storage::disk('public')->put($relative, file_get_contents($tempPath));
                    @unlink($tempPath);
                    $this->dlog('DOCX fallback saved', ['relative' => $relative]);
                    return $relative;
                }
            }
        } catch (\Throwable $e) {}
        return null;
    }

    protected function fallbackLetter(string $name, string $notes): string
    {
        $notesLine = $notes ? "\n\nAdditional context: {$notes}" : '';
        return "Dear Hiring Team,\n\nMy name is {$name}. I am excited to express my interest in opportunities that align with my background. I bring a track record of delivering results, collaborating across teams, and continuously improving processes to create impact.".
            "\n\nI would welcome the chance to contribute, learn, and grow while supporting your goals. Please find my details attached or available upon request.\n{$notesLine}\n\nKind regards,\n{$name}";
    }

    protected function ensureHtml(string $content, string $name): string
    {
        $trim = trim($content);
        $looksHtml = str_contains($trim, '<') && str_contains($trim, '>');
        if ($looksHtml) { return $content; }
        $escaped = e($trim);
        return <<<HTML
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <style>
    body { font-family: Arial, sans-serif; color:#111; line-height:1.5; font-size:14px; }
    h1 { font-size:20px; margin:0 0 6px; }
    .muted { color:#666; font-size:12px; }
    p { margin:0 0 10px; }
  </style>
  <title>Application - {$name}</title>
  </head>
<body>
  <h1>Application Letter</h1>
  <div class="muted">Generated</div>
  <div>
    <p>{$escaped}</p>
  </div>
</body>
</html>
HTML;
    }

    protected function dlog(string $message, array $context = []): void
    {
        // Always log at info level so it shows up without requiring LOG_LEVEL=debug
        Log::info('[AI TRACE] '.$message, $context);
    }

    public function failed(\Throwable $e): void
    {
        try {
            $application = Application::find($this->applicationId);
            if ($application) {
                $this->updateMeta($application, ['status' => 'failed']);
                $application->save();
            }
        } catch (\Throwable $inner) {}
        Log::error('GenerateApplication: Job failed', [
            'application_id' => $this->applicationId,
            'error' => $e->getMessage(),
        ]);
    }
}
