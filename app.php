<?php
// Núcleo do site: configuração, sessão, dados (JSON com lock), login, e-mails e layout.

// Erro do PHP nunca aparece na página (pode trazer o caminho da conta); fica só no log
ini_set('display_errors', '0');

// O config.php não vai para o repositório: ele nasce de uma cópia do config.exemplo.php
if (!is_file(__DIR__ . '/config.php')) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Falta configurar: copie config.exemplo.php para config.php e preencha os dados.');
}
$config = require __DIR__ . '/config.php';
require __DIR__ . '/smtp.php';
require __DIR__ . '/steam.php';
require __DIR__ . '/nintendo.php';
require __DIR__ . '/capas.php';

const DADOS_DIR            = __DIR__ . '/dados';
// Primeira linha do arquivo de dados: se alguém pedir o arquivo pelo navegador, o servidor
// executa isto e responde 404 vazio, mesmo que o .htaccess da pasta seja ignorado.
const DADOS_GUARDA         = "<?php http_response_code(404); exit; __halt_compiler();\n";
const VALIDADE_CONVITE     = 14 * 86400;
const VALIDADE_REDEFINICAO = 3600;
const CACHE_STEAM          = 6 * 3600;
const CACHE_STEAM_VERSAO   = 5;   // mude para descartar o cache antigo (5: sem jogos sem preço)
// Hash de uma senha aleatória: o login confere contra ele quando o e-mail não existe,
// para a resposta levar o mesmo tempo nos dois casos.
const HASH_FALSO = '$2y$10$2YnM4/HRP1LAF6KdC90hq.YELfwdQQJzBM4MiHXoPFkIZ5Q7xeFQ.';

// Erro esperado, com mensagem pronta para mostrar ao usuário.
class Recusa extends RuntimeException
{
}

// Erro inesperado fora dos formulários (ex.: dados corrompidos): página simples em vez de tela branca
set_exception_handler(function (Throwable $e) {
    error_log('Amigo Secreto: ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><meta charset="utf-8"><title>Erro</title>'
        . '<div style="font:15px/22px system-ui,sans-serif;max-width:480px;margin:96px auto;padding:0 24px;color:#1f1b16">'
        . '<p style="font-size:18px;margin:0 0 8px">O site não conseguiu abrir agora</p>'
        // Só as mensagens escritas para o usuário aparecem; o detalhe do resto fica no log
        . '<p style="color:#5e554a;margin:0">' . htmlspecialchars($e instanceof Recusa ? $e->getMessage() : 'Tente de novo em instantes.', ENT_QUOTES, 'UTF-8') . '</p></div>';
});

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443)
    || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
if ($https) {
    header('Strict-Transport-Security: max-age=31536000');
}
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
session_name('amigosecreto');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $https]);
session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

// ---------------------------------------------------------------- utilidades

function h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function csrf_campo(): string
{
    return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">';
}

function exigir_csrf(): void
{
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        throw new Recusa('Sessão expirada. Recarregue a página e tente de novo.');
    }
}

function flash(string $tipo, string $msg): void
{
    $_SESSION['flash'] = ['tipo' => $tipo, 'msg' => $msg];
}

function pegar_flash(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

// Depois de um POST, volta para a página com GET (evita reenviar o formulário).
function redirecionar(string $para = ''): void
{
    header('Location: ' . ($para !== '' ? $para : strtok($_SERVER['REQUEST_URI'], '?')));
    exit;
}

function ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '?';
}

// Os links dos e-mails só saem em https; http vale apenas para testar no próprio computador.
function url_site_valida(string $url): bool
{
    return (bool) preg_match('~^https://.|^http://(localhost|127\.0\.0\.1)(:\d+)?(/|$)~', $url);
}

function url_site(string $caminho): string
{
    global $config;
    $base = rtrim(trim((string) ($config['url_site'] ?? '')), '/');
    if (!url_site_valida($base)) {
        throw new Recusa('Preencha o url_site no config.php com o endereço do site, começando por https://, antes de enviar e-mails.');
    }
    return $base . '/' . $caminho;
}

function email_valido(string $email): bool
{
    return strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function data_extenso(string $ymd): string
{
    $t = strtotime($ymd);
    if (!$ymd || !$t) {
        return '';
    }
    $dias = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];
    $meses = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    return $dias[(int) date('w', $t)] . ', ' . (int) date('j', $t) . ' de ' . $meses[(int) date('n', $t) - 1];
}

function data_hora(int $t): string
{
    return $t ? date('d/m/Y \à\s H:i', $t) : '';
}

// Valor em centavos a partir do texto livre do evento ("R$ 150", "150,00", "1.500"); null se não der para ler.
function valor_centavos(string $texto): ?int
{
    $s = preg_replace('~[^\d,.]~', '', $texto);
    if ($s === '' || !preg_match('~\d~', $s)) {
        return null;
    }
    if (strpos($s, ',') !== false) {
        $s = str_replace(',', '.', str_replace('.', '', $s));   // 1.500,50
    } elseif (preg_match('~\.\d{3}$~', $s)) {
        $s = str_replace('.', '', $s);                          // 1.500
    }
    return (int) round((float) $s * 100);
}

function reais(int $centavos): string
{
    return 'R$ ' . number_format($centavos / 100, 2, ',', '.');
}

function plural(int $n, string $um, string $varios): string
{
    return $n . ' ' . ($n === 1 ? $um : $varios);
}

// Ícones lucide (traço 1.5), desenhados inline.
function icone(string $nome): string
{
    static $p = [
        'sair'      => '<path d="m16 17 5-5-5-5"/><path d="M21 12H9"/><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>',
        'mais'      => '<path d="M5 12h14"/><path d="M12 5v14"/>',
        'lixo'      => '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>',
        'email'     => '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
        'calendario'=> '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
        'carteira'  => '<path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/>',
        'pessoas'   => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'externo'   => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
        'atualizar' => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
        'ok'        => '<path d="M20 6 9 17l-5-5"/>',
        'alerta'    => '<circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/>',
        'enviar'    => '<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>',
        'desfazer'  => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/>',
        'editar'    => '<path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/><path d="m15 5 4 4"/>',
        'fechar'    => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'controle'  => '<path d="M6 11h4"/><path d="M8 9v4"/><path d="M15 12h.01"/><path d="M18 10h.01"/><path d="M17.32 5H6.68a4 4 0 0 0-3.978 3.59c-.006.052-.01.101-.017.152C2.604 9.416 2 14.456 2 16a3 3 0 0 0 3 3c1 0 1.5-.5 2-1l1.414-1.414A2 2 0 0 1 9.828 16h4.344a2 2 0 0 1 1.414.586L17 18c.5.5 1 1 2 1a3 3 0 0 0 3-3c0-1.545-.604-6.584-.685-7.258-.007-.05-.011-.1-.017-.151A4 4 0 0 0 17.32 5z"/>',
        'seta'      => '<path d="m6 9 6 6 6-6"/>',
        'expandir'  => '<path d="M15 3h6v6"/><path d="M9 21H3v-6"/><path d="m21 3-7 7"/><path d="m3 21 7-7"/>',
        'busca'     =>'<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'presente'  =>'<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13"/><path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5A4.8 8 0 0 1 12 8a4.8 8 0 0 1 4.5-5 2.5 2.5 0 0 1 0 5"/>',
        'olho'      =>'<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
        'olho-off'  => '<path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49"/><path d="M14.084 14.158a3 3 0 0 1-4.242-4.242"/><path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143"/><path d="m2 2 20 20"/>',
        'cadeado'   => '<rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    ];
    return '<svg class="ic ic-' . $nome . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$nome] ?? '') . '</svg>';
}

// ---------------------------------------------------------------- dados

function dados_inicial(): array
{
    return [
        'evento'        => ['nome' => 'Amigo Secreto Geoje', 'data' => '', 'valor' => '', 'obs' => ''],
        'participantes' => [],
        'sorteio'       => null,
        'steam_cache'   => [],
        'limites'       => [],
    ];
}

function dados_lock(int $modo)
{
    if (!is_dir(DADOS_DIR) && !mkdir(DADOS_DIR, 0755, true) && !is_dir(DADOS_DIR)) {
        throw new Recusa('Não foi possível criar a pasta de dados.');
    }
    if (!is_file(DADOS_DIR . '/.htaccess')) {
        file_put_contents(DADOS_DIR . '/.htaccess', "Require all denied\n");
    }
    $l = fopen(DADOS_DIR . '/dados.lock', 'c');
    if (!$l || !flock($l, $modo)) {
        throw new Recusa('Não foi possível travar o arquivo de dados.');
    }
    return $l;
}

function dados_carregar(): array
{
    $arquivo = DADOS_DIR . '/dados.php';
    if (is_file($arquivo)) {
        $texto = (string) file_get_contents($arquivo);
        $n = strlen(DADOS_GUARDA);
        $json = strncmp($texto, DADOS_GUARDA, $n) === 0 ? substr($texto, $n) : '';
    } elseif (is_file(DADOS_DIR . '/dados.json')) {
        // Formato antigo, sem a linha de guarda; a próxima gravação já sai no novo
        $json = (string) file_get_contents(DADOS_DIR . '/dados.json');
    } else {
        return dados_inicial();
    }
    $d = json_decode($json, true);
    if (!is_array($d)) {
        // Nunca grava por cima de um arquivo que não deu para ler
        throw new Recusa('O arquivo de dados está corrompido. Nada foi alterado.');
    }
    return array_replace(dados_inicial(), $d);
}

function dados_ler(): array
{
    // Dados ainda no formato antigo: regrava no novo. Se não der, segue lendo o antigo
    static $tentou = false;
    if (!$tentou && is_file(DADOS_DIR . '/dados.json') && !is_file(DADOS_DIR . '/dados.php')) {
        $tentou = true;
        try {
            dados_alterar(function () {
            });
        } catch (Throwable $e) {
            error_log('Amigo Secreto: não foi possível converter o arquivo de dados: ' . $e->getMessage());
        }
    }
    $l = dados_lock(LOCK_SH);
    try {
        return dados_carregar();
    } finally {
        flock($l, LOCK_UN);
        fclose($l);
    }
}

// Lê, deixa a callback alterar (recebe &$d) e grava de forma atômica. Se a callback lançar
// exceção, nada é gravado. Devolve o que a callback devolver.
function dados_alterar(callable $alterar)
{
    $l = dados_lock(LOCK_EX);
    try {
        $d = dados_carregar();
        $retorno = $alterar($d);

        // Descarta tentativas de mais de 1 hora
        foreach ($d['limites'] as $chave => $tempos) {
            $tempos = array_values(array_filter($tempos, function ($t) { return $t > time() - 3600; }));
            if ($tempos) {
                $d['limites'][$chave] = $tempos;
            } else {
                unset($d['limites'][$chave]);
            }
        }

        $json = json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $tmp = DADOS_DIR . '/dados.tmp.php';
        if ($json === false || file_put_contents($tmp, DADOS_GUARDA . $json) !== strlen(DADOS_GUARDA . $json) || !rename($tmp, DADOS_DIR . '/dados.php')) {
            throw new Recusa('Não foi possível gravar os dados.');
        }
        // O arquivo do formato antigo sai depois que o novo já está gravado
        if (is_file(DADOS_DIR . '/dados.json') && !@unlink(DADOS_DIR . '/dados.json') && is_file(DADOS_DIR . '/dados.json')) {
            error_log('Amigo Secreto: apague o arquivo antigo dados/dados.json');
        }
        return $retorno;
    } finally {
        flock($l, LOCK_UN);
        fclose($l);
    }
}

function buscar_por_email(array $d, string $email): ?array
{
    foreach ($d['participantes'] as $p) {
        if (strcasecmp($p['email'], $email) === 0) {
            return $p;
        }
    }
    return null;
}

// ---------------------------------------------------------------- limites de tentativas

function limite_ok(array $d, string $chave, int $max, int $janela): bool
{
    $n = 0;
    foreach ($d['limites'][$chave] ?? [] as $t) {
        if ($t > time() - $janela) {
            $n++;
        }
    }
    return $n < $max;
}

function limite_marcar(array &$d, string $chave): void
{
    $d['limites'][$chave][] = time();
}

// ---------------------------------------------------------------- sessão do participante

// Muda quando o sorteio é refeito ou a senha é trocada: derruba as sessões antigas.
function versao_sessao(array $d, array $p): string
{
    return hash('sha256', ($d['sorteio']['feito_em'] ?? '') . '|' . ($p['senha'] ?? ''));
}

function participante_atual(array $d): ?array
{
    $pid = $_SESSION['pid'] ?? null;
    if (!$pid) {
        return null;
    }
    $p = $d['participantes'][$pid] ?? null;
    if (!$p || !$d['sorteio'] || empty($p['senha']) || !hash_equals(versao_sessao($d, $p), (string) ($_SESSION['ver'] ?? ''))) {
        unset($_SESSION['pid'], $_SESSION['ver']);
        return null;
    }
    return $p;
}

function iniciar_sessao_participante(array $d, array $p): void
{
    session_regenerate_id(true);
    $_SESSION['pid'] = $p['id'];
    $_SESSION['ver'] = versao_sessao($d, $p);
}

// A senha do modelo (ou uma curta demais) não abre o painel.
function senha_admin_fraca(): bool
{
    global $config;
    $s = $config['senha_admin'] ?? null;
    return !is_string($s) || $s === 'troque-esta-senha' || strlen($s) < 10;
}

function admin_logado(): bool
{
    global $config;
    return !senha_admin_fraca() && !empty($_SESSION['admin']) && is_string($_SESSION['admin'])
        && hash_equals(hash('sha256', $config['senha_admin']), $_SESSION['admin']);
}

// Gera um token de uso único; no participante fica só o hash.
function novo_token(array &$p, int $validade): string
{
    $token = bin2hex(random_bytes(32));
    $p['token'] = hash('sha256', $token);
    $p['token_expira'] = time() + $validade;
    return $token;
}

function participante_do_token(array $d, string $token): ?array
{
    if (!preg_match('~^[a-f0-9]{64}$~', $token)) {
        return null;
    }
    $hash = hash('sha256', $token);
    foreach ($d['participantes'] as $p) {
        if (!empty($p['token']) && hash_equals($p['token'], $hash) && $p['token_expira'] > time()) {
            return $p;
        }
    }
    return null;
}

// ---------------------------------------------------------------- Steam com cache

// Listas de desejos por steamid: array de jogos, [] (vazia ou privada) ou null (indisponível).
function listas_steam(array $steamids, bool $forcar = false): array
{
    $cache = dados_ler()['steam_cache'];
    $res = [];
    $novos = [];
    foreach (array_unique(array_filter($steamids)) as $sid) {
        $c = $cache[$sid] ?? null;
        if ($c && !$forcar && ($c['v'] ?? 1) === CACHE_STEAM_VERSAO && $c['em'] > time() - CACHE_STEAM) {
            $res[$sid] = $c['jogos'];
            continue;
        }
        $jogos = steam_lista_desejos((string) $sid);
        if ($jogos === null) {
            $res[$sid] = $c['jogos'] ?? null;   // Steam fora do ar: usa o cache vencido
            continue;
        }
        $res[$sid] = $jogos;
        // A foto do perfil vem junto; se a Steam não responder, fica a que já estava
        $novos[$sid] = ['jogos' => $jogos, 'avatar' => steam_avatar((string) $sid) ?? ($c['avatar'] ?? '')];
    }
    if ($novos) {
        dados_alterar(function (array &$d) use ($novos) {
            foreach ($novos as $sid => $n) {
                $d['steam_cache'][$sid] = ['v' => CACHE_STEAM_VERSAO, 'em' => time(), 'jogos' => $n['jogos'], 'avatar' => $n['avatar']];
            }
        });
    }
    return $res;
}

// Fotos do perfil Steam já guardadas no cache: steamid => URL ('' se não tiver).
function avatares_steam(): array
{
    return array_map(function ($c) { return $c['avatar'] ?? ''; }, dados_ler()['steam_cache']);
}

// Foto da pessoa; sem foto, as iniciais num círculo.
function avatar_html(array $p, array $avatares, string $classe = ''): string
{
    $url = !empty($p['steamid']) ? ($avatares[$p['steamid']] ?? '') : '';
    if ($url !== '') {
        return '<img class="avatar ' . $classe . '" src="' . h($url) . '" alt="" loading="lazy">';
    }
    $partes = preg_split('~\s+~', trim($p['nome']));
    $ini = mb_strtoupper(mb_substr($partes[0], 0, 1) . (count($partes) > 1 ? mb_substr(end($partes), 0, 1) : ''));
    return '<span class="avatar avatar-ini ' . $classe . '" aria-hidden="true">' . h($ini) . '</span>';
}

// ---------------------------------------------------------------- e-mails

function email_html(string $conteudo): string
{
    $logo = h(url_site('assets/marca-geoje.png'));
    return '<div style="background:#1e1f23;padding:32px 16px;font-family:Montserrat,Arial,Helvetica,sans-serif;color:#ededf0">'
        . '<div style="max-width:520px;margin:0 auto;background:#25262b;border:1px solid #33343a;border-radius:14px;padding:32px">'
        . '<p style="margin:0 0 24px"><img src="' . $logo . '" width="44" alt="" style="vertical-align:middle;border:0">'
        . '<span style="vertical-align:middle;margin-left:10px;font-size:15px;font-weight:bold;letter-spacing:3px;color:#ededf0">GEOJE</span></p>'
        . $conteudo . '</div></div>';
}

function email_botao(string $url, string $texto): string
{
    return '<p style="margin:28px 0"><a href="' . h($url) . '" style="background:#004aad;color:#ffffff;text-decoration:none;'
        . 'padding:13px 22px;border-radius:8px;font-size:15px;font-weight:bold;display:inline-block">' . h($texto) . '</a></p>';
}

function email_convite(array $evento, array $p, array $tirou, string $link): array
{
    $quando = trim(data_extenso($evento['data']) . ($evento['valor'] ? ' · valor mínimo ' . $evento['valor'] : ''), ' ·');
    $texto = "Olá, {$p['nome']}.\n\nO sorteio do {$evento['nome']} foi feito.\n\n"
        . ($quando ? "$quando\n\n" : '')
        . "Crie sua senha para entrar no site e descobrir quem você tirou:\n$link\n\n"
        . "O link vale por 14 dias. Guarde segredo.\n";
    $html = email_html(
        '<p style="margin:0 0 4px;font-size:14px;color:#a6a7ae">' . h($evento['nome']) . '</p>'
        . '<p style="margin:0;font-size:22px;font-weight:bold;line-height:30px">Olá, ' . h($p['nome']) . '. O sorteio foi feito.</p>'
        . ($quando ? '<p style="margin:10px 0 0;font-size:14px;color:#a6a7ae">' . h($quando) . '</p>' : '')
        . '<p style="margin:20px 0 0;font-size:15px;line-height:24px;color:#ededf0">Crie sua senha para entrar no site e descobrir quem você tirou.</p>'
        . email_botao($link, 'Criar minha senha')
        . '<p style="margin:0;font-size:13px;line-height:21px;color:#87888f">O link vale por 14 dias. Guarde segredo.</p>'
    );
    return [$evento['nome'] . ': o sorteio foi feito', $texto, $html];
}

function email_redefinicao(array $evento, array $p, string $link): array
{
    $texto = "Olá, {$p['nome']}.\n\nPara criar uma senha nova no {$evento['nome']}, abra o link:\n$link\n\n"
        . "O link vale por 1 hora. Se não foi você que pediu, ignore este e-mail.\n";
    $html = email_html(
        '<p style="margin:0 0 4px;font-size:14px;color:#a6a7ae">' . h($evento['nome']) . '</p>'
        . '<p style="margin:0;font-size:22px;font-weight:bold;line-height:30px">Olá, ' . h($p['nome']) . '.</p>'
        . '<p style="margin:12px 0 0;font-size:15px;line-height:24px">Use o botão para criar uma senha nova.</p>'
        . email_botao($link, 'Criar senha nova')
        . '<p style="margin:0;font-size:13px;line-height:21px;color:#87888f">O link vale por 1 hora. Se não foi você que pediu, ignore este e-mail.</p>'
    );
    return [$evento['nome'] . ': senha nova', $texto, $html];
}

// Envia os convites ([participante, quem ele tirou, token], ...) numa só conexão, marcando
// cada envio que der certo. Devolve [quantos saíram, mensagem de erro ou null].
function enviar_convites(array $itens): array
{
    global $config;
    $evento = dados_ler()['evento'];
    $enviados = 0;
    $smtp = null;
    try {
        $links = [];
        foreach ($itens as $i => [$p, , $token]) {
            $links[$i] = url_site('senha.php?t=' . $token);
        }
        $smtp = new Smtp($config['smtp']);
        foreach ($itens as $i => [$p, $tirou]) {
            [$assunto, $texto, $html] = email_convite($evento, $p, $tirou, $links[$i]);
            $smtp->enviar($config['smtp']['remetente'], $p['email'], montar_mensagem($config['smtp'], $p['nome'], $p['email'], $assunto, $texto, $html));
            $pid = $p['id'];
            dados_alterar(function (array &$d) use ($pid) {
                if (isset($d['participantes'][$pid])) {
                    $d['participantes'][$pid]['convite_enviado_em'] = time();
                }
            });
            $enviados++;
        }
        return [$enviados, null];
    } catch (Throwable $e) {
        return [$enviados, $e->getMessage()];
    } finally {
        if ($smtp) {
            $smtp->fechar();
        }
    }
}

function enviar_redefinicao(array $p, string $token): void
{
    global $config;
    [$assunto, $texto, $html] = email_redefinicao(dados_ler()['evento'], $p, url_site('senha.php?t=' . $token));
    $smtp = new Smtp($config['smtp']);
    try {
        $smtp->enviar($config['smtp']['remetente'], $p['email'], montar_mensagem($config['smtp'], $p['nome'], $p['email'], $assunto, $texto, $html));
    } finally {
        $smtp->fechar();
    }
}

// ---------------------------------------------------------------- layout

function topo(string $titulo, array $o = []): void
{
    $evento = $o['evento'] ?? '';
    ?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title><?= h($titulo) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css?v=35">
<link rel="icon" href="assets/logo-geoje.png">
</head>
<body class="<?= h($o['classe'] ?? '') ?>">
<?= ceu(44, 'ceu-fundo') ?>
<header class="barra">
  <div class="barra-in">
    <a class="marca" href="<?= h($o['inicio'] ?? './') ?>"><img src="assets/marca-geoje.png" alt=""><span class="marca-nome">GEOJE</span></a>
    <?php if ($evento && empty($o['abas'])): ?><span class="barra-evento"><?= h($evento) ?></span><?php endif ?>
    <?= $o['abas'] ?? '' ?>
    <?php if (!empty($o['usuario'])): ?>
    <form method="post" class="barra-dir">
      <?= csrf_campo() ?>
      <?php if (!empty($o['tour'])): ?><button type="button" class="ajuda-botao ajuda-topo" data-tour-abrir title="Ver o tour de novo" aria-label="Ver o tour de novo">?</button><?php endif ?>
      <span class="barra-usuario"><?= $o['avatar'] ?? '' ?><?= h($o['usuario']) ?></span>
      <button class="botao fantasma pequeno" name="acao" value="sair"><?= icone('sair') ?>Sair</button>
    </form>
    <?php endif ?>
  </div>
</header>
<?php
    $f = pegar_flash();
    if ($f) {
        echo '<div class="aviso ' . ($f['tipo'] === 'ok' ? 'aviso-ok' : 'aviso-erro') . '" role="status">'
            . icone($f['tipo'] === 'ok' ? 'ok' : 'alerta') . '<span>' . h($f['msg']) . '</span></div>';
    }
}

// O "?" ao lado de cada funcionalidade: abre um balão com a explicação (app.js)
function ajuda(string $texto): string
{
    return '<span class="ajuda"><button type="button" class="ajuda-botao" aria-label="Ajuda" aria-expanded="false">?</button>'
        . '<span class="ajuda-balao" role="tooltip">' . h($texto) . '</span></span>';
}

function rodape(): void
{
    echo "\n<script src=\"assets/app.js?v=19\" defer></script>\n</body>\n</html>\n";
}

// Metade esquerda das telas de acesso: o logo Geoje em tamanho de pôster, com a faixa azul.
function poster(string $legenda): string
{
    return '<section class="poster" aria-hidden="true">' . ceu(30)
        . '<div class="poster-planeta"><img src="assets/marca-geoje.png" alt=""></div>'
        . '<div class="poster-faixa"><strong>GEOJE</strong><p>' . h($legenda) . '</p></div></section>';
}

// O envelope da área do participante: fechado com o lacre ou aberto com a carta e o nome.
// $foto: avatar_html() de quem a pessoa tirou, colado na carta ao lado de "Você tirou".
function envelope(string $nome, bool $lacrado, string $evento, string $dia, string $foto = ''): string
{
    return '<div class="envelope-cena' . ($lacrado ? ' lacrada' : '') . '" data-revelar>'
        . '<span class="env-aba" aria-hidden="true"></span>'
        . '<div class="env-carta">'
        . '<p class="env-de"><span>' . h($evento) . '</span><span>' . h($dia) . '</span></p>'
        . '<p class="env-rotulo">' . $foto . '<span>Você tirou</span></p>'
        . '<p class="env-nome" data-nome>' . h($nome) . '</p>'
        . '<p class="env-linha">Veja a lista de desejos e guarde segredo</p>'
        . '</div>'
        . '<span class="env-corpo" aria-hidden="true"></span>'
        . '<span class="lacre" aria-hidden="true"><img src="assets/marca-geoje.png" alt=""></span>'
        . '<span class="env-dica">Toque para abrir</span>'
        . '</div>';
}

// Estrelas do logo espalhadas no fundo (vêm no HTML para aparecerem junto com a página).
function ceu(int $n, string $classe = ''): string
{
    $s = '<div class="ceu' . ($classe ? ' ' . $classe : '') . '" aria-hidden="true">';
    for ($i = 0; $i < $n; $i++) {
        $s .= sprintf(
            '<svg viewBox="0 0 24 24" style="left:%.1f%%;top:%.1f%%;width:%dpx;--d:%.2fs;--t:%.2fs"><path d="M12 0C13 8 16 11 24 12 16 13 13 16 12 24 11 16 8 13 0 12 8 11 11 8 12 0Z"/></svg>',
            random_int(40, 960) / 10, random_int(40, 920) / 10, random_int(8, 22), random_int(0, 400) / 100, random_int(300, 600) / 100
        );
    }
    return $s . '</div>';
}
