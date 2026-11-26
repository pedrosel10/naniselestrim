<?php
// Recebe o formulário de atendimento, salva o lead e avisa a Nani por e-mail.

declare(strict_types=1);
require __DIR__ . '/../_app/app.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function responder(int $status, array $corpo): void {
    http_response_code($status);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') responder(405, ['ok' => false, 'erro' => 'método']);

$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in)) responder(400, ['ok' => false, 'erro' => 'formato']);

// Anti-robô: campo escondido preenchido ou envio rápido demais → finge sucesso e descarta
if (!empty($in['site']) || (int) ($in['tempo'] ?? 0) < 4000) responder(200, ['ok' => true]);

$txt = fn(string $k, int $max) => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);

$lead = [
    'nome' => $txt('nome', 80),
    'whatsapp' => $txt('whatsapp', 24),
    'tema' => $txt('tema', 20),
    'tema_outro' => $txt('tema_outro', 160),
    'relato' => $txt('relato', 1500),
    'acompanhamento' => $txt('acompanhamento', 20),
    'quando' => $txt('quando', 20),
    'contato' => $txt('contato', 20),
    'origem' => $txt('origem', 80),
];

$digitos = preg_replace('/\D/', '', $lead['whatsapp']);
$erros = [];
if (mb_strlen($lead['nome']) < 2) $erros[] = 'nome';
if (strlen($digitos) < 8 || strlen($digitos) > 15) $erros[] = 'whatsapp';
foreach (['tema', 'acompanhamento', 'quando', 'contato'] as $c) {
    if (!isset(ROTULOS[$c][$lead[$c]])) $erros[] = $c;
}
if ($lead['tema'] === 'outro' && mb_strlen($lead['tema_outro']) < 2) $erros[] = 'tema_outro';
if ($lead['tema'] !== 'outro') $lead['tema_outro'] = '';
if (empty($in['consentimento'])) $erros[] = 'consentimento';
if ($erros) responder(422, ['ok' => false, 'erro' => 'campos', 'campos' => $erros]);

try {
    $pdo = db();
    $ip = ip_hash();

    // Limite: no máximo 20 envios por hora do mesmo endereço (operadoras de celular
    // compartilham o mesmo endereço entre muitos clientes, então não dá para ser baixo)
    $q = $pdo->prepare('SELECT COUNT(*) FROM leads WHERE ip_hash = ? AND criado_em > ?');
    $q->execute([$ip, date('Y-m-d H:i:s', time() - 3600)]);
    if ((int) $q->fetchColumn() >= 20) responder(429, ['ok' => false, 'erro' => 'limite']);

    $q = $pdo->prepare('INSERT INTO leads
        (criado_em, atualizado_em, nome, whatsapp, tema, tema_outro, relato, acompanhamento, quando, contato, origem, ip_hash)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    $q->execute([agora(), agora(), $lead['nome'], $lead['whatsapp'], $lead['tema'], $lead['tema_outro'],
        $lead['relato'], $lead['acompanhamento'], $lead['quando'], $lead['contato'], $lead['origem'], $ip]);
    $id = (int) $pdo->lastInsertId();
} catch (Throwable $e) {
    error_log('[nani-crm] erro ao salvar lead: ' . $e->getMessage());
    responder(500, ['ok' => false, 'erro' => 'servidor']);
}

// Aviso por e-mail. O relato NÃO vai no e-mail, só fica no CRM.
$cfg = config();
if ($cfg && !empty($cfg['email_aviso'])) {
    $host = 'naniselestrim.com'; // fixo: não confiar no cabeçalho Host
    $tema = rotulo('tema', $lead['tema']) . ($lead['tema_outro'] ? ': ' . $lead['tema_outro'] : '');
    $corpo = "Novo pedido de atendimento pelo site.\n\n"
        . "Nome: {$lead['nome']}\n"
        . "WhatsApp: {$lead['whatsapp']}\n"
        . "Tema: {$tema}\n"
        . 'Acompanhamento: ' . rotulo('acompanhamento', $lead['acompanhamento']) . "\n"
        . 'Quando: ' . rotulo('quando', $lead['quando']) . "\n"
        . 'Contato: ' . rotulo('contato', $lead['contato']) . "\n"
        . ($lead['relato'] ? "\nEscreveu um relato. Leia no CRM.\n" : '')
        . "\nAbrir no CRM: https://{$host}/crm/#lead-{$id}\n"
        . 'Chamar no WhatsApp: https://wa.me/' . whatsapp_digitos($lead['whatsapp']) . "\n";
    $assunto = '=?UTF-8?B?' . base64_encode('Novo lead: ' . $lead['nome']) . '?=';
    $cab = "From: Site Nani Selestrim <nao-responda@{$host}>\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n";
    @mail($cfg['email_aviso'], $assunto, $corpo, $cab);
}

responder(200, ['ok' => true]);
