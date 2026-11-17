<?php

namespace App\Services\ExternalImport;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * Reads iTestEMR's "Print All Records" pages: one patient folder per <section class="px"> (several
 * saved into one .html file), or a single saved page. Each folder has a cover table (name, Patient ID)
 * and one block per visit made of titled sections: Vision & Vitals, History, Diagnosis & Treatment,
 * External Medication, Refraction, Spectacle Prescription and Review & Follow-Up.
 */
class ITestEmrRecordsReader
{
    /** True when the HTML looks like saved iTestEMR patient folders. */
    public static function looks(string $html): bool
    {
        return str_contains($html, 'Patient Folder') && preg_match('/Visit\s*#\s*\d+/', $html) && str_contains($html, 'Patient ID:');
    }

    /** @return array<int, array{number: string, name: string, visits: array}> */
    public function read(string $path): array
    {
        $html = @file_get_contents($path);
        if ($html === false || $html === '') throw new RuntimeException('The records file could not be read.');
        if (! self::looks($html)) throw new RuntimeException('This is not a saved iTestEMR records page.');

        preg_match_all('/<section class="px"[^>]*>(.*?)<\/section>/s', $html, $m);
        $chunks = $m[1] ?: [$html];
        $folders = [];
        foreach ($chunks as $chunk) if ($folder = $this->folder($chunk)) $folders[] = $folder;

        return $folders;
    }

    private function folder(string $html): ?array
    {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div>'.$html.'</div>', LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $x = new DOMXPath($doc);

        $cover = [];
        foreach ($x->query('//table[contains(@class,"cover-table")]//tr') as $tr) {
            $cells = $x->query('./td', $tr);
            if ($cells->length >= 2) $cover[rtrim($this->text($cells->item(0)), ':')] = $this->text($cells->item(1));
        }
        $number = $cover['Patient ID'] ?? '';
        if ($number === '') return null;

        $visits = [];
        $visit = null;
        foreach ($x->query('//div[contains(concat(" ", normalize-space(@class), " "), " section ")]') as $section) {
            $titleNode = $x->query('./div[contains(@class,"section-title")]', $section)->item(0);
            $title = $titleNode ? $this->text($titleNode) : '';
            if (preg_match('/^Visit\s*#\s*(\d+)/', $title)) {
                if ($visit) $visits[] = $visit;
                $visit = ['date' => preg_match('/(\d{4}-\d{2}-\d{2})/', $title, $d) ? $d[1] : null] + $this->pairs($x, $section);
                continue;
            }
            if (! $visit) continue;
            match (true) {
                str_starts_with($title, 'Vision') => $visit['vision'] = $this->vision($x, $section),
                str_starts_with($title, 'History') => $visit['history'] = $this->labelled($x, $section),
                str_starts_with($title, 'Diagnosis') => $visit += $this->diagnosisAndTreatment($x, $section),
                str_starts_with($title, 'External Medication') => $visit += $this->external($x, $section),
                str_starts_with($title, 'Refraction') => $visit['refraction'] = $this->prescription($x, $section) + ['by' => preg_match('/By\s+(.+)$/u', $title, $b) ? trim($b[1]) : null],
                str_starts_with($title, 'Spectacle') => $visit['spectacles'] = $this->prescription($x, $section),
                str_starts_with($title, 'Review') => $visit['review'] = $this->review($x, $section),
                default => null,
            };
        }
        if ($visit) $visits[] = $visit;

        return ['number' => $number, 'name' => $cover['Patient Name'] ?? '', 'visits' => $visits];
    }

    /** The visit header: Doctor, Visit ID, Visit Time as th/td pairs. */
    private function pairs(DOMXPath $x, DOMElement $section): array
    {
        $out = [];
        foreach ($x->query('.//tr', $section) as $tr) {
            $cells = iterator_to_array($x->query('./th|./td', $tr));
            for ($i = 0; $i + 1 < count($cells); $i += 2) $out[strtolower(str_replace(' ', '_', $this->text($cells[$i])))] = $this->text($cells[$i + 1]);
        }

        return ['doctor' => $out['doctor'] ?? '', 'visit_id' => $out['visit_id'] ?? '', 'time' => $out['visit_time'] ?? ''];
    }

    private function vision(DOMXPath $x, DOMElement $section): array
    {
        $rows = [];
        foreach ($x->query('.//table//tbody/tr', $section) as $tr) {
            $th = $x->query('./th', $tr)->item(0);
            if (! $th) continue;
            $tds = array_map(fn ($td) => $this->value($this->text($td)), iterator_to_array($x->query('./td', $tr)));
            $rows[$this->text($th)] = ['od' => $tds[0] ?? null, 'os' => $tds[1] ?? null, 'ou' => $tds[2] ?? null];
        }
        $vitals = [];
        foreach ($x->query('.//div[contains(@class,"vital-box")]', $section) as $box) {
            $label = $this->text($x->query('./strong', $box)->item(0));
            $value = trim(mb_substr($this->text($box), mb_strlen($label)));
            if (($value = $this->value(preg_replace('/^-\s*(bpm|kg|°?C)?$/u', '', $value))) !== null) $vitals[$label] = $value;
        }

        return ['unaided' => $rows['Unaided VA'] ?? [], 'pinhole' => $rows['Pinhole'] ?? [], 'iop' => $rows['IOP (mmHg)'] ?? [], 'vitals' => $vitals];
    }

    /** Paragraphs of "<span class=label>Label:</span> value". */
    private function labelled(DOMXPath $x, DOMElement $section): array
    {
        $out = [];
        foreach ($x->query('.//p[span[contains(@class,"label")]]', $section) as $p) {
            $label = $this->text($x->query('./span', $p)->item(0));
            $out[rtrim($label, ':')] = $this->value(trim(mb_substr($this->text($p), mb_strlen($label))));
        }

        return $out;
    }

    private function diagnosisAndTreatment(DOMXPath $x, DOMElement $section): array
    {
        $out = ['diagnoses' => [], 'medications' => []];
        $into = null;
        foreach ($x->query('./p', $section) as $p) {
            $text = $this->text($p);
            if (str_contains(' '.$p->getAttribute('class').' ', ' label ')) { $into = str_starts_with($text, 'Medication') ? 'medications' : 'diagnoses'; continue; }
            if ($into && ($item = $this->value(preg_replace('/^\d+\.\s*/', '', $text))) !== null) $out[$into][] = $item;
        }

        return $out;
    }

    private function external(DOMXPath $x, DOMElement $section): array
    {
        $items = array_values(array_filter(array_map(fn ($li) => $this->value($this->text($li)), iterator_to_array($x->query('.//li', $section)))));
        $plan = null;
        $ps = iterator_to_array($x->query('./p', $section));
        foreach ($ps as $i => $p) if (str_contains($this->text($p), 'Additional Treatment Plan') && isset($ps[$i + 1])) $plan = $this->value($this->text($ps[$i + 1]));

        return ['external_medications' => $items, 'plan' => $plan];
    }

    /** A Refraction or Spectacle Prescription block: OD/OS rows (sphere, cylinder, axis, VA), Add, PD, auto-refraction text. */
    private function prescription(DOMXPath $x, DOMElement $section): array
    {
        $out = ['od' => [], 'os' => [], 'add' => null, 'pd' => null, 'auto' => null];
        foreach ($x->query('.//tr[td]', $section) as $tr) {
            $tds = array_map(fn ($td) => $this->text($td), iterator_to_array($x->query('./td', $tr)));
            $eye = strtolower($tds[0] ?? '');
            if (in_array($eye, ['od', 'os'], true)) $out[$eye] = ['sphere' => $this->value($tds[1] ?? null), 'cylinder' => $this->value($tds[2] ?? null), 'axis' => $this->value($tds[3] ?? null), 'va' => $this->value($tds[4] ?? null)];
        }
        foreach ($x->query('./p[strong]', $section) as $p) {
            $label = $this->text($x->query('./strong', $p)->item(0));
            $value = $this->value(trim(mb_substr($this->text($p), mb_strlen($label))));
            match (strtolower(rtrim($label, ':'))) {
                'add' => $out['add'] = $value === 'OD | OS' ? null : $value,
                'pd' => $out['pd'] = $value,
                default => null,
            };
        }
        if ($pre = $x->query('.//pre', $section)->item(0)) $out['auto'] = $this->value($this->text($pre));

        return $out;
    }

    private function review(DOMXPath $x, DOMElement $section): array
    {
        $out = [];
        foreach ($x->query('.//td[strong]', $section) as $td) {
            $label = $this->text($x->query('./strong', $td)->item(0));
            $out[strtolower(str_replace(' ', '_', rtrim($label, ':')))] = $this->value(trim(mb_substr($this->text($td), mb_strlen($label))));
        }

        return ['period' => $out['review_period'] ?? null, 'next' => $out['next_visit'] ?? null, 'time' => $out['time'] ?? null, 'status' => $out['consultation_status'] ?? null];
    }

    private function text($node): string
    {
        if (! $node) return '';

        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', str_replace("\u{FFFD}", '–', $node->textContent)));
    }

    /** Blank and "-" placeholders are no value. */
    private function value(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' || $value === '-' ? null : $value;
    }
}
