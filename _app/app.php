<?php
// Núcleo compartilhado: configuração, banco de dados e utilidades.
// Os dados ficam FORA da pasta pública do site (~/naniselestrim-data).

declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');

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
    $pdo = new PDO('sqlite:' . garantir_pasta() . '/crm.sqlite');
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
    return $pdo;
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
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $ip = trim(explode(',', $ip)[0]);
    return hash('sha256', $ip . '|nani-crm');
}

function agora(): string {
    return date('Y-m-d H:i:s');
}
