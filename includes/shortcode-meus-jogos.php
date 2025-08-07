<?php
function wpfp_shortcode_meus_jogos() {
    if (!is_user_logged_in()) return '<p>⚠️ Você precisa estar logado para ver seus jogos.</p>';

    global $wpdb;
    $user_id = get_current_user_id();
    $table = $wpdb->prefix . 'pool_palpites';

    $pools = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT pool_id FROM $table WHERE user_id = %d",
        $user_id
    ));

    if (empty($pools)) return '<p>⚠️ Nenhum palpite encontrado para este usuário.</p>';

    $output = '<div class="meus-jogos">';
    $output .= '<h2>🎯 Meus Palpites</h2>';

    foreach ($pools as $pool_id) {
        $titulo = get_the_title($pool_id);
        $output .= "<h3>🏟️ {$titulo}</h3>";
        $output .= '<table class="wp-list-table widefat striped">';
        $output .= '<thead><tr><th>#</th><th>Data</th><th>Partida</th><th>Palpite</th><th>Resultado</th><th>Pontuação</th></tr></thead><tbody>';

        $palpites = $wpdb->get_results($wpdb->prepare(
            "SELECT fixture_id, palpite_home, palpite_away, pontuacao FROM $table WHERE user_id = %d AND pool_id = %d",
            $user_id, $pool_id
        ));

        foreach ($palpites as $i => $row) {
            $fixture = get_transient("fixture_{$row->fixture_id}");
            $data = $fixture['fixture']['date'] ?? 'N/A';
            $time_home = $fixture['teams']['home']['name'] ?? 'Time A';
            $time_away = $fixture['teams']['away']['name'] ?? 'Time B';
            $gols_home = $fixture['goals']['home'] ?? '-';
            $gols_away = $fixture['goals']['away'] ?? '-';

            $output .= '<tr>';
            $output .= '<td>' . ($i + 1) . '</td>';
            $output .= '<td>' . date('d/m/Y H:i', strtotime($data)) . '</td>';
            $output .= '<td>' . esc_html("{$time_home} x {$time_away}") . '</td>';
            $output .= '<td>' . esc_html("{$row->palpite_home} x {$row->palpite_away}") . '</td>';
            $output .= '<td>' . esc_html("{$gols_home} x {$gols_away}") . '</td>';
            $output .= '<td>' . intval($row->pontuacao) . '</td>';
            $output .= '</tr>';
        }

        // Total de pontos
        $total = array_sum(array_column($palpites, 'pontuacao'));
        $output .= "<tr><td colspan='6'><strong>Total: {$total} pontos</strong></td></tr>";
        $output .= '</tbody></table>';
    }

    $output .= '</div>';
    return $output;
}

add_shortcode('meus_jogos', 'wpfp_shortcode_meus_jogos');
