function wpfp_render_pool_fixtures($atts) {
    $atts = shortcode_atts([
        'id' => 0,
    ], $atts);

    $post_id = intval($atts['id']);
    if (!$post_id) return '<p>❌ Pool não encontrado.</p>';

    $game_ids = get_post_meta($post_id, '_wpfp_selected_games', true);
    if (!is_array($game_ids) || empty($game_ids)) return '<p>⚠️ Nenhum jogo disponível para este pool.</p>';

    $output = '<div class="wpfp-fixture-list"><table style="width:100%; border-collapse: collapse;">';
    $output .= '<thead><tr><th>ID</th><th>Data</th><th>Times</th><th>Status</th><th>Placar</th></tr></thead><tbody>';

    foreach ($game_ids as $id) {
        $fixture = get_transient("fixture_{$id}");
        if (!$fixture) continue;

        $fixture_data = $fixture['fixture'];
        $teams        = $fixture['teams'];
        $goals        = $fixture['goals'];

        $date = date_i18n('d/m/Y H:i', strtotime($fixture_data['date']));
        $home = $teams['home']['name'];
        $away = $teams['away']['name'];
        $status = $fixture_data['status']['short'];
        $placar = is_null($goals['home']) ? '—' : "{$goals['home']} x {$goals['away']}";

        $output .= "<tr>
            <td>{$id}</td>
            <td>{$date}</td>
            <td>{$home} x {$away}</td>
            <td>{$status}</td>
            <td>{$placar}</td>
        </tr>";
    }

    $output .= '</tbody></table></div>';

    return $output;
}
add_shortcode('wpfp_pool_games', 'wpfp_render_pool_fixtures');
