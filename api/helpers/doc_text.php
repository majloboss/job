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
        // substr, nie mb_substr: sprava moze sama obsahovat neplatne bajty
        return [null, doc_do_utf8(substr($e->getMessage(), 0, 500))];
    }

    $text = doc_cleanup($text);
    if (mb_strlen($text) < 20) {
        return [null, 'Zo súboru sa nepodarilo vyťažiť text (možno je to sken)'];
    }
    if (!doc_je_citatelny($text)) {
        return [null, 'Vyťažený text je nečitateľný — súbor sa uložil, '
                    . 'ale do posudzovania nepôjde. Skús ho uložiť ako DOCX.'];
    }
    return [mb_substr($text, 0, 60000), null];
}

// Je vytazeny text naozaj text, alebo zmet znakov?
//
// Zalozny PDF extraktor pri vlozenych fontoch vracia bajty, ktore su sice
// platne UTF-8, ale citat sa nedaju ("7ÝI˝ł ňąÓQ %a¸BČA"). Take nieco nema
// zmysel posielat modelu — lepsie je priznat, ze sa text nevytazil.
//
// Rozlisuje sa podielom beznych znakov: v skutocnom texte (aj slovenskom)
// je vacsina pismen zakladna latinka, cislice, medzery a interpunkcia.
function doc_je_citatelny(string $text): bool {
    $vzorka = mb_substr($text, 0, 3000);
    $spolu  = mb_strlen($vzorka);
    if ($spolu < 20) return false;

    // zakladna latinka + slovenska diakritika + cislice a bezna interpunkcia
    $bezne = preg_match_all(
        '/[a-zA-Z0-9\s.,;:!?()\/@+—–\-\'"àáäčďéěíĺľňóôöŕřšťúůüýžÀÁÄČĎÉĚÍĹĽŇÓÔÖŔŘŠŤÚŮÜÝŽ]/u',
        $vzorka
    );

    return $bezne !== false && ($bezne / $spolu) >= 0.85;
}

// Zabezpeci, ze retazec je platne UTF-8.
//
// Nazvy kodovani sa medzi buildmi PHP lisia (CP1250 / Windows-1250), a
// neznamy nazov vyhodi vynimku — preto sa kazdy skusa zvlast a v pripade
// neuspechu sa pokracuje dalsim. Ked nesadne ziadne, neplatne bajty sa
// zahodia, aby sa text nestratil cely.
function doc_do_utf8(string $s): string {
    if ($s === '' || mb_check_encoding($s, 'UTF-8')) return $s;

    foreach (['CP1250', 'Windows-1250', 'ISO-8859-2', 'CP1252', 'ISO-8859-1'] as $kod) {
        try {
            if (!in_array(strtolower($kod), array_map('strtolower', mb_list_encodings()), true)) {
                continue;
            }
            $prevod = @mb_convert_encoding($s, 'UTF-8', $kod);
            if (is_string($prevod) && mb_check_encoding($prevod, 'UTF-8')) return $prevod;
        } catch (Throwable) {
            continue;   // neznamy nazov kodovania v tomto builde PHP
        }
    }

    // Posledna moznost: zahod bajty, ktore nie su platne UTF-8.
    return (string)preg_replace('/[\x80-\xFF]/', '', $s);
}

function doc_cleanup(string $s): string {
    // Prekodovanie MUSI byt prve — vzory s /u na neplatnom UTF-8 vracaju null.
    $s = doc_do_utf8($s);

    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = preg_replace('/[^\P{C}\n\t]+/u', ' ', $s)   ?? $s;   // riadiace znaky prec
    $s = preg_replace('/[ \t\x{00A0}]+/u', ' ', $s)  ?? $s;
    $s = preg_replace('/\n[ \t]*\n[ \t]*\n+/', "\n\n", $s) ?? $s;
    return trim($s);
}

function doc_plain_text(string $path): string {
    $s = file_get_contents($path);
    if ($s === false) throw new RuntimeException('Súbor sa nedá načítať');
    return doc_do_utf8($s);
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
    // \'e9 = znak v kodovej stranke dokumentu (stredoeuropska)
    $s = preg_replace_callback("/\\\\'([0-9a-fA-F]{2})/", static function ($m) {
        return doc_do_utf8(chr(hexdec($m[1])));
    }, $s) ?? $s;
    $s = preg_replace('/\\\\[a-z]+-?\d* ?/i', ' ', $s);   // riadiace slova
    return str_replace(['{', '}'], ' ', $s);
}

// ------------------------------------------------------------
// PDF: najprv `pdftotext` (presny), az potom vlastny extraktor.
//
// pdftotext rozumie kodovaniu fontov v PDF a vrati rovno UTF-8. Vlastny
// extraktor nizsie je len zaloha pre servery, kde nastroj nie je — vytiahne
// bajty tak, ako su v prude, a pri neanglickom texte z nich byva zmet.
// ------------------------------------------------------------
function doc_pdf_text(string $path): string {
    $out = doc_pdftotext($path);
    if ($out !== null) return $out;

    return doc_pdf_text_native($path);
}

// Vrati text z pdftotext, alebo null ked nastroj nie je / zlyhal.
//
// POUZIVA SA proc_open S POLOM ARGUMENTOV, nie shell_exec ani exec.
// Na Websupporte su shell_exec aj exec definovane a nie su v disable_functions,
// ale spustenie cez shell nic nevrati (shell_exec 0 B, exec navratovy kod -1).
// proc_open s polom obchadza shell a funguje — overene diagnostikou na
// produkcii: navratovy kod 0, 9,6 kB spravneho UTF-8 textu vratane diakritiky.
//
// Pole argumentov ma este jednu vyhodu: nic sa neinterpretuje shellom, takze
// nazov suboru netreba escapovat.
function doc_pdftotext(string $path): ?string {
    if (!function_exists('proc_open')) return null;

    $zakazane = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (in_array('proc_open', $zakazane, true)) return null;

    $bin = null;
    foreach (['/usr/bin/pdftotext', '/bin/pdftotext', '/usr/local/bin/pdftotext'] as $c) {
        if (is_executable($c)) { $bin = $c; break; }
    }
    if ($bin === null) return null;

    // -layout zachova stlpce, ktore ma vacsina zivotopisov
    $prikaz = [$bin, '-enc', 'UTF-8', '-layout', '-q', $path, '-'];
    $popis  = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

    $proces = @proc_open($prikaz, $popis, $rury);
    if (!is_resource($proces)) return null;

    $text = stream_get_contents($rury[1]);
    fclose($rury[1]);
    fclose($rury[2]);          // stderr nezaujima, ale rura sa musi zavriet
    $kod = proc_close($proces);

    // strlen, nie mb_strlen: na neplatnom UTF-8 by mb_strlen mohlo vratit 0
    // a dobry vysledok by sa zahodil.
    if ($kod === 0 && is_string($text) && strlen(trim($text)) > 20) return $text;

    return null;
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
    return doc_do_utf8($text);
}
