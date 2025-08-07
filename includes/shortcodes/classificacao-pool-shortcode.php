<?php
function wpfp_shortcode_classificacao_pool($atts) {
    if (!is_user_logged_in()) return '<p>⚠️ Você precisa estar logado para ver a classificação.</p>';

    global $wpdb;

    $atts = shortcode_atts([
        'pool_id' => 0
    ], $atts);

    $pool_id = intval($atts['pool_id']);
    if ($pool_id <= 0) return '<p>❌ ID do pool inválido.</p>';

    // Verificar jogos válidos com resultado
    $selected_games = get_post_meta($pool_id, '_wpfp_selected_games', true);
    $cancelados = get_post_meta($pool_id, '_wpfp_jogos_cancelados', true);
    if (!is_array($selected_games)) $selected_games = [];
    if (!is_array($cancelados)) $cancelados = [];

    $jogos_validos = array_diff($selected_games, $cancelados);
    $todos_encerrados = true;
    foreach ($jogos_validos as $id) {
        $fixture = get_transient("fixture_$id");
        if (!$fixture || !isset($fixture['goals']['home'], $fixture['goals']['away'])) {
            $todos_encerrados = false;
            break;
        }
    }

    if (!$todos_encerrados) {
        return '<p>⏳ Aguardando encerramento de todos os jogos para exibir a classificação.</p>';
    }

    // Buscar apostas e pontuação
    $tabela = "{$wpdb->prefix}pool_palpites";
    $users_data = $wpdb->get_results($wpdb->prepare("
        SELECT user_id, SUM(pontuacao) as total_pontos
        FROM $tabela
        WHERE pool_id = %d
        GROUP BY user_id
        ORDER BY total_pontos DESC
    ", $pool_id));

    if (empty($users_data)) return '<p>⚠️ Nenhuma pontuação registrada ainda.</p>';

    $output = '<div class="classificacao-pool">';
    $output .= '<h2>🏆 Classificação do Pool: ' . esc_html(get_the_title($pool_id)) . '</h2>';
    $output .= '<table class="wp-list-table widefat striped">';
    $output .= '<thead><tr><th>Posição</th><th>Usuário</th><th>Pontuação</th><th>Premiação</th></tr></thead><tbody>';

    foreach ($users_data as $index => $row) {
        $user_info = get_userdata($row->user_id);
        $nome = $user_info ? $user_info->display_name : 'Usuário #' . $row->user_id;
        $premio = '';

        if ($index === 0) $premio = '🥇 1º lugar';
        elseif ($index === 1) $premio = '🥈 2º lugar';

        $output .= "<tr>
            <td>" . ($index + 1) . "</td>
            <td>{$nome}</td>
            <td>{$row->total_pontos} pts</td>
            <td>{$premio}</td>
        </tr>";
    }

    $output .= '</tbody></table></div>';
    return $output;
}

add_shortcode('classificacao_pool', 'wpfp_shortcode_classificacao_pool');
