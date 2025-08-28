<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Mail\GeneratedApplication;
use App\Models\Application;
use Illuminate\Support\Facades\Auth;

class ApplicationController extends Controller
{
    public function create()
    {
        return view('application.form');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'images.*' => ['nullable', 'image', 'max:4096'],
            'files.*' => ['nullable', 'file', 'max:8192'],
            'agree' => ['accepted'],
        ]);

        $storedImages = [];
        $storedFiles = [];

        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                if ($image->isValid()) {
                    $storedImages[] = $image->store('uploads/images', 'public');
                }
            }
        }

        if ($request->hasFile('files')) {
            foreach ($request->file('files') as $file) {
                if ($file->isValid()) {
                    $storedFiles[] = $file->store('uploads/files', 'public');
                }
            }
        }

        // Auth user for logging and ownership
        $user = Auth::user();

        // Build public URLs so AI can access uploads if needed (no local parsing)
        $fileUrls = [];
        foreach ($storedFiles as $p) {
            $fileUrls[] = Storage::disk('public')->url($p);
        }
        $imageUrls = [];
        foreach ($storedImages as $p) {
            $imageUrls[] = Storage::disk('public')->url($p);
        }

        $prompt = $this->buildPrompt(
            $validated['name'],
            $validated['notes'] ?? '',
            count($storedImages),
            array_map(fn($p) => basename($p), $storedFiles),
            $fileUrls,
            $imageUrls
        );

        Log::info('AI prompt built', [
            'user' => $user?->id,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'images_count' => count($storedImages),
            'files_count' => count($storedFiles),
            'file_names' => array_map(fn($p) => basename($p), $storedFiles),
            'file_urls' => $fileUrls,
            'image_urls' => $imageUrls,
        ]);

        // Upload user files to OpenAI and call Responses API with attachments
        Log::info('OpenAI request starting', [
            'model' => 'gpt-4o-mini',
            'prompt_chars' => strlen($prompt),
        ]);

        $fileIds = [];
        try {
            $fileIds = $this->uploadFilesToOpenAI(array_merge($storedFiles, $storedImages));
            Log::info('OpenAI files uploaded', ['count' => count($fileIds)]);
        } catch (\Throwable $e) {
            Log::warning('OpenAI file upload failed; continuing without files', ['message' => $e->getMessage()]);
        }

        $generated = $this->generateWithAssistant($prompt, $fileIds);

        // Fallback if OpenAI fails
        if (!$generated) {
            Log::warning('OpenAI generation failed; using fallback letter');
            $generated = $this->fallbackLetter($validated['name'], $validated['notes'] ?? '');
        }
        Log::info('OpenAI response received', [
            'response_preview' => Str::limit($generated, 200),
            'response_chars' => strlen($generated),
        ]);

        // Persist record and write files
        $timestamp = now()->format('Ymd_His');
        $safeSlug = Str::slug($validated['name']) ?: 'application';
        $baseDir = "generated/{$safeSlug}_{$timestamp}";

        // Save txt
        $txtPath = "$baseDir/application_$timestamp.txt";
        Storage::disk('public')->put($txtPath, $generated);

        // Try to generate DOCX using PHPWord if available
        $docxPath = null;
        try {
            if (class_exists('PhpOffice\\PhpWord\\PhpWord')) {
                Log::info('DOCX generation starting');
                $docxPath = $this->generateDocxFromTemplate(
                    name: $validated['name'],
                    body: $generated,
                    baseDir: $baseDir,
                    timestamp: $timestamp,
                    email: $validated['email'] ?? '',
                    notes: $validated['notes'] ?? ''
                );
                Log::info('DOCX generation finished', ['relative_docx' => $docxPath]);
            }
        } catch (\Throwable $e) {
            Log::error('DOCX generation error', ['message' => $e->getMessage()]);
        }

        $application = Application::create([
            'user_id' => $user?->id,
            'email' => $validated['email'],
            'name' => $validated['name'],
            'notes' => $validated['notes'] ?? null,
            'body' => $generated,
            'txt_path' => $txtPath ? Storage::disk('public')->path($txtPath) : null,
            'docx_path' => $docxPath ? Storage::disk('public')->path($docxPath) : null,
            'amount_cents' => (int) (config('billing.price_cents') ?? 0),
            'meta' => [
                'images' => $storedImages,
                'files' => $storedFiles,
            ],
        ]);

        // Email the generated content with attachments immediately (Brevo SMTP configured in .env)
        $absoluteDocx = $application->docx_path ?: null;
        $mailable = new GeneratedApplication(
            name: $validated['name'],
            body: $generated,
            docxPath: $absoluteDocx,
        );
        try {
            $mailConfig = [
                'MAIL_MAILER' => env('MAIL_MAILER'),
                'MAIL_HOST' => env('MAIL_HOST'),
                'MAIL_PORT' => env('MAIL_PORT'),
                'MAIL_ENCRYPTION' => env('MAIL_ENCRYPTION'),
                'MAIL_FROM_ADDRESS' => env('MAIL_FROM_ADDRESS'),
                'MAIL_FROM_NAME' => env('MAIL_FROM_NAME'),
                'queue' => (bool) env('MAIL_QUEUE', false),
            ];
            Log::info('Sending email', [
                'to' => $validated['email'],
                'has_docx' => (bool) $absoluteDocx,
                'txt_path' => $txtPath,
                'mail_config' => $mailConfig,
            ]);
            Mail::to($validated['email'])->send($mailable);
            Log::info('Email sent');
        } catch (\Throwable $e) {
            // log and continue to UI
            Log::error('Mail send failed', ['message' => $e->getMessage()]);
        }

        return to_route('applications.index')->with('status', __('messages.sent_success'));
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
You are an expert career assistant. Draft a concise, personalized job application/cover letter.

Requirements:
- Professional tone, friendly and confident.
- 3–6 short paragraphs, use clear headings if beneficial.
- Tailor to the candidate and notes provided.
- End with a compelling closing and contact lines.

Candidate name: {$name}
Additional notes/context from user: "{$notes}"
Number of images uploaded: {$imageCount}
Other files uploaded (names only): {$fileList}

IMPORTANT: Assume the CV/resume uploaded contains the candidate's contact info and experiences. Craft a professional letter that integrates typical contact lines and relevant achievements, even if the raw files are not parsed.
{$urlBlock}

Return only the letter text suitable for emailing.
PROMPT;
    }

    protected function generateWithOpenAI(string $prompt): ?string
    {
        $apiKey = config('services.openai.key') ?? env('OPENAI_API_KEY') ?? env('OPEN_API_KEY');
        if (!$apiKey) {
            return null;
        }

        try {
            $response = Http::timeout(30)
                ->withToken($apiKey)
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-4o-mini',
                    'messages' => [
                        ['role' => 'system', 'content' => 'You write excellent, concise cover letters.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'temperature' => 0.7,
                    'max_tokens' => 800,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                return trim($data['choices'][0]['message']['content'] ?? '');
            }
        } catch (\Throwable $e) {
            // log silently in this minimal sample
            // logger()->error('OpenAI error: '.$e->getMessage());
        }

        return null;
    }

    /**
     * Get OPENAI_ASSISTANT_ID from env or create a minimal assistant (with file_search tool) on the fly.
     */
    protected function getOrCreateAssistantId(): ?string
    {
        $apiKey = config('services.openai.key') ?? env('OPENAI_API_KEY') ?? env('OPEN_API_KEY');
        if (!$apiKey) { return null; }

        $assistantId = env('OPENAI_ASSISTANT_ID');
        if ($assistantId) {
            return $assistantId;
        }

        try {
            $payload = [
                'model' => 'gpt-4o-mini',
                'name' => 'ShinyAdventure Cover Letter Assistant',
                'instructions' => 'You help generate concise, personalized job application letters using user prompts and attached files. Use file_search to extract relevant details if needed.',
                'tools' => [ ['type' => 'file_search'] ],
            ];
            $res = Http::timeout(30)
                ->withToken($apiKey)
                ->withHeaders(['OpenAI-Beta' => 'assistants=v2'])
                ->acceptJson()
                ->post('https://api.openai.com/v1/assistants', $payload);
            if ($res->successful()) {
                $id = $res->json('id');
                Log::info('OpenAI assistant created', ['assistant_id' => $id]);
                return $id;
            }
            Log::error('OpenAI assistant create failed', ['status' => $res->status(), 'body' => Str::limit($res->body(), 500)]);
        } catch (\Throwable $e) {
            Log::error('OpenAI assistant create exception', ['message' => $e->getMessage()]);
        }
        return null;
    }

    /**
     * Upload an array of relative public-disk paths to OpenAI Files API for assistants/responses usage.
     * Returns an array of file_ids.
     */
    protected function uploadFilesToOpenAI(array $relativePaths): array
    {
        $apiKey = config('services.openai.key') ?? env('OPENAI_API_KEY') ?? env('OPEN_API_KEY');
        if (!$apiKey) {
            return [];
        }

        $ids = [];
        foreach ($relativePaths as $rel) {
            try {
                $abs = Storage::disk('public')->path($rel);
                if (!is_file($abs)) { continue; }
                $name = basename($abs);
                $mime = @mime_content_type($abs) ?: 'application/octet-stream';
                $response = Http::timeout(60)
                    ->withToken($apiKey)
                    ->attach('file', file_get_contents($abs), $name)
                    ->asMultipart()
                    ->post('https://api.openai.com/v1/files', [
                        ['name' => 'purpose', 'contents' => 'assistants'],
                    ]);
                if ($response->successful()) {
                    $data = $response->json();
                    if (!empty($data['id'])) {
                        $ids[] = $data['id'];
                    }
                } else {
                    Log::warning('OpenAI file upload error', ['name' => $name, 'status' => $response->status(), 'body' => Str::limit($response->body(), 300)]);
                }
            } catch (\Throwable $e) {
                Log::warning('OpenAI file upload exception', ['file' => $rel, 'message' => $e->getMessage()]);
            }
        }
        return $ids;
    }

    /**
     * Generate text using OpenAI Assistants Threads/Runs with file attachments.
     */
    protected function generateWithAssistant(string $prompt, array $fileIds): ?string
    {
        $apiKey = config('services.openai.key') ?? env('OPENAI_API_KEY') ?? env('OPEN_API_KEY');
        if (!$apiKey) {
            return null;
        }

        try {
            // 1) Create thread
            $threadRes = Http::timeout(30)
                ->withToken($apiKey)
                ->withHeaders(['OpenAI-Beta' => 'assistants=v2'])
                ->acceptJson()
                ->post('https://api.openai.com/v1/threads', []);
            if (!$threadRes->successful()) {
                Log::error('OpenAI thread create failed', ['status' => $threadRes->status(), 'body' => Str::limit($threadRes->body(), 500)]);
                return null;
            }
            $threadId = $threadRes->json('id');
            Log::info('OpenAI thread created', ['thread_id' => $threadId]);

            // 2) Create user message with attachments
            $msgPayload = [
                'role' => 'user',
                'content' => $prompt,
            ];
            if (!empty($fileIds)) {
                $attachments = [];
                foreach ($fileIds as $fid) {
                    $attachments[] = [
                        'file_id' => $fid,
                        'tools' => [['type' => 'file_search']],
                    ];
                }
                $msgPayload['attachments'] = $attachments;
            }
            $msgRes = Http::timeout(60)
                ->withToken($apiKey)
                ->withHeaders(['OpenAI-Beta' => 'assistants=v2'])
                ->acceptJson()
                ->post("https://api.openai.com/v1/threads/{$threadId}/messages", $msgPayload);
            if (!$msgRes->successful()) {
                Log::error('OpenAI message create failed', ['status' => $msgRes->status(), 'body' => Str::limit($msgRes->body(), 500)]);
                return null;
            }
            Log::info('OpenAI message created');

            // 3) Start run (ensure we have an assistant_id)
            $assistantId = $this->getOrCreateAssistantId();
            if (!$assistantId) {
                Log::error('No assistant_id available');
                return null;
            }
            $runPayload = [
                'assistant_id' => $assistantId,
            ];
            // When files provided, declare file_search tool
            if (!empty($fileIds)) {
                $runPayload['tools'] = [['type' => 'file_search']];
            }

            $runRes = Http::timeout(60)
                ->withToken($apiKey)
                ->withHeaders(['OpenAI-Beta' => 'assistants=v2'])
                ->acceptJson()
                ->post("https://api.openai.com/v1/threads/{$threadId}/runs", array_filter($runPayload));
            if (!$runRes->successful()) {
                Log::error('OpenAI run create failed', ['status' => $runRes->status(), 'body' => Str::limit($runRes->body(), 500)]);
                return null;
            }
            $runId = $runRes->json('id');
            Log::info('OpenAI run started', ['run_id' => $runId]);

            // 4) Poll run status
            $deadline = now()->addSeconds(60);
            while (now()->lt($deadline)) {
                usleep(500000); // 0.5s
                $getRun = Http::timeout(30)
                    ->withToken($apiKey)
                    ->withHeaders(['OpenAI-Beta' => 'assistants=v2'])
                    ->acceptJson()
                    ->get("https://api.openai.com/v1/threads/{$threadId}/runs/{$runId}");
                if (!$getRun->successful()) {
                    Log::warning('OpenAI run poll failed', ['status' => $getRun->status(), 'body' => Str::limit($getRun->body(), 300)]);
                    continue;
                }
                $status = $getRun->json('status');
                if (in_array($status, ['completed', 'failed', 'cancelled', 'expired'])) {
                    Log::info('OpenAI run finished', ['status' => $status]);
                    if ($status !== 'completed') {
                        return null;
                    }
                    break;
                }
            }

            // 5) Retrieve latest message(s)
            $msgList = Http::timeout(30)
                ->withToken($apiKey)
                ->withHeaders(['OpenAI-Beta' => 'assistants=v2'])
                ->acceptJson()
                ->get("https://api.openai.com/v1/threads/{$threadId}/messages", [
                    'limit' => 1,
                    'order' => 'desc',
                ]);
            if (!$msgList->successful()) {
                Log::error('OpenAI messages fetch failed', ['status' => $msgList->status(), 'body' => Str::limit($msgList->body(), 500)]);
                return null;
            }
            $messages = $msgList->json('data') ?? [];
            foreach ($messages as $m) {
                if (($m['role'] ?? '') === 'assistant') {
                    // content can be array of parts
                    $content = $m['content'][0]['text']['value'] ?? null;
                    if (is_string($content) && trim($content) !== '') {
                        return trim($content);
                    }
                }
            }
            Log::warning('OpenAI assistant response had no text');
        } catch (\Throwable $e) {
            Log::error('OpenAI assistants exception', ['message' => $e->getMessage()]);
        }
        return null;
    }

    // Removed file extraction and PDF generation per user request to keep prompt form-only and output DOCX/TXT

    protected function fallbackLetter(string $name, string $notes): string
    {
        $notesLine = $notes ? "\n\nAdditional context: {$notes}" : '';
        return "Dear Hiring Team,\n\nMy name is {$name}. I am excited to express my interest in opportunities that align with my background. I bring a track record of delivering results, collaborating across teams, and continuously improving processes to create impact.".
            "\n\nI would welcome the chance to contribute, learn, and grow while supporting your goals. Please find my details attached or available upon request.\n{$notesLine}\n\nKind regards,\n{$name}";
    }

    protected function generateDocxFromTemplate(string $name, string $body, string $baseDir, string $timestamp, string $email = '', string $notes = ''): ?string
    {
        // Attempt to use template in doc/ if PHPWord supports templates; else write plain docx
        $publicDisk = Storage::disk('public');
        $relative = "$baseDir/application_$timestamp.docx";

        try {
            // Prefer TemplateProcessor if available and a template exists
            if (class_exists('PhpOffice\\PhpWord\\TemplateProcessor')) {
                $templateCandidates = [
                    base_path('doc/Vorlage Zander Rohan.docx'),
                    base_path('doc/Vorlage Zander Rohan.docx'), // duplicate line to emphasize single known template name
                ];
                $templatePath = null;
                foreach ($templateCandidates as $cand) {
                    if (is_file($cand)) {
                        $templatePath = $cand;
                        break;
                    }
                }

                if ($templatePath) {
                    $processor = new \PhpOffice\PhpWord\TemplateProcessor($templatePath);
                    // Common placeholders expected in template: ${name}, ${email}, ${notes}, ${date}, ${body}
                    $processor->setValue('name', $name);
                    $processor->setValue('email', $email);
                    $processor->setValue('notes', $notes ?: '-');
                    $processor->setValue('date', now()->format('Y-m-d'));
                    $bodyXml = str_replace(["\r\n", "\n", "\r"], '<w:br/>', $body);
                    $processor->setValue('body', $bodyXml);

                    $tempPath = storage_path("app/tmp_doc_$timestamp.docx");
                    $processor->saveAs($tempPath);
                    $publicDisk->put($relative, file_get_contents($tempPath));
                    @unlink($tempPath);
                    return $relative;
                }
            }

            // Basic docx without template as a fallback using PHPWord
            $phpWord = new \PhpOffice\PhpWord\PhpWord();
            $section = $phpWord->addSection();
            $section->addText($name, ['bold' => true, 'size' => 14]);
            $section->addTextBreak(1);
            foreach (preg_split("/(\r\n|\r|\n)/", $body) as $line) {
                $section->addText($line);
            }

            $tempPath = storage_path("app/tmp_doc_$timestamp.docx");
            $phpWord->save($tempPath, 'Word2007');
            // Move into public disk
            $publicDisk->put($relative, file_get_contents($tempPath));
            @unlink($tempPath);
            return $relative;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // Authenticated: list and download
    public function index(Request $request)
    {
        $user = Auth::user();
        $apps = Application::query()
            ->when($user, fn($q) => $q->where('user_id', $user->id))
            ->latest()
            ->paginate(10);

        return view('application.index', [
            'applications' => $apps,
        ]);
    }

    public function download(Request $request, Application $application, string $type)
    {
        $this->authorize('view', $application);

        $path = null;
        $filename = null;
        if ($type === 'txt' && $application->txt_path) {
            $path = $application->txt_path;
            $filename = 'application.txt';
        } elseif ($type === 'docx' && $application->docx_path) {
            $path = $application->docx_path;
            $filename = 'application.docx';
        } else {
            abort(404);
        }

        return response()->download($path, $filename);
    }
}
