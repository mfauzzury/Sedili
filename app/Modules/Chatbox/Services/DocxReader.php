<?php

namespace App\Modules\Chatbox\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * The text of a Word (.docx) document: paragraphs, headings ("#"), list items ("-") and tables (cells
 * separated by "|"). Headers, footers, comments and images are left out. Old .doc files are not
 * supported: they are not a zip of XML.
 */
class DocxReader
{
    private const NAMESPACE = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /** The XML of a long document is a few MB: more than this is not a text document */
    private const MAX_XML_BYTES = 40_000_000;

    /**
     * @throws RuntimeException when the file is not a readable Word document
     */
    public function read(string $path): string
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Not a zip file');
        }
        try {
            $stat = $zip->statName('word/document.xml');
            if ($stat === false || $stat['size'] > self::MAX_XML_BYTES) {
                throw new RuntimeException('No (or a too large) word/document.xml');
            }
            $xml = $zip->getFromName('word/document.xml');
        } finally {
            $zip->close();
        }
        if ($xml === false) {
            throw new RuntimeException('word/document.xml cannot be read');
        }

        $document = new DOMDocument;
        // No network and no entity substitution: the file comes from an upload
        if (! $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('word/document.xml is not valid XML');
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', self::NAMESPACE);
        $body = $xpath->query('/w:document/w:body')->item(0);
        if ($body === null) {
            throw new RuntimeException('No document body');
        }

        $blocks = [];
        $this->walk($body, $xpath, $blocks);

        return implode("\n\n", array_filter($blocks, fn (string $block): bool => trim($block) !== ''));
    }

    /**
     * @param  list<string>  $blocks
     */
    private function walk(DOMNode $node, DOMXPath $xpath, array &$blocks): void
    {
        foreach ($node->childNodes as $child) {
            if (! $child instanceof DOMElement || $child->namespaceURI !== self::NAMESPACE) {
                continue;
            }
            match ($child->localName) {
                'p' => $blocks[] = $this->paragraph($child, $xpath),
                'tbl' => $blocks[] = $this->table($child, $xpath),
                // Content controls, sections... hold paragraphs of their own
                default => $this->walk($child, $xpath, $blocks),
            };
        }
    }

    private function paragraph(DOMElement $paragraph, DOMXPath $xpath, bool $markup = true): string
    {
        $text = '';
        foreach ($xpath->query('.//w:r/w:t | .//w:r/w:tab | .//w:r/w:br | .//w:r/w:cr', $paragraph) ?: [] as $part) {
            $text .= match ($part->localName) {
                't' => $part->textContent,
                'tab' => "\t",
                default => "\n",
            };
        }
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        if (! $markup) {
            return $text;
        }

        $style = $xpath->evaluate('string(w:pPr/w:pStyle/@w:val)', $paragraph);
        if (is_string($style) && preg_match('/^(?:heading|titre)\s*(\d)$/i', $style, $match) === 1) {
            return str_repeat('#', min(6, (int) $match[1])).' '.$text;
        }
        if (is_string($style) && strcasecmp($style, 'Title') === 0) {
            return '# '.$text;
        }
        if ($xpath->query('w:pPr/w:numPr', $paragraph)->length > 0) {
            return '- '.$text;
        }

        return $text;
    }

    private function table(DOMElement $table, DOMXPath $xpath): string
    {
        $rows = [];
        foreach ($xpath->query('w:tr', $table) ?: [] as $row) {
            $cells = [];
            foreach ($xpath->query('w:tc', $row) ?: [] as $cell) {
                $parts = [];
                foreach ($xpath->query('.//w:p', $cell) ?: [] as $paragraph) {
                    if (! $paragraph instanceof DOMElement) {
                        continue;
                    }
                    $text = $this->paragraph($paragraph, $xpath, markup: false);
                    if ($text !== '') {
                        $parts[] = $text;
                    }
                }
                $cells[] = implode(' ', $parts);
            }
            if (array_filter($cells, fn (string $cell): bool => $cell !== '') !== []) {
                $rows[] = implode(' | ', $cells);
            }
        }

        return implode("\n", $rows);
    }
}
