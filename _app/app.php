<?php
// Núcleo compartilhado: configuração, banco de dados e utilidades.
// Os dados ficam FORA da pasta pública do site (~/naniselestrim-data).

declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');

// A hospedagem ainda roda PHP 7.4: funções do PHP 8 usadas aqui ganham versão própria
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $h, string $n): bool { return $n === '' || strncmp($h, $n, strlen($n)) === 0; }
}

function pasta_dados(): string {
    $env = getenv('NANI_DATA_DIR');
    if ($env) return rtrim($env, '/');
    // pasta pública: ~/naniselestrim.com  →  dados: ~/naniselestrim-data
    return dirname(rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__), '/')) . '/naniselestrim-data';
}

function garantir_pasta(): string {
    $dir = pasta_dados();
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
    return $dir;
}

function config(): ?array {
    $arq = pasta_dados() . '/config.php';
    if (!is_file($arq)) return null;
    $c = require $arq;
    return is_array($c) ? $c : null;
}

function salvar_config(array $c): void {
    $arq = garantir_pasta() . '/config.php';
    file_put_contents($arq, "<?php\nreturn " . var_export($c, true) . ";\n", LOCK_EX);
    @chmod($arq, 0600);
}

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $arq = garantir_pasta() . '/crm.sqlite';
    $pdo = new PDO('sqlite:' . $arq);
    @chmod($arq, 0600);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('CREATE TABLE IF NOT EXISTS leads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        criado_em TEXT NOT NULL,
        atualizado_em TEXT NOT NULL,
        nome TEXT NOT NULL,
        whatsapp TEXT NOT NULL,
        tema TEXT NOT NULL,
        tema_outro TEXT,
        relato TEXT,
        acompanhamento TEXT NOT NULL,
        quando TEXT NOT NULL,
        contato TEXT NOT NULL,
        origem TEXT,
        status TEXT NOT NULL DEFAULT "novo",
        notas TEXT NOT NULL DEFAULT "",
        ip_hash TEXT
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS tentativas (ip_hash TEXT NOT NULL, em INTEGER NOT NULL)');
    return $pdo;
}

// Sessão guardada na pasta de dados (não na pasta temporária compartilhada do servidor)
function iniciar_sessao(): void {
    $dir = garantir_pasta() . '/sessoes';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    if (is_dir($dir) && is_writable($dir)) session_save_path($dir);
    $vida = 60 * 60 * 24 * 30;
    ini_set('session.gc_maxlifetime', (string) $vida);
    ini_set('session.use_strict_mode', '1');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('nani_crm');
    session_set_cookie_params(['lifetime' => $vida, 'path' => '/crm/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
}

// Bloqueio de força bruta: até 8 senhas erradas por endereço a cada 15 minutos
function login_bloqueado(): bool {
    $q = db()->prepare('SELECT COUNT(*) FROM tentativas WHERE ip_hash = ? AND em > ?');
    $q->execute([ip_hash(), time() - 900]);
    return (int) $q->fetchColumn() >= 8;
}
function registrar_falha(): void {
    db()->prepare('INSERT INTO tentativas (ip_hash, em) VALUES (?, ?)')->execute([ip_hash(), time()]);
    db()->prepare('DELETE FROM tentativas WHERE em < ?')->execute([time() - 86400]);
}
function limpar_falhas(): void {
    db()->prepare('DELETE FROM tentativas WHERE ip_hash = ?')->execute([ip_hash()]);
}

// Rótulos legíveis para os valores salvos
const ROTULOS = [
    'tema' => [
        'autodominio' => 'Ansiedade, irritação ou falta de autodomínio',
        'filhos' => 'Dificuldades na educação dos filhos',
        'casamento' => 'Casamento',
        'afetivo' => 'Questões afetivas e emocionais',
        'outro' => 'Outro',
    ],
    'acompanhamento' => [
        'nunca' => 'Nunca fez acompanhamento',
        'ja_fiz' => 'Já fez acompanhamento',
        'fazendo' => 'Está em acompanhamento agora',
    ],
    'quando' => [
        'logo' => 'O quanto antes',
        'semanas' => 'Nas próximas semanas',
        'conhecendo' => 'Ainda está conhecendo',
    ],
    'contato' => [
        'conversar' => 'Quer conversar',
        'informacoes' => 'Prefere só receber informações',
    ],
];

const ETAPAS = [
    'novo' => 'Novo',
    'conversa' => 'Em conversa',
    'agendado' => 'Consulta agendada',
    'paciente' => 'Paciente',
    'nao_seguiu' => 'Não seguiu',
];

function rotulo(string $campo, ?string $valor): string {
    return ROTULOS[$campo][$valor ?? ''] ?? (string) $valor;
}

function h(?string $s): string {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

// Número em formato internacional só com dígitos, para o link do WhatsApp
function whatsapp_digitos(string $tel): string {
    $d = preg_replace('/\D/', '', $tel);
    if (str_starts_with(trim($tel), '+')) return $d;
    if (strlen($d) === 10 || strlen($d) === 11) return '55' . $d;
    return $d;
}

function ip_hash(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return hash('sha256', $ip . '|nani-crm');
}

function agora(): string {
    return date('Y-m-d H:i:s');
}
