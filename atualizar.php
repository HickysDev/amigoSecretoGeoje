<?php
// Atualiza preços e promoções das listas de desejos de todos os participantes.
// Rode uma vez por dia pelo cron da Hostinger (hPanel → Avançado → Cron Jobs), por exemplo às 6h:
//   /usr/bin/php /home/SEU_USUARIO/public_html/atualizar.php
// Só roda pela linha de comando; pelo navegador responde 404.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/app.php';

$pessoas = dados_ler()['participantes'];
$inicio = microtime(true);
$listas = listas_steam(array_column($pessoas, 'steamid'), true);
foreach ($pessoas as $p) {
    if (empty($p['steamid'])) {
        continue;
    }
    $jogos = $listas[$p['steamid']] ?? null;
    $promo = $jogos ? count(array_filter($jogos, function ($j) { return !empty($j['desconto']); })) : 0;
    echo $p['nome'], ': ', $jogos === null ? 'Steam não respondeu' : ($jogos ? count($jogos) . " jogos, $promo em promoção" : 'lista vazia ou privada'), "\n";
}
printf("Atualizado em %.1fs (%s)\n", microtime(true) - $inicio, date('d/m/Y H:i'));
