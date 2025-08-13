<?php
if (!defined('ABSPATH')) exit;

/**
 * Gera payload EMV PIX (BR Code) com CRC16.
 * Baseado no manual do BACEN: GUI = br.gov.bcb.pix, moeda 986, país BR.
 */
class WPFPPixEMV
{
    public static function buildPayload(string $pixKey, float $amount, string $merchantName, string $merchantCity, string $txid = ''): string
    {
        $pixKey       = trim($pixKey);
        $merchantName = self::normalize($merchantName ?: 'RECEBEDOR');
        $merchantCity = self::normalize($merchantCity ?: 'CIDADE');

        // Campo 26 - Merchant Account Information (GUI + chave)
        $mai = self::kv('00', 'br.gov.bcb.pix')
             . self::kv('01', $pixKey);
        // Se quiser descrição curta, usar ID 02 (até ~25 chars). Opcional:
        // $mai .= self::kv('02', self::normalize($descricaoCurta));

        // Payload base
        $payload  = self::kv('00', '01');                   // Payload Format Indicator
        // $payload .= self::kv('01', '12');                // Point of Initiation Method: 12 = dinâmico (opcional). Sem = estático.
        $payload .= self::kv('26', $mai);                   // Merchant Account Information
        $payload .= self::kv('52', '0000');                 // Merchant Category Code (0000 = não definido)
        $payload .= self::kv('53', '986');                  // Transaction Currency (986 = BRL)
        if ($amount > 0) {
            $payload .= self::kv('54', self::formatAmount($amount)); // Amount
        }
        $payload .= self::kv('58', 'BR');                   // Country Code
        $payload .= self::kv('59', mb_substr($merchantName, 0, 25)); // Merchant Name (max 25)
        $payload .= self::kv('60', mb_substr($merchantCity, 0, 15)); // Merchant City (max 15)

        // Additional Data Field Template (62) -> TXID (05)
        $txid = $txid !== '' ? self::normalize($txid) : '***'; // '***' = sem TXID fixo
        $adf  = self::kv('05', mb_substr($txid, 0, 35));
        $payload .= self::kv('62', $adf);

        // CRC16 (63)
        $payload .= '6304' . self::crc16($payload . '6304');

        return $payload;
    }

    /** Key-Value com tamanho (ID + LEN + VAL) */
    private static function kv(string $id, string $value): string
    {
        $len = strlen($value);
        return $id . str_pad((string)$len, 2, '0', STR_PAD_LEFT) . $value;
    }

    /** Formato do valor com ponto como separador e 2 casas */
    private static function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /** Normaliza (remove acentos, mantém maiúsculas A-Z 0-9 espaço e pontuação básica) */
    private static function normalize(string $str): string
    {
        $str = wp_strip_all_tags($str);
        $str = html_entity_decode($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $str = iconv('UTF-8', 'ASCII//TRANSLIT', $str); // remove acentos
        $str = strtoupper($str);
        // Remove caracteres inválidos EMV (mantém básicos)
        $str = preg_replace('/[^A-Z0-9 \-\.\,\/\@\&\+]/', '', $str);
        $str = trim($str);
        return $str;
    }

    /** CRC16-CCITT (0x1021), inicial 0xFFFF */
    private static function crc16(string $payload): string
    {
        $polynomial = 0x1021;
        $crc = 0xFFFF;

        $bytes = unpack('C*', $payload);
        foreach ($bytes as $byte) {
            $crc ^= ($byte << 8);
            for ($bit = 0; $bit < 8; $bit++) {
                if (($crc & 0x8000) !== 0) {
                    $crc = (($crc << 1) ^ $polynomial) & 0xFFFF;
                } else {
                    $crc = ($crc << 1) & 0xFFFF;
                }
            }
        }
        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }
}
