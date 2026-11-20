<?php
// Lista de desejos da eShop da Nintendo, a partir do texto copiado da página da lista (link de compartilhamento).
// Cada jogo vem assim:
//   * [Nome 19/11/26](https://www.nintendo.com/pt-br/store/products/slug/)
//   Current Price:R$ 110,49
//   ~~Regular Price:R$ 129,99~~
//   -15%
// Sem promoção só há "Regular Price"; jogo sem preço (ainda não lançado) não tem linha de preço.

const NINTENDO_ID_BASE = 5000000000;   // ids sintéticos, acima de qualquer appid da Steam

// Devolve os jogos no mesmo formato das listas da Steam (appid, nome, preco, centavos, ...).
function nintendo_ler(string $texto): array
{
    $jogos = [];
    $atual = null;
    $fechar = function () use (&$jogos, &$atual) {
        if ($atual !== null) {
            $jogos[$atual['appid']] = $atual;
        }
        $atual = null;
    };
    foreach (preg_split('~\R~u', $texto) as $linha) {
        $linha = trim($linha);
        if (preg_match('~^[*\-]\s*\[(.+)\]\((https?://(?:www\.)?nintendo\.com/[^)\s]+)\)$~u', $linha, $m)) {
            $fechar();
            $nome = trim(preg_replace('~\s*(?:\d{2}/\d{2}/\d{2}|Coming\s+\d{4}|Em breve.*)$~u', '', $m[1]));
            $slug = basename(rtrim(parse_url($m[2], PHP_URL_PATH) ?: '', '/'));
            if ($nome === '' || $slug === '') {
                continue;
            }
            $atual = [
                'appid'          => NINTENDO_ID_BASE + crc32($slug),
                'nome'           => mb_substr($nome, 0, 150),
                'ordem'          => count($jogos),
                'preco'          => 'Sem preço',
                'centavos'       => -1,
                'desconto'       => 0,
                'preco_original' => '',
                'promo_ate'      => 0,
                'url'            => 'https://www.nintendo.com/pt-br/store/products/' . $slug . '/',
                'capa'           => '',
                'loja'           => 'nintendo',
            ];
        } elseif ($atual !== null) {
            if (preg_match('~Current Price:\s*(R\$\s*[\d.,]+)~u', $linha, $m)) {
                $atual['preco'] = preg_replace('~\s+~u', ' ', $m[1]);
                $atual['centavos'] = valor_centavos($m[1]) ?? -1;
                $atual['_atual'] = true;
            } elseif (preg_match('~Regular Price:\s*(R\$\s*[\d.,]+)~u', $linha, $m)) {
                if (!empty($atual['_atual'])) {
                    $atual['preco_original'] = preg_replace('~\s+~u', ' ', $m[1]);
                } else {
                    $atual['preco'] = preg_replace('~\s+~u', ' ', $m[1]);
                    $atual['centavos'] = valor_centavos($m[1]) ?? -1;
                }
            } elseif (preg_match('~^-(\d{1,3})%$~', $linha, $m)) {
                $atual['desconto'] = (int) $m[1];
            }
        }
    }
    $fechar();
    foreach ($jogos as &$j) {
        unset($j['_atual']);
        if ($j['preco_original'] === '') {
            $j['desconto'] = 0;
        }
    }
    unset($j);
    return array_slice(array_values($jogos), 0, 500);
}
