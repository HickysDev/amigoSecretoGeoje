<?php
require __DIR__ . '/app.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        exigir_csrf();
        $acao = $_POST['acao'] ?? '';

        if ($acao === 'entrar') {
            $senha = (string) ($_POST['senha'] ?? '');
            if (senha_admin_fraca()) {
                throw new Recusa('Defina uma senha_admin com pelo menos 10 caracteres no config.php.');
            }
            $certa = dados_alterar(function (array &$d) use ($senha, $config) {
                $chave = 'admin:ip:' . ip();
                if (!limite_ok($d, $chave, 5, 900)) {
                    throw new Recusa('Muitas tentativas. Espere 15 minutos e tente de novo.');
                }
                $ok = hash_equals($config['senha_admin'], $senha);
                if (!$ok) {
                    limite_marcar($d, $chave);
                }
                return $ok;
            });
            if (!$certa) {
                sleep(1);
                throw new Recusa('Senha incorreta.');
            }
            session_regenerate_id(true);
            $_SESSION['admin'] = hash('sha256', $config['senha_admin']);
            redirecionar();
        }
        if ($acao === 'sair') {
            unset($_SESSION['admin']);
            session_regenerate_id(true);
            redirecionar();
        }
        if (!admin_logado()) {
            throw new Recusa('Sua sessão terminou. Entre de novo.');
        }

        if ($acao === 'evento') {
            $data = trim((string) ($_POST['data'] ?? ''));
            if ($data !== '' && !preg_match('~^\d{4}-\d{2}-\d{2}$~', $data)) {
                throw new Recusa('Data inválida.');
            }
            $evento = [
                'nome'  => trim((string) ($_POST['nome'] ?? '')) ?: 'Amigo Secreto',
                'data'  => $data,
                'valor' => trim((string) ($_POST['valor'] ?? '')),
                'obs'   => trim((string) ($_POST['obs'] ?? '')),
            ];
            dados_alterar(function (array &$d) use ($evento) {
                $d['evento'] = $evento;
            });
            flash('ok', 'Evento salvo');
        } elseif ($acao === 'salvar_participante') {
            $id = (string) ($_POST['id'] ?? '');
            $nome = trim(str_replace(["\r", "\n"], ' ', (string) ($_POST['nome'] ?? '')));
            $email = trim((string) ($_POST['email'] ?? ''));
            $perfil = trim((string) ($_POST['steam'] ?? ''));
            if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Recusa('Preencha o nome e um e-mail válido.');
            }
            $steamid = null;
            if ($perfil !== '' && !($steamid = steam_id($perfil))) {
                throw new Recusa("Perfil Steam de $nome não encontrado. Cole o link do perfil ou deixe vazio.");
            }
            dados_alterar(function (array &$d) use ($id, $nome, $email, $perfil, $steamid) {
                if ($d['sorteio']) {
                    throw new Recusa('Depois do sorteio a lista de participantes não muda. Desfaça o sorteio para editar.');
                }
                $outro = buscar_por_email($d, $email);
                if ($outro && $outro['id'] !== $id) {
                    throw new Recusa("O e-mail $email já está na lista.");
                }
                if ($id === '' || !isset($d['participantes'][$id])) {
                    $id = 'p' . bin2hex(random_bytes(4));
                    $d['participantes'][$id] = ['id' => $id, 'senha' => null, 'token' => null, 'token_expira' => 0,
                                                'convite_enviado_em' => 0, 'acessou_em' => 0];
                }
                $d['participantes'][$id] = array_merge($d['participantes'][$id], [
                    'nome' => $nome, 'email' => $email, 'steam' => $steamid ? $perfil : '', 'steamid' => $steamid,
                ]);
            });
            flash('ok', "$nome salvo");
        } elseif ($acao === 'remover') {
            $id = (string) ($_POST['id'] ?? '');
            dados_alterar(function (array &$d) use ($id) {
                if ($d['sorteio']) {
                    throw new Recusa('Depois do sorteio a lista de participantes não muda. Desfaça o sorteio para editar.');
                }
                unset($d['participantes'][$id]);
            });
            flash('ok', 'Participante removido');
        } elseif ($acao === 'sortear') {
            url_site('');   // confere o config antes de qualquer coisa
            set_time_limit(300);
            ignore_user_abort(true);
            $antes = dados_ler();
            if (count($antes['participantes']) < 3) {
                throw new Recusa('Cadastre pelo menos 3 participantes.');
            }
            // Pré-carrega as listas (fora do lock) e anota quem está com a lista fechada
            $listas = listas_steam(array_column($antes['participantes'], 'steamid'), true);
            $fechadas = [];
            foreach ($antes['participantes'] as $p) {
                if (!empty($p['steamid']) && ($listas[$p['steamid']] ?? null) === []) {
                    $fechadas[] = $p['nome'];
                }
            }

            $itens = dados_alterar(function (array &$d) {
                if ($d['sorteio']) {
                    throw new Recusa('O sorteio já foi feito.');
                }
                $ids = array_keys($d['participantes']);
                if (count($ids) < 3) {
                    throw new Recusa('Cadastre pelo menos 3 participantes.');
                }
                // Embaralha e liga cada pessoa à próxima num ciclo único: ninguém tira a si mesmo
                for ($i = count($ids) - 1; $i > 0; $i--) {
                    $j = random_int(0, $i);
                    [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
                }
                $pares = [];
                foreach ($ids as $i => $id) {
                    $pares[$id] = $ids[($i + 1) % count($ids)];
                }
                // Gravado por id: a ordem do arquivo não pode contar o ciclo
                ksort($pares);
                $d['sorteio'] = ['feito_em' => time(), 'pares' => $pares];
                $itens = [];
                foreach ($pares as $de => $para) {
                    $p = &$d['participantes'][$de];
                    $p['senha'] = null;
                    $p['convite_enviado_em'] = 0;
                    $p['acessou_em'] = 0;
                    $p['revelado'] = false;
                    $p['carrinho'] = [];
                    $p['surpresas'] = [];
                    $token = novo_token($p, VALIDADE_CONVITE);
                    $itens[] = [$p, $d['participantes'][$para], $token];
                    unset($p);
                }
                // Os e-mails saem em ordem aleatória: a hora de chegada não diz quem tirou quem
                for ($i = count($itens) - 1; $i > 0; $i--) {
                    $j = random_int(0, $i);
                    [$itens[$i], $itens[$j]] = [$itens[$j], $itens[$i]];
                }
                return $itens;
            });

            [$enviados, $erro] = enviar_convites($itens);
            $aviso = $fechadas ? ' Lista da Steam vazia ou privada: ' . implode(', ', $fechadas) . '.' : '';
            if ($erro) {
                flash('erro', "Sorteio feito, mas só $enviados de " . count($itens) . " convites saíram ($erro). Use Reenviar convite em quem aparece como não recebido.$aviso");
            } else {
                flash('ok', "Sorteio feito. $enviados convites enviados.$aviso");
            }
        } elseif ($acao === 'reenviar') {
            url_site('');
            $id = (string) ($_POST['id'] ?? '');
            // O par só é lido para montar o e-mail; nunca aparece na página
            $itens = dados_alterar(function (array &$d) use ($id) {
                $para = $d['sorteio']['pares'][$id] ?? null;
                if (!$para || !isset($d['participantes'][$id])) {
                    throw new Recusa('Participante não encontrado no sorteio.');
                }
                $token = novo_token($d['participantes'][$id], VALIDADE_CONVITE);
                return [[$d['participantes'][$id], $d['participantes'][$para], $token]];
            });
            [$enviados, $erro] = enviar_convites($itens);
            $nome = $itens[0][0]['nome'];
            if ($erro) {
                flash('erro', "O convite de $nome não saiu ($erro)");
            } else {
                flash('ok', "Convite reenviado para $nome");
            }
        } elseif ($acao === 'desfazer') {
            if (trim((string) ($_POST['confirmacao'] ?? '')) !== 'DESFAZER') {
                throw new Recusa('Digite DESFAZER para confirmar.');
            }
            dados_alterar(function (array &$d) {
                if (!$d['sorteio']) {
                    throw new Recusa('Não há sorteio para desfazer.');
                }
                $d['sorteio'] = null;
                foreach ($d['participantes'] as &$p) {
                    $p['senha'] = null;
                    $p['token'] = null;
                    $p['token_expira'] = 0;
                    $p['convite_enviado_em'] = 0;
                    $p['acessou_em'] = 0;
                    $p['revelado'] = false;
                    $p['carrinho'] = [];
                    $p['surpresas'] = [];
                }
                unset($p);
            });
            flash('ok', 'Sorteio desfeito. Os links e senhas antigos não valem mais');
        } elseif ($acao === 'atualizar_listas') {
            set_time_limit(300);
            listas_steam(array_column(dados_ler()['participantes'], 'steamid'), true);
            flash('ok', 'Listas da Steam atualizadas');
        }
    } catch (Recusa $e) {
        flash('erro', $e->getMessage());
    } catch (Throwable $e) {
        error_log('Amigo Secreto: ' . $e->getMessage());
        flash('erro', 'Algo deu errado. Tente de novo em instantes');
    }
    redirecionar();
}

$d = dados_ler();
$evento = $d['evento'];

if (!admin_logado()):
    topo('Organizador · ' . $evento['nome'], ['evento' => $evento['nome'], 'inicio' => 'admin.php', 'classe' => 'tela-acesso']);
    ?>
<main class="acesso">
  <?= poster('Painel do organizador · ' . $evento['nome']) ?>
  <section class="acesso-form">
    <h1 class="titulo">Painel do organizador</h1>
    <p class="sub">Cadastre o grupo, faça o sorteio e acompanhe quem já entrou</p>
    <?php if (senha_admin_fraca()): ?>
    <div class="aviso aviso-erro estatico"><?= icone('alerta') ?><span>O painel está fechado: defina uma senha_admin com pelo menos 10 caracteres no config.php</span></div>
    <?php else: ?>
    <form method="post" class="form">
      <?= csrf_campo() ?>
      <input type="text" name="usuario" value="organizador" autocomplete="username" hidden readonly>
      <label class="campo"><span>Senha do organizador</span><input type="password" name="senha" autocomplete="current-password" required autofocus></label>
      <button class="botao primario grande" name="acao" value="entrar">Entrar</button>
    </form>
    <?php endif ?>
  </section>
</main>
<?php
    rodape();
    exit;
endif;

$pessoas = array_values($d['participantes']);
usort($pessoas, function ($a, $b) { return strcasecmp($a['nome'], $b['nome']); });
$sorteado = (bool) $d['sorteio'];
$entraram = count(array_filter($pessoas, function ($p) { return !empty($p['senha']); }));
$sem_url = !url_site_valida(trim((string) ($config['url_site'] ?? '')));
$avatares = avatares_steam();

function situacao_steam(array $d, array $p): string
{
    if (empty($p['steamid'])) {
        return '<span class="meta">Sem Steam</span>';
    }
    $c = $d['steam_cache'][$p['steamid']] ?? null;
    $texto = !$c ? 'Perfil conectado' : ($c['jogos'] ? plural(count($c['jogos']), 'jogo', 'jogos') : 'Lista vazia ou privada');
    return '<a class="link-steam" href="' . h(steam_link_perfil($p['steamid'])) . '" target="_blank" rel="noopener">'
        . icone('controle') . h($texto) . '</a>';
}

function situacao_acesso(array $p): string
{
    if (!empty($p['senha'])) {
        return '<span class="estado estado-ok">Entrou' . ($p['acessou_em'] ? ' em ' . h(date('d/m', $p['acessou_em'])) : '') . '</span>';
    }
    if (!empty($p['convite_enviado_em'])) {
        return '<span class="estado">Convite enviado</span>';
    }
    return '<span class="estado estado-erro">Não recebeu</span>';
}

topo('Organizador · ' . $evento['nome'], ['evento' => $evento['nome'], 'usuario' => 'Organizador', 'inicio' => 'admin.php', 'classe' => 'logado']);
?>
<main class="painel">
  <div class="painel-topo">
    <div>
      <h1 class="titulo">Painel do organizador</h1>
      <p class="sub">
        <?php if ($sorteado): ?>
          Sorteio feito em <?= h(data_hora($d['sorteio']['feito_em'])) ?> · <?= $entraram ?> de <?= count($pessoas) ?> já entraram no site
        <?php else: ?>
          Antes do sorteio · <?= plural(count($pessoas), 'participante', 'participantes') ?>
        <?php endif ?>
      </p>
    </div>
  </div>

  <?php if ($sem_url): ?>
  <div class="aviso aviso-erro estatico"><?= icone('alerta') ?><span>Preencha o url_site no config.php com o endereço do site, começando por https://. Sem ele os e-mails não podem levar o link</span></div>
  <?php endif ?>

  <div class="painel-grade">
    <section class="cartao">
      <div class="cartao-topo">
        <h2>Participantes<?= ajuda('Quem entra no sorteio. O perfil Steam é opcional: cada pessoa também pode informar o dela depois de entrar') ?></h2>
        <form method="post"><?= csrf_campo() ?><button class="botao fantasma pequeno" name="acao" value="atualizar_listas"><?= icone('atualizar') ?>Atualizar listas da Steam</button></form>
      </div>
      <?php if ($pessoas): ?>
      <div class="tabela-rolagem">
      <table class="tabela">
        <thead><tr><th>Nome</th><th>E-mail</th><th>Steam</th><?php if ($sorteado): ?><th>Acesso</th><?php endif ?><th class="acoes"><span class="sr">Ações</span></th></tr></thead>
        <tbody>
        <?php foreach ($pessoas as $p): ?>
          <tr>
            <td><span class="pessoa-cel"><?= avatar_html($p, $avatares) ?><?= h($p['nome']) ?></span></td>
            <td class="meta"><?= h($p['email']) ?></td>
            <td><?= situacao_steam($d, $p) ?></td>
            <?php if ($sorteado): ?><td><?= situacao_acesso($p) ?></td><?php endif ?>
            <td class="acoes">
              <?php if ($sorteado): ?>
                <form method="post"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= h($p['id']) ?>">
                  <button class="botao fantasma pequeno" name="acao" value="reenviar"><?= icone('enviar') ?>Reenviar convite</button></form>
              <?php else: ?>
                <button class="botao icone-so" type="button" title="Editar" aria-label="Editar <?= h($p['nome']) ?>"
                  data-editar='<?= h(json_encode(['id' => $p['id'], 'nome' => $p['nome'], 'email' => $p['email'], 'steam' => $p['steam'] ?? ''])) ?>'><?= icone('editar') ?></button>
                <form method="post" onsubmit="return confirm('Remover <?= h(addslashes($p['nome'])) ?>?')"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= h($p['id']) ?>">
                  <button class="botao icone-so" name="acao" value="remover" title="Remover" aria-label="Remover <?= h($p['nome']) ?>"><?= icone('lixo') ?></button></form>
              <?php endif ?>
            </td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
      </div>
      <?php else: ?>
        <p class="vazio">Ninguém cadastrado ainda. Comece pelo formulário abaixo</p>
      <?php endif ?>

      <?php if (!$sorteado): ?>
      <form method="post" class="form novo-participante" id="form-participante">
        <?= csrf_campo() ?>
        <input type="hidden" name="id" value="">
        <h3 id="form-titulo">Adicionar participante</h3>
        <div class="linha-campos">
          <label class="campo"><span>Nome</span><input name="nome" required></label>
          <label class="campo"><span>E-mail</span><input type="email" name="email" required></label>
          <label class="campo"><span>Perfil Steam (opcional)</span><input name="steam" placeholder="steamcommunity.com/id/fulano"></label>
        </div>
        <div class="linha-botoes">
          <button class="botao secundario" name="acao" value="salvar_participante"><?= icone('mais') ?><span id="form-botao">Adicionar</span></button>
          <button class="botao fantasma" type="button" id="cancelar-edicao" hidden>Cancelar</button>
        </div>
      </form>
      <?php endif ?>
    </section>

    <div class="painel-lado">
      <section class="cartao">
        <h2>Sorteio<?= ajuda('O site sorteia e manda um e-mail para cada pessoa com o link de acesso. Nem você vê quem tirou quem') ?></h2>
        <?php if (!$sorteado): ?>
          <p class="sub">Cada pessoa recebe um e-mail com quem tirou e o link para criar a senha. Depois disso a lista de participantes fica travada</p>
          <form method="post" onsubmit="return confirm('Sortear e enviar os convites agora?')">
            <?= csrf_campo() ?>
            <button class="botao primario grande largo" name="acao" value="sortear" <?= count($pessoas) < 3 || $sem_url ? 'disabled' : '' ?>><?= icone('enviar') ?>Sortear e enviar convites</button>
          </form>
          <?php if (count($pessoas) < 3): ?><p class="nota">São precisos pelo menos 3 participantes</p><?php endif ?>
        <?php else: ?>
          <p class="sub">Ninguém, nem o organizador, vê quem tirou quem. Se alguém não recebeu, use Reenviar convite</p>
          <details class="perigo">
            <summary><?= icone('desfazer') ?>Desfazer sorteio</summary>
            <form method="post" class="form">
              <?= csrf_campo() ?>
              <p class="nota">Apaga o sorteio, as senhas e os links. Para sortear de novo, todos recebem convites novos. Digite DESFAZER para confirmar</p>
              <label class="campo"><span>Confirmação</span><input name="confirmacao" autocomplete="off" required></label>
              <button class="botao perigoso" name="acao" value="desfazer">Desfazer sorteio</button>
            </form>
          </details>
        <?php endif ?>
      </section>

      <section class="cartao">
        <h2>Evento<?= ajuda('Nome, data, valor mínimo e observação aparecem para todos os participantes') ?></h2>
        <form method="post" class="form">
          <?= csrf_campo() ?>
          <label class="campo"><span>Nome</span><input name="nome" value="<?= h($evento['nome']) ?>" required></label>
          <div class="linha-campos dois">
            <label class="campo"><span>Data</span><input type="date" name="data" value="<?= h($evento['data']) ?>"></label>
            <label class="campo"><span>Valor mínimo</span><input name="valor" value="<?= h($evento['valor']) ?>" placeholder="R$ 100"></label>
          </div>
          <label class="campo"><span>Observação</span><textarea name="obs" rows="3" placeholder="Local, horário, regras"><?= h($evento['obs']) ?></textarea></label>
          <button class="botao secundario" name="acao" value="evento">Salvar evento</button>
        </form>
      </section>
    </div>
  </div>
</main>
<script>
(function () {
  var f = document.getElementById('form-participante');
  if (!f) return;
  var cancelar = document.getElementById('cancelar-edicao');
  function modo(editando) {
    document.getElementById('form-titulo').textContent = editando ? 'Editar participante' : 'Adicionar participante';
    document.getElementById('form-botao').textContent = editando ? 'Salvar' : 'Adicionar';
    cancelar.hidden = !editando;
  }
  document.querySelectorAll('[data-editar]').forEach(function (b) {
    b.addEventListener('click', function () {
      var p = JSON.parse(b.dataset.editar);
      ['id', 'nome', 'email', 'steam'].forEach(function (c) { f.elements[c].value = p[c] || ''; });
      modo(true);
      f.scrollIntoView({behavior: 'smooth', block: 'center'});
      f.elements.nome.focus();
    });
  });
  cancelar.addEventListener('click', function () { f.reset(); f.elements.id.value = ''; modo(false); });
})();
</script>
<?php rodape(); ?>
