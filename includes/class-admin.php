<?php
class WPFP_Admin {
    public function __construct() {
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_notices', [$this, 'maybe_show_success_message']);
    }

    public function register_admin_menu() {
        add_menu_page(
            'Football Pool',
            'Football Pool',
            'manage_options',
            'wpfp-settings',
            [$this, 'settings_page'],
            'dashicons-admin-generic'
        );
    }

    public function register_settings() {
        register_setting('wpfp_settings_group', 'wpfp_api_key');
        register_setting('wpfp_settings_group', 'wpfp_api_host');

        // Seção para estrutura futura
        add_settings_section(
            'wpfp_main_section',
            '', // Título (vazio por enquanto)
            function () {
                // Callback vazio, pode ser expandido no futuro
            },
            'wpfp_settings_group'
        );
    }

    public function enqueue_admin_assets($hook) {
        // Verifica se estamos na tela correta
        if ($hook !== 'toplevel_page_wpfp-settings') return;

        wp_enqueue_script(
            'wpfp-admin',
            WPFPOOL_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            '1.0',
            true
        );

        wp_localize_script('wpfp-admin', 'wpfp_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('wpfp_test_api_nonce')
        ]);
    }

    public function maybe_show_success_message() {
        if (!isset($_GET['settings-updated']) || $_GET['settings-updated'] !== 'true') return;

        add_settings_error(
            'wpfp_settings_messages',
            'wpfp_settings_saved',
            '✅ API configurada com sucesso! Agora você pode criar seus Pools de Apostas 🎯',
            'updated'
        );
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>Configuração da API-Football</h1>

            <?php settings_errors(); ?>

            <form method="post" action="options.php">
                <?php
                    settings_fields('wpfp_settings_group');
                    do_settings_sections('wpfp_settings_group');
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">API Key</th>
                        <td>
                            <input type="text" name="wpfp_api_key" value="<?php echo esc_attr(get_option('wpfp_api_key')); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">API Host</th>
                        <td>
                            <input type="text" name="wpfp_api_host" value="<?php echo esc_attr(get_option('wpfp_api_host')); ?>" class="regular-text" />
                            <p class="description">Use <code>v3.football.api-sports.io</code></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Salvar Configurações'); ?>

                <div style="margin-top: 20px;">
                    <button id="wpfp-test-api" class="button button-secondary">Testar Conexão com a API</button>
                    <span id="wpfp-api-status" style="margin-left:10px;"></span>
                </div>
            </form>
        </div>
        <section>
            <h2>Próximos Passos</h2>
            <p>Agora que você configurou a API, você pode começar a criar seus Pools de Apostas. Use o menu <strong>"Pools de Apostas / Criar Pool de Apostas"</strong> para adicionar novos pools e gerenciar suas apostas.</p>
            <p>Para mais informações, consulte a <a href="https://www.api-football.com/documentation-v3">documentação da API-Football</a>.</p>

            <h2>Shortcodes Disponíveis</h2>
            <ul>
                <li><code>[wpfp_standings]</code> - Exibe a tabela de classificação</li>
                <li><code>[wpfp_fixtures]</code> - Exibe os próximos jogos</li>
                <li><code>[wpfp_results]</code> - Exibe os resultados dos jogos</li>
                <li><code>[wpfp_bets]</code> - Exibe os pools de apostas</li>
                <li><code>[wpfp_bet_form]</code> - Formulário para enviar apostas</li>
                <li><code>[wpfp_user_bets]</code> - Exibe as apostas do usuário</li>
                <li><code>[wpfp_user_standings]</code> - Exibe a classificação do usuário</li>
                <li><code>[wpfp_user_results]</code> - Exibe os resultados das apostas do usuário</li>
                <li><code>[wpfp_user_fixtures]</code> - Exibe os jogos do usuário</li>
                <li><code>[wpfp_user_profile]</code> - Exibe o perfil do usuário</li>
                <li><code>[listar_todos_pools_cpt]</code> - Exibe todos os pools criados e seus ids</li>
                <li><code>[detalhes_pools_completos]</code> - Exibe todos os detalhes dos pools criados</li>
                <li><code>[banner_pool pool_id="123"]</code> - Exibe o banner do pool com ID 123</li>
            </ul>

            <h2>Templates Disponíveis</h2>
            <ul>
                <li>
                    <strong>Página de palpite - 
                        <code>pagina-palpite-pool.php</code>:
                    </strong>
                     <ul>
                        <ol>
                            <p><strong> Ao criar o Banner pelo shortcode <code>[banner_pool pool_id="123"]</code> a página de apostas é gerada automaticamente</strong></p>
                       
                            <ul>
                                <li>   * <strong>Exemplo:</strong> <code>https://seusite.com/pagina-aposta/?pool_id=123</code></li>
                                <li>   * Substitua "123" pelo ID do pool que você deseja exibir</li>
                            </ul>
                        </ol>
                    </ul>
                </li>
        <?php
    }
}
