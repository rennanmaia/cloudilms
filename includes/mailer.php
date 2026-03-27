<?php
/**
 * CloudiLMS – Mailer
 *
 * Envia e-mails via SMTP autenticado (TLS/SSL) se configurado nas settings,
 * ou via mail() do PHP como fallback para ambientes de desenvolvimento.
 *
 * Uso:
 *   $mailer = new Mailer();
 *   $ok = $mailer->send('destino@email.com', 'Assunto', '<p>HTML…</p>');
 */
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';

class Mailer {

    private string $smtpHost;
    private int    $smtpPort;
    private string $smtpUser;
    private string $smtpPass;
    private string $smtpEnc;   // 'tls' | 'ssl' | 'none'
    private string $fromEmail;
    private string $fromName;

    public function __construct() {
        $db   = Database::getConnection();
        $rows = $db->query(
            "SELECT key_name, value FROM settings
             WHERE key_name IN ('smtp_host','smtp_port','smtp_encryption',
                                'smtp_user','smtp_pass','smtp_from_email','smtp_from_name')"
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        $this->smtpHost  = trim($rows['smtp_host']       ?? '');
        $this->smtpPort  = (int)($rows['smtp_port']      ?? 587);
        $this->smtpEnc   = trim($rows['smtp_encryption'] ?? 'tls');
        $this->smtpUser  = trim($rows['smtp_user']       ?? '');
        $this->smtpPass  = decryptValue($rows['smtp_pass'] ?? '');
        $this->fromEmail = trim($rows['smtp_from_email'] ?? '');
        $this->fromName  = trim($rows['smtp_from_name']  ?? APP_NAME);

        if (!$this->fromEmail) {
            $host            = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $this->fromEmail = 'noreply@' . preg_replace('/:\d+$/', '', $host);
        }
        if (!$this->fromName) {
            $this->fromName = APP_NAME;
        }
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Envia e-mail para $to com $subject e corpo HTML $htmlBody.
     * Retorna true em caso de sucesso, false em caso de falha.
     * Em falha registra o erro em error_log().
     */
    public function send(string $to, string $subject, string $htmlBody): bool {
        if ($this->smtpHost !== '') {
            return $this->sendSmtp($to, $subject, $htmlBody);
        }
        return $this->sendNative($to, $subject, $htmlBody);
    }

    // ── SMTP sender ───────────────────────────────────────────────────────────

    private function sendSmtp(string $to, string $subject, string $htmlBody): bool {
        $socketHost = ($this->smtpEnc === 'ssl')
            ? 'ssl://' . $this->smtpHost
            : $this->smtpHost;

        $errno = $errstr = '';
        $sock  = @stream_socket_client(
            $socketHost . ':' . $this->smtpPort,
            $errno, $errstr, 10,
            STREAM_CLIENT_CONNECT
        );
        if (!$sock) {
            error_log("Mailer: não foi possível conectar ao SMTP {$this->smtpHost}:{$this->smtpPort} – {$errstr}");
            return false;
        }

        stream_set_timeout($sock, 15);

        try {
            $this->smtpExpect($sock, 220); // greeting

            $ehlo = $this->serverName();
            $this->smtpCmd($sock, "EHLO {$ehlo}");
            $this->smtpExpect($sock, 250);

            // STARTTLS para porta 587
            if ($this->smtpEnc === 'tls') {
                $this->smtpCmd($sock, 'STARTTLS');
                $this->smtpExpect($sock, 220);
                if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('STARTTLS falhou.');
                }
                // Re-EHLO após TLS
                $this->smtpCmd($sock, "EHLO {$ehlo}");
                $this->smtpExpect($sock, 250);
            }

            // AUTH LOGIN
            if ($this->smtpUser !== '') {
                $this->smtpCmd($sock, 'AUTH LOGIN');
                $this->smtpExpect($sock, 334);
                $this->smtpCmd($sock, base64_encode($this->smtpUser));
                $this->smtpExpect($sock, 334);
                $this->smtpCmd($sock, base64_encode($this->smtpPass));
                $this->smtpExpect($sock, 235); // Authentication successful
            }

            $this->smtpCmd($sock, "MAIL FROM:<{$this->fromEmail}>");
            $this->smtpExpect($sock, 250);

            $this->smtpCmd($sock, "RCPT TO:<{$to}>");
            $this->smtpExpect($sock, 250);

            $this->smtpCmd($sock, 'DATA');
            $this->smtpExpect($sock, 354);

            fwrite($sock, $this->buildMessage($to, $subject, $htmlBody) . "\r\n.\r\n");
            $this->smtpExpect($sock, 250);

            $this->smtpCmd($sock, 'QUIT');
            fclose($sock);
            return true;

        } catch (RuntimeException $e) {
            error_log('Mailer SMTP error: ' . $e->getMessage());
            @fclose($sock);
            return false;
        }
    }

    private function smtpCmd($sock, string $cmd): void {
        fwrite($sock, $cmd . "\r\n");
    }

    /** Lê resposta SMTP multilinha e lança RuntimeException se código != $expected */
    private function smtpExpect($sock, int $expected): string {
        $response = '';
        while (($line = fgets($sock, 512)) !== false) {
            $response .= $line;
            // Linha final tem espaço na posição 3: "250 OK"
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        $code = (int)substr(trim($response), 0, 3);
        if ($code !== $expected) {
            throw new RuntimeException("SMTP esperava {$expected}, recebeu: " . trim($response));
        }
        return $response;
    }

    // ── Fallback: mail() ──────────────────────────────────────────────────────

    private function sendNative(string $to, string $subject, string $htmlBody): bool {
        $encoded = $this->encodeHeader($subject);
        $from    = $this->encodeHeader($this->fromName) . ' <' . $this->fromEmail . '>';
        $headers = implode("\r\n", [
            'From: '         . $from,
            'Reply-To: '     . $this->fromEmail,
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: quoted-printable',
            'X-Mailer: CloudiLMS',
        ]);
        $result = @mail($to, $encoded, quoted_printable_encode($htmlBody), $headers);
        if (!$result) {
            error_log("Mailer: mail() falhou para {$to}");
        }
        return $result;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function buildMessage(string $to, string $subject, string $htmlBody): string {
        $date    = date('r');
        $msgId   = '<' . bin2hex(random_bytes(16)) . '@' . $this->serverName() . '>';
        $subEnc  = $this->encodeHeader($subject);
        $fromEnc = $this->encodeHeader($this->fromName) . ' <' . $this->fromEmail . '>';

        return implode("\r\n", [
            "Date: {$date}",
            "Message-ID: {$msgId}",
            "From: {$fromEnc}",
            "To: {$to}",
            "Subject: {$subEnc}",
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: quoted-printable',
            'X-Mailer: CloudiLMS',
            '',
            quoted_printable_encode($htmlBody),
        ]);
    }

    private function encodeHeader(string $value): string {
        if (mb_detect_encoding($value, 'ASCII', true)) return $value;
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function serverName(): string {
        return preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    }
}
