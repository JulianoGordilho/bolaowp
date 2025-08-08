<?php
if (!defined('ABSPATH')) exit;

/**
 * Compat: mapeia status canônicos para variantes no DB (sem DDL).
 * Canônicos: pending | approved | rejected
 * Variantes legadas: pendente | aprovada | reprovada
 */

if (!function_exists('wpfp_status_enum_set')) {
    function wpfp_status_enum_set() {
        static $cache = null;
        if ($cache !== null) return $cache;

        global $wpdb;
        $table = $wpdb->prefix . 'pool_apostas';

        // Tenta pegar do cache (option) para evitar SHOW COLUMNS sempre
        $opt_key = 'wpfp_status_enum_set_' . $wpdb->prefix;
        $cached = get_option($opt_key);
        if (is_array($cached)) {
            $cache = $cached;
            return $cache;
        }

        $row = $wpdb->get_row("SHOW COLUMNS FROM {$table} LIKE 'status'");
        $set = [];
        if ($row && !empty($row->Type) && stripos($row->Type, 'enum(') === 0) {
            if (preg_match_all("/'([^']+)'/", $row->Type, $m)) {
                $set = array_map('strval', $m[1]);
            }
        }

        // Fallback se não achou ENUM (ou tabela não existe ainda)
        if (!$set) {
            $set = ['pending','aprovado','rejeitado','pendente','aprovada','reprovada'];
        }

        update_option($opt_key, $set, false);
        $cache = $set;
        return $cache;
    }
}

if (!function_exists('wpfp_status_variants')) {
    function wpfp_status_variants($canonical) {
        $map = [
            'pending'  => ['pending','pendente'],
            'approved' => ['aprovado','aprovada'],
            'rejected' => ['rejeitado','reprovada','rejeitada'], // aceita ambos termos pt
        ];
        return $map[$canonical] ?? [$canonical];
    }
}

if (!function_exists('wpfp_status_db')) {
    /**
     * Retorna o rótulo ideal para gravar no DB, com base no ENUM existente.
     * Ex: wpfp_status_db('pending') => 'pending' OU 'pendente'
     */
    function wpfp_status_db($canonical) {
        $enum = wpfp_status_enum_set();
        $variants = wpfp_status_variants($canonical);
        foreach ($variants as $v) {
            if (in_array($v, $enum, true)) return $v;
        }
        // fallback: usa o primeiro variant (controlado por nós)
        return $variants[0];
    }
}

if (!function_exists('wpfp_status_in_clause')) {
    /**
     * Gera uma clausula IN com variantes presentes no ENUM.
     * Ex: wpfp_status_in_clause(['pending','approved']) => "('pending','aprovado','aprovada', ...)"
     */
    function wpfp_status_in_clause(array $canonicals) {
        $enum = wpfp_status_enum_set();
        $values = [];
        foreach ($canonicals as $c) {
            foreach (wpfp_status_variants($c) as $v) {
                if (in_array($v, $enum, true)) $values[] = $v;
            }
        }
        if (!$values) $values = $canonicals; // fallback
        $values = array_unique($values);
        global $wpdb;
        $quoted = array_map(function($s){ return "'" . esc_sql($s) . "'"; }, $values);
        return '(' . implode(',', $quoted) . ')';
    }
}

if (!function_exists('wpfp_status_canonical')) {
    /**
     * Normaliza um valor vindo do banco para o canônico.
     */
    function wpfp_status_canonical($db_value) {
        $db_value = strtolower((string)$db_value);
        if (in_array($db_value, ['pending','pendente'], true)) return 'pending';
        if (in_array($db_value, ['aprovado','aprovada'], true)) return 'approved';
        if (in_array($db_value, ['rejeitado','reprovada','rejeitada'], true)) return 'rejected';
        return $db_value;
    }
}
