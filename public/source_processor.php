<?php
declare(strict_types=1);

function sourceCleanText(string $text): string
{
    $text = str_replace("\0", '', $text);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $text) ?? $text;
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return trim($text);
}

function extractDocxText(string $path): string
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Não foi possível abrir o DOCX.');
    }

    $xml = $zip->getFromName('word/document.xml');
    $zip->close();

    if ($xml === false || $xml === '') {
        throw new RuntimeException('DOCX sem conteúdo textual legível.');
    }

    $xml = preg_replace('/<w:tab[^>]*\/>/i', "\t", $xml) ?? $xml;
    $xml = preg_replace('/<w:br[^>]*\/>/i', "\n", $xml) ?? $xml;
    $xml = preg_replace('/<\/w:p>/i', "\n", $xml) ?? $xml;
    $text = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');

    return sourceCleanText($text);
}

function extractPdfText(string $path): string
{
    $binary = trim((string)shell_exec('command -v pdftotext 2>/dev/null'));
    if ($binary === '') {
        throw new RuntimeException('Extrator PDF indisponível no ambiente.');
    }

    $output = tempnam(sys_get_temp_dir(), 'curso_pdf_');
    if ($output === false) {
        throw new RuntimeException('Não foi possível criar arquivo temporário para PDF.');
    }

    $command = escapeshellcmd($binary) . ' -layout -enc UTF-8 ' . escapeshellarg($path) . ' ' . escapeshellarg($output) . ' 2>&1';
    exec($command, $lines, $exitCode);

    if ($exitCode !== 0) {
        @unlink($output);
        throw new RuntimeException('Falha ao extrair texto do PDF.');
    }

    $text = file_get_contents($output);
    @unlink($output);

    if ($text === false || trim($text) === '') {
        throw new RuntimeException('PDF sem texto extraível. Documentos digitalizados exigirão OCR em etapa posterior.');
    }

    return sourceCleanText($text);
}

function processUploadedSource(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Falha no upload da fonte.');
    }

    $name = basename((string)($file['name'] ?? 'fonte'));
    $path = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowed = ['txt', 'md', 'csv', 'pdf', 'docx'];

    if (!in_array($ext, $allowed, true)) {
        throw new RuntimeException('Formato não suportado. Envie TXT, MD, CSV, PDF ou DOCX.');
    }

    if ($size < 1 || $size > 10 * 1024 * 1024) {
        throw new RuntimeException('O arquivo deve ter entre 1 byte e 10 MB.');
    }

    if (!is_uploaded_file($path)) {
        throw new RuntimeException('Arquivo de upload inválido.');
    }

    if ($ext === 'pdf') {
        $content = extractPdfText($path);
    } elseif ($ext === 'docx') {
        $content = extractDocxText($path);
    } else {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('Não foi possível ler o arquivo.');
        }
        $content = sourceCleanText($raw);
    }

    if ($content === '') {
        throw new RuntimeException('Nenhum conteúdo textual foi extraído da fonte.');
    }

    return [
        'name' => $name,
        'extension' => $ext,
        'content' => mb_substr($content, 0, 250000),
        'characters' => mb_strlen($content),
    ];
}
