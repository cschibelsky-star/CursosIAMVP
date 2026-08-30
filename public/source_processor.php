<?php
declare(strict_types=1);

function sourceCleanText(string $text): string
{
    $text = str_replace("\0", '', $text);
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $text) ?? $text;
    $lines = preg_split('/\n/u', $text) ?: [];
    $clean = [];
    foreach ($lines as $line) {
        $line = preg_replace('/[\t ]+/u', ' ', $line) ?? $line;
        $clean[] = trim($line);
    }
    $text = implode("\n", $clean);
    $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
    return trim($text);
}

function sourceQuality(string $content): array
{
    $content = trim($content);
    $chars = mb_strlen($content);
    $words = preg_split('/\s+/u', $content, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $wordCount = count($words);
    $paragraphs = array_values(array_filter(preg_split('/\n{2,}/u', $content) ?: [], fn(string $p): bool => trim($p) !== ''));
    if ($chars < 200 || $wordCount < 35) return ['status'=>'baixa','note'=>'Conteúdo muito curto para sustentar uma geração pedagógica confiável.','characters'=>$chars,'words'=>$wordCount,'paragraphs'=>count($paragraphs)];
    if ($chars < 1200 || $wordCount < 180) return ['status'=>'media','note'=>'Fonte utilizável, mas curta. Recomenda-se combinar com outras fontes.','characters'=>$chars,'words'=>$wordCount,'paragraphs'=>count($paragraphs)];
    return ['status'=>'boa','note'=>'Volume textual adequado para uso na geração do curso.','characters'=>$chars,'words'=>$wordCount,'paragraphs'=>count($paragraphs)];
}

function extractDocxText(string $path): string
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('Não foi possível abrir o DOCX.');
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false || $xml === '') throw new RuntimeException('DOCX sem conteúdo textual legível.');
    $xml = preg_replace('/<w:tab[^>]*\/>/i', "\t", $xml) ?? $xml;
    $xml = preg_replace('/<w:br[^>]*\/>/i', "\n", $xml) ?? $xml;
    $xml = preg_replace('/<\/w:p>/i', "\n", $xml) ?? $xml;
    return sourceCleanText(html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8'));
}

function extractPdfText(string $path): string
{
    $pdftotext = trim((string)shell_exec('command -v pdftotext 2>/dev/null'));
    if ($pdftotext !== '') {
        $output = tempnam(sys_get_temp_dir(), 'curso_pdf_');
        if ($output === false) throw new RuntimeException('Não foi possível criar arquivo temporário para PDF.');
        $command = escapeshellcmd($pdftotext) . ' -layout -enc UTF-8 ' . escapeshellarg($path) . ' ' . escapeshellarg($output) . ' 2>&1';
        exec($command, $lines, $exitCode);
        $text = $exitCode === 0 ? file_get_contents($output) : false;
        @unlink($output);
        if (is_string($text)) {
            $clean = sourceCleanText($text);
            if (mb_strlen($clean) >= 120) return $clean;
        }
    }

    $pdftoppm = trim((string)shell_exec('command -v pdftoppm 2>/dev/null'));
    $tesseract = trim((string)shell_exec('command -v tesseract 2>/dev/null'));
    if ($pdftoppm === '' || $tesseract === '') throw new RuntimeException('PDF sem camada textual suficiente e OCR indisponível. Instale pdftoppm e tesseract com idioma português no runtime HML.');

    $workDir = sys_get_temp_dir() . '/curso_ocr_' . bin2hex(random_bytes(8));
    if (!mkdir($workDir, 0700, true) && !is_dir($workDir)) throw new RuntimeException('Não foi possível preparar o diretório temporário de OCR.');
    try {
        $prefix = $workDir . '/page';
        exec(escapeshellcmd($pdftoppm) . ' -jpeg -r 220 ' . escapeshellarg($path) . ' ' . escapeshellarg($prefix) . ' 2>&1', $renderLines, $renderCode);
        if ($renderCode !== 0) throw new RuntimeException('Falha ao converter o PDF em imagens para OCR.');
        $images = glob($workDir . '/page-*.jpg') ?: [];
        natsort($images);
        if (!$images) throw new RuntimeException('OCR não encontrou páginas renderizadas no PDF.');
        $parts = [];
        foreach (array_values($images) as $index => $image) {
            $base = $workDir . '/ocr_' . ($index + 1);
            exec(escapeshellcmd($tesseract) . ' ' . escapeshellarg($image) . ' ' . escapeshellarg($base) . ' -l por --psm 6 2>&1', $ocrLines, $ocrCode);
            if ($ocrCode !== 0) continue;
            $txtPath = $base . '.txt';
            if (is_file($txtPath)) {
                $pageText = file_get_contents($txtPath);
                if (is_string($pageText) && trim($pageText) !== '') $parts[] = $pageText;
            }
        }
        $clean = sourceCleanText(implode("\n\n", $parts));
        if (mb_strlen($clean) < 120) throw new RuntimeException('OCR executado, mas o PDF não produziu texto suficiente para geração pedagógica confiável.');
        return $clean;
    } finally {
        foreach (glob($workDir . '/*') ?: [] as $file) @unlink($file);
        @rmdir($workDir);
    }
}

function processUploadedSource(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Falha no upload da fonte.');
    $name = basename((string)($file['name'] ?? 'fonte'));
    $path = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowed = ['txt', 'md', 'csv', 'pdf', 'docx'];
    if (!in_array($ext, $allowed, true)) throw new RuntimeException('Formato não suportado. Envie TXT, MD, CSV, PDF ou DOCX.');
    if ($size < 1 || $size > 10 * 1024 * 1024) throw new RuntimeException('O arquivo deve ter entre 1 byte e 10 MB.');
    if (!is_uploaded_file($path)) throw new RuntimeException('Arquivo de upload inválido.');
    if ($ext === 'pdf') $content = extractPdfText($path);
    elseif ($ext === 'docx') $content = extractDocxText($path);
    else {
        $raw = file_get_contents($path);
        if ($raw === false) throw new RuntimeException('Não foi possível ler o arquivo.');
        $content = sourceCleanText($raw);
    }
    if ($content === '') throw new RuntimeException('Nenhum conteúdo textual foi extraído da fonte.');
    return ['name'=>$name,'extension'=>$ext,'content'=>mb_substr($content,0,250000),'characters'=>mb_strlen($content)];
}
