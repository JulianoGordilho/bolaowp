<?php
/**
 * Plugin Name: WP Football Pool
 * Description: Plugin de apostas com integração à API-Football.
 * Version: 1.0
 * Author: Zedasilva
 */

// 🔁 Ativa o uso de MOCK se dados da API não estiverem disponíveis nos shortcodes
if (!defined('WPFPOOL_USAR_MOCK_EM_FALHA_API')) {
    define('WPFPOOL_USAR_MOCK_EM_FALHA_API', false); // ← Altere para false para desligar o mock // true habilita todos os banners de mock
}

defined('ABSPATH') || exit;

// Constantes do plugin
define('WPFPOOL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPFPOOL_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WPFPOOL_PLUGIN_DISPLAY', plugin_dir_url(__FILE__));

// Includes
require_once WPFPOOL_PLUGIN_DIR . 'includes/class-admin.php';
require_once WPFPOOL_PLUGIN_DIR . 'includes/class-api-client.php';
require_once WPFPOOL_PLUGIN_DIR . 'includes/class-pool-manager.php';
require_once WPFPOOL_PLUGIN_DIR . 'includes/class-pool-display.php';
require_once WPFPOOL_PLUGIN_DIR . 'includes/shortcodes/classificacao-pool-shortcode.php';
require_once WPFPOOL_PLUGIN_DIR . 'includes/shortcode-meus-jogos.php';
require_once WPFPOOL_PLUGIN_DIR . 'includes/functions-palpites.php';
require_once WPFPOOL_PLUGIN_DIR . 'includes/class-pool-apostas.php';
require_once WPFPOOL_PLUGIN_DIR . 'includes/functions-api.php';

//IMAGE: Define o caminho para a imagem de fundo
if (!defined('WPFPOOL_PLUGIN_FILE')) {
    define('WPFPOOL_PLUGIN_FILE', __FILE__);
}

// Inicialização das classes
add_action('plugins_loaded', function () {
    new WPFP_Admin();
    new WPFP_Pool_Manager();
    new Pool_Display();
});

// AJAX - Testar conexão com API
add_action('wp_ajax_wpfp_test_api', function () {
    check_ajax_referer('wpfp_test_api_nonce', 'nonce');

    $client = new WPFP_API_Client();
    $response = $client->getLeagues();

    if (!$response || !isset($response['response'])) {
        wp_send_json_error('Não foi possível conectar à API. Verifique sua chave e host.');
    }

    wp_send_json_success();
});

//CSS palpite pool
add_action('wp_enqueue_scripts', function () {
    if (get_query_var('pagina_aposta') == '1') {
        wp_enqueue_style(
            'wpfp-palpite-pool-css',
            plugins_url('assets/css/palpite-pool.css', __FILE__),
            [],
            filemtime(plugin_dir_path(__FILE__) . 'assets/css/palpite-pool.css')
        );
    }
});



// 🔧 Criação automática das tabelas ao ativar o plugin
register_activation_hook(__FILE__, 'wpfpool_criar_tabelas');

function wpfpool_criar_tabelas() {
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();
    $prefix = $wpdb->prefix;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // Tabela de Pools
    $sql_pools = "CREATE TABLE {$prefix}pools (
        id INT AUTO_INCREMENT PRIMARY KEY,
        titulo VARCHAR(255) NOT NULL,
        liga VARCHAR(100),
        pais VARCHAR(100),
        data_limite DATETIME NOT NULL,
        valor_cota FLOAT NOT NULL,
        total_cotas INT DEFAULT 100,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) $charset_collate;";
    dbDelta($sql_pools);

    // Tabela de Apostas
    $sql_apostas = "CREATE TABLE {$prefix}pool_apostas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        pool_id INT NOT NULL,
        qtd_cotas INT DEFAULT 1,
        status ENUM('pendente', 'aprovada', 'reprovada') DEFAULT 'pendente',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (pool_id) REFERENCES {$prefix}pools(id) ON DELETE CASCADE
    ) $charset_collate;";
    dbDelta($sql_apostas);

    // Tabela de Palpites
    $sql_palpites = "CREATE TABLE {$prefix}pool_palpites (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        pool_id INT NOT NULL,
        fixture_id INT NOT NULL,
        palpite_home TINYINT,
        palpite_away TINYINT,
        pontos INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (pool_id) REFERENCES {$prefix}pools(id) ON DELETE CASCADE
    ) $charset_collate;";
    dbDelta($sql_palpites);
}

// 🧹 Remoção das tabelas ao desinstalar o plugin
register_uninstall_hook(__FILE__, 'wpfpool_remover_tabelas');

function wpfpool_remover_tabelas() {
    global $wpdb;

    $prefix = $wpdb->prefix;

    $wpdb->query("DROP TABLE IF EXISTS {$prefix}pool_apostas");
    $wpdb->query("DROP TABLE IF EXISTS {$prefix}pools");
    $wpdb->query("DROP TABLE IF EXISTS {$prefix}pool_palpites");
}

// 🚀 Rota personalizada para classificação de pools
add_action('init', function () {
    add_rewrite_rule('^classificacao-pool/?$', 'index.php?classificacao_pool=1', 'top');
    add_rewrite_tag('%classificacao_pool%', '([0-1])');
    add_rewrite_tag('%pool_id%', '([0-9]+)');
});

// Rota personalizada para a página de palpites
add_action('init', function () {
    add_rewrite_rule('^pagina-aposta/?$', 'index.php?pagina_aposta=1', 'top');
    add_rewrite_tag('%pagina_aposta%', '([0-1])');
    add_rewrite_tag('%pool_id%', '([0-9]+)');
});

add_action('template_include', function ($template) {
    if (get_query_var('pagina_aposta') == '1') {
        $custom = WPFPOOL_PLUGIN_DIR . 'templates/pagina-palpite-pool.php';
        if (file_exists($custom)) {
            return $custom;
        }
    }
    return $template;
});



// Permitir novas variáveis de query
add_filter('query_vars', function ($vars) {
    $vars[] = 'classificacao_pool';
    $vars[] = 'pool_id';
    return $vars;
});

// Template include para a página de classificação
add_action('template_include', function ($template) {
    if (get_query_var('classificacao_pool') == '1') {
        $custom = WPFPOOL_PLUGIN_DIR . 'templates/classificacao-pool.php';
        if (file_exists($custom)) {
            return $custom;
        }
    }
    return $template;
});

// Flush regras ao ativar/desativar plugin
register_activation_hook(__FILE__, function () {
    flush_rewrite_rules();
});
register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
});
