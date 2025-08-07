<?php
class WPFP_Pool_Manager {
    private $api;

    public function __construct() {
        $this->api = new WPFP_API_Client();

        add_action('init', [$this, 'register_cpt']);
        add_action('add_meta_boxes', [$this, 'register_metaboxes']);
        add_action('save_post', [$this, 'save_pool_data']);

        add_action('wp_ajax_wpfp_load_fixtures', [$this, 'ajax_load_fixtures']);
        add_action('wp_ajax_wpfp_leagues_with_games', [$this, 'ajax_leagues_with_games']);

        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

    public function enqueue_admin_assets($hook) {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && $screen->post_type !== 'wpfp_pool') return;

        if (defined('WPFP_USAR_MOCK_FIXTURES') && WPFP_USAR_MOCK_FIXTURES) {
            wp_enqueue_script(
                'wpfp-mock-fixtures',
                plugin_dir_url(__FILE__) . '../mock/wpfp-fixtures-mock.js',
                [],
                '1.0',
                true
            );
        }

        $base_url = plugin_dir_url(dirname(__FILE__));

        wp_enqueue_style(
            'wpfp-admin-css',
            $base_url . 'assets/css/wpfp-admin.css',
            [],
            '1.0'
        );

        wp_enqueue_script(
            'wpfp-admin-js',
            $base_url . 'assets/js/wpfp-admin.js',
            ['jquery'],
            '1.0',
            true
        );

        $post_id = isset($_GET['post']) ? intval($_GET['post']) : 0;

        wp_localize_script('wpfp-admin-js', 'wpfpData', [
            'apiKey'           => get_option('wpfp_api_key'),
            'apiHost'          => get_option('wpfp_api_host'),
            'selectedCountry'  => get_post_meta($post_id, '_wpfp_country', true),
            'selectedLeague'   => get_post_meta($post_id, '_wpfp_liga_brasileira', true),
            'selectedSeason'   => get_post_meta($post_id, '_wpfp_ano_temporada', true),
            'selectedGames'    => get_post_meta($post_id, '_wpfp_selected_games', true),
            'cancelledGames'   => get_post_meta($post_id, '_wpfp_jogos_cancelados', true),
        ]);
    }

    public function register_cpt() {
        register_post_type('wpfp_pool', [
            'labels' => [
                'name' => 'Pools de Apostas',
                'singular_name' => 'Pool de Aposta',
                'add_new_item' => 'Criar Pool de Aposta',
                'edit_item' => 'Editar Pool',
            ],
            'public' => false,
            'show_ui' => true,
            'menu_icon' => 'dashicons-awards',
            'supports' => ['title'],
            'menu_position' => 20,
        ]);
    }

    public function register_metaboxes() {
        add_meta_box(
            'wpfp_pool_data',
            '🎯 Dados do Pool de Aposta',
            [$this, 'render_metabox'],
            'wpfp_pool',
            'normal',
            'default'
        );
    }
    public function render_metabox($post) {
        $pontos_por_cota = get_post_meta($post->ID, '_wpfp_pontos_por_cota', true);
        $cotas_max = get_post_meta($post->ID, '_wpfp_cotas_max', true);
        $league_id = get_post_meta($post->ID, '_wpfp_liga_brasileira', true);
        $selected_country = get_post_meta($post->ID, '_wpfp_country', true);
        $selected_games = get_post_meta($post->ID, '_wpfp_selected_games', true);
        $cancelados = get_post_meta($post->ID, '_wpfp_jogos_cancelados', true);

        if (!is_array($selected_games)) $selected_games = [];
        if (!is_array($cancelados)) $cancelados = [];

        ?>
        <div id="wpfp-form-validator" style="color:red; margin: 12px 0;"></div>

        <div class="wpfp-field">
            <label><strong>País do Campeonato:</strong></label><br>
            <select name="wpfp_country" id="wpfp_country">
                <option value="">-- Escolha um país --</option>
                <option value="todos" <?php selected($selected_country, 'todos'); ?>>Todos</option>
            </select>
        </div>

        <div class="wpfp-field">
            <label><strong>Selecione um Campeonato (Liga):</strong></label><br>
            <select name="wpfp_liga_brasileira" id="wpfp_liga_brasileira" disabled>
                <option value="">-- Escolha o país primeiro --</option>
            </select>
        </div>

        <div class="wpfp-field">
            <label><strong>Selecione o Ano:</strong></label><br>
            <select name="wpfp_ano_temporada" id="wpfp_ano_temporada" disabled>
                <option value="">-- Escolha uma liga primeiro --</option>
            </select>
            <div id="wpfp_api_error" style="display:none;"></div>
        </div>

        <div id="wpfp-tabela-jogos" class="wpfp-field"></div>
        <div id="wpfp-jogos-selecionados" class="wpfp-field"></div>

        <div class="wpfp-field">
            <label><strong>Valor da cota:</strong></label><br>
            <input type="number" name="wpfp_pontos_por_cota" id="wpfp_pontos_por_cota"
                   value="<?php echo esc_attr($pontos_por_cota); ?>" required min="1" />
        </div>

        <div class="wpfp-field">
            <label><strong>Quantidade de Cotas para esse pool:</strong></label><br>
            <input type="number" name="wpfp_cotas_max" id="wpfp_cotas_max"
                   value="<?php echo esc_attr($cotas_max); ?>" required min="1" />
        </div>

        <div class="wpfp-info" id="wpfp-resultados-calc">
            <strong>📊 Premiações Calculadas:</strong>
            <ul style="margin-top:10px; list-style-type: square;">
                <li><strong>Jogos cancelados:</strong> <span id="qtd_jogos_cancelados">0</span></li>
                <li><strong>Pontuação total do jogador:</strong> <span id="pontuacao_total_jogador">-</span></li>
                <li><strong>Pontuação acumulada do pool (75%):</strong> <span id="pontuacao_pool">-</span></li>
                <li><strong>Premiação extra (3% + acumulado):</strong> <span id="premio_extra">-</span></li>
                <li><strong>Pontuação máxima da casa (22%):</strong> <span id="pontuacao_casa">-</span></li>
                <li><strong>🏆 Prêmio 1º lugar:</strong> <span id="premio_primeiro">-</span></li>
                <li><strong>🏆 Prêmio 1º lugar (com extra):</strong> <span id="premio_primeiro_extra">-</span></li>
                <li><strong>🥈 Prêmio 2º lugar:</strong> <span id="premio_segundo">-</span></li>
                <li><strong>Status:</strong> <span id="status_pool">-</span></li>
            </ul>
        </div>

        <div id="wpfp-form-missing-fields" class="wpfp-field" style="color:red;"></div>

        <div class="wpfp-field">
            <button type="button" class="button button-primary" id="wpfp_salvar" name="wpfp_salvar" value="1">💾 Salvar Pool</button>
            <button type="button" class="button" id="btn-limpar-campos">🧹 Limpar Campos</button>
            <button type="button" class="button" id="wpfp_export_json">⬇️ Exportar JSON</button>
            <button type="button" class="button" id="wpfp_export_csv">⬇️ Exportar CSV</button>
        </div>

        <?php
        // Insere os jogos selecionados como campos ocultos
        foreach ($selected_games as $id) {
            echo '<input type="hidden" name="wpfp_selected_games[]" value="' . esc_attr($id) . '">';
        }
        foreach ($cancelados as $id) {
            echo '<input type="hidden" name="wpfp_jogos_cancelados[]" value="' . esc_attr($id) . '">';
        }
    }
    public static function get_fixture_list_by_pool($pool_id) {
        $fixtures_raw = get_option("wpfp_pool_{$pool_id}_fixtures");

        if (empty($fixtures_raw) || !is_array($fixtures_raw)) {
            return [];
        }

        $fixtures = [];

        foreach ($fixtures_raw as $fixture) {
            $fixtures[] = [
                'id' => $fixture['id'],
                'datetime' => $fixture['datetime'],
                'home_team' => $fixture['home_team']['name'],
                'home_team_logo' => $fixture['home_team']['logo'],
                'away_team' => $fixture['away_team']['name'],
                'away_team_logo' => $fixture['away_team']['logo'],
                'rodada' => $fixture['rodada'] ?? '',
                'campeonato' => $fixture['campeonato'] ?? '',
            ];
        }

        return $fixtures;
    }

    public function save_pool_data($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        update_post_meta($post_id, '_wpfp_pontos_por_cota', intval($_POST['wpfp_pontos_por_cota'] ?? 0));
        update_post_meta($post_id, '_wpfp_cotas_max', intval($_POST['wpfp_cotas_max'] ?? 0));
        update_post_meta($post_id, '_wpfp_country', sanitize_text_field($_POST['wpfp_country'] ?? ''));
        update_post_meta($post_id, '_wpfp_liga_brasileira', intval($_POST['wpfp_liga_brasileira'] ?? 0));
        update_post_meta($post_id, '_wpfp_ano_temporada', intval($_POST['wpfp_ano_temporada'] ?? 0));

        $league_id = intval($_POST['wpfp_liga_brasileira'] ?? 0);
        $country = sanitize_text_field($_POST['wpfp_country'] ?? '');
        $season = intval($_POST['wpfp_ano_temporada'] ?? 0);

        if ($league_id && $season) {
            $api = new WPFP_API_Client();
            $fixtures = $api->getFixtures($league_id, $season, $country);

            if (!empty($fixtures)) {
                $datas = array_map(function ($f) {
                    return strtotime($f['fixture']['date']);
                }, $fixtures);

                sort($datas);
                $primeira_data = date('Y-m-d H:i:s', reset($datas));
                update_post_meta($post_id, '_wpfp_data_limite_cache', $primeira_data);
            }

            if (!empty($fixtures[0]['league']['name'])) {
                update_post_meta($post_id, '_wpfp_liga', sanitize_text_field($fixtures[0]['league']['name']));
            }

            if (!empty($fixtures[0]['league']['country'])) {
                update_post_meta($post_id, '_wpfp_pais', sanitize_text_field($fixtures[0]['league']['country']));
            }
        }

        if (!empty($_POST['wpfp_selected_games']) && is_array($_POST['wpfp_selected_games'])) {
            $games = array_slice(array_map('intval', $_POST['wpfp_selected_games']), 0, 10);
            update_post_meta($post_id, '_wpfp_selected_games', $games);
        } else {
            delete_post_meta($post_id, '_wpfp_selected_games');
        }

        if (!empty($_POST['wpfp_jogos_cancelados']) && is_array($_POST['wpfp_jogos_cancelados'])) {
            $cancelados = array_map('intval', $_POST['wpfp_jogos_cancelados']);
            update_post_meta($post_id, '_wpfp_jogos_cancelados', $cancelados);
        } else {
            delete_post_meta($post_id, '_wpfp_jogos_cancelados');
        }

        // ➕ Campos de premiação enviados via formulário oculto
        $campos_premio = [
            '_wpfp_pontuacao_total',
            '_wpfp_pontuacao_pool',
            '_wpfp_pontuacao_casa',
            '_wpfp_premio_extra',
            '_wpfp_premio_primeiro',
            '_wpfp_premio_primeiro_extra',
            '_wpfp_premio_segundo',
        ];

        foreach ($campos_premio as $campo) {
            $form_key = ltrim($campo, '_');
            if (isset($_POST[$form_key])) {
                update_post_meta($post_id, $campo, floatval(str_replace(',', '.', $_POST[$form_key])));
            }
        }

        // Salvar fixtures como transients
        if (!empty($_POST['wpfp_selected_games']) && is_array($_POST['wpfp_selected_games'])) {
            $api = new WPFP_API_Client();
            foreach ($_POST['wpfp_selected_games'] as $game_id) {
                $game_id = intval($game_id);
                if (false === get_transient("fixture_{$game_id}")) {
                    $fixture = $api->getFixtureById($game_id);
                    if (!empty($fixture)) {
                        set_transient("fixture_{$game_id}", $fixture, 12 * HOUR_IN_SECONDS);
                    }
                }
            }
        }

        // Publicar automaticamente se rascunho
        if (isset($_POST['wpfp_salvar']) && !defined('DOING_AJAX')) {
            $current_post = get_post($post_id);

            if ($current_post && $current_post->post_status === 'draft') {
                wp_update_post([
                    'ID'          => $post_id,
                    'post_status' => 'publish',
                ]);
            }

            // Reforçar liga/pais/data a partir do primeiro jogo
            if (!empty($_POST['wpfp_selected_games'])) {
                $api = new WPFP_API_Client();
                $fixture_id = intval($_POST['wpfp_selected_games'][0]);
                $fixture = $api->getFixtureById($fixture_id);

                if (!empty($fixture)) {
                    $liga = $fixture['league']['name'] ?? '';
                    $pais = $fixture['league']['country'] ?? '';
                    $data_limite = $fixture['fixture']['date'] ?? '';

                    update_post_meta($post_id, '_wpfp_liga', sanitize_text_field($liga));
                    update_post_meta($post_id, '_wpfp_pais', sanitize_text_field($pais));
                    update_post_meta($post_id, '_wpfp_data_limite_cache', sanitize_text_field($data_limite));
                }
            }

            wp_redirect(admin_url('edit.php?post_type=wpfp_pool'));
            exit;
        }
    }
} // ← Fecha a classe WPFP_Pool_Manager

// 🔐 Verificação de dependência
if (class_exists('WPFP_API_Client')) {
    new WPFP_Pool_Manager();
} else {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>❌ O plugin <strong>WP Football Pool API Client</strong> não está ativo. Por favor, ative-o para usar o gerenciador de pools.</p></div>';
    });
}
