<?php

namespace App\Support;

use XMLWriter;
use ZipArchive;

/** Small, typed OOXML exports: text stays text (including formula-like input). */
class ExcelWorkbook
{
    public static function download(string $filename, array $headers, iterable $rows)
    {
        $sheet = tempnam(sys_get_temp_dir(), 'paperstic-sheet-');
        $archive = tempnam(sys_get_temp_dir(), 'paperstic-xlsx-');
        try {
            $xml = new XMLWriter;
            $xml->openUri($sheet);
            $xml->startDocument('1.0', 'UTF-8');
            $xml->startElementNS(null, 'worksheet', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $xml->startElement('sheetViews');
            $xml->startElement('sheetView');
            $xml->writeAttribute('workbookViewId', '0');
            $xml->startElement('pane');
            foreach (['ySplit' => '1', 'topLeftCell' => 'A2', 'activePane' => 'bottomLeft', 'state' => 'frozen'] as $key => $value) {
                $xml->writeAttribute($key, $value);
            }
            $xml->endElement();
            $xml->endElement();
            $xml->endElement();
            $xml->startElement('sheetData');
            $writeRow = function ($values) use ($xml) {
                $xml->startElement('row');
                foreach ($values as $value) {
                    $xml->startElement('c');
                    if (is_int($value) || is_float($value)) {
                        $xml->writeElement('v', (string) $value);
                    } else {
                        $xml->writeAttribute('t', 'inlineStr');
                        $xml->startElement('is');
                        $xml->writeElement('t', preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) $value));
                        $xml->endElement();
                    }
                    $xml->endElement();
                }
                $xml->endElement();
            };
            $writeRow($headers);
            foreach ($rows as $row) {
                $writeRow($row);
            }
            $xml->endElement();
            $xml->endElement();
            $xml->endDocument();
            $xml->flush();
            $zip = new ZipArchive;
            if ($zip->open($archive, ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Excel export could not be prepared.');
            }
            $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
            $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Paperstic" sheetId="1" r:id="rId1"/></sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
            $zip->addFile($sheet, 'xl/worksheets/sheet1.xml');
            $zip->close();
        } catch (\Throwable $error) {
            @unlink($archive);
            throw $error;
        } finally {
            @unlink($sheet);
        }

        return response()->download($archive, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'private, no-store'])->deleteFileAfterSend(true);
    }
}
