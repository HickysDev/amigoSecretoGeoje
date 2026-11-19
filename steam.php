<?php
// Lista de desejos da Steam pelas APIs públicas (sem chave). O perfil precisa ter os
// "Detalhes de jogos" públicos (Steam → Editar perfil → Privacidade) para a lista aparecer.

const STEAM_LOJA = 'https://store.steampowered.com/';
const STEAM_CDN  = 'https://shared.akamai.steamstatic.com/store_item_assets/';

function steam_get(string $url): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERAGENT      => 'AmigoSecreto/1.0',
        ]);
        $r = curl_exec($ch);
        return ($r !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) ? $r : null;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 15, 'user_agent' => 'AmigoSecreto/1.0']]);
    $r = @file_get_contents($url, false, $ctx);
    return $r === false ? null : $r;
}

// Aceita o link do perfil (steamcommunity.com/profiles/<id> ou /id/<nome>), o ID de 17 dígitos
// ou o nome personalizado. Qualquer outra coisa é recusada.
function steam_id(string $perfil): ?string
{
    $perfil = trim($perfil);
    $url = '~^(?:https?://)?(?:www\.)?steamcommunity\.com/(id|profiles)/([A-Za-z0-9_-]{2,64})/?$~';
    if (preg_match($url, $perfil, $m)) {
        [$tipo, $valor] = [$m[1], $m[2]];
    } elseif (preg_match('~^\d{17}$~', $perfil)) {
        [$tipo, $valor] = ['profiles', $perfil];
    } elseif (preg_match('~^[A-Za-z0-9_-]{2,32}$~', $perfil)) {
        [$tipo, $valor] = ['id', $perfil];
    } else {
        return null;
    }
    if ($tipo === 'profiles') {
        return preg_match('~^7656\d{13}$~', $valor) ? $valor : null;
    }
    $xml = steam_get('https://steamcommunity.com/id/' . rawurlencode($valor) . '/?xml=1');
    return ($xml && preg_match('~<steamID64>(7656\d{13})</steamID64>~', $xml, $id)) ? $id[1] : null;
}

// Foto do perfil (avatarFull do XML público). '' se não tiver; null se a Steam não respondeu.
function steam_avatar(string $steamid): ?string
{
    $xml = steam_get('https://steamcommunity.com/profiles/' . $steamid . '/?xml=1');
    if ($xml === null) {
        return null;
    }
    // Só aceita endereço do CDN de avatares da Steam
    return preg_match('~<avatarFull><!\[CDATA\[(https://avatars\.[a-z.]*steamstatic\.com/[\w.-]+\.jpg)\]\]></avatarFull>~', $xml, $m) ? $m[1] : '';
}

function steam_link_perfil(string $steamid): string
{
    return 'https://steamcommunity.com/profiles/' . $steamid . '/';
}

function steam_link_lista(string $steamid): string
{
    return STEAM_LOJA . 'wishlist/profiles/' . $steamid . '/';
}

// Todos os jogos da lista de desejos, na ordem de prioridade do dono:
// [['appid','nome','preco','url','capa'], ...]. Devolve null se a Steam não respondeu.
function steam_lista_desejos(string $steamid): ?array
{
    $r = steam_get('https://api.steampowered.com/IWishlistService/GetWishlist/v1/?steamid=' . $steamid);
    if ($r === null) {
        return null;
    }
    $itens = json_decode($r, true)['response']['items'] ?? [];
    // Prioridade 0 = sem posição definida: vai para o fim
    usort($itens, function ($a, $b) {
        return (($a['priority'] ?? 0) ?: PHP_INT_MAX) <=> (($b['priority'] ?? 0) ?: PHP_INT_MAX);
    });
    $ordem = array_map(function ($i) { return (int) $i['appid']; }, $itens);
    if (!$ordem) {
        return [];
    }
    return steam_itens($ordem);
}

// Nome, preço, promoção e capa dos jogos pedidos, na ordem recebida. Quem está sem preço na
// loja fica de fora. Devolve null se a Steam não respondeu.
function steam_itens(array $ordem): ?array
{
    // Detalhes da loja em lotes de 100 (a Steam aceita vários de uma vez)
    $por_appid = [];
    foreach (array_chunk($ordem, 100) as $lote) {
        $pedido = json_encode([
            'ids'          => array_map(function ($a) { return ['appid' => $a]; }, $lote),
            'context'      => ['language' => 'brazilian', 'country_code' => 'BR'],
            'data_request' => ['include_assets' => true],
        ]);
        $r = steam_get('https://api.steampowered.com/IStoreBrowseService/GetItems/v1/?input_json=' . rawurlencode($pedido));
        if ($r === null) {
            return null;
        }
        foreach (json_decode($r, true)['response']['store_items'] ?? [] as $j) {
            $por_appid[(int) ($j['appid'] ?? 0)] = $j;
        }
    }

    $jogos = [];
    foreach ($ordem as $appid) {
        $j = $por_appid[$appid] ?? null;
        if (!$j || empty($j['success']) || empty($j['name'])) {
            continue;
        }
        $capa = '';
        $a = $j['assets'] ?? [];
        if (!empty($a['header']) && !empty($a['asset_url_format'])
            && preg_match('~^[\w./?=${}-]+$~', $a['asset_url_format'] . $a['header'])) {
            $capa = STEAM_CDN . str_replace('${FILENAME}', $a['header'], $a['asset_url_format']);
        }
        $compra = $j['best_purchase_option'] ?? [];
        // Sem preço na loja (em geral ainda não lançado): não dá para dar de presente, então nem entra
        if (!isset($compra['final_price_in_cents']) && empty($j['is_free'])) {
            continue;
        }
        $fim = 0;
        foreach ($compra['active_discounts'] ?? [] as $desc) {
            $fim = $fim ? min($fim, (int) $desc['discount_end_date']) : (int) ($desc['discount_end_date'] ?? 0);
        }
        $desconto = (int) ($compra['discount_pct'] ?? 0);
        $jogos[] = [
            'appid'          => $appid,
            'nome'           => $j['name'],
            'ordem'          => count($jogos),   // posição na lista do dono
            'preco'          => $compra['formatted_final_price'] ?? (!empty($j['is_free']) ? 'Grátis' : 'Sem preço'),
            'centavos'       => isset($compra['final_price_in_cents']) ? (int) $compra['final_price_in_cents'] : (!empty($j['is_free']) ? 0 : -1),
            'desconto'       => $desconto,
            'preco_original' => $desconto ? ($compra['formatted_original_price'] ?? '') : '',
            'promo_ate'      => $desconto ? $fim : 0,
            'url'            => STEAM_LOJA . 'app/' . $appid . '/',
            'capa'           => $capa,
        ];
    }
    return $jogos;
}

// Busca na loja pelo nome, para os jogos fora da lista de desejos. Devolve os jogos no mesmo
// formato de steam_itens(), ou null se a Steam não respondeu.
function steam_buscar(string $termo, int $max = 8): ?array
{
    $r = steam_get(STEAM_LOJA . 'api/storesearch/?l=brazilian&cc=BR&term=' . rawurlencode($termo));
    if ($r === null) {
        return null;
    }
    $ids = [];
    foreach (json_decode($r, true)['items'] ?? [] as $i) {
        if (($i['type'] ?? '') === 'app' && (int) ($i['id'] ?? 0) > 0) {
            $ids[] = (int) $i['id'];
        }
    }
    $ids = array_slice(array_values(array_unique($ids)), 0, $max);
    return $ids ? steam_itens($ids) : [];
}
