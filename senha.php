<?php
require __DIR__ . '/app.php';

$token = (string) ($_GET['t'] ?? $_POST['t'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    try {
        exigir_csrf();

        if ($acao === 'definir') {
            $nova = (string) ($_POST['nova'] ?? '');
            if (strlen($nova) < 8) {
                throw new Recusa('A senha precisa ter pelo menos 8 caracteres.');
            }
            if ($nova !== ($_POST['confirma'] ?? '')) {
                throw new Recusa('A confirmação não bate com a senha.');
            }
            [$d, $p] = dados_alterar(function (array &$d) use ($token, $nova) {
                $p = participante_do_token($d, $token);
                if (!$p || !$d['sorteio']) {
                    throw new Recusa('Este link não vale mais. Peça um novo abaixo.');
                }
                $pid = $p['id'];
                $d['participantes'][$pid]['senha'] = password_hash($nova, PASSWORD_DEFAULT);
                $d['participantes'][$pid]['token'] = null;
                $d['participantes'][$pid]['token_expira'] = 0;
                $d['participantes'][$pid]['acessou_em'] = time();
                return [$d, $d['participantes'][$pid]];
            });
            iniciar_sessao_participante($d, $p);
            redirecionar('./');
        }

        if ($acao === 'pedir') {
            $email = trim((string) ($_POST['email'] ?? ''));
            // A resposta é sempre a mesma, exista o e-mail ou não
            $tarefa = !email_valido($email) ? null : dados_alterar(function (array &$d) use ($email) {
                $chaves = ['reset:ip:' . ip(), 'reset:email:' . sha1(strtolower($email))];
                // Passou do limite: não envia nem conta de novo
                if (!limite_ok($d, $chaves[0], 10, 3600) || !limite_ok($d, $chaves[1], 3, 3600)) {
                    return null;
                }
                foreach ($chaves as $c) {
                    limite_marcar($d, $c);
                }
                $p = buscar_por_email($d, $email);
                if (!$p || !$d['sorteio']) {
                    return null;
                }
                $token = novo_token($d['participantes'][$p['id']], VALIDADE_REDEFINICAO);
                return [$p, $token];
            });
            flash('ok', 'Se o e-mail estiver no grupo, enviamos um link para criar a senha. Confira também a caixa de spam');
            header('Location: senha.php');
            // Responde antes de falar com o servidor de e-mail, para o tempo não denunciar nada
            session_write_close();
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }
            if ($tarefa) {
                try {
                    enviar_redefinicao($tarefa[0], $tarefa[1]);
                } catch (Throwable $e) {
                    error_log('Amigo Secreto: falha ao enviar redefinição: ' . $e->getMessage());
                }
            }
            exit;
        }
    } catch (Recusa $e) {
        flash('erro', $e->getMessage());
    } catch (Throwable $e) {
        error_log('Amigo Secreto: ' . $e->getMessage());
        flash('erro', 'Algo deu errado. Tente de novo em instantes');
    }
    redirecionar($token !== '' ? 'senha.php?t=' . rawurlencode($token) : 'senha.php');
}

$d = dados_ler();
$evento = $d['evento'];
$p = $token !== '' ? participante_do_token($d, $token) : null;

topo('Senha · ' . $evento['nome'], ['evento' => $evento['nome'], 'classe' => 'tela-acesso']);
?>
<main class="acesso">
  <?= poster($evento['nome']) ?>
  <section class="acesso-form">
    <?php if ($p && $d['sorteio']): ?>
      <h1 class="titulo"><?= empty($p['senha']) ? 'Crie sua senha' : 'Crie uma senha nova' ?></h1>
      <p class="sub">Para <?= h($p['nome']) ?> entrar no <?= h($evento['nome']) ?> com o e-mail <?= h($p['email']) ?></p>
      <form method="post" class="form">
        <?= csrf_campo() ?>
        <input type="hidden" name="t" value="<?= h($token) ?>">
        <input type="email" value="<?= h($p['email']) ?>" autocomplete="username" hidden readonly>
        <label class="campo"><span>Senha</span><input type="password" name="nova" autocomplete="new-password" minlength="8" required autofocus></label>
        <label class="campo"><span>Repita a senha</span><input type="password" name="confirma" autocomplete="new-password" minlength="8" required></label>
        <p class="nota">Pelo menos 8 caracteres</p>
        <button class="botao primario grande" name="acao" value="definir">Salvar e entrar</button>
      </form>
    <?php else: ?>
      <?php if ($token !== ''): ?>
        <h1 class="titulo">Este link não vale mais</h1>
        <p class="sub">Ele já foi usado ou passou da validade. Peça um novo com o seu e-mail</p>
      <?php else: ?>
        <h1 class="titulo">Primeiro acesso ou senha esquecida</h1>
        <p class="sub">Informe o e-mail em que você recebeu o sorteio. Mandamos um link para você criar a senha</p>
      <?php endif ?>
      <form method="post" action="senha.php" class="form">
        <?= csrf_campo() ?>
        <label class="campo"><span>E-mail</span><input type="email" name="email" autocomplete="email" required autofocus></label>
        <button class="botao primario grande" name="acao" value="pedir"><?= icone('email') ?>Enviar link</button>
      </form>
      <p class="nota"><a href="./">Voltar para entrar</a></p>
    <?php endif ?>
  </section>
</main>
<?php rodape(); ?>
