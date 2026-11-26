<?php
// CRM de atendimentos da Nani: login, lista de leads por etapa, notas e WhatsApp.

declare(strict_types=1);
require __DIR__ . '/../_app/app.php';

iniciar_sessao();
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; script-src 'self' 'unsafe-inline'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];
$cfg = config();
$aviso = '';
$post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

function voltar(string $ancora = ''): void {
    $q = isset($_GET['etapa']) ? '?etapa=' . urlencode((string) $_GET['etapa']) : '';
    header('Location: /crm/' . $q . ($ancora ? '#' . $ancora : ''), true, 303);
    exit;
}

function csrf_ok(string $csrf): bool {
    return hash_equals($csrf, (string) ($_POST['csrf'] ?? ''));
}

// 1) Primeira vez: definir senha
if (!$cfg) {
    if ($post && csrf_ok($csrf)) {
        $s1 = (string) ($_POST['senha'] ?? '');
        $s2 = (string) ($_POST['senha2'] ?? '');
        $email = trim((string) ($_POST['email'] ?? ''));
        if (strlen($s1) < 8) $aviso = 'A senha precisa ter pelo menos 8 caracteres.';
        elseif ($s1 !== $s2) $aviso = 'As senhas não conferem.';
        elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $aviso = 'Confira o e-mail.';
        else {
            salvar_config(['senha' => password_hash($s1, PASSWORD_DEFAULT), 'email_aviso' => $email]);
            session_regenerate_id(true);
            $_SESSION['ok'] = true;
            voltar();
        }
    }
    $tela = 'configurar';
}
// 2) Login
elseif (empty($_SESSION['ok'])) {
    if ($post && csrf_ok($csrf)) {
        if (login_bloqueado()) {
            $aviso = 'Muitas tentativas. Espere 15 minutos e tente de novo.';
        } elseif (password_verify((string) ($_POST['senha'] ?? ''), $cfg['senha'])) {
            limpar_falhas();
            session_regenerate_id(true);
            $_SESSION['ok'] = true;
            voltar();
        } else {
            registrar_falha();
            sleep(1);
            $aviso = 'Senha incorreta.';
        }
    }
    $tela = 'login';
}
// 3) Painel
else {
    $tela = 'painel';
    if ($post) {
        if (!csrf_ok($csrf)) voltar();
        $acao = (string) ($_POST['acao'] ?? '');
        $id = (int) ($_POST['id'] ?? 0);
        if ($acao === 'sair') { $_SESSION = []; session_destroy(); header('Location: /crm/', true, 303); exit; }
        if ($acao === 'salvar' && $id) {
            $etapa = (string) ($_POST['status'] ?? '');
            if (!isset(ETAPAS[$etapa])) $etapa = 'novo';
            $q = db()->prepare('UPDATE leads SET status = ?, notas = ?, atualizado_em = ? WHERE id = ?');
            $q->execute([$etapa, mb_substr((string) ($_POST['notas'] ?? ''), 0, 5000), agora(), $id]);
            voltar('lead-' . $id);
        }
        if ($acao === 'etapa' && $id && isset(ETAPAS[$_POST['status'] ?? ''])) {
            $q = db()->prepare('UPDATE leads SET status = ?, atualizado_em = ? WHERE id = ?');
            $q->execute([$_POST['status'], agora(), $id]);
            voltar('lead-' . $id);
        }
        if ($acao === 'excluir' && $id) {
            db()->prepare('DELETE FROM leads WHERE id = ?')->execute([$id]);
            voltar();
        }
        if ($acao === 'email') {
            $email = trim((string) ($_POST['email'] ?? ''));
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $cfg['email_aviso'] = $email;
                salvar_config($cfg);
            }
            voltar('ajustes');
        }
        if ($acao === 'senha') {
            $s1 = (string) ($_POST['senha'] ?? '');
            if (strlen($s1) >= 8 && $s1 === (string) ($_POST['senha2'] ?? '')) {
                $cfg['senha'] = password_hash($s1, PASSWORD_DEFAULT);
                salvar_config($cfg);
            }
            voltar('ajustes');
        }
        voltar();
    }

    $etapaAtual = (string) ($_GET['etapa'] ?? 'ativos');
    $todos = db()->query('SELECT * FROM leads ORDER BY criado_em DESC')->fetchAll();
    $contagem = array_fill_keys(array_keys(ETAPAS), 0);
    foreach ($todos as $l) $contagem[$l['status']] = ($contagem[$l['status']] ?? 0) + 1;
    $ativos = ['novo', 'conversa', 'agendado'];
    $leads = array_values(array_filter($todos, fn($l) =>
        $etapaAtual === 'todos' ? true : ($etapaAtual === 'ativos' ? in_array($l['status'], $ativos, true) : $l['status'] === $etapaAtual)));
}

function quanto_tempo(string $data): string {
    $s = time() - strtotime($data);
    if ($s < 60) return 'agora';
    if ($s < 3600) return 'há ' . floor($s / 60) . ' min';
    if ($s < 86400) return 'há ' . floor($s / 3600) . ' h';
    $d = (int) floor($s / 86400);
    if ($d === 1) return 'ontem';
    if ($d < 30) return "há {$d} dias";
    return date('d/m/Y', strtotime($data));
}

function link_whatsapp(array $l): string {
    $primeiro = explode(' ', trim($l['nome']))[0];
    $msg = "Olá, {$primeiro}! Aqui é a Nani Selestrim. Recebi seu pedido de atendimento pelo site e queria conversar com você.";
    return 'https://wa.me/' . whatsapp_digitos($l['whatsapp']) . '?text=' . rawurlencode($msg);
}
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>CRM · Nani Selestrim</title>
<meta name="theme-color" content="#F3EBDD">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=EB+Garamond:wght@400;500&family=Jost:wght@400;500&display=swap" rel="stylesheet">
<style>
  @font-face{font-family:"Editorial New";src:url(/fonts/PPEditorialNew-Regular.otf) format("opentype");font-weight:400;font-display:swap}
  :root{--creme:#F3EBDD;--creme-2:#FBF7F0;--branco:#FFFDF9;--marrom:#3B281C;--marrom-2:#2F2016;--suave:#7B6555;--tijolo:#A4442E;--fio:#D9CBB6;--verde:#4E6B45}
  *{box-sizing:border-box;margin:0;padding:0}
  html{-webkit-text-size-adjust:100%}
  body{background:var(--creme);color:var(--marrom);font-family:"EB Garamond",Georgia,serif;-webkit-font-smoothing:antialiased;min-height:100vh}
  .sans{font-family:"Jost",sans-serif}
  .wrap{max-width:760px;margin:0 auto;padding:22px 16px 60px}
  h1{font-family:"Editorial New",serif;font-weight:400;letter-spacing:-.02em;font-size:2rem;line-height:1.05}
  .rotulo{font-family:"Jost",sans-serif;font-size:.66rem;font-weight:500;letter-spacing:.22em;text-transform:uppercase;color:var(--suave)}

  /* telas de acesso */
  .acesso{min-height:100vh;display:grid;place-items:center;padding:24px}
  .acesso form{width:100%;max-width:360px;background:var(--creme-2);border:1px solid var(--fio);padding:30px 24px;position:relative}
  .acesso form::before{content:"";position:absolute;inset:5px;border:1px solid rgba(217,203,182,.6);pointer-events:none}
  .acesso h1{margin:8px 0 18px}
  label.campo{display:block;margin-top:14px}
  label.campo span{display:block;margin-bottom:6px}
  input[type=password],input[type=email],input[type=text],textarea,select{
    width:100%;font:inherit;font-size:1.1rem;color:var(--marrom);background:var(--branco);border:1px solid var(--fio);padding:11px 12px;border-radius:0;outline:none;-webkit-appearance:none;appearance:none
  }
  select{background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%237B6555' stroke-width='1.6'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;background-size:16px;padding-right:34px}
  input:focus,textarea:focus,select:focus{border-color:var(--marrom)}
  .botao{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:0;cursor:pointer;text-decoration:none;
    background:var(--marrom);color:var(--creme);font-family:"Jost",sans-serif;font-size:.72rem;font-weight:500;letter-spacing:.18em;text-transform:uppercase;padding:13px 18px}
  .botao.cheio{width:100%;margin-top:20px;padding:15px}
  .botao.zap{background:#2F5A3A}
  .botao.claro{background:transparent;color:var(--marrom);border:1px solid var(--fio)}
  .botao svg{width:15px;height:15px}
  .aviso{margin-top:12px;color:var(--tijolo);font-family:"Jost",sans-serif;font-size:.85rem}
  .nota{margin-top:10px;font-size:.98rem;color:var(--suave);line-height:1.4}

  /* topo do painel */
  .topo{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;margin-bottom:18px}
  .topo form{margin:0}
  .sair{background:none;border:0;cursor:pointer;font-family:"Jost",sans-serif;font-size:.68rem;letter-spacing:.18em;text-transform:uppercase;color:var(--suave);padding:6px 0}

  /* filtros */
  .filtros{display:flex;gap:8px;overflow-x:auto;padding-bottom:6px;margin:0 -16px 16px;padding-left:16px;padding-right:16px;scrollbar-width:none}
  .filtros::-webkit-scrollbar{display:none}
  .filtros a{flex-shrink:0;display:inline-flex;align-items:center;gap:7px;text-decoration:none;color:var(--marrom);
    font-family:"Jost",sans-serif;font-size:.78rem;padding:8px 13px;border:1px solid var(--fio);background:var(--creme-2)}
  .filtros a b{font-weight:500;color:var(--suave);font-size:.72rem}
  .filtros a.on{background:var(--marrom);color:var(--creme);border-color:var(--marrom)}
  .filtros a.on b{color:#D8C7B3}

  /* leads */
  .lista{display:flex;flex-direction:column;gap:10px}
  .vazio{padding:40px 20px;text-align:center;color:var(--suave);font-size:1.1rem;border:1px dashed var(--fio)}
  details.lead{background:var(--creme-2);border:1px solid var(--fio);scroll-margin-top:16px}
  details.lead[open]{background:var(--branco);border-color:#CDBBA2;box-shadow:0 14px 30px -24px rgba(59,40,28,.5)}
  details.lead > summary{list-style:none;cursor:pointer;display:block;-webkit-tap-highlight-color:transparent}
  .cab{padding:15px 16px;display:grid;grid-template-columns:1fr auto;gap:4px 12px}
  details.lead > summary::-webkit-details-marker{display:none}
  .nome{font-family:"Editorial New",serif;font-size:1.45rem;letter-spacing:-.015em;line-height:1.1}
  .quando{font-family:"Jost",sans-serif;font-size:.72rem;color:var(--suave);text-align:right;white-space:nowrap}
  .resumo{grid-column:1/-1;font-size:1.02rem;color:var(--suave);line-height:1.3}
  .tags{grid-column:1/-1;display:flex;flex-wrap:wrap;gap:6px;margin-top:6px}
  .tag{font-family:"Jost",sans-serif;font-size:.66rem;font-weight:500;letter-spacing:.06em;padding:4px 8px;background:#EFE5D5;color:var(--marrom)}
  .tag.quente{background:#F2DDD3;color:#8A3521}
  .tag.etapa-novo{background:var(--tijolo);color:#fff}
  .corpo{padding:4px 16px 18px;border-top:1px solid #EDE3D3}
  .acoes-rapidas{display:flex;flex-wrap:wrap;gap:8px;margin:14px 0 16px}
  dl{display:grid;grid-template-columns:auto 1fr;gap:6px 14px;font-size:1.02rem}
  dt{font-family:"Jost",sans-serif;font-size:.66rem;font-weight:500;letter-spacing:.16em;text-transform:uppercase;color:var(--suave);padding-top:4px}
  dd{line-height:1.35}
  .relato{margin-top:14px;padding:12px 14px;background:var(--creme-2);border-left:2px solid var(--tijolo);font-size:1.05rem;line-height:1.45;white-space:pre-wrap}
  .editar{margin-top:16px;display:grid;gap:10px}
  .editar textarea{min-height:90px;font-size:1.02rem}
  .linha{display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap}
  .excluir{background:none;border:0;cursor:pointer;font-family:"Jost",sans-serif;font-size:.68rem;letter-spacing:.14em;text-transform:uppercase;color:var(--tijolo);padding:8px 0}

  .ajustes{margin-top:40px;border-top:1px solid var(--fio);padding-top:16px}
  .ajustes summary{cursor:pointer;list-style:none}
  .ajustes summary::-webkit-details-marker{display:none}
  .ajustes form{margin-top:14px;display:grid;gap:10px;max-width:420px}
</style>
</head>
<body>

<?php if ($tela === 'configurar'): ?>
<div class="acesso">
  <form method="post" action="/crm/">
    <p class="rotulo">Primeiro acesso</p>
    <h1>Crie a senha do CRM</h1>
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <label class="campo"><span class="rotulo">Usuário</span><input type="text" name="usuario" id="usuario" value="nani" autocomplete="username" autocapitalize="none" spellcheck="false"></label>
    <label class="campo"><span class="rotulo">Senha (mín. 8 caracteres)</span><input type="password" name="senha" id="senha-nova" required minlength="8" autocomplete="new-password"></label>
    <label class="campo"><span class="rotulo">Repita a senha</span><input type="password" name="senha2" required minlength="8" autocomplete="new-password"></label>
    <label class="campo"><span class="rotulo">E-mail para avisos de lead novo</span><input type="email" name="email" placeholder="opcional"></label>
    <?php if ($aviso): ?><p class="aviso"><?= h($aviso) ?></p><?php endif; ?>
    <button class="botao cheio" type="submit">Criar acesso</button>
    <p class="nota">Esta tela só aparece uma vez. Depois, o CRM pede essa senha.</p>
  </form>
</div>

<?php elseif ($tela === 'login'): ?>
<div class="acesso">
  <form method="post" action="/crm/" id="login">
    <p class="rotulo">Atendimentos</p>
    <h1>Nani Selestrim</h1>
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <label class="campo"><span class="rotulo">Usuário</span><input type="text" name="usuario" id="usuario" value="nani" autocomplete="username" autocapitalize="none" spellcheck="false"></label>
    <label class="campo"><span class="rotulo">Senha</span><input type="password" name="senha" id="senha" required autofocus autocomplete="current-password"></label>
    <?php if ($aviso): ?><p class="aviso"><?= h($aviso) ?></p><?php endif; ?>
    <button class="botao cheio" type="submit">Entrar</button>
  </form>
</div>

<?php else: ?>
<div class="wrap">
  <header class="topo">
    <div>
      <p class="rotulo">Atendimentos</p>
      <h1>Leads</h1>
    </div>
    <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><button class="sair" name="acao" value="sair">Sair</button></form>
  </header>

  <nav class="filtros">
    <?php
      $abas = ['ativos' => ['Ativos', $contagem['novo'] + $contagem['conversa'] + $contagem['agendado']]];
      foreach (ETAPAS as $k => $v) $abas[$k] = [$v, $contagem[$k]];
      $abas['todos'] = ['Todos', count($todos)];
      foreach ($abas as $k => [$nomeAba, $n]):
    ?>
      <a href="?etapa=<?= h($k) ?>" class="<?= $etapaAtual === $k ? 'on' : '' ?>"><?= h($nomeAba) ?> <b><?= $n ?></b></a>
    <?php endforeach; ?>
  </nav>

  <div class="lista">
    <?php if (!$leads): ?>
      <p class="vazio">Nenhum lead nesta etapa.</p>
    <?php endif; ?>
    <?php foreach ($leads as $l):
      $tema = rotulo('tema', $l['tema']) . ($l['tema_outro'] ? ': ' . $l['tema_outro'] : '');
    ?>
    <details class="lead" id="lead-<?= (int) $l['id'] ?>">
      <summary><div class="cab">
        <span class="nome"><?= h($l['nome']) ?></span>
        <span class="quando"><?= h(quanto_tempo($l['criado_em'])) ?></span>
        <span class="resumo"><?= h($tema) ?></span>
        <span class="tags">
          <span class="tag etapa-<?= h($l['status']) ?>"><?= h(ETAPAS[$l['status']] ?? $l['status']) ?></span>
          <?php if ($l['quando'] === 'logo'): ?><span class="tag quente">Quer começar logo</span><?php endif; ?>
          <span class="tag"><?= h(rotulo('contato', $l['contato'])) ?></span>
          <?php if ($l['relato']): ?><span class="tag">Escreveu relato</span><?php endif; ?>
        </span>
      </div></summary>
      <div class="corpo">
        <div class="acoes-rapidas">
          <a class="botao zap" href="<?= h(link_whatsapp($l)) ?>" target="_blank" rel="noopener">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm0 18.2a8.2 8.2 0 0 1-4.2-1.1l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.2-.4.2-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.9c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.8 11.9 11.9 0 0 0 4.6 4c1.7.7 2.4.8 3.2.7.5-.1 1.5-.6 1.8-1.2.2-.6.2-1.1.1-1.2l-.4-.2z"/></svg>
            Chamar no WhatsApp
          </a>
          <?php if ($l['status'] === 'novo'): ?>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= (int) $l['id'] ?>"><input type="hidden" name="status" value="conversa">
            <button class="botao claro" name="acao" value="etapa">Marcar “Em conversa”</button>
          </form>
          <?php endif; ?>
        </div>
        <dl>
          <dt>WhatsApp</dt><dd><?= h($l['whatsapp']) ?></dd>
          <dt>Tema</dt><dd><?= h($tema) ?></dd>
          <dt>Terapia</dt><dd><?= h(rotulo('acompanhamento', $l['acompanhamento'])) ?></dd>
          <dt>Começar</dt><dd><?= h(rotulo('quando', $l['quando'])) ?></dd>
          <dt>Contato</dt><dd><?= h(rotulo('contato', $l['contato'])) ?></dd>
          <dt>Origem</dt><dd><?= h($l['origem'] ?: '—') ?></dd>
          <dt>Recebido</dt><dd><?= h(date('d/m/Y \à\s H:i', strtotime($l['criado_em']))) ?></dd>
        </dl>
        <?php if ($l['relato']): ?><div class="relato"><?= h($l['relato']) ?></div><?php endif; ?>
        <form class="editar" method="post">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
          <label><span class="rotulo">Etapa</span>
            <select name="status">
              <?php foreach (ETAPAS as $k => $v): ?><option value="<?= h($k) ?>" <?= $l['status'] === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?>
            </select>
          </label>
          <label><span class="rotulo">Anotações</span><textarea name="notas" placeholder="Ex.: conversamos dia 12, retorna na segunda"><?= h($l['notas']) ?></textarea></label>
          <div class="linha">
            <button class="botao" name="acao" value="salvar">Salvar</button>
            <button class="excluir" name="acao" value="excluir" onclick="return confirm('Excluir este lead de vez? Isso não pode ser desfeito.')">Excluir lead</button>
          </div>
        </form>
      </div>
    </details>
    <?php endforeach; ?>
  </div>

  <details class="ajustes" id="ajustes">
    <summary class="rotulo">Ajustes</summary>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <label><span class="rotulo">E-mail para avisos de lead novo</span><input type="email" name="email" value="<?= h($cfg['email_aviso'] ?? '') ?>"></label>
      <button class="botao" name="acao" value="email">Salvar e-mail</button>
    </form>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="text" name="usuario" value="nani" autocomplete="username" hidden>
      <label><span class="rotulo">Nova senha</span><input type="password" name="senha" minlength="8" autocomplete="new-password"></label>
      <label><span class="rotulo">Repita a nova senha</span><input type="password" name="senha2" minlength="8" autocomplete="new-password"></label>
      <button class="botao" name="acao" value="senha">Trocar senha</button>
    </form>
  </details>
</div>
<script>
  // abre o lead indicado no endereço (vindo do e-mail ou depois de salvar)
  const alvo = location.hash && document.querySelector(location.hash);
  if (alvo && alvo.tagName === 'DETAILS') { alvo.open = true; alvo.scrollIntoView({ block: 'start' }); }
</script>
<?php endif; ?>
</body>
</html>
