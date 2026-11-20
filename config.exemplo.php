<?php
// Modelo de configuração. Copie este arquivo para config.php e preencha (o config.php não vai para o Git).
return [
    // Senha do painel do organizador (admin.php). Troque: com esta senha de exemplo, ou com
    // menos de 10 caracteres, o painel não abre.
    'senha_admin' => 'troque-esta-senha',

    // Endereço do site, com https:// e sem barra no fim. Obrigatório: é usado nos links dos e-mails.
    // Ex.: 'https://seudominio.com.br' ou 'https://seudominio.com.br/amigo-secreto'
    'url_site' => '',

    // E-mail que envia as mensagens. Na Hostinger: crie o e-mail em "E-mails" no hPanel.
    'smtp' => [
        'host'      => 'smtp.hostinger.com',
        'porta'     => 465,
        'seguranca' => 'ssl',        // 'ssl' (porta 465), 'tls' (porta 587) ou 'nenhuma'
        'usuario'   => 'amigosecreto@seudominio.com.br',
        'senha'     => 'senha-do-email',
        'remetente' => 'amigosecreto@seudominio.com.br',
        'nome'      => 'Amigo Secreto',
    ],
    // Capas dos jogos da eShop (IGDB). Crie um app em dev.twitch.tv/console e cole as chaves. Opcional.
    'igdb' => ['client_id' => '', 'client_secret' => ''],
];
