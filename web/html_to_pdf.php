<?php

/**
 * Genereer een PDF (binary string) uit een HTML-string via wkhtmltopdf.
 *
 * Deze helper is bewust generiek gehouden zodat je hem makkelijk kunt hergebruiken
 * in andere projecten.
 *
 * Verwachte input in $config:
 * - wkhtmltopdf_path (string): pad of commandonaam, bv. "/usr/bin/wkhtmltopdf" of "wkhtmltopdf"
 * - wkhtmltopdf_options (array): extra CLI-opties als losse argumenten
 *   Voorbeeld:
 *   [
 *     '--page-size', 'A4',
 *     '--margin-top', '10mm',
 *     '--margin-right', '10mm',
 *     '--margin-bottom', '10mm',
 *     '--margin-left', '10mm',
 *   ]
 *
 * Retourneert:
 * - PDF binary string
 *
 * Gooit RuntimeException bij fouten.
 */

function htmlToPdf(string $html, array $config = []): string
{
    if (trim($html) === '') {
        throw new RuntimeException('Kan geen PDF maken van lege HTML');
    }

    $wkhtmltopdfPath = trim((string) ($config['wkhtmltopdf_path'] ?? 'wkhtmltopdf'));
    if ($wkhtmltopdfPath === '') {
        $wkhtmltopdfPath = 'wkhtmltopdf';
    }

    // Veilige defaults voor server-side PDF-rendering.
    $defaultOptions = [
        '--quiet',
        '--encoding',
        'UTF-8',
        '--page-size',
        'A4',
        '--margin-top',
        '10mm',
        '--margin-right',
        '10mm',
        '--margin-bottom',
        '10mm',
        '--margin-left',
        '10mm',
        '--disable-javascript',
        '--print-media-type',
        '--enable-local-file-access',
    ];

    $customOptions = $config['wkhtmltopdf_options'] ?? [];
    if (!is_array($customOptions)) {
        $customOptions = [];
    }

    $tmpBase = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'html-to-pdf-' . bin2hex(random_bytes(8));
    $htmlFile = $tmpBase . '.html';
    $pdfFile = $tmpBase . '.pdf';

    file_put_contents($htmlFile, $html);

    $args = array_merge([$wkhtmltopdfPath], $defaultOptions, $customOptions, [$htmlFile, $pdfFile]);
    $command = implode(' ', array_map('escapeshellarg', $args));

    // proc_open gebruiken zodat stdout/stderr expliciet uit te lezen is.
    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        @unlink($htmlFile);
        throw new RuntimeException('Kon wkhtmltopdf niet starten');
    }

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    try {
        if ($exitCode !== 0 || !is_file($pdfFile) || filesize($pdfFile) === 0) {
            $details = trim($stderr . "\n" . $stdout);
            if ($details === '') {
                $details = 'onbekende fout';
            }
            throw new RuntimeException('wkhtmltopdf mislukt: ' . $details);
        }

        $pdf = file_get_contents($pdfFile);
        if ($pdf === false || $pdf === '') {
            throw new RuntimeException('wkhtmltopdf gaf geen geldige PDF output');
        }

        return $pdf;
    } finally {
        // Temp files altijd opruimen, ook bij exceptions.
        @unlink($htmlFile);
        @unlink($pdfFile);
    }
}
