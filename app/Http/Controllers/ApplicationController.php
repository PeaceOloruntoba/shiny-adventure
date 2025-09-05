<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Mail\GeneratedApplication;
use App\Models\Application;
use Illuminate\Support\Facades\Auth;
use App\Jobs\GenerateApplication;
use Dompdf\Dompdf;
use Dompdf\Options;

class ApplicationController extends Controller
{
    // Track the last assistants call context for file retrieval
    protected ?string $lastThreadId = null;
    protected ?string $lastRunId = null;
    public function create()
    {
        return view('application.form');
    }

    /**
     * Return cached OpenAI File IDs for our DOCX/PDF templates. Upload if cache miss or files changed.
     */
    protected function getCachedTemplateFileIds(): array
    {
        $apiKey = config('services.openai.key') ?? env('OPENAI_API_KEY') ?? env('OPEN_API_KEY');
        if (!$apiKey) { return []; }

        $docxTpl = base_path('doc/Vorlage Zander Rohan.docx');
        $pdfTpl  = base_path('doc/Vorlage Zander Rohan.pdf');
        $existing = array_values(array_filter([$docxTpl, $pdfTpl], fn($p) => is_file($p)));
        if (empty($existing)) { return []; }

        $finger = [];
        foreach ($existing as $p) {
            $finger[] = basename($p) . '|' . filesize($p) . '|' . filemtime($p);
        }
        $key = 'openai_template_file_ids_' . sha1(implode(';', $finger));

        return Cache::remember($key, now()->addHours(6), function () use ($existing) {
            Log::info('Uploading templates to OpenAI (cache miss)');
            $ids = $this->uploadAbsoluteFilesToOpenAI($existing);
            Log::info('Template upload complete', ['count' => count($ids)]);
            return $ids;
        });
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

        // Create application record in processing state; generation handled by queued job
        $application = Application::create([
            'user_id' => $user?->id,
            'email' => $validated['email'],
            'name' => $validated['name'],
            'notes' => $validated['notes'] ?? null,
            'body' => '',
            'txt_path' => null,
            'docx_path' => null,
            'amount_cents' => (int) (config('billing.price_cents') ?? 0),
            'meta' => [
                'images' => $storedImages,
                'files' => $storedFiles,
                'pdf_rel' => null,
                'status' => 'processing',
            ],
        ]);

        // Process immediately (no queue). Keep status logic in place; UI will reflect updates.
        try {
            (new GenerateApplication($application->id))->handle();
            Log::info('GenerateApplication processed synchronously', ['application_id' => $application->id]);
        } catch (\Throwable $e) {
            Log::error('Synchronous generation error', ['application_id' => $application->id, 'message' => $e->getMessage()]);
        }

        return to_route('applications.index');
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

Formatting and output requirements:
- Use the ATTACHED Word/PDF templates as the visual/structural reference for formatting.
- Use tools to generate TWO files as outputs of this run: a Word document (.docx) and a PDF (.pdf).
- Name them clearly (e.g., application.docx and application.pdf).
- The DOCX and PDF should contain the final polished letter respecting the template’s layout and style.
- If you need to transform content to match the template, do so via the code interpreter tool.

Return the files as outputs of the run (not inline text); plain text is optional.
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
     * Upload absolute file paths to OpenAI Files API (purpose=assistants). Returns array of file_ids.
     */
    protected function uploadAbsoluteFilesToOpenAI(array $absolutePaths): array
    {
        $apiKey = config('services.openai.key') ?? env('OPENAI_API_KEY') ?? env('OPEN_API_KEY');
        if (!$apiKey) { return []; }

        $ids = [];
        foreach ($absolutePaths as $abs) {
            try {
                if (!is_file($abs)) { continue; }
                $name = basename($abs);
                $response = Http::timeout(60)
                    ->withToken($apiKey)
                    ->attach('file', file_get_contents($abs), $name)
                    ->asMultipart()
                    ->post('https://api.openai.com/v1/files', [
                        ['name' => 'purpose', 'contents' => 'assistants'],
                    ]);
                if ($response->successful()) {
                    $data = $response->json();
                    if (!empty($data['id'])) { $ids[] = $data['id']; }
                } else {
                    Log::warning('OpenAI file upload error (abs)', ['name' => $name, 'status' => $response->status(), 'body' => Str::limit($response->body(), 300)]);
                }
            } catch (\Throwable $e) {
                Log::warning('OpenAI file upload exception (abs)', ['file' => $abs, 'message' => $e->getMessage()]);
            }
        }
        return $ids;
    }

    /**
     * Fetch AI-generated files (docx/pdf) from the last assistant run steps.
     * Returns array of ['id' => file_id, 'filename' => string|null]
     */
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
            if (!$stepsRes->successful()) {
                Log::warning('OpenAI run steps fetch failed', ['status' => $stepsRes->status(), 'body' => Str::limit($stepsRes->body(), 500)]);
                return [];
            }
            $out = [];
            $data = $stepsRes->json('data') ?? [];
            foreach ($data as $step) {
                $details = $step['step_details'] ?? [];
                if (($details['type'] ?? '') === 'tool_calls') {
                    $toolCalls = $details['tool_calls'] ?? [];
                    foreach ($toolCalls as $tc) {
                        if (($tc['type'] ?? '') === 'code_interpreter') {
                            $outputs = $tc['code_interpreter']['outputs'] ?? [];
                            foreach ($outputs as $o) {
                                // Look for file outputs
                                if (($o['type'] ?? '') === 'file_path' && !empty($o['file_id'])) {
                                    $fid = $o['file_id'];
                                    $filename = $o['file_path']['filename'] ?? null;
                                    $out[] = ['id' => $fid, 'filename' => $filename];
                                }
                            }
                        }
                    }
                }
            }
            // Fallback: also scan latest assistant message for file attachments
            if (empty($out)) {
                $msgList = Http::timeout(30)
                    ->withToken($apiKey)
                    ->withHeaders(['OpenAI-Beta' => 'assistants=v2'])
                    ->acceptJson()
                    ->get("https://api.openai.com/v1/threads/{$this->lastThreadId}/messages", [ 'limit' => 5, 'order' => 'desc' ]);
                if ($msgList->successful()) {
                    $messages = $msgList->json('data') ?? [];
                    foreach ($messages as $m) {
                        if (($m['role'] ?? '') !== 'assistant') { continue; }
                        $content = $m['content'] ?? [];
                        foreach ($content as $part) {
                            if (($part['type'] ?? '') === 'file_path' && !empty($part['file_id'])) {
                                $out[] = ['id' => $part['file_id'], 'filename' => $part['file_path']['filename'] ?? null];
                            }
                        }
                    }
                }
            }
            // Filter only docx/pdf
            $filtered = [];
            foreach ($out as $item) {
                $name = $item['filename'] ?? '';
                if (!$name) {
                    $meta = $this->getOpenAIFileMeta($item['id']);
                    $name = $meta['filename'] ?? '';
                }
                $lname = strtolower($name);
                if (str_ends_with($lname, '.docx') || str_ends_with($lname, '.pdf')) {
                    $filtered[] = ['id' => $item['id'], 'filename' => $name ?: null];
                }
            }
            return $filtered;
        } catch (\Throwable $e) {
            Log::warning('Fetch assistant output files exception', ['message' => $e->getMessage()]);
            return [];
        }
    }

    /** Get file metadata (e.g., filename) from OpenAI Files API */
    protected function getOpenAIFileMeta(string $fileId): array
    {
        $apiKey = config('services.openai.key') ?? env('OPENAI_API_KEY') ?? env('OPEN_API_KEY');
        if (!$apiKey) { return []; }
        try {
            $res = Http::timeout(30)
                ->withToken($apiKey)
                ->acceptJson()
                ->get("https://api.openai.com/v1/files/{$fileId}");
            if ($res->successful()) { return $res->json() ?: []; }
        } catch (\Throwable $e) {
            // ignore
        }
        return [];
    }

    /** Download an OpenAI file by ID and save under public disk baseDir. Returns relative path or null. */
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
            if (!$contentRes->successful()) {
                Log::warning('OpenAI file download failed', ['file_id' => $fileId, 'status' => $contentRes->status()]);
                return null;
            }
            $relative = rtrim($baseDir, '/').'/'.$filename;
            Storage::disk('public')->put($relative, $contentRes->body());
            return $relative;
        } catch (\Throwable $e) {
            Log::warning('OpenAI file download exception', ['file_id' => $fileId, 'message' => $e->getMessage()]);
            return null;
        }
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
                'instructions' => 'You help generate concise, personalized job application letters using user prompts and attached files. Use file_search to extract relevant details and code_interpreter to transform content and produce DOCX/PDF outputs that match the provided templates.',
                'tools' => [ ['type' => 'file_search'], ['type' => 'code_interpreter'] ],
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
            $this->lastThreadId = $threadId;
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
            $this->lastRunId = $runId;
            Log::info('OpenAI run started', ['run_id' => $runId]);

            // 4) Poll run status (shorter window for UX); fallback if not done
            $deadline = now()->addSeconds(45);
            $startedAt = microtime(true);
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
                    Log::info('OpenAI run finished', ['status' => $status, 'elapsed_s' => round(microtime(true) - $startedAt, 2)]);
                    if ($status !== 'completed') {
                        return null;
                    }
                    break;
                }
            }
            if (now()->gte($deadline)) {
                Log::warning('OpenAI run timeout; proceeding with fallback', ['elapsed_s' => round(microtime(true) - $startedAt, 2)]);
                return null;
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
        if ($type === 'docx' && $application->docx_path) {
            $path = $application->docx_path;
            $filename = 'application.docx';
        } elseif ($type === 'pdf') {
            $pdfRel = data_get($application->meta, 'pdf_rel');
            if ($pdfRel) {
                $abs = Storage::disk('public')->path($pdfRel);
                if (is_file($abs)) {
                    $path = $abs;
                    $filename = 'application.pdf';
                }
            }
        } else {
            abort(404);
        }

        return response()->download($path, $filename);
    }

    public function preview(Application $application)
    {
        $this->authorize('view', $application);
        $name = $application->name;
        $date = now()->format('Y-m-d');
        // Always prefer rendering the exact multi-page HTML template if present
        $tpl = base_path('doc/Vorlage-Zander-Rohan-html.html');
        if (is_file($tpl)) {
            $body = @file_get_contents($tpl) ?: '';
        } else {
            $body = $application->body ?: '<p>No content available.</p>';
        }
        return view('application.preview', compact('name', 'date', 'body', 'application'));
    }

    public function pdf(Application $application)
    {
        $this->authorize('view', $application);
        // Render current HTML body to PDF on-the-fly for download
        $body = $application->body ?? '';
        if (stripos($body, '<html') !== false) {
            // Already a full HTML document from the assistant; render as-is
            $html = $body;
        } else {
            $html = view('application.pdf', [
                'name' => $application->name,
                'date' => now()->format('Y-m-d'),
                'body' => $body,
                'headerBg' => data_get($application->meta, 'header_bg_data'),
            ])->render();
        }

        $options = new Options();
        // Allow remote assets (e.g., header background images) when rendering PDFs
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4');
        $dompdf->render();
        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="application.pdf"',
        ]);
    }

    public function destroy(Application $application)
    {
        $this->authorize('delete', $application);

        // Attempt OpenAI cleanup if metadata available
        try {
            $apiKey = config('services.openai.key') ?? env('OPENAI_API_KEY') ?? env('OPEN_API_KEY');
            $threadId = data_get($application->meta, 'openai.thread_id');
            $fileIds = (array) data_get($application->meta, 'openai.file_ids', []);
            if ($apiKey && $threadId) {
                Http::timeout(15)
                    ->withToken($apiKey)
                    ->withHeaders(['OpenAI-Beta' => 'assistants=v2'])
                    ->delete("https://api.openai.com/v1/threads/{$threadId}");
            }
            if ($apiKey && !empty($fileIds)) {
                foreach ($fileIds as $fid) {
                    Http::timeout(15)
                        ->withToken($apiKey)
                        ->delete("https://api.openai.com/v1/files/{$fid}");
                }
            }
        } catch (\Throwable $e) {
            Log::warning('OpenAI cleanup on delete failed', ['id' => $application->id, 'message' => $e->getMessage()]);
        }

        // Delete stored files
        try {
            $pdfRel = data_get($application->meta, 'pdf_rel');
            if ($pdfRel) {
                Storage::disk('public')->delete($pdfRel);
            }
            if ($application->docx_path && is_file($application->docx_path)) {
                @unlink($application->docx_path);
            }
        } catch (\Throwable $e) {
            // ignore
        }

        $application->delete();
        return to_route('applications.index')->with('status', 'Application deleted');
    }
}
