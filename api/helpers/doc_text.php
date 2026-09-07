<?php
// Vytazenie textu z nahrateho dokumentu.
//
// Vracia [text|null, chyba|null]. Ked sa text vytazit neda, dokument sa aj tak
// ulozi — user ho vidi, len nejde do promptu pri posudzovani vhodnosti.
//
// Zamerne bez externych kniznic (Websupport hosting): DOCX/ODT su ZIP archivy,
// ktore vie otvorit vstavany ZipArchive, TXT/RTF sa citaju priamo. PDF sa skusi
// cez `pdftotext`, ak je na serveri; inak vlastny minimalny extraktor.

function doc_extract_text(string $path, string $mime): array {
    try {
        $text = match (true) {
            $mime === 'application/pdf'  => doc_pdf_text($path),
            str_contains($mime, 'wordprocessingml') => doc_zip_xml_text($path, 'word/document.xml'),
            str_contains($mime, 'opendocument.text') => doc_zip_xml_text($path, 'content.xml'),
            $mime === 'text/plain'       => doc_plain_text($path),
            str_contains($mime, 'rtf')   => doc_rtf_text($path),
            str_starts_with($mime, 'image/') => throw new RuntimeException(
                'Z obrázka sa text nevyťaží — nahraj životopis ako PDF alebo DOCX'),
            default => throw new RuntimeException('Formát sa nedá previesť na text'),
        };
    } catch (Throwable $e) {
        return [null, mb_substr($e->getMessage(), 0, 500)];
    }

    $text = doc_cleanup($text);
    if (mb_strlen($text) < 20) {
        return [null, 'Zo súboru sa nepodarilo vyťažiť text (možno je to sken)'];
    }
    return [mb_substr($text, 0, 60000), null];
}

function doc_cleanup(string $s): string {
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = preg_replace('/[^\P{C}\n\t]+/u', ' ', $s) ?? $s;   // riadiace znaky prec
    $s = preg_replace('/[ \t\x{00A0}]+/u', ' ', $s);
    $s = preg_replace('/\n\s*\n\s*\n+/', "\n\n", $s);
    return trim($s);
}

function doc_plain_text(string $path): string {
    $s = file_get_contents($path);
    if ($s === false) throw new RuntimeException('Súbor sa nedá načítať');
    if (!mb_check_encoding($s, 'UTF-8')) {
        $s = mb_convert_encoding($s, 'UTF-8', ['UTF-8', 'Windows-1250', 'ISO-8859-2']);
    }
    return $s;
}

// DOCX aj ODT su ZIP archivy s XML vnutri.
function doc_zip_xml_text(string $path, string $entry): string {
    if (!class_exists('ZipArchive')) throw new RuntimeException('Na serveri chýba rozšírenie zip');

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('Súbor sa nedá otvoriť ako archív');

    $xml = $zip->getFromName($entry);
    $zip->close();
    if ($xml === false) throw new RuntimeException('V dokumente chýba očakávaný obsah');

    // odstavce a zalomenia na nove riadky, zvysok znaciek prec
    $xml = preg_replace('#</(w:p|text:p|text:h)>#', "\n", $xml);
    $xml = preg_replace('#<(w:br|w:tab|text:line-break|text:tab)\b[^>]*/?>#', ' ', $xml);
    return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function doc_rtf_text(string $path): string {
    $s = doc_plain_text($path);
    $s = preg_replace('/\{\\\\\*.*?\}/s', ' ', $s);       // skryte skupiny
    $s = preg_replace('/\\\\par[d]?\b/', "\n", $s);
    // \'e9 = znak v kodovej stranke dokumentu; berieme Windows-1250 (stredna Europa)
    $s = preg_replace_callback("/\\\\'([0-9a-fA-F]{2})/", static function ($m) {
        return mb_convert_encoding(chr(hexdec($m[1])), 'UTF-8', 'Windows-1250');
    }, $s) ?? $s;
    $s = preg_replace('/\\\\[a-z]+-?\d* ?/i', ' ', $s);   // riadiace slova
    return str_replace(['{', '}'], ' ', $s);
}

// ------------------------------------------------------------
// PDF: najprv `pdftotext` (presnejsi), inak vlastny extraktor.
// ------------------------------------------------------------
function doc_pdf_text(string $path): string {
    if (function_exists('shell_exec') && !ini_get('safe_mode')) {
        $bin = trim((string)@shell_exec('command -v pdftotext 2>/dev/null'));
        if ($bin !== '') {
            $out = @shell_exec(escapeshellcmd($bin) . ' -enc UTF-8 -q '
                 . escapeshellarg($path) . ' - 2>/dev/null');
            if (is_string($out) && mb_strlen(trim($out)) > 20) return $out;
        }
    }
    return doc_pdf_text_native($path);
}

// Minimalny PDF extraktor: rozbali prudy komprimovane cez FlateDecode
// a vyzbiera retazce z operatorov Tj / TJ. Staci na textove CV, sken nie.
function doc_pdf_text_native(string $path): string {
    $raw = file_get_contents($path);
    if ($raw === false) throw new RuntimeException('Súbor sa nedá načítať');
    if (!function_exists('gzuncompress')) throw new RuntimeException('Na serveri chýba zlib');

    $text = '';
    if (preg_match_all('#stream\r?\n?(.*?)endstream#s', $raw, $streams)) {
        foreach ($streams[1] as $stream) {
            $data = @gzuncompress(ltrim($stream, "\r\n"));
            if ($data === false) $data = $stream;      // nekomprimovany prud
            if (!str_contains($data, 'Tj') && !str_contains($data, 'TJ')) continue;

            // ( ... ) Tj   aj   [ (..) -250 (..) ] TJ
            if (preg_match_all('/\(((?:\\\\.|[^()\\\\])*)\)/s', $data, $chunks)) {
                foreach ($chunks[1] as $c) {
                    $text .= strtr($c, ['\\(' => '(', '\\)' => ')', '\\\\' => '\\',
                                        '\\n' => "\n", '\\r' => '', '\\t' => ' ']);
                }
                $text .= "\n";
            }
        }
    }
    if (trim($text) === '') {
        throw new RuntimeException('Z PDF sa nepodarilo vyťažiť text (pravdepodobne sken)');
    }
    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = mb_convert_encoding($text, 'UTF-8', ['UTF-8', 'Windows-1250', 'ISO-8859-2']);
    }
    return $text;
}
