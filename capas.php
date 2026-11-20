<?php
// Capas dos jogos da eShop pelo IGDB (Twitch). Precisa de 'igdb' => ['client_id' => ..., 'client_secret' => ...]
// no config.php. Sem isso, os jogos ficam sem capa e o resto do site funciona normalmente.
// Os resultados (inclusive "não achei") ficam em dados/capas.json (pasta protegida pelo .htaccess).

const CAPAS_ARQ = __DIR__ . '/dados/capas.json';
const CAPAS_MAX_POR_ENVIO = 100;   // jogos buscados por envio; o resto fica para o próximo (o cache cresce)
const CAPAS_ORCAMENTO = 50;       // segundos no máximo gastos na busca

function capas_post(string $url, string $corpo, array $cab = []): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $corpo,
        CURLOPT_HTTPHEADER     => $cab,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 6,
    ]);
    $r = curl_exec($ch);
    $ok = $r !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
    $j = $ok ? json_decode($r, true) : null;
    return is_array($j) ? $j : null;
}

// Cache: [] se o arquivo não existe, null se está ilegível/inválido (nesse caso não se grava por cima).
function capas_ler(): ?array
{
    if (!is_file(CAPAS_ARQ)) {
        return [];
    }
    $t = @file_get_contents(CAPAS_ARQ);
    $j = $t === false ? null : json_decode($t, true);
    return is_array($j) ? $j : null;
}

// Relê o arquivo, aplica a mudança e troca por rename (leitores nunca veem arquivo pela metade).
function capas_gravar(callable $mudar): void
{
    $cache = capas_ler();
    if ($cache === null) {
        return;
    }
    $mudar($cache);
    $json = json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        return;
    }
    @mkdir(dirname(CAPAS_ARQ), 0755, true);
    $tmp = CAPAS_ARQ . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $json) !== false && !@rename($tmp, CAPAS_ARQ)) {
        @unlink($tmp);
    }
}

function capas_token(array $igdb): ?string
{
    $cache = capas_ler() ?? [];
    if (!empty($cache['_token']) && ($cache['_token_ate'] ?? 0) > time() + 60) {
        return $cache['_token'];
    }
    $r = capas_post('https://id.twitch.tv/oauth2/token?' . http_build_query([
        'client_id' => $igdb['client_id'], 'client_secret' => $igdb['client_secret'], 'grant_type' => 'client_credentials',
    ]), '');
    if (empty($r['access_token'])) {
        return null;
    }
    $token = $r['access_token'];
    $ate = time() + (int) ($r['expires_in'] ?? 3600);
    capas_gravar(function (array &$c) use ($token, $ate) {
        $c['_token'] = $token;
        $c['_token_ate'] = $ate;
    });
    return $token;
}

function capas_chave(string $nome): string
{
    return mb_strtolower(trim(preg_replace('~\s+~u', ' ', preg_replace('~[™®©]~u', '', $nome))));
}

// Devolve [nome do jogo => url da capa ('' se não achou)]. Busca no IGDB só o que ainda não está no cache.
function capas_buscar(array $nomes): array
{
    global $config;
    $igdb = $config['igdb'] ?? [];
    $out = [];
    if (empty($igdb['client_id']) || empty($igdb['client_secret']) || !function_exists('curl_init')) {
        return $out;
    }
    $cache = capas_ler() ?? [];
    $falta = [];
    foreach (array_unique($nomes) as $n) {
        $k = capas_chave($n);
        if (isset($cache['c'][$k]) && ($cache['c'][$k]['url'] !== '' || ($cache['v'] ?? 0) >= 2 && $cache['c'][$k]['em'] > time() - 86400)) {
            $out[$n] = $cache['c'][$k]['url'];
        } else {
            $falta[$n] = $k;
        }
    }
    if (!$falta || !($token = capas_token($igdb))) {
        return $out;
    }
    $cab = ['Client-ID: ' . $igdb['client_id'], 'Authorization: Bearer ' . $token, 'Accept: application/json'];
    @set_time_limit(90);
    $inicio = time();
    $novos = [];
    foreach (array_slice($falta, 0, CAPAS_MAX_POR_ENVIO, true) as $n => $k) {
        if (time() - $inicio > CAPAS_ORCAMENTO) {
            break;
        }
        $busca = trim(preg_replace('~[™®©"]|\s*[-–]\s*Nintendo Switch\s*$~u', '', str_replace(chr(92), '', $n)));
        $tentativas = [$busca];
        $curto = trim(preg_replace('~\s*[\(\[].*$~u', '', preg_replace('~\s*[:–-]\s*[^:–-]*(Edition|Collection|Bundle|Pack|Deluxe|Complete).*$~iu', '', $busca)));
        if ($curto !== '' && $curto !== $busca) {
            $tentativas[] = $curto;
        }
        $r = [];
        foreach ($tentativas as $t) {
            $r = capas_post('https://api.igdb.com/v4/games', 'search "' . $t . '"; fields name,cover.image_id; where cover != null; limit 5;', $cab);
            if ($r === null || $r) {
                break;
            }
            usleep(280000);
        }
        if ($r === null) {
            break;                                 // falha de rede/limite: para e tenta de novo no próximo envio
        }
        $id = '';
        foreach ($r as $g) {                       // prefere o nome igual; senão o primeiro com capa
            $id = $id !== '' ? $id : ($g['cover']['image_id'] ?? '');
            if (capas_chave($g['name'] ?? '') === $k && !empty($g['cover']['image_id'])) {
                $id = $g['cover']['image_id'];
                break;
            }
        }
        $novos[$k] = ['url' => $id !== '' ? 'https://images.igdb.com/igdb/image/upload/t_cover_big/' . $id . '.jpg' : '', 'em' => time()];
        $out[$n] = $novos[$k]['url'];
        usleep(280000);                            // IGDB aceita ~4 pedidos por segundo
    }
    if ($novos) {
        capas_gravar(function (array &$c) use ($novos) {
            $c['v'] = 2;                           // versão 2: "não achei" de versões antigas é ignorado
            foreach ($novos as $k => $v) {
                $c['c'][$k] = $v;
            }
        });
    }
    return $out;
}
