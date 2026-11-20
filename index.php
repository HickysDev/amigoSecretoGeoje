<?php
require __DIR__ . '/app.php';

// Jogos fora da lista de desejos que cada pessoa pode separar
const MAX_SURPRESAS = 30;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        exigir_csrf();
        $acao = $_POST['acao'] ?? '';

        if ($acao === 'entrar') {
            $email = trim((string) ($_POST['email'] ?? ''));
            $senha = (string) ($_POST['senha'] ?? '');
            if (!email_valido($email)) {
                throw new Recusa('E-mail ou senha incorretos.');
            }
            $entrou = dados_alterar(function (array &$d) use ($email, $senha) {
                $chaves = ['login:ip:' . ip(), 'login:email:' . sha1(strtolower($email))];
                foreach ($chaves as $c) {
                    if (!limite_ok($d, $c, 5, 900)) {
                        throw new Recusa('Muitas tentativas. Espere 15 minutos e tente de novo.');
                    }
                }
                if (!$d['sorteio']) {
                    throw new Recusa('O sorteio ainda não foi feito. Você recebe um e-mail quando ele acontecer.');
                }
                $p = buscar_por_email($d, $email);
                $certa = password_verify($senha, !empty($p['senha']) ? $p['senha'] : HASH_FALSO);
                if (!$p || empty($p['senha']) || !$certa) {
                    foreach ($chaves as $c) {
                        limite_marcar($d, $c);
                    }
                    return null;
                }
                $d['participantes'][$p['id']]['acessou_em'] = time();
                return [$d, $d['participantes'][$p['id']]];
            });
            if (!$entrou) {
                sleep(1);
                throw new Recusa('E-mail ou senha incorretos.');
            }
            iniciar_sessao_participante($entrou[0], $entrou[1]);
        } elseif ($acao === 'sair') {
            unset($_SESSION['pid'], $_SESSION['ver']);
            session_regenerate_id(true);
        } else {
            $eu = participante_atual(dados_ler());
            if (!$eu) {
                throw new Recusa('Sua sessão terminou. Entre de novo.');
            }
            $pid = $eu['id'];

            if ($acao === 'revelar') {
                // Guarda se a pessoa deixou o nome à mostra ou escondido (chamado pelo app.js)
                $revelado = ($_POST['revelado'] ?? '') === '1';
                dados_alterar(function (array &$d) use ($pid, $revelado) {
                    $d['participantes'][$pid]['revelado'] = $revelado;
                });
                http_response_code(204);
                exit;
            } elseif ($acao === 'ocultar') {
                // Esconde ou volta a mostrar um jogo da própria lista (chamado pelo app.js)
                $appid = (int) ($_POST['appid'] ?? 0);
                $oculto = ($_POST['oculto'] ?? '') === '1';
                if ($appid <= 0) {
                    throw new Recusa('Jogo inválido.');
                }
                dados_alterar(function (array &$d) use ($pid, $appid, $oculto) {
                    $lista = array_diff($d['participantes'][$pid]['ocultos'] ?? [], [$appid]);
                    if ($oculto) {
                        $lista[] = $appid;
                    }
                    $d['participantes'][$pid]['ocultos'] = array_slice(array_values(array_unique($lista)), -5000);
                });
                http_response_code(204);
                exit;
            } elseif ($acao === 'carrinho') {
                // Jogos que a pessoa talvez compre para quem tirou (só ela vê; chamado pelo app.js)
                $appid = (int) ($_POST['appid'] ?? 0);
                $escolhido = ($_POST['escolhido'] ?? '') === '1';
                if ($appid <= 0) {
                    throw new Recusa('Jogo inválido.');
                }
                dados_alterar(function (array &$d) use ($pid, $appid, $escolhido) {
                    $lista = array_diff($d['participantes'][$pid]['carrinho'] ?? [], [$appid]);
                    if ($escolhido) {
                        $lista[] = $appid;
                    }
                    $d['participantes'][$pid]['carrinho'] = array_slice(array_values(array_unique($lista)), -1000);
                });
                http_response_code(204);
                exit;
            } elseif ($acao === 'tour_visto') {
                // O tour de boas-vindas só abre sozinho na primeira vez (chamado pelo app.js)
                dados_alterar(function (array &$d) use ($pid) {
                    $d['participantes'][$pid]['tour_visto'] = time();
                });
                http_response_code(204);
                exit;
            } elseif ($acao === 'buscar_jogo') {
                // Busca na loja da Steam os jogos fora da lista de desejos (chamado pelo app.js)
                $termo = trim((string) ($_POST['termo'] ?? ''));
                if (mb_strlen($termo) < 2 || mb_strlen($termo) > 60) {
                    json_sair(['erro' => 'Digite ao menos 2 letras']);
                }
                $pode = dados_alterar(function (array &$d) use ($pid) {
                    $chave = 'busca:' . $pid;
                    $d['limites'][$chave] = array_values(array_filter($d['limites'][$chave] ?? [], function ($t) { return $t > time() - 300; }));
                    if (!limite_ok($d, $chave, 40, 300)) {
                        return false;
                    }
                    limite_marcar($d, $chave);
                    return true;
                });
                if (!$pode) {
                    json_sair(['erro' => 'Muitas buscas seguidas. Espere alguns minutos']);
                }
                session_write_close();
                $achados = steam_buscar($termo);
                if ($achados === null) {
                    json_sair(['erro' => 'A Steam não respondeu. Tente de novo']);
                }
                $html = '';
                foreach ($achados as $j) {
                    $html .= jogo_resultado($j, isset($eu['surpresas'][$j['appid']]));
                }
                json_sair(['html' => $html]);
            } elseif ($acao === 'surpresa') {
                // Jogo fora da lista de desejos que a pessoa talvez compre (só ela vê; chamado pelo app.js)
                $appid = (int) ($_POST['appid'] ?? 0);
                if ($appid <= 0) {
                    json_sair(['erro' => 'Jogo inválido']);
                }
                if (($_POST['escolhido'] ?? '') !== '1') {
                    dados_alterar(function (array &$d) use ($pid, $appid) {
                        unset($d['participantes'][$pid]['surpresas'][$appid]);
                    });
                    http_response_code(204);
                    exit;
                }
                session_write_close();
                // Nome e preço vêm sempre da Steam, nunca do navegador
                $achado = steam_itens([$appid]);
                if (!$achado) {
                    json_sair(['erro' => $achado === null ? 'A Steam não respondeu. Tente de novo' : 'Esse jogo está sem preço na Steam']);
                }
                $j = $achado[0];
                $coube = dados_alterar(function (array &$d) use ($pid, $j) {
                    $s = $d['participantes'][$pid]['surpresas'] ?? [];
                    if (!isset($s[$j['appid']]) && count($s) >= MAX_SURPRESAS) {
                        return false;
                    }
                    $s[$j['appid']] = $j + ['em' => time()];
                    $d['participantes'][$pid]['surpresas'] = $s;
                    return true;
                });
                if (!$coube) {
                    json_sair(['erro' => 'Limite de ' . MAX_SURPRESAS . ' jogos fora da lista']);
                }
                json_sair(['html' => jogo_capa($j, true, false, true)]);
            } elseif ($acao === 'ocultar_varios') {
                // "Esconder todos" / "Mostrar todos" da aba Minha lista (chamado pelo app.js)
                $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) ($_POST['appids'] ?? ''))))));
                $oculto = ($_POST['oculto'] ?? '') === '1';
                dados_alterar(function (array &$d) use ($pid, $ids, $oculto) {
                    $lista = array_diff($d['participantes'][$pid]['ocultos'] ?? [], $ids);
                    if ($oculto) {
                        $lista = array_merge($lista, $ids);
                    }
                    $d['participantes'][$pid]['ocultos'] = array_slice(array_values(array_unique($lista)), -5000);
                });
                http_response_code(204);
                exit;
            } elseif ($acao === 'atualizar_lista') {
                if (empty($eu['steamid'])) {
                    throw new Recusa('Conecte seu perfil Steam primeiro.');
                }
                dados_alterar(function (array &$d) use ($pid) {
                    if (!limite_ok($d, 'atualizar:' . $pid, 5, 900)) {
                        throw new Recusa('Você já atualizou várias vezes agora. Espere uns minutos e tente de novo.');
                    }
                    limite_marcar($d, 'atualizar:' . $pid);
                });
                set_time_limit(120);
                $jogos = listas_steam([$eu['steamid']], true)[$eu['steamid']] ?? null;
                if ($jogos === null) {
                    throw new Recusa('A Steam não respondeu agora. Tente de novo em alguns minutos.');
                }
                flash('ok', $jogos ? 'Lista atualizada: ' . plural(count($jogos), 'jogo', 'jogos') . ' na sua Steam' : 'Sua lista na Steam está vazia ou privada');
            } elseif ($acao === 'steam') {
                $perfil = trim((string) ($_POST['steam'] ?? ''));
                $steamid = null;
                if ($perfil !== '' && !($steamid = steam_id($perfil))) {
                    throw new Recusa('Perfil Steam não encontrado. Cole o link do seu perfil, como steamcommunity.com/id/seunome');
                }
                dados_alterar(function (array &$d) use ($pid, $perfil, $steamid) {
                    $antigo = $d['participantes'][$pid]['steamid'] ?? null;
                    unset($d['steam_cache'][$antigo], $d['steam_cache'][$steamid]);
                    $d['participantes'][$pid]['steam'] = $steamid ? $perfil : '';
                    $d['participantes'][$pid]['steamid'] = $steamid;
                });
                flash('ok', $steamid ? 'Perfil Steam salvo. Sua lista de desejos já aparece para o grupo' : 'Perfil Steam removido');
            } elseif ($acao === 'nintendo') {
                $texto = trim((string) ($_POST['nintendo'] ?? ''));
                if (strlen($texto) > 200000) {
                    throw new Recusa('O texto é grande demais. Copie só a lista de desejos.');
                }
                $jogos = $texto === '' ? [] : nintendo_ler($texto);
                if ($texto !== '' && !$jogos) {
                    throw new Recusa('Não achei jogos nesse texto. Copie a página da lista de desejos inteira (Ctrl+A, Ctrl+C) e cole de novo.');
                }
                if ($jogos) {                       // capas pelo IGDB (opcional; sem chave ou com falha, fica sem capa)
                    $capas = capas_buscar(array_column($jogos, 'nome'));
                    foreach ($jogos as &$jg) {
                        $jg['capa'] = $capas[$jg['nome']] ?? '';
                    }
                    unset($jg);
                }
                dados_alterar(function (array &$d) use ($pid, $jogos) {
                    if (!limite_ok($d, 'nintendo:' . $pid, 10, 900)) {
                        throw new Recusa('Você já salvou várias vezes agora. Espere uns minutos e tente de novo.');
                    }
                    limite_marcar($d, 'nintendo:' . $pid);
                    $d['participantes'][$pid]['nintendo'] = $jogos;
                    $d['participantes'][$pid]['nintendo_em'] = time();
                });
                flash('ok', $jogos ? plural(count($jogos), 'jogo', 'jogos') . ' da eShop salvos. Sua lista já aparece para o grupo' : 'Lista da eShop removida');
            } elseif ($acao === 'senha') {
                $atual = (string) ($_POST['atual'] ?? '');
                $nova = (string) ($_POST['nova'] ?? '');
                if (strlen($nova) < 8) {
                    throw new Recusa('A senha nova precisa ter pelo menos 8 caracteres.');
                }
                if ($nova !== ($_POST['confirma'] ?? '')) {
                    throw new Recusa('A confirmação não bate com a senha nova.');
                }
                [$d, $p] = dados_alterar(function (array &$d) use ($pid, $atual, $nova) {
                    if (!password_verify($atual, $d['participantes'][$pid]['senha'] ?? HASH_FALSO)) {
                        throw new Recusa('A senha atual está incorreta.');
                    }
                    $d['participantes'][$pid]['senha'] = password_hash($nova, PASSWORD_DEFAULT);
                    return [$d, $d['participantes'][$pid]];
                });
                // As outras sessões caem; esta continua
                $_SESSION['ver'] = versao_sessao($d, $p);
                flash('ok', 'Senha alterada');
            }
        }
    } catch (Recusa $e) {
        flash('erro', $e->getMessage());
    } catch (Throwable $e) {
        error_log('Amigo Secreto: ' . $e->getMessage());
        flash('erro', 'Algo deu errado. Tente de novo em instantes');
    }
    // Volta para a mesma aba de onde o formulário veio
    $aba = $_GET['aba'] ?? '';
    redirecionar(in_array($aba, ['lista', 'conta'], true) ? './?aba=' . $aba : '');
}

$d = dados_ler();
$evento = $d['evento'];
$eu = participante_atual($d);

// Capa do jogo na lista de quem a pessoa tirou, com o botão "Talvez eu compre"
// Lista grande de quem a pessoa tirou: mostra só os primeiros e um botão para ver todos
const RECOLHER_APOS = 9;

// Resposta das ações chamadas pelo app.js que devolvem conteúdo
function json_sair(array $r): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($r);
    exit;
}

// Jogos fora da lista que a pessoa separou. Preço com mais de CACHE_STEAM é consultado de novo.
function surpresas_atuais(array $eu): array
{
    $s = $eu['surpresas'] ?? [];
    $velhos = array_filter($s, function ($j) { return ($j['em'] ?? 0) < time() - CACHE_STEAM; });
    if ($velhos) {
        $novos = steam_itens(array_map('intval', array_keys($s)));
        // Steam fora do ar: fica com o preço antigo e tenta de novo em 10 minutos
        $em = $novos === null ? time() - CACHE_STEAM + 600 : time();
        foreach ($s as $appid => $j) {
            $s[$appid]['em'] = $em;
        }
        foreach ($novos ?? [] as $j) {
            $s[$j['appid']] = $j + ['em' => $em];
        }
        $pid = $eu['id'];
        dados_alterar(function (array &$d) use ($pid, $s) {
            // Só atualiza o que continua separado (a pessoa pode ter tirado em outra aba)
            foreach ($d['participantes'][$pid]['surpresas'] ?? [] as $appid => $j) {
                if (isset($s[$appid])) {
                    $d['participantes'][$pid]['surpresas'][$appid] = $s[$appid];
                }
            }
        });
    }
    return array_values($s);
}

// Linha do resultado da busca na loja da Steam
function jogo_resultado(array $j, bool $adicionado): string
{
    return '<div class="resultado">'
        . '<span class="jogo-mini">' . ($j['capa'] ? '<img src="' . h($j['capa']) . '" alt="" loading="lazy">' : '') . '</span>'
        . '<a class="jogo-nome" href="' . h($j['url']) . '" target="_blank" rel="noopener">' . h($j['nome']) . '</a>'
        . preco_html($j)
        . '<button type="button" class="botao secundario pequeno" data-surpresa-add="' . (int) $j['appid'] . '"' . ($adicionado ? ' disabled' : '') . '>'
        . icone('mais') . '<span>' . ($adicionado ? 'Adicionado' : 'Adicionar') . '</span></button></div>';
}

// $recolhido: começa escondido (lista grande mostra só os primeiros; o app.js abre o resto)
// $surpresa: jogo fora da lista de desejos; o botão tira o jogo em vez de marcar e desmarcar
// Selo vermelho com o ícone do Switch nos jogos que vieram da eShop da Nintendo.
function selo_switch(array $j): string
{
    if (($j['loja'] ?? '') !== 'nintendo') {
        return '';
    }
    return '<span class="selo-switch" title="Nintendo Switch"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8.5 3h-2A3.5 3.5 0 0 0 3 6.5v11A3.5 3.5 0 0 0 6.5 21h2zM5.5 7.6a1.2 1.2 0 1 1 0 2.4 1.2 1.2 0 0 1 0-2.4zM10 3v18h7.5a3.5 3.5 0 0 0 3.5-3.5v-11A3.5 3.5 0 0 0 17.5 3zm6 4.8a2.2 2.2 0 1 1 0 4.4 2.2 2.2 0 0 1 0-4.4z"/></svg>Switch</span>';
}

function jogo_capa(array $j, bool $escolhido = false, bool $recolhido = false, bool $surpresa = false): string
{
    $ate = !empty($j['promo_ate']) ? '<span class="promo-ate">Promoção até ' . h(date('d/m', $j['promo_ate'])) . '</span>' : '';
    $tem_preco = ($j['centavos'] ?? -1) >= 0;
    $titulo = !$tem_preco ? 'Sem preço na Steam' : ($escolhido ? 'Tirar da seleção' : 'Talvez eu compre');
    return '<div class="jogo-caixa' . ($escolhido ? ' escolhido' : '') . '"' . jogo_dados($j) . ($recolhido ? ' hidden' : '') . '>'
        . '<a class="jogo" href="' . h($j['url']) . '" target="_blank" rel="noopener" title="' . h($j['nome']) . '">'
        . '<span class="jogo-img">' . ($j['capa'] ? '<img src="' . h($j['capa']) . '" alt="" loading="lazy">' : '') . selo_switch($j) . '</span>'
        . '<span class="jogo-info"><span class="jogo-nome"><span class="jogo-titulo">' . h($j['nome']) . '</span>' . $ate . '</span>'
        . preco_html($j) . '</span></a>'
        . '<button type="button" class="escolher" ' . ($surpresa ? 'data-surpresa' : 'data-escolher') . '="' . (int) $j['appid'] . '" aria-pressed="' . ($escolhido ? 'true' : 'false') . '"'
        . ' title="' . $titulo . '" aria-label="' . $titulo . ': ' . h($j['nome']) . '"' . ($tem_preco ? '' : ' disabled') . '>'
        . icone('mais') . icone('ok') . '</button></div>';
}

// Barra com a soma dos jogos escolhidos e quanto falta para o valor mínimo
function barra_cesta(int $soma, int $qtd, ?int $minimo): string
{
    $pct = $minimo ? min(100, (int) round($soma * 100 / max(1, $minimo))) : 0;
    $ok = $minimo !== null && $qtd > 0 && $soma >= $minimo;
    if (!$qtd) {
        $status = 'Clique no + das capas para separar o que talvez você compre';
    } elseif ($minimo === null) {
        $status = '';
    } elseif ($ok) {
        $status = $soma > $minimo ? 'Mínimo atingido · ' . reais($soma - $minimo) . ' acima' : 'Mínimo atingido';
    } else {
        $status = 'Faltam ' . reais($minimo - $soma);
    }
    return '<div class="cesta' . ($ok ? ' cesta-ok' : '') . ($qtd ? '' : ' cesta-vazia') . '" data-cesta data-minimo="' . ($minimo ?? '') . '" aria-live="polite">'
        . '<span class="cesta-icone">' . icone('presente') . '</span>'
        . '<div class="cesta-texto"><p class="cesta-total"><b data-cesta-soma>' . reais($soma) . '</b>'
        . ($minimo !== null ? '<span> de ' . reais($minimo) . ' mínimo</span>' : '')
        . ajuda('Soma dos jogos que você separou, da lista e de fora dela. É só para você se organizar: nada é comprado e ninguém é avisado') . '</p>'
        . '<p class="cesta-status" data-cesta-status>' . h($status) . '</p></div>'
        . ($minimo !== null ? '<div class="cesta-barra" aria-hidden="true"><i data-cesta-barra style="transform:translateX(' . ($pct - 100) . '%)"></i></div>' : '')
        . '<span class="cesta-qtd" data-cesta-qtd>' . plural($qtd, 'jogo escolhido', 'jogos escolhidos') . '</span>'
        . '<button type="button" class="botao fantasma pequeno" data-cesta-limpar' . ($qtd ? '' : ' hidden') . '>Limpar</button>'
        . '</div>';
}

function jogo_linha(array $j): string
{
    return '<a class="jogo-linha" href="' . h($j['url']) . '" target="_blank" rel="noopener"' . jogo_dados($j) . '>'
        . '<span class="jogo-mini">' . ($j['capa'] ? '<img src="' . h($j['capa']) . '" alt="" loading="lazy">' : '') . '</span>'
        . '<span class="jogo-nome">' . h($j['nome']) . '</span>' . selo_switch($j)
        . preco_html($j) . '</a>';
}

// Capa na aba Minha lista, com o olho para esconder ou mostrar o jogo para o grupo
function jogo_capa_dono(array $j, bool $oculto): string
{
    $acao = $oculto ? 'Mostrar para o grupo' : 'Esconder do grupo';
    return '<div class="jogo-caixa' . ($oculto ? ' oculto' : '') . '"' . jogo_dados($j) . '>'
        . '<a class="jogo" href="' . h($j['url']) . '" target="_blank" rel="noopener" title="' . h($j['nome']) . '">'
        . '<span class="jogo-img">' . ($j['capa'] ? '<img src="' . h($j['capa']) . '" alt="" loading="lazy">' : '') . selo_switch($j) . '</span>'
        . '<span class="jogo-info"><span class="jogo-nome"><span class="jogo-titulo">' . h($j['nome']) . '</span></span>' . preco_html($j) . '</span></a>'
        . '<span class="escondido">Escondido</span>'
        . '<button type="button" class="escolher olho-btn" data-ocultar="' . (int) $j['appid'] . '" aria-pressed="' . ($oculto ? 'true' : 'false')
        . '" title="' . $acao . '" aria-label="' . $acao . ': ' . h($j['nome']) . '">' . icone('olho') . icone('olho-off') . '</button></div>';
}

function tempo_relativo(int $t): string
{
    $s = time() - $t;
    if (!$t) {
        return 'ainda não atualizada';
    }
    if ($s < 120) {
        return 'atualizada agora há pouco';
    }
    if ($s < 3600) {
        return 'atualizada há ' . intdiv($s, 60) . ' minutos';
    }
    if ($s < 86400) {
        return 'atualizada há ' . plural(intdiv($s, 3600), 'hora', 'horas');
    }
    return 'atualizada em ' . date('d/m \à\s H:i', $t);
}

// Preço como na Steam: em promoção, o selo verde com a % e o preço antigo riscado
// A Steam manda "R$32,98"; o resto do site escreve "R$ 32,98"
function preco_br(string $preco): string
{
    return preg_replace('/^R\$\s*/', 'R$ ', $preco);
}

function preco_html(array $j): string
{
    if (empty($j['desconto'])) {
        return '<span class="preco">' . h(preco_br($j['preco'])) . '</span>';
    }
    return '<span class="preco promo"><span class="preco-pct">-' . (int) $j['desconto'] . '%</span>'
        . '<span class="preco-valores">' . ($j['preco_original'] ? '<s>' . h(preco_br($j['preco_original'])) . '</s>' : '')
        . '<span>' . h(preco_br($j['preco'])) . '</span></span></span>';
}

// Dados usados pelo app.js para reordenar a lista
function jogo_dados(array $j): string
{
    return ' data-desconto="' . (int) ($j['desconto'] ?? 0) . '" data-centavos="' . (int) ($j['centavos'] ?? -1)
        . '" data-ordem="' . (int) ($j['ordem'] ?? 0) . '" data-nome="' . h(mb_strtolower($j['nome'])) . '"';
}

// Ordem padrão: maior desconto primeiro; empates na ordem de preferência do dono
function por_desconto(array $jogos): array
{
    usort($jogos, function ($a, $b) {
        return [-($a['desconto'] ?? 0), $a['ordem'] ?? 0] <=> [-($b['desconto'] ?? 0), $b['ordem'] ?? 0];
    });
    return $jogos;
}

function em_promocao(array $jogos): int
{
    return count(array_filter($jogos, function ($j) { return !empty($j['desconto']); }));
}

function seletor_ordem(): string
{
    $opcoes = ['desconto' => 'Maior desconto', 'menor' => 'Menor preço', 'maior' => 'Maior preço', 'nome' => 'Nome', 'preferencia' => 'Preferência do dono'];
    $s = '<label class="ordenar"><span>Ordenar por</span><select data-ordenar>';
    foreach ($opcoes as $v => $t) {
        $s .= '<option value="' . $v . '">' . $t . '</option>';
    }
    return $s . '</select></label>';
}

function resumo_lista(array $jogos): string
{
    $n = em_promocao($jogos);
    return plural(count($jogos), 'jogo', 'jogos') . ($n ? ' · ' . plural($n, 'em promoção', 'em promoção') : '');
}

// Texto quando não há jogos para mostrar
function sem_lista(array $p, $jogos, bool $sou_eu): string
{
    if (empty($p['steamid'])) {
        $msg = $sou_eu ? 'Você ainda não conectou seu perfil Steam. Faça isso em Meu perfil' : 'Não conectou um perfil Steam';
    } elseif ($jogos === null) {
        $msg = 'Não foi possível carregar a lista agora. Tente de novo mais tarde';
    } else {
        $msg = $sou_eu
            ? 'Sua lista está vazia ou privada. Na Steam, deixe os detalhes de jogos como públicos em Editar perfil, Privacidade'
            : 'Lista de desejos vazia ou privada';
    }
    return '<p class="vazio">' . h($msg) . '</p>';
}

if (!$eu):
    topo('Entrar · ' . $evento['nome'], ['evento' => $evento['nome'], 'classe' => 'tela-acesso']);
    $quando = data_extenso($evento['data']);
    ?>
<main class="acesso">
  <?= poster($evento['nome'] . ($quando ? ' · ' . $quando : '')) ?>
  <section class="acesso-form">
    <h1 class="titulo">Entre para ver quem você tirou</h1>
    <p class="sub">Use o e-mail em que você recebeu o sorteio e a senha que criou pelo link</p>
    <form method="post" class="form">
      <?= csrf_campo() ?>
      <label class="campo"><span>E-mail</span><input type="email" name="email" autocomplete="email" required autofocus></label>
      <label class="campo"><span>Senha</span><input type="password" name="senha" autocomplete="current-password" required></label>
      <button class="botao primario grande" name="acao" value="entrar">Entrar</button>
    </form>
    <p class="nota"><a href="senha.php">Primeiro acesso ou esqueci a senha</a></p>
    <p class="nota nota-org"><a href="admin.php">Sou o organizador</a></p>
  </section>
</main>
<?php
    rodape();
    exit;
endif;

$tirou = $d['participantes'][$d['sorteio']['pares'][$eu['id']] ?? ''] ?? null;
$listas = listas_steam(array_column($d['participantes'], 'steamid'));
$avatares = avatares_steam();   // lido depois de listas_steam, que pode ter acabado de buscar as fotos
// Jogos que a pessoa escondeu não saem do servidor para os outros; só o dono vê todos ($com_ocultos)
$jogos_de = function (array $p, bool $com_ocultos = false) use ($listas) {
    $jogos = empty($p['steamid']) ? [] : ($listas[$p['steamid']] ?? null);
    if (!empty($p['nintendo'])) {
        $jogos = array_merge($jogos ?: [], $p['nintendo']);
    }
    if (!$jogos || $com_ocultos || empty($p['ocultos'])) {
        return $jogos;
    }
    $ocultos = array_flip($p['ocultos']);
    return array_values(array_filter($jogos, function ($j) use ($ocultos) { return !isset($ocultos[$j['appid']]); }));
};
$pessoas = array_values($d['participantes']);
usort($pessoas, function ($a, $b) use ($eu) {
    return [$a['id'] !== $eu['id'], mb_strtolower($a['nome'])] <=> [$b['id'] !== $eu['id'], mb_strtolower($b['nome'])];
});
$quando = data_extenso($evento['data']);
$revelado = !empty($eu['revelado']);

$aba = in_array($_GET['aba'] ?? '', ['lista', 'conta'], true) ? $_GET['aba'] : 'amigo';
$minha = $jogos_de($eu, true);
$meus_ocultos = array_flip($eu['ocultos'] ?? []);
$n_escondidos = $minha ? count(array_filter($minha, function ($j) use ($meus_ocultos) { return isset($meus_ocultos[$j['appid']]); })) : 0;

// Abas na barra do topo
$abas_html = '<nav class="abas-topo" aria-label="Seções">';
foreach (['amigo' => ['./', 'presente', 'Amigo secreto'], 'lista' => ['./?aba=lista', 'controle', 'Minha lista<span class="aba-longo"> de desejos</span>'], 'conta' => ['./?aba=conta', 'cadeado', 'Conta']] as $chave => [$url, $ic, $rotulo]) {
    $abas_html .= '<a class="aba' . ($aba === $chave ? ' ativa' : '') . '" href="' . $url . '" data-aba="' . $chave . '"' . ($aba === $chave ? ' aria-current="page"' : '') . '>'
        . icone($ic) . '<span>' . $rotulo . '</span></a>';
}
$abas_html .= '</nav>';

topo($evento['nome'], ['evento' => $evento['nome'], 'usuario' => $eu['nome'], 'avatar' => avatar_html($eu, $avatares, 'avatar-topo'), 'abas' => $abas_html, 'classe' => 'logado', 'tour' => true]);
?>

<div class="tour" data-tour<?= empty($eu['tour_visto']) ? ' data-auto' : '' ?> hidden>
  <div class="tour-foco" data-tour-foco></div>
  <div class="tour-cartao" role="dialog" aria-modal="true" aria-labelledby="tour-titulo">
    <p class="tour-passo" data-tour-passo></p>
    <h2 id="tour-titulo" data-tour-titulo></h2>
    <p class="tour-texto" data-tour-texto></p>
    <div class="tour-acoes">
      <button type="button" class="botao fantasma pequeno" data-tour-pular>Pular</button>
      <button type="button" class="botao secundario pequeno" data-tour-voltar>Voltar</button>
      <button type="button" class="botao pequeno" data-tour-proximo>Próximo</button>
    </div>
  </div>
</div>
<div class="painel-aba" data-painel="amigo"<?= $aba === 'amigo' ? '' : ' hidden' ?>>
<main class="area">
  <aside class="lado">
    <div class="revelar">
      <?= envelope($tirou['nome'] ?? '', !$revelado, $evento['nome'], $quando ? explode(', ', $quando)[1] : '', $tirou ? avatar_html($tirou, $avatares, 'avatar-carta') : '') ?>
      <button class="botao <?= $revelado ? 'secundario' : 'primario' ?> grande" type="button" id="revelar" aria-expanded="<?= $revelado ? 'true' : 'false' ?>"
        data-abrir="Abrir o envelope" data-fechar="Guardar no envelope"><?= $revelado ? 'Guardar no envelope' : 'Abrir o envelope' ?></button>
      <p class="nota">Depois de revelar, fica à mostra sempre que você entrar. Esconda quando tiver alguém olhando</p>
    </div>
    <dl class="fatos">
      <?php if ($quando): ?><div><dt><?= icone('calendario') ?>Data</dt><dd><?= h(ucfirst($quando)) ?></dd></div><?php endif ?>
      <?php if ($evento['valor']): ?><div><dt><?= icone('carteira') ?>Valor mínimo<?= ajuda('O presente deve custar pelo menos esse valor. Passar do valor não tem problema') ?></dt><dd class="num"><?= h($evento['valor']) ?></dd></div><?php endif ?>
      <div><dt><?= icone('pessoas') ?>Participantes</dt><dd class="num"><?= count($pessoas) ?></dd></div>
    </dl>
    <?php if ($evento['obs']): ?><p class="obs"><?= nl2br(h($evento['obs'])) ?></p><?php endif ?>
  </aside>

  <div class="conteudo">
    <?php if ($tirou): $jt = $jogos_de($tirou); ?>
    <section class="bloco segredo" id="segredo"<?= $revelado ? '' : ' hidden' ?>>
      <div class="bloco-topo">
        <div>
          <h2>Lista de desejos de <?= h($tirou['nome']) ?><?= ajuda('São os jogos que a pessoa marcou na Steam, com o preço de hoje. O + da capa separa o jogo como "talvez eu compre". Só você vê o que separou') ?></h2>
          <?php if ($jt): ?><p class="sub"><?= resumo_lista($jt) ?>. Preços de hoje</p><?php endif ?>
        </div>
        <div class="bloco-acoes">
          <?php if (!empty($tirou['steamid'])): ?>
          <a class="botao secundario pequeno" href="<?= h(steam_link_lista($tirou['steamid'])) ?>" target="_blank" rel="noopener"><?= icone('externo') ?>Abrir na Steam</a>
          <?php endif ?>
        </div>
      </div>
      <?php if ($jt): ?>
      <div class="ferramentas">
        <?php if (count($jt) > RECOLHER_APOS): ?><label class="busca busca-curta"><?= icone('busca') ?><input type="search" placeholder="Buscar jogo" aria-label="Buscar jogo na lista" data-busca-segredo></label><?php endif ?>
        <?= seletor_ordem() ?>
      </div>
      <?php
        // Só conta o que ainda está na lista e visível (o dono pode ter escondido ou a Steam tirado)
        $cesta = array_flip($eu['carrinho'] ?? []);
        $soma = 0;
        $qtd = 0;
        foreach ($jt as $j) {
            if (isset($cesta[$j['appid']]) && ($j['centavos'] ?? -1) >= 0) {
                $soma += $j['centavos'];
                $qtd++;
            }
        }
      ?>
      <div class="jogos" data-lista-jogos data-recolher="<?= RECOLHER_APOS ?>"><?php foreach (array_values(por_desconto($jt)) as $i => $j) echo jogo_capa($j, isset($cesta[$j['appid']]) && ($j['centavos'] ?? -1) >= 0, $i >= RECOLHER_APOS); ?></div>
      <p class="vazio" data-segredo-vazio hidden>Nenhum jogo com esse nome</p>
      <?php if (count($jt) > RECOLHER_APOS): ?>
      <div class="ver-mais"><button type="button" class="botao secundario" data-ver-todos><?= icone('seta') ?><span>Ver todos os <?= count($jt) ?> jogos</span></button></div>
      <?php endif ?>
      <?php else: echo sem_lista($tirou, $jt, false); endif ?>
      <?php
        $surpresas = surpresas_atuais($eu);
        $soma = ($soma ?? 0) + array_sum(array_map(function ($j) { return max(0, (int) ($j['centavos'] ?? 0)); }, $surpresas));
        $qtd = ($qtd ?? 0) + count($surpresas);
      ?>
      <div class="surpresas">
        <div class="surpresas-topo">
          <div>
            <h3>Fora da lista<?= ajuda('Busque qualquer jogo da loja da Steam para dar de surpresa. Ele entra na mesma soma e só você vê') ?></h3>
            <p class="sub">Para surpreender com um jogo que não está na lista de desejos. Só você vê</p>
          </div>
          <label class="busca busca-curta"><?= icone('busca') ?><input type="search" placeholder="Buscar na Steam" aria-label="Buscar jogo na loja da Steam" autocomplete="off" data-busca-steam></label>
        </div>
        <div class="resultados" data-resultados aria-live="polite" hidden></div>
        <div class="jogos" data-surpresas-grade><?php foreach ($surpresas as $j) echo jogo_capa($j, true, false, true); ?></div>
      </div>
      <?= barra_cesta($soma, $qtd, valor_centavos($evento['valor'] ?? '')) ?>
    </section>
    <?php endif ?>

    <section class="bloco">
      <div class="bloco-topo" data-tour-grupo>
        <div>
          <h2>Listas do grupo<?= ajuda('Clique num nome para abrir a lista. "Ver em tela cheia" mostra as capas grandes. Todos veem as mesmas listas, então nada aqui revela o sorteio') ?></h2>
          <p class="sub">Todo mundo vê a lista de todo mundo. Nada aqui mostra quem tirou quem</p>
        </div>
        <div class="bloco-acoes"><?= seletor_ordem() ?></div>
      </div>
      <div class="pessoas">
        <?php foreach ($pessoas as $p): $sou = $p['id'] === $eu['id']; $jp = $jogos_de($p); ?>
        <details class="pessoa">
          <summary>
            <?= avatar_html($p, $avatares) ?>
            <span class="pessoa-nome"><?= h($p['nome']) ?><?= $sou ? ' <span class="voce">você</span>' : '' ?></span>
            <span class="pessoa-meta"><?= $jp ? resumo_lista($jp) : (empty($p['steamid']) ? 'Sem Steam' : 'Sem lista') ?></span>
            <span class="pessoa-seta"><?= icone('seta') ?></span>
          </summary>
          <div class="pessoa-corpo">
            <?php if ($sou): ?>
              <p class="dica-ocultar"><?= icone('olho') ?>É assim que o grupo vê a sua lista. <a href="./?aba=lista">Escolher o que aparece</a></p>
            <?php endif ?>
            <?php if ($jp): ?>
              <div class="pessoa-acoes"><button type="button" class="botao secundario pequeno" data-ampliar data-nome="<?= h($p['nome']) ?>" data-resumo="<?= h(resumo_lista($jp)) ?>"><?= icone('expandir') ?>Ver em tela cheia</button></div>
              <div data-lista-jogos><?php foreach (por_desconto($jp) as $j) echo jogo_linha($j); ?></div>
              <?php if (!empty($p['steamid'])): ?>
              <a class="link-steam" href="<?= h(steam_link_lista($p['steamid'])) ?>" target="_blank" rel="noopener"><?= icone('externo') ?>Lista completa na Steam</a>
              <?php endif ?>
            <?php else: echo sem_lista($p, $jp, $sou); endif ?>
          </div>
        </details>
        <?php endforeach ?>
      </div>
    </section>
  </div>
</main>
<dialog class="ampliada" data-ampliada aria-labelledby="ampliada-titulo">
  <div class="ampliada-topo">
    <div><h2 id="ampliada-titulo">Lista de desejos de <span data-ampliada-nome></span></h2><p class="sub" data-ampliada-resumo></p></div>
    <button type="button" class="botao icone-so" data-fechar-ampliada aria-label="Fechar"><?= icone('fechar') ?></button>
  </div>
  <div class="ferramentas">
    <label class="busca busca-curta"><?= icone('busca') ?><input type="search" placeholder="Buscar jogo" aria-label="Buscar jogo" data-busca-ampliada></label>
    <?= seletor_ordem() ?>
  </div>
  <div class="ampliada-corpo">
    <div class="jogos" data-lista-jogos data-ampliada-grade></div>
    <p class="vazio" data-ampliada-vazio hidden>Nenhum jogo com esse nome</p>
  </div>
</dialog>

</div>

<?php $atualizada = dados_ler()['steam_cache'][$eu['steamid'] ?? '']['em'] ?? 0; ?>
<div class="painel-aba" data-painel="lista"<?= $aba === 'lista' ? '' : ' hidden' ?>>
<main class="minha">
  <div class="minha-topo">
    <h1 class="titulo">Minha lista de desejos<?= ajuda('É a lista que o grupo vê. Clique no olho de uma capa para esconder ou mostrar o jogo. Ninguém fica sabendo o que você escondeu') ?></h1>
    <p class="sub">Escolha o que quem tirou você pode ver. Os jogos vêm da sua lista na Steam ou na eShop; aqui você esconde ou mostra cada um</p>
  </div>
  <div class="minha-grade">
    <aside class="minha-lado">
      <form method="post" class="form cartao">
        <?= csrf_campo() ?>
        <h3>Perfil Steam<?= ajuda('Cole o link do seu perfil, como steamcommunity.com/id/seunome. A lista de desejos e a sua foto vêm de lá') ?></h3>
        <?php if (!empty($eu['steamid'])): ?>
        <a class="perfil-steam" href="<?= h(steam_link_perfil($eu['steamid'])) ?>" target="_blank" rel="noopener"><?= avatar_html($eu, $avatares) ?><span>Ver perfil na Steam</span><?= icone('externo') ?></a>
        <?php endif ?>
        <label class="campo"><span>Link do perfil</span>
          <input name="steam" value="<?= h($eu['steam'] ?? '') ?>" placeholder="steamcommunity.com/id/seunome"></label>
        <p class="nota">A lista precisa estar pública: na Steam, Editar perfil, Privacidade, Detalhes de jogos públicos</p>
        <button class="botao secundario" name="acao" value="steam">Salvar perfil</button>
      </form>
      <form method="post" action="./?aba=lista" class="form cartao" id="eshop">
        <?= csrf_campo() ?>
        <h3>Lista da eShop (Nintendo)<?= ajuda('Cole o texto da sua lista de desejos da eShop. Os preços vêm em reais, como estão na página') ?></h3>
        <ol class="passos">
          <li>Abra o link da sua lista de desejos da eShop (o de compartilhar).</li>
          <li>Espere os jogos aparecerem. Aperte <b>Ctrl+A</b> e depois <b>Ctrl+C</b> (no celular: segure no texto, Selecionar tudo, Copiar).</li>
          <li>Cole abaixo e clique em Salvar lista.</li>
        </ol>
        <img class="passos-gif" src="assets/como-colar-eshop.gif?v=2" alt="Passo a passo: abrir a lista na eShop, copiar o texto e colar aqui" loading="lazy">
        <label class="campo"><span>Texto da lista</span>
          <textarea name="nintendo" rows="5" placeholder="Cole aqui o texto copiado da eShop"></textarea></label>
        <?php if (!empty($eu['nintendo'])): ?>
        <p class="nota"><?= plural(count($eu['nintendo']), 'jogo salvo', 'jogos salvos') ?>, <?= h(tempo_relativo((int) ($eu['nintendo_em'] ?? 0))) ?>. Colar de novo substitui a lista; salvar vazio remove</p>
        <?php endif ?>
        <button class="botao secundario" name="acao" value="nintendo">Salvar lista</button>
      </form>
      <?php if (!empty($eu['steamid'])): ?>
      <form method="post" class="form cartao">
        <?= csrf_campo() ?>
        <h3>Atualização<?= ajuda('Use depois de mexer na sua lista na Steam, para o site buscar os jogos e os preços de novo') ?></h3>
        <p class="sub">Lista <?= h(tempo_relativo((int) $atualizada)) ?>. Ela também se atualiza sozinha a cada 6 horas</p>
        <button class="botao secundario" name="acao" value="atualizar_lista"><?= icone('atualizar') ?>Atualizar agora</button>
      </form>
      <?php endif ?>
      <?php if ($minha): ?>
      <dl class="fatos cartao">
        <div><dt><?= icone('controle') ?>Na lista</dt><dd class="num" data-cont-total><?= count($minha) ?></dd></div>
        <div><dt><?= icone('olho') ?>Aparecem para o grupo</dt><dd class="num" data-cont-aparecem><?= count($minha) - $n_escondidos ?></dd></div>
        <div><dt><?= icone('olho-off') ?>Escondidos</dt><dd class="num" data-cont-escondidos><?= $n_escondidos ?></dd></div>
      </dl>
      <?php endif ?>
    </aside>

    <section class="minha-lista">
      <?php if ($minha): ?>
      <div class="ferramentas">
        <label class="busca"><?= icone('busca') ?><input type="search" placeholder="Buscar jogo" aria-label="Buscar jogo" data-busca></label>
        <div class="filtro" role="group" aria-label="Mostrar">
          <button type="button" data-filtro="todos" aria-pressed="true">Todos<span data-cont-total><?= count($minha) ?></span></button>
          <button type="button" data-filtro="aparecem" aria-pressed="false">Aparecem<span data-cont-aparecem><?= count($minha) - $n_escondidos ?></span></button>
          <button type="button" data-filtro="escondidos" aria-pressed="false">Escondidos<span data-cont-escondidos><?= $n_escondidos ?></span></button>
        </div>
        <?= seletor_ordem() ?>
      </div>
      <div class="acoes-massa">
        <span data-cont-tela><?= plural(count($minha), 'jogo na tela', 'jogos na tela') ?></span>
        <button type="button" class="botao fantasma pequeno" data-massa="1"><?= icone('olho-off') ?>Esconder os da tela</button>
        <button type="button" class="botao fantasma pequeno" data-massa="0"><?= icone('olho') ?>Mostrar os da tela</button>
      </div>
      <div class="jogos" data-lista-jogos data-minha><?php foreach (por_desconto($minha) as $j) echo jogo_capa_dono($j, isset($meus_ocultos[$j['appid']])); ?></div>
      <p class="vazio" data-sem-resultado hidden>Nenhum jogo com esse filtro</p>
      <?php else: echo sem_lista($eu, $minha, true); endif ?>
    </section>
  </div>
</main>

</div>

<div class="painel-aba" data-painel="conta"<?= $aba === 'conta' ? '' : ' hidden' ?>>
<main class="minha conta">
  <div class="minha-topo">
    <h1 class="titulo">Conta</h1>
    <p class="sub">Você entra com o e-mail <?= h($eu['email']) ?></p>
  </div>
  <form method="post" class="form cartao">
    <?= csrf_campo() ?>
    <h3>Trocar senha<?= ajuda('A senha vale só para este site. Se esquecer, use "Primeiro acesso ou esqueci a senha" na tela de entrada') ?></h3>
    <label class="campo"><span>Senha atual</span><input type="password" name="atual" autocomplete="current-password" required></label>
    <label class="campo"><span>Senha nova</span><input type="password" name="nova" autocomplete="new-password" minlength="8" required></label>
    <label class="campo"><span>Repita a senha nova</span><input type="password" name="confirma" autocomplete="new-password" minlength="8" required></label>
    <p class="nota">Pelo menos 8 caracteres. Trocar a senha desconecta os outros aparelhos</p>
    <button class="botao secundario" name="acao" value="senha">Trocar senha</button>
  </form>
</main>
</div>
<?php rodape(); ?>
