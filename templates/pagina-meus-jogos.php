<?php
/**
 * Template: Resultados dos Pools
 */
if (!is_user_logged_in()) {
    wp_redirect(wp_login_url());
    exit;
}

global $wpdb;

$pools = get_posts([
    'post_type'      => 'wpfp_pool',
    'posts_per_page' => -1,
    'post_status'    => 'publish',
    'orderby'        => 'date',
    'order'          => 'DESC',
]);

$agora = current_time('timestamp');

echo "<div class='resultados-pools'>";
echo "<h2>📊 Resultados de Pools Encerrados</h2>";

if (empty($pools)) {
    echo "<p>⚠️ Nenhum pool encerrado encontrado.</p>";
    return;
}

foreach ($pools as $pool) {
    $pool_id = $pool->ID;
    $titulo = $pool->post_title;
    $selected_games = get_post_meta($pool_id, '_wpfp_selected_games', true) ?: [];
    $cancelados = get_post_meta($pool_id, '_wpfp_jogos_cancelados', true) ?: [];

    $fixture_check = true;
    foreach ($selected_games as $game_id) {
        if (in_array($game_id, $cancelados)) continue;
        $fx = get_transient("fixture_{$game_id}");
        if (!$fx || $fx['goals']['home'] === null || $fx['goals']['away'] === null) {
            $fixture_check = false;
            break;
        }
    }

    $data_limite_raw = get_post_meta($pool_id, '_wpfp_data_limite_cache', true);
    if (!$data_limite_raw) {
        $data_limite_raw = current_time('mysql');
    }

    $limite_timestamp = strtotime($data_limite_raw);
    if ($limite_timestamp > $agora || !$fixture_check) continue;

    echo "<div class='resultado-pool'>";
    echo "<h3>{$titulo}</h3>";
    echo "<p><strong>Encerrado em:</strong> " . date('d/m/Y H:i', $limite_timestamp) . "</p>";
    echo "<form method='get' action='" . esc_url(site_url('/classificacao-pool')) . "'>";
    echo "<input type='hidden' name='pool_id' value='{$pool_id}' />";
    echo "<button type='submit'>🏆 Exibir Classificação de Vencedores</button>";
    echo "</form>";
    echo "</div><hr>";
}

echo "</div>";
?>

<style>
.resultado-pool {
    background: #f9f9f9;
    border: 1px solid #ddd;
    padding: 15px;
    margin-bottom: 20px;
    border-radius: 8px;
}
.resultado-pool h3 {
    margin-top: 0;
}
</style>
