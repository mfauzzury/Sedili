<?php

namespace App\Modules\Chatbox\Services;

use App\Modules\Chatbox\Exceptions\ChatboxException;
use App\Modules\Chatbox\Models\ChatDocument;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Reads the text of an uploaded document (PDF or Word) once, so that it can be given to Claude with
 * every question. The documents are added one by one and kept until the admin removes them.
 */
class KnowledgeImporter
{
    /** Text of all the documents together, far below what Claude can read at once */
    public const MAX_TOKENS = 200_000;

    public const MAX_CHARACTERS = self::MAX_TOKENS * ChatDocument::CHARS_PER_TOKEN;

    /** Below this the document has (almost) no text: most likely a scan, pictures of pages */
    public const MIN_CHARACTERS = 200;

    public const DISK = 'local';

    /** @var list<string> */
    public const EXTENSIONS = ['pdf', 'docx'];

    /**
     * @param  string  $storedPath  where the upload is on the private disk
     * @param  string  $label  what the admin calls it ("Staff handbook")
     *
     * @throws ChatboxException with a message for the admin; the upload is deleted
     */
    public function import(string $storedPath, string $label, ?int $uploadedBy = null, bool $enabled = true): ChatDocument
    {
        $disk = Storage::disk(self::DISK);
        $kind = strtolower(pathinfo($storedPath, PATHINFO_EXTENSION));

        try {
            [$text, $pages] = match ($kind) {
                'pdf' => $this->readPdf($disk->path($storedPath)),
                'docx' => [self::clean((new DocxReader)->read($disk->path($storedPath))), 0],
                default => throw new ChatboxException('Only PDF and Word (.docx) documents can be added.'),
            };
            $this->assertFits($text, $kind);
        } catch (ChatboxException $e) {
            $disk->delete($storedPath);
            throw $e;
        } catch (Throwable $e) {
            $disk->delete($storedPath);
            throw new ChatboxException(match ($kind) {
                'docx' => 'This Word document could not be read. Check that it is a .docx file (not an old .doc) and not password protected.',
                default => 'This PDF could not be read. Check that it is not damaged or password protected.',
            }, previous: $e);
        }

        return ChatDocument::query()->create([
            'name' => basename($storedPath),
            'label' => trim($label),
            'kind' => $kind,
            'enabled' => $enabled,
            'pages' => $pages,
            'characters' => mb_strlen($text),
            'path' => $storedPath,
            'text' => $text,
            'uploaded_by' => $uploadedBy,
        ]);
    }

    /**
     * Delete a document and its file.
     */
    public function remove(ChatDocument $document): void
    {
        Storage::disk(self::DISK)->delete($document->path);
        $document->delete();
    }

    /**
     * Make the documents what the admin's list says: rename and switch the ones that are in it, remove
     * the ones that are not, and read the new ones (items without an "id" that have a stored "file").
     * One document that cannot be read does not stop the others.
     *
     * @param  iterable<array<string, mixed>>  $items  each with "label", "enabled" and "id" or "file"
     * @return list<string> what went wrong, to tell the admin
     */
    public function sync(iterable $items, ?int $uploadedBy = null): array
    {
        $kept = [];
        foreach ($items as $item) {
            if (filled($item['id'] ?? null) && ($document = ChatDocument::query()->find($item['id'], ['id', 'label', 'enabled'])) !== null) {
                $document->update(['label' => trim((string) ($item['label'] ?? '')), 'enabled' => (bool) ($item['enabled'] ?? false)]);
                $kept[] = $document->id;
            }
        }
        foreach (ChatDocument::query()->whereNotIn('id', $kept)->get(['id', 'path']) as $removed) {
            $this->remove($removed);
        }

        $problems = [];
        foreach ($items as $item) {
            $path = $item['file'] ?? null;
            if (blank($item['id'] ?? null) && is_string($path) && $path !== '') {
                try {
                    $this->import($path, (string) ($item['label'] ?? ''), $uploadedBy, (bool) ($item['enabled'] ?? true));
                } catch (ChatboxException $e) {
                    $problems[] = '"'.($item['label'] ?? 'Document').'": '.$e->getMessage();
                }
            }
        }

        return $problems;
    }

    /**
     * @throws ChatboxException when there is no text, or too much with the other documents
     */
    private function assertFits(string $text, string $kind): void
    {
        $length = mb_strlen($text);
        if ($length < self::MIN_CHARACTERS) {
            throw new ChatboxException($kind === 'pdf'
                ? 'This PDF has no text to read (it looks like a scan). Upload a PDF made from a document, or run OCR on it first.'
                : 'This document has hardly any text to read.');
        }

        $others = (int) ChatDocument::query()->sum('characters');
        if ($others + $length > self::MAX_CHARACTERS) {
            throw new ChatboxException('There is too much text: this document has '.number_format($length).' characters and the documents together may have '.number_format(self::MAX_CHARACTERS).' ('.number_format($others).' are used). Remove a document or upload a shorter one.');
        }
    }

    /**
     * @return array{0: string, 1: int} the text and the number of pages
     */
    private function readPdf(string $path): array
    {
        $pdf = (new Parser)->parseFile($path);
        $pageTexts = array_map(fn ($page): string => $page->getText(), $pdf->getPages());

        return [self::clean(self::stripBoilerplate($pageTexts)), count($pageTexts)];
    }

    /**
     * Removes what is printed on every page and says nothing: the running header and footer, the page
     * numbers, and the lines of a table of contents that end in dot leaders ("Leave ........ 12").
     * Fewer tokens to pay for with every question, and cleaner text for Claude to read. A line is a
     * header or footer when, among the first two and last two lines of a page, it is (apart from its
     * digits) the same on at least half of the pages.
     *
     * @param  list<string>  $pages  the text of each page
     */
    public static function stripBoilerplate(array $pages): string
    {
        $lines = array_map(fn (string $page): array => array_map('trim', explode("\n", $page)), $pages);

        $edges = [];
        $seen = [];
        foreach ($lines as $p => $pageLines) {
            $filled = array_keys(array_filter($pageLines, fn (string $line): bool => $line !== ''));
            $edges[$p] = array_unique([...array_slice($filled, 0, 2), ...array_slice($filled, -2)]);
            $keys = array_unique(array_map(fn (int $i): string => self::fingerprint($pageLines[$i]), $edges[$p]));
            foreach ($keys as $key) {
                $seen[$key] = ($seen[$key] ?? 0) + 1;
            }
        }

        $threshold = max(3, (int) ceil(count($pages) / 2));
        $kept = [];
        foreach ($lines as $p => $pageLines) {
            foreach ($edges[$p] as $i) {
                $key = self::fingerprint($pageLines[$i]);
                // With fewer than three pages nothing reaches the threshold: only page numbers go
                if ($key === '#' || $seen[$key] >= $threshold) {
                    unset($pageLines[$i]);
                }
            }
            $kept[] = implode("\n", array_filter($pageLines, fn (string $line): bool => preg_match('/(?:\.\s?){4,}\s*\d{1,4}$/u', $line) !== 1));
        }

        return implode("\n\n", $kept);
    }

    /**
     * The line as it is compared from page to page: lowercase, with the page number replaced by "#"
     * ("Page 3 of 6", a bare "12", "- 3 -", "Handbook | 3"). Other digits stay: "Section 1" and
     * "Section 2" are different lines, they are content.
     */
    private static function fingerprint(string $line): string
    {
        $line = mb_strtolower($line);
        $line = preg_replace('/\b(?:page|p\.)\s*\d+(?:\s*(?:of|\/)\s*\d+)?/u', '#', $line) ?? $line;
        $line = preg_replace('/^[-–—\s]*\d+(?:\s*(?:of|\/)\s*\d+)?[-–—\s]*$/u', '#', $line) ?? $line;
        $line = preg_replace('/(?<=[|·\-–—])\s*\d+\s*$|^\s*\d+\s*(?=[|·\-–—])/u', ' #', $line) ?? $line;

        return trim(preg_replace('/\s+/', ' ', $line) ?? $line);
    }

    /**
     * Valid UTF-8, without the control characters of the PDF and with a tidy layout: lines kept,
     * runs of spaces and blank lines shortened.
     */
    public static function clean(string $text): string
    {
        $text = mb_scrub($text);
        $text = preg_replace('/[^\P{C}\n\t]+/u', '', $text) ?? $text;
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/ ?\n ?/', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
