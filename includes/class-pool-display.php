<?php
class Pool_Display {
    private $log_enabled = true;

    public function __construct() {
        add_shortcode('exibir_pool', [$this, 'render_pool_box']);
        add_shortcode('listar_pools', [$this, 'render_lista_de_pools']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_shortcode('listar_todos_pools', [$this, 'render_lista_todos_os_pools']);
        add_shortcode('listar_todos_pools_cpt', [$this, 'render_lista_completa_de_pools']);
        add_shortcode('detalhes_pools_completos', [$this, 'render_detalhes_pools_completos']);
        add_shortcode('banner_pool', [$this, 'render_banner_pool']);
    }

    public function enqueue_assets() {
        wp_enqueue_style(
            'wpfpool-pool-display-style',
            WPFPOOL_PLUGIN_URL . 'assets/css/pool-display.css',
            [],
            '1.0.0'
        );

        wp_enqueue_style(
            'wpfpool-banner-style',
            WPFPOOL_PLUGIN_URL . 'assets/css/banner-pool.css',
            [],
            '1.0.0'
        );
    }

    public function render_pool_box() {
        ob_start();

        $pool = $this->get_next_active_pool_from_cpt();

        if (!$pool) {
            echo '<div class="pool-box encerrado">Nenhum pool disponível no momento.</div>';
            return ob_get_clean();
        }

        $pool_id = $pool->ID;

        $titulo         = esc_html(get_the_title($pool_id));
        $liga           = esc_html(get_post_meta($pool_id, '_wpfp_liga_brasileira', true));
        $pais           = esc_html(get_post_meta($pool_id, '_wpfp_country', true));
        $valor_cota     = floatval(get_post_meta($pool_id, '_wpfp_pontos_por_cota', true));
        $total_cotas    = intval(get_post_meta($pool_id, '_wpfp_cotas_max', true));
        $selected_games = get_post_meta($pool_id, '_wpfp_selected_games', true);

        $data_limite = $this->get_data_limite_from_fixtures($selected_games);
        $data_limite_formatada = date('d/m/Y H:i', strtotime($data_limite));

        $cotas_usadas = $this->get_cotas_usadas($pool_id);
        $cotas_disponiveis = max($total_cotas - $cotas_usadas, 0);
        $valor_total = $cotas_usadas * $valor_cota;

        $pote_75      = $valor_total * 0.75;
        $premio_1     = $pote_75 * 0.7;
        $premio_2     = $pote_75 * 0.3;
        $super_premio = $valor_total * 0.3;

        $agora = current_time('timestamp');
        $limite_timestamp = strtotime($data_limite);
        $encerrado = ($cotas_disponiveis <= 0 || $agora > $limite_timestamp);
        if (defined('WPFPOOL_FORCAR_POOL_ABERTO') && WPFPOOL_FORCAR_POOL_ABERTO === true) {
            $encerrado = false;
        }
        ?>

        <div class="pool-box <?= $encerrado ? 'encerrado' : '' ?>">
            <h2><?= $titulo ?></h2>
            <h4><?= $liga ?> - <?= $pais ?></h4>
            <p><strong>Data limite:</strong> <?= $data_limite_formatada ?></p>
            <p><strong>Cotas disponíveis:</strong> <?= $cotas_disponiveis ?> de <?= $total_cotas ?></p>
            <p><strong>Valor por cota:</strong> R$ <?= number_format($valor_cota, 2, ',', '.') ?></p>

            <hr>
            <h4>🏆 Premiação</h4>
            <ul>
                <li>🥇 Primeiro lugar: R$ <?= number_format($premio_1, 2, ',', '.') ?></li>
                <li>🥈 Segundo lugar: R$ <?= number_format($premio_2, 2, ',', '.') ?></li>
                <li>🎯 Super prêmio (placares exatos): R$ <?= number_format($super_premio, 2, ',', '.') ?></li>
            </ul>

            <?php if (!$encerrado): ?>
                <a href="<?= $this->get_apostar_url($pool_id) ?>" class="botao-apostar">Fazer minha aposta</a>
            <?php else: ?>
                <div class="mensagem encerrado">Pool encerrado</div>
            <?php endif; ?>
        </div>

        <?php
        return ob_get_clean();
    }

    private function get_next_active_pool_from_cpt() {
        $agora = current_time('timestamp');

        $posts = get_posts([
            'post_type'      => 'wpfp_pool',
            'posts_per_page' => 10,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'ASC',
        ]);

        foreach ($posts as $post) {
            $selected_games = get_post_meta($post->ID, '_wpfp_selected_games', true);
            if (!is_array($selected_games)) continue;

            $data_limite = $this->get_data_limite_from_fixtures($selected_games);
            if (strtotime($data_limite) > $agora) {
                return $post;
            }
        }

        return null;
    }

   private function get_data_limite_from_fixtures($game_ids, $usar_menor_data = false) {
    if (!is_array($game_ids)) return current_time('mysql');

    $result = null;

    foreach ($game_ids as $id) {
        $game_data = get_transient("fixture_$id");

        if ($game_data && isset($game_data['fixture']['date'])) {
            $timestamp = strtotime($game_data['fixture']['date']);

            if (is_null($result)) {
                $result = $timestamp;
            } elseif ($usar_menor_data) {
                $result = min($result, $timestamp);
            } else {
                $result = max($result, $timestamp);
            }
        }
    }

    return $result ? date('Y-m-d H:i:s', $result) : current_time('mysql');
}


    private function get_cotas_usadas($pool_id) {
        global $wpdb;

        $total = $wpdb->get_var($wpdb->prepare("
            SELECT SUM(qtd_cotas) FROM {$wpdb->prefix}pool_apostas
            WHERE pool_id = %d AND status != 'reprovada'
        ", $pool_id));

        return $total ? intval($total) : 0;
    }

    private function get_apostar_url($pool_id) {
        if (is_user_logged_in()) {
            return site_url("/pagina-aposta/?pool_id=" . $pool_id);
        } else {
            return wp_login_url(site_url("/pagina-aposta/?pool_id=" . $pool_id));
        }
    }

    private function log($msg) {
        if (!$this->log_enabled) return;
        error_log('[Pool_Display] ' . $msg);
    }

    // Shortcode: listar todos os pools ativos
    public function render_lista_de_pools() {
        ob_start();

        $posts = get_posts([
            'post_type'      => 'wpfp_pool',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'ASC',
        ]);

        if (empty($posts)) {
            echo '<p>⚠️ Nenhum pool ativo encontrado.</p>';
            return ob_get_clean();
        }

        $agora = current_time('timestamp');
        $encontrou_ativo = false;

        echo '<div class="lista-pools">';
        echo '<h2>📋 Pools Ativos</h2>';
        echo '<table class="wp-list-table widefat striped">';
        echo '<thead>
                <tr>
                    <th>ID</th>
                    <th>Título</th>
                    <th>Liga</th>
                    <th>País</th>
                    <th>Data Limite</th>
                    <th>Status</th>
                </tr>
            </thead>';
        echo '<tbody>';

        foreach ($posts as $post) {
            $id               = $post->ID;
            $titulo           = esc_html(get_the_title($id));
            $liga             = esc_html(get_post_meta($id, '_wpfp_liga_brasileira', true));
            $pais             = esc_html(get_post_meta($id, '_wpfp_country', true));
            $selected_games   = get_post_meta($id, '_wpfp_selected_games', true);
            $data_limite_raw  = $this->get_data_limite_from_fixtures($selected_games);
            $limite_timestamp = strtotime($data_limite_raw);

            if ($limite_timestamp <= $agora) continue; // Ignora pools já encerrados

            $encontrou_ativo = true;
            $data_limite_fmt = date('d/m/Y H:i', $limite_timestamp);
            $status          = 'Ativo';

            echo '<tr>';
            echo '<td>' . $id . '</td>';
            echo '<td>' . $titulo . '</td>';
            echo '<td>' . $liga . '</td>';
            echo '<td>' . $pais . '</td>';
            echo '<td>' . $data_limite_fmt . '</td>';
            echo '<td>🟢 ' . $status . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';

        if (!$encontrou_ativo) {
            echo '<p>⚠️ Nenhum pool ativo encontrado.</p>';
        }

        return ob_get_clean();
    }


    // Shortcode: listar todos os pools, inclusive encerrados
    public function render_lista_todos_os_pools() {
    ob_start();

    $posts = get_posts([
        'post_type'      => 'wpfp_pool',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'orderby'        => 'date',
        'order'          => 'DESC',
    ]);

    if (empty($posts)) {
        echo '<p>⚠️ Nenhum pool encontrado.</p>';
        return ob_get_clean();
    }

    echo '<div class="lista-pools-completa">';
    echo '<h2>📚 Histórico de Todos os Pools</h2>';
    echo '<table class="wp-list-table widefat striped">';
    echo '<thead>
            <tr>
                <th>ID</th>
                <th>Título</th>
                <th>Liga</th>
                <th>País</th>
                <th>Data Limite</th>
                <th>Status</th>
            </tr>
          </thead>';
    echo '<tbody>';

    $agora = current_time('timestamp');

    foreach ($posts as $post) {
        $id               = $post->ID;
        $titulo           = esc_html(get_the_title($id));
        $liga             = esc_html(get_post_meta($id, '_wpfp_liga_brasileira', true));
        $pais             = esc_html(get_post_meta($id, '_wpfp_country', true));
        $selected_games   = get_post_meta($id, '_wpfp_selected_games', true);
        $data_limite_raw  = $this->get_data_limite_from_fixtures($selected_games);
        $data_limite_fmt  = date('d/m/Y H:i', strtotime($data_limite_raw));
        $status           = (strtotime($data_limite_raw) < $agora) ? 'Encerrado' : 'Ativo';

        echo '<tr>';
        echo '<td>' . $id . '</td>';
        echo '<td>' . $titulo . '</td>';
        echo '<td>' . $liga . '</td>';
        echo '<td>' . $pais . '</td>';
        echo '<td>' . $data_limite_fmt . '</td>';
        echo '<td>' . ($status === 'Ativo' ? '🟢' : '🔴') . ' ' . $status . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';

    return ob_get_clean();
}

public function render_lista_completa_de_pools() {
    ob_start();

    $posts = get_posts([
        'post_type'      => 'wpfp_pool',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'orderby'        => 'date',
        'order'          => 'DESC',
    ]);

    if (empty($posts)) {
        echo '<p>⚠️ Nenhum pool encontrado.</p>';
        return ob_get_clean();
    }

    echo '<div class="lista-pools">';
    echo '<h2>📋 Todos os Pools (mesmo sem fixtures)</h2>';
    echo '<table class="wp-list-table widefat striped">';
    echo '<thead>
            <tr>
                <th>ID</th>
                <th>Título</th>
                <th>Liga</th>
                <th>País</th>
                <th>Data Limite</th>
                <th>Status</th>
            </tr>
          </thead>';
    echo '<tbody>';

    $agora = current_time('timestamp');

    foreach ($posts as $post) {
        $id             = $post->ID;
        $titulo         = esc_html(get_the_title($id));
        $liga           = esc_html(get_post_meta($id, '_wpfp_liga_brasileira', true));
        $pais           = esc_html(get_post_meta($id, '_wpfp_country', true));
        $selected_games = get_post_meta($id, '_wpfp_selected_games', true);

        $tem_fixture = is_array($selected_games) && count($selected_games) > 0;

        if ($tem_fixture) {
            $data_limite_raw = $this->get_data_limite_from_fixtures($selected_games);
            $data_limite_fmt = date('d/m/Y H:i', strtotime($data_limite_raw));
            $limite_timestamp = strtotime($data_limite_raw);
            $status = ($limite_timestamp > $agora) ? '🟢 Ativo' : '🔴 Encerrado';
        } else {
            $data_limite_fmt = '⚠️ Não definida';
            $status = '⚠️ Incompleto';
        }

        echo '<tr>';
        echo '<td>' . $id . '</td>';
        echo '<td>' . $titulo . '</td>';
        echo '<td>' . $liga . '</td>';
        echo '<td>' . $pais . '</td>';
        echo '<td>' . $data_limite_fmt . '</td>';
        echo '<td>' . $status . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';

    return ob_get_clean();
}


    private function usar_mock_ativo() {
        return defined('WPFPOOL_USAR_MOCK_EM_FALHA_API') && WPFPOOL_USAR_MOCK_EM_FALHA_API === true;
    }


    //Exibir pool detalhados

    public function render_detalhes_pools_completos() {
        ob_start();

        $pools = get_posts([
            'post_type'      => 'wpfp_pool',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);

        if (empty($pools)) {
            echo '<p>⚠️ Nenhum pool encontrado.</p>';
            return ob_get_clean();
        }

        echo '<div class="lista-detalhada-pools">';
        echo '<h2>📋 Detalhes Completos dos Pools</h2>';

        foreach ($pools as $pool) {
            $pool_id = $pool->ID;
            $titulo = esc_html($pool->post_title);
            $liga = esc_html(get_post_meta($pool_id, '_wpfp_liga_brasileira', true));
            $pais = esc_html(get_post_meta($pool_id, '_wpfp_country', true));
            $valor_cota = floatval(get_post_meta($pool_id, '_wpfp_pontos_por_cota', true));
            $total_cotas = intval(get_post_meta($pool_id, '_wpfp_cotas_max', true));
            $selected_games = get_post_meta($pool_id, '_wpfp_selected_games', true) ?: [];
            $cancelados = get_post_meta($pool_id, '_wpfp_jogos_cancelados', true) ?: [];

            $data_limite = $this->get_data_limite_from_fixtures($selected_games);
            $data_limite_formatada = date('d/m/Y H:i', strtotime($data_limite));
            $agora = current_time('timestamp');
            $encerrado = strtotime($data_limite) <= $agora;
            if (defined('WPFPOOL_FORCAR_POOL_ABERTO') && WPFPOOL_FORCAR_POOL_ABERTO === true) {
                $encerrado = false;
            }

            $cotas_usadas = $this->get_cotas_usadas($pool_id);
            $cotas_disponiveis = max($total_cotas - $cotas_usadas, 0);
            $valor_total = $cotas_usadas * $valor_cota;

            $pote_75 = $valor_total * 0.75;
            $premio_1 = $pote_75 * 0.7;
            $premio_2 = $pote_75 * 0.3;
            $super_premio = $valor_total * 0.3;

            // Jogadores únicos (futuramente poderemos usar DISTINCT user_id)
            $num_apostadores = $this->get_num_apostadores($pool_id);

            echo "<div class='pool-detalhado-box'>";
            echo "<h3>🏟️ {$titulo}</h3>";
            echo "<p><strong>Liga:</strong> {$liga} - {$pais}</p>";
            echo "<p><strong>Data Limite:</strong> {$data_limite_formatada}</p>";
            echo "<p><strong>Valor da Cota:</strong> R$ " . number_format($valor_cota, 2, ',', '.') . "</p>";
            echo "<p><strong>Quantidade de Cotas:</strong> {$total_cotas}</p>";
            echo "<p><strong>Pessoas que aderiram:</strong> {$num_apostadores}</p>";
            echo "<hr>";

            echo "<h4>🎯 Premiações Calculadas</h4>";
            echo "<li style='list-style: square; margin-left: 20px;'>";
            echo "<li><strong>Jogos cancelados:</strong> " . count($cancelados) . "</li>";
            echo "<li><strong>Pontuação total do jogador:</strong> " . ($cotas_usadas * $valor_cota) . " pontos</li>";
            echo "<li><strong>Pontuação acumulada do pool (75%):</strong> R$ " . number_format($pote_75, 2, ',', '.') . "</li>";
            echo "<li><strong>Premiação extra (30%):</strong> R$ " . number_format($super_premio, 2, ',', '.') . "</li>";
            echo "<li><strong>Pontuação máxima da casa (25%):</strong> R$ " . number_format($valor_total * 0.25, 2, ',', '.') . "</li>";
            echo "<li>🏆 <strong>Prêmio 1º lugar:</strong> R$ " . number_format($premio_1, 2, ',', '.') . "</li>";
            echo "<li>🏆 <strong>Prêmio 1º lugar (com extra*):</strong> R$ " . number_format($premio_1 + $super_premio, 2, ',', '.') . "</li>";
            echo "<li>🥈 <strong>Prêmio 2º lugar:</strong> R$ " . number_format($premio_2, 2, ',', '.') . "</li>";
            echo "<li style='font-size: 8px; font-style: italic;'>Prêmio extra caso o apostador acerte a pontuação exata de todos os 10 jogos.</li>";

            // Exibir jogos selecionados
        echo "<h4>📅 Jogos Selecionados</h4>";
    if (!empty($selected_games)) {
        $cancelados = get_post_meta($pool_id, '_wpfp_jogos_cancelados', true);
        if (!is_array($cancelados)) $cancelados = [];

        echo "<table style='width:100%; border-collapse: collapse; margin-top:10px; font-size: 12px;'>";
        echo "<thead><tr >
            <th style='border-bottom:1px solid #ccc;'>#</th>
            <th style='border-bottom:1px solid #ccc;'>Data</th>
            <th style='border-bottom:1px solid #ccc;'>Partida</th>
            <th style='border-bottom:1px solid #ccc;'>Placar</th>
            <th style='border-bottom:1px solid #ccc;'>Status</th>
        </tr></thead>";
        echo "<tbody>";

        foreach ($selected_games as $index => $game_id) {
           $fixture = null;
            if (!$this->usar_mock_ativo()) {
                $fixture = get_transient("fixture_{$game_id}");
            }

                   // 🔁 Se não existe e mock estiver ativado, buscar no mock
                    if (!$fixture && $this->usar_mock_ativo()) {
                        $mock_file = WPFPOOL_PLUGIN_DIR . 'mock/wpfp-fixtures-mock.js';
                        if (file_exists($mock_file)) {
                            $mock_json = file_get_contents($mock_file);
                            if (preg_match('/window\.mockFixturesResponse\s*=\s*(\{.*\});?/s', $mock_json, $matches)) {
                                $mock_data = json_decode($matches[1], true);
                                if (!empty($mock_data['response'])) {
                                    foreach ($mock_data['response'] as $mock) {
                                        if ((int) $mock['fixture']['id'] === (int) $game_id) {
                                            $fixture = $mock;
                                            break;
                                        }
                                    }
                                }
                            }
                        }
                    }


            // Se ainda não encontrou, pula para o próximo jogo

            if (!$fixture || empty($fixture['fixture'])) continue;

            $data_jogo = date('d/m/Y H:i', strtotime($fixture['fixture']['date']));
            $time_casa = $fixture['teams']['home']['name'] ?? 'Indefinido';
            $time_fora = $fixture['teams']['away']['name'] ?? 'Indefinido';
            $placar_casa = $fixture['goals']['home'] ?? '-';
            $placar_fora = $fixture['goals']['away'] ?? '-';

            $cancelado = in_array($game_id, $cancelados);
            $status = $cancelado ? '❌ Cancelado' : '✅ Válido';
            $row_style = $cancelado ? 'color: #999;' : '';

            // Opcional: link externo para o jogo (exemplo com fixture ID)
            $link_api = "https://www.api-football.com/documentation-v3#fixtures-fixtures-id";

            echo "<tr style='{$row_style}'>";
            echo "<td>#{$game_id}</td>";
            echo "<td>{$data_jogo}</td>";
        // echo "<td><a href='{$link_api}' target='_blank'>{$time_casa} x {$time_fora}</a></td>";
            echo "<td>{$time_casa} x {$time_fora}</td>";
            echo "<td>{$placar_casa} - {$placar_fora}</td>";
            echo "<td>{$status}</td>";
            echo "</tr>";
        }

        echo "</tbody></table>";
        } else {
            echo "<p>Nenhum jogo selecionado para este pool.</p>";
        }



            //En exibir jogos selecionados

            echo "</div><hr>";
    }

    echo '</div>';
    return ob_get_clean();
}

private function get_num_apostadores($pool_id) {
    global $wpdb;

    $sql = $wpdb->prepare("
        SELECT COUNT(DISTINCT user_id) 
        FROM {$wpdb->prefix}pool_apostas 
        WHERE pool_id = %d AND status != 'reprovada'
    ", $pool_id);

    return intval($wpdb->get_var($sql));
}

//Banner de publicidade
public function render_banner_pool($atts) {
    $atts = shortcode_atts([
        'pool_id' => 0,
    ], $atts);

    $pool_id = intval($atts['pool_id']);
    if (!$pool_id || get_post_type($pool_id) !== 'wpfp_pool') {
        return '<div class="banner-pool erro">❌ Pool inválido.</div>';
    }

    $titulo           = esc_html(get_the_title($pool_id));
    $liga             = esc_html(get_post_meta($pool_id, '_wpfp_liga_brasileira', true));
    $pais             = esc_html(get_post_meta($pool_id, '_wpfp_country', true));
    $valor_cota       = floatval(get_post_meta($pool_id, '_wpfp_pontos_por_cota', true));
    $total_cotas      = intval(get_post_meta($pool_id, '_wpfp_cotas_max', true));
    $selected_games   = get_post_meta($pool_id, '_wpfp_selected_games', true);
    if (!is_array($selected_games)) $selected_games = [];

    $cotas_aderidas = $this->get_cotas_usadas($pool_id);
    $pontuacao_total_jogador = count($selected_games) * 5;

    $acumulado = $cotas_aderidas * $valor_cota;
    $pontuacao_pool_75 = $acumulado * 0.75;
    $premio_extra = $acumulado * 0.30;
    $pontuacao_casa_25 = $acumulado * 0.25;
    $premio_primeiro = $pontuacao_pool_75 * 0.7;
    $premio_primeiro_com_extra = $acumulado * 0.70;
    $premio_segundo = $pontuacao_pool_75 * 0.3;

    // Data limite = menor data entre os jogos selecionados
    $data_limite = $this->get_data_limite_from_fixtures($selected_games, true);
    $data_limite_formatada = date('d/m/Y H:i', strtotime($data_limite));

    $agora = current_time('timestamp');
    $limite_timestamp = strtotime($data_limite);
    $encerrado = ($cotas_aderidas >= $total_cotas || $agora > $limite_timestamp);
        if (defined('WPFPOOL_FORCAR_POOL_ABERTO') && WPFPOOL_FORCAR_POOL_ABERTO === true) {
            $encerrado = false;
        }
    $status_texto = $encerrado ? '🔒 Esgotado' : '🟢 Disponível';

    ob_start();
    ?>

    <div class="banner-pool <?= $encerrado ? 'encerrado' : '' ?>" style="border:2px solid #0073aa; padding:20px; margin:20px 0; background:#f1f1f1;">
        <h2 style="color:#0073aa; font-size:24px; margin-bottom:10px;">🏆 <?= $titulo ?></h2>
        <p><strong>Liga:</strong> <?= $liga ?> | <strong>País:</strong> <?= $pais ?></p>
        <p><strong>Status:</strong> <?= $status_texto ?></p>
        <p><strong>Data limite para aposta:</strong> <?= $data_limite_formatada ?></p>
        <p><strong>Valor da Cota:</strong> R$ <?= number_format($valor_cota, 2, ',', '.') ?></p>
        <p><strong>Quantidade de Cotas para esse pool:</strong> <?= $total_cotas ?></p>
        <p><strong>Cotas aderidas:</strong> <?= $cotas_aderidas ?> </p>

        <h4 style="margin-top:20px;">🎯 <u>Premiações Calculadas</u></h4>
        <ul>
            <li>📅 Data limite: <strong><?= $data_limite_formatada ?></strong></li>
            <li>💰💰 Essa aposta pode atingir a prêmiação máxima de: <strong>R$ <?= number_format(($total_cotas * $valor_cota)*0.75, 2, ',', '.') ?></strong></li>
            <li>📈 Pontuação total do jogador: <strong><?= $pontuacao_total_jogador ?> pontos</strong></li>
            <li>💰 Pontuação acumulada do pool: <strong>R$ <?= number_format($pontuacao_pool_75, 2, ',', '.') ?></strong></li>
            <li>🎁 Premiação extra: <strong>R$ <?= number_format($premio_extra, 2, ',', '.') ?></strong></li>
           <!-- <li>🏠 Pontuação máxima da casa: <strong>R$ <?= number_format($pontuacao_casa_25, 2, ',', '.') ?></strong></li> -->
            <li>🥇 Prêmio 1º lugar: <strong>R$ <?= number_format($premio_primeiro, 2, ',', '.') ?></strong></li>
            <li>🥇 Prêmio 1º lugar (com extra): <strong>R$ <?= number_format($premio_primeiro_com_extra, 2, ',', '.') ?></strong></li>
            <li>🥈 Prêmio 2º lugar: <strong>R$ <?= number_format($premio_segundo, 2, ',', '.') ?></strong></li>
        </ul>

        <?php if (!$encerrado): ?>
            <a href="<?= $this->get_apostar_url($pool_id) ?>" class="botao-apostar" style="margin-top:15px; display:inline-block; background:#0073aa; color:#fff; padding:10px 20px; text-decoration:none; border-radius:5px;">🔥 Apostar agora</a>
        <?php else: ?>
            <p style="color:red; font-weight:bold;">⚠️ Apostas encerradas para esse pool.</p>
        <?php endif; ?>
    </div>

    <?php
    return ob_get_clean();
}





}
