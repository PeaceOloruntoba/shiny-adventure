<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
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

        // Extract text from uploaded files (best-effort) to improve AI context
        $extracted = $this->extractFileTexts($storedFiles);

        $prompt = $this->buildPrompt(
            $validated['name'],
            $validated['notes'] ?? '',
            count($storedImages),
            array_map(fn($p) => basename($p), $storedFiles),
            $extracted
        );

        $generated = $this->generateWithOpenAI($prompt);

        // Fallback if OpenAI fails
        if (!$generated) {
            $generated = $this->fallbackLetter($validated['name'], $validated['notes'] ?? '');
        }

        // Persist record and write files
        $user = Auth::user();
        $timestamp = now()->format('Ymd_His');
        $safeSlug = Str::slug($validated['name']) ?: 'application';
        $baseDir = "generated/{$safeSlug}_{$timestamp}";

        // Save txt
        $txtPath = "$baseDir/application_$timestamp.txt";
        Storage::disk('public')->put($txtPath, $generated);

        // Try to generate DOCX using PHPWord if available
        $docxPath = null;
        $pdfPath = null;
        try {
            if (class_exists('PhpOffice\\PhpWord\\PhpWord')) {
                $docxPath = $this->generateDocxFromTemplate(
                    name: $validated['name'],
                    body: $generated,
                    baseDir: $baseDir,
                    timestamp: $timestamp,
                    email: $validated['email'] ?? '',
                    notes: $validated['notes'] ?? ''
                );
            }
            // Optionally generate PDF if Dompdf is available
            if (class_exists('Dompdf\\Dompdf')) {
                $pdfPath = $this->generatePdf($validated['name'], $generated, $baseDir, $timestamp);
            }
        } catch (\Throwable $e) {
            // silently ignore in minimal sample
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

        // Email the generated content with attachments; queue when worker is running, otherwise send immediately
        $absoluteDocx = $application->docx_path ?: null;
        $absolutePdf = $pdfPath ? Storage::disk('public')->path($pdfPath) : null;
        $mailable = new GeneratedApplication(
            name: $validated['name'],
            body: $generated,
            docxPath: $absoluteDocx,
            pdfPath: $absolutePdf,
        );

        // Prefer immediate send unless explicitly opted-in to queue via MAIL_QUEUE=true
        $useQueue = filter_var(env('MAIL_QUEUE', false), FILTER_VALIDATE_BOOL);
        if ($useQueue) {
            Mail::to($validated['email'])->queue($mailable);
        } else {
            Mail::to($validated['email'])->send($mailable);
        }

        return to_route('applications.index')->with('status', __('messages.sent_success'));
    }

    protected function buildPrompt(string $name, string $notes, int $imageCount, array $fileNames, array $extractedTexts = []): string
    {
        $fileList = empty($fileNames) ? 'none' : implode(', ', $fileNames);
        $notes = trim($notes);
        $excerpts = '';
        if (!empty($extractedTexts)) {
            $snippets = [];
            foreach ($extractedTexts as $fname => $text) {
                $clean = trim(preg_replace('/\s+/', ' ', $text ?? ''));
                if ($clean !== '') {
                    $snippets[] = "From {$fname}: " . Str::limit($clean, 600);
                }
                if (count($snippets) >= 3) break; // avoid overly long prompts
            }
            if (!empty($snippets)) {
                $excerpts = "\nExtracted content from uploads (excerpts):\n- " . implode("\n- ", $snippets) . "\n";
            }
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
Other files uploaded (names only, content not parsed): {$fileList}
{$excerpts}

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
     * Best-effort extraction of text from uploaded files to inform AI prompt.
     * Supports .txt directly; for .docx reads word/document.xml; ignores binaries.
     * Returns [filename => text].
     */
    protected function extractFileTexts(array $storedFiles): array
    {
        $out = [];
        foreach ($storedFiles as $relative) {
            try {
                $path = Storage::disk('public')->path($relative);
                $name = basename($relative);
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if ($ext === 'txt') {
                    $out[$name] = Str::limit(@file_get_contents($path) ?: '', 4000);
                } elseif ($ext === 'docx') {
                    $zip = new \ZipArchive();
                    if ($zip->open($path) === true) {
                        $xml = $zip->getFromName('word/document.xml');
                        $zip->close();
                        if ($xml) {
                            $text = strip_tags(str_replace(['</w:p>', '</w:tr>'], "\n", $xml));
                            $out[$name] = Str::limit($text, 4000);
                        }
                    }
                }
            } catch (\Throwable $e) {
                // ignore individual extraction errors
            }
        }
        return $out;
    }

    /**
     * Generate a simple PDF via Dompdf (if installed). Returns relative path on public disk.
     */
    protected function generatePdf(string $name, string $body, string $baseDir, string $timestamp): ?string
    {
        try {
            $html = view('application.pdf', [
                'name' => $name,
                'body' => nl2br(e($body)),
                'date' => now()->format('Y-m-d'),
            ])->render();

            $options = new \Dompdf\Options();
            $options->set('isRemoteEnabled', true);
            $dompdf = new \Dompdf\Dompdf($options);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            $relative = "$baseDir/application_{$timestamp}.pdf";
            Storage::disk('public')->put($relative, $dompdf->output());
            return $relative;
        } catch (\Throwable $e) {
            return null;
        }
    }

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
