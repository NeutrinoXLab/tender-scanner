<?php

namespace App\Services;

use Generator;
use RuntimeException;
use XMLReader;
use ZipArchive;

class BulkSpreadsheetReader
{
    public function spool(string $path): string
    {
        $target = $path.'.rows';
        if (is_file($target)) {
            return $target;
        }
        $stream = fopen($target.'.part', 'wb');
        if ($stream === false) {
            throw new RuntimeException('Nu se poate pregăti fișierul pentru procesare în loturi.');
        }
        try {
            $started = microtime(true);
            foreach ($this->rows($path) as $row) {
                if (microtime(true) - $started > 240) {
                    throw new RuntimeException('Pregătirea exportului a depășit limita de patru minute.');
                }
                $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
                if (fwrite($stream, $line) !== strlen($line)) {
                    throw new RuntimeException('Spațiu insuficient pentru indexul bulk.');
                }
            }
        } finally {
            fclose($stream);
        }
        if (! rename($target.'.part', $target)) {
            throw new RuntimeException('Indexul bulk nu poate fi salvat.');
        }

        return $target;
    }

    /** @return Generator<int, array<int, string>> */
    public function rows(string $path): Generator
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Exportul nu este o arhivă XLSX/ODS validă.');
        }
        $ods = $zip->locateName('content.xml') !== false;
        $entry = $ods ? 'content.xml' : 'xl/worksheets/sheet1.xml';
        if ($zip->locateName($entry) === false) {
            $zip->close();
            throw new RuntimeException('Foaia de date lipsește din export.');
        }
        $hasStrings = $zip->locateName('xl/sharedStrings.xml') !== false;
        $zip->close();
        $strings = tmpfile();
        $index = tmpfile();
        if ($strings === false || $index === false) {
            throw new RuntimeException('Nu se poate crea indexul temporar al exportului.');
        }
        $previousErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            if (! $ods && $hasStrings) {
                $reader = $this->open($path, 'xl/sharedStrings.xml');
                try {
                    while ($reader->read()) {
                        if ($reader->nodeType === XMLReader::DOC_TYPE) {
                            throw new RuntimeException('DTD nu este permis în export.');
                        }
                        if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
                            fwrite($index, sprintf('%020d', ftell($strings))."\n");
                            fwrite($strings, json_encode($this->text($reader->readOuterXml(), 't'), JSON_THROW_ON_ERROR)."\n");
                        }
                    }
                    $this->assertValidXml();
                } finally {
                    $reader->close();
                }
            }
            $reader = $this->open($path, $entry);
            try {
                while ($reader->read()) {
                    if ($reader->nodeType === XMLReader::DOC_TYPE) {
                        throw new RuntimeException('DTD nu este permis în export.');
                    }
                    if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== ($ods ? 'table-row' : 'row')) {
                        continue;
                    }
                    $xml = $reader->readOuterXml();
                    if ($xml === '') {
                        throw new RuntimeException('XML incomplet în exportul oficial.');
                    }
                    $row = new \DOMDocument;
                    if (! $row->loadXML($xml, LIBXML_NONET)) {
                        throw new RuntimeException('Rând XML invalid.');
                    }
                    $values = [];
                    foreach ($row->documentElement->childNodes as $cell) {
                        if (! $cell instanceof \DOMElement) {
                            continue;
                        }
                        if ($ods) {
                            $value = $cell->getAttributeNS('urn:oasis:names:tc:opendocument:xmlns:office:1.0', 'date-value');
                            if ($value === '') {
                                $value = $cell->getAttributeNS('urn:oasis:names:tc:opendocument:xmlns:office:1.0', 'value');
                            }
                            if ($value === '') {
                                $value = $cell->textContent;
                            }
                            $repeat = max(1, (int) $cell->getAttributeNS('urn:oasis:names:tc:opendocument:xmlns:table:1.0', 'number-columns-repeated'));
                            $repeat = min($repeat, 64 - count($values));
                            for ($i = 0; $i < $repeat; $i++) {
                                $values[] = trim($value);
                            }
                        } else {
                            preg_match('/^([A-Z]+)/', $cell->getAttribute('r'), $match);
                            $column = 0;
                            foreach (str_split($match[1] ?? 'A') as $letter) {
                                $column = $column * 26 + ord($letter) - 64;
                            }
                            if ($column < 1 || $column > 64) {
                                throw new RuntimeException('Exportul depășește schema de 64 coloane suportate.');
                            }
                            $value = $cell->getElementsByTagName('v')->item(0)?->textContent ?? '';
                            if ($cell->getAttribute('t') === 's') {
                                fseek($index, (int) $value * 21);
                                $offset = fgets($index);
                                if ($offset === false) {
                                    throw new RuntimeException('Index XLSX invalid.');
                                }
                                fseek($strings, (int) $offset);
                                $value = json_decode(fgets($strings), true, flags: JSON_THROW_ON_ERROR);
                            } elseif ($cell->getAttribute('t') === 'inlineStr') {
                                $value = $cell->textContent;
                            }
                            $values[$column - 1] = trim($value);
                        }
                    }
                    if (array_filter($values, fn (string $value): bool => $value !== '') === []) {
                        continue;
                    }
                    $last = max(array_keys($values));
                    yield array_replace(array_fill(0, $last + 1, ''), $values);
                }
                $this->assertValidXml();
            } finally {
                $reader->close();
            }
        } finally {
            fclose($strings);
            fclose($index);
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
    }

    private function assertValidXml(): void
    {
        foreach (libxml_get_errors() as $error) {
            if ($error->level >= LIBXML_ERR_ERROR) {
                throw new RuntimeException('XML incomplet sau invalid în exportul oficial.');
            }
        }
    }

    private function open(string $path, string $entry): XMLReader
    {
        $reader = new XMLReader;
        if (! $reader->open('zip://'.str_replace('\\', '/', realpath($path)).'#'.$entry, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('Exportul XML nu poate fi citit.');
        }

        return $reader;
    }

    private function text(string $xml, string $tag): string
    {
        $document = new \DOMDocument;
        $document->loadXML($xml, LIBXML_NONET);
        $text = '';
        foreach ($document->getElementsByTagName($tag) as $node) {
            $text .= $node->textContent;
        }

        return $text;
    }
}
