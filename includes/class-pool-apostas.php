<?php
class Pool_Apostas {
    public static function init() {
        register_activation_hook(WPFPOOL_PLUGIN_FILE, [self::class, 'criar_tabela']);
    }

    public static function criar_tabela() {
        global $wpdb;
        $table = $wpdb->prefix . 'pool_apostas';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            pool_id BIGINT(20) UNSIGNED NOT NULL,
            jogo_id BIGINT(20) UNSIGNED NOT NULL,
            gols_casa TINYINT,
            gols_fora TINYINT,
            status VARCHAR(20) DEFAULT 'ativo',
            data DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_aposta (user_id, pool_id, jogo_id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
}
