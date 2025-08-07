<?php
class WPFP_Installer {
    public static function install() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $table_cotas = $wpdb->prefix . 'wpfp_cotas';
        $table_palpites = $wpdb->prefix . 'wpfp_palpites';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE $table_cotas (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            pool_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(20) DEFAULT 'pendente',
            cotas INT NOT NULL,
            total_pontos FLOAT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $charset_collate;");

        dbDelta("CREATE TABLE $table_palpites (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            cota_id BIGINT UNSIGNED NOT NULL,
            match_id BIGINT UNSIGNED NOT NULL,
            placar_casa INT,
            placar_fora INT,
            pontos INT DEFAULT 0
        ) $charset_collate;");
    }
}
