<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV files for accountants, streamed so large exports use little memory. Excel opens them with the
 * right characters (UTF-8 with a byte order mark), and a cell that would start a formula is kept
 * as text, so a client name like "=HYPERLINK(...)" cannot run in the accountant's spreadsheet.
 */
class CsvExport
{
    /**
     * @param  list<string>  $header
     * @param  iterable<int, list<mixed>>  $rows
     */
    public static function download(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_map(self::cell(...), $header), escape: '');

            foreach ($rows as $row) {
                fputcsv($out, array_map(self::cell(...), $row), escape: '');
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * One cell as text. Cells starting with = + - @ or a tab get a leading apostrophe, except plain
     * negative numbers such as refunds.
     */
    public static function cell(mixed $value): string
    {
        $text = match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            default => (string) $value,
        };

        if ($text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! preg_match('/^-\d+(\.\d+)?$/', $text)) {
            return "'".$text;
        }

        return $text;
    }
}
