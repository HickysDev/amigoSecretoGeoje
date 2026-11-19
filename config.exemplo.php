<?php
// Modelo de configuração. Copie este arquivo para config.php e preencha (o config.php não vai para o Git).
return [
    // Senha do painel do organizador (admin.php). Troque!
    'senha_admin' => 'troque-esta-senha',

    // Endereço do site, sem barra no fim. Obrigatório: é usado nos links dos e-mails.
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
];
