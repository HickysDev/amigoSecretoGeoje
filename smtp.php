<?php
// Cliente SMTP mínimo (SSL/STARTTLS + AUTH LOGIN), sem dependências.

class Smtp
{
    private $sock;

    public function __construct(array $c)
    {
        $host = ($c['seguranca'] === 'ssl' ? 'ssl://' : 'tcp://') . $c['host'] . ':' . $c['porta'];
        $this->sock = @stream_socket_client($host, $errno, $errstr, 30);
        if (!$this->sock) {
            throw new RuntimeException("Não foi possível conectar ao servidor de e-mail: $errstr");
        }
        stream_set_timeout($this->sock, 30);
        $this->esperar(220);
        $this->comando('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'), 250);
        if ($c['seguranca'] === 'tls') {
            $this->comando('STARTTLS', 220);
            if (!stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Falha ao iniciar TLS com o servidor de e-mail.');
            }
            $this->comando('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'), 250);
        }
        $this->comando('AUTH LOGIN', 334);
        $this->comando(base64_encode($c['usuario']), 334);
        $this->comando(base64_encode($c['senha']), 235, 'Usuário ou senha do e-mail recusados.');
    }

    public function enviar(string $de, string $para, string $mensagem): void
    {
        $this->comando("MAIL FROM:<$de>", 250);
        $this->comando("RCPT TO:<$para>", [250, 251], "Destinatário recusado: $para");
        $this->comando('DATA', 354);
        // Linhas começando com "." precisam ser duplicadas (RFC 5321)
        $mensagem = preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], ["\n", "\r\n"], $mensagem));
        $this->comando($mensagem . "\r\n.", 250, "Falha ao enviar para $para");
    }

    public function fechar(): void
    {
        if ($this->sock) {
            @fwrite($this->sock, "QUIT\r\n");
            fclose($this->sock);
            $this->sock = null;
        }
    }

    private function comando(string $linha, $codigos, ?string $erro = null): void
    {
        fwrite($this->sock, $linha . "\r\n");
        $this->esperar($codigos, $erro);
    }

    private function esperar($codigos, ?string $erro = null): void
    {
        $resposta = '';
        while (($l = fgets($this->sock, 1024)) !== false) {
            $resposta .= $l;
            if (strlen($l) < 4 || $l[3] !== '-') {
                break;
            }
        }
        if (!in_array((int) substr($resposta, 0, 3), (array) $codigos, true)) {
            throw new RuntimeException(($erro ?? 'Erro do servidor de e-mail') . ' (' . trim($resposta) . ')');
        }
    }
}

function cabecalho_utf8(string $texto): string
{
    return '=?UTF-8?B?' . base64_encode($texto) . '?=';
}

// Monta uma mensagem MIME com versão em texto e em HTML.
function montar_mensagem(array $cfg, string $nome, string $email, string $assunto, string $texto, string $html): string
{
    $limite = 'b' . bin2hex(random_bytes(8));
    $dominio = substr(strrchr($cfg['remetente'], '@'), 1);
    return 'Date: ' . date('r') . "\n"
        . 'Message-ID: <' . bin2hex(random_bytes(12)) . "@$dominio>\n"
        . 'From: ' . cabecalho_utf8($cfg['nome']) . " <{$cfg['remetente']}>\n"
        . 'To: ' . cabecalho_utf8($nome) . " <$email>\n"
        . 'Subject: ' . cabecalho_utf8($assunto) . "\n"
        . "MIME-Version: 1.0\n"
        . "Content-Type: multipart/alternative; boundary=\"$limite\"\n\n"
        . "--$limite\nContent-Type: text/plain; charset=UTF-8\nContent-Transfer-Encoding: base64\n\n"
        . chunk_split(base64_encode($texto), 76, "\n")
        . "--$limite\nContent-Type: text/html; charset=UTF-8\nContent-Transfer-Encoding: base64\n\n"
        . chunk_split(base64_encode($html), 76, "\n")
        . "--$limite--\n";
}
