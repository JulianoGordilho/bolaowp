<?php
/**
 * Página de Classificação dos Vencedores
 */

if (!is_user_logged_in()) {
    wp_redirect(wp_login_url());
    exit;
}

if (!isset($_GET['pool_id'])) {
    echo '<p>❌ Pool inválido.</p>';
    return;
}

$pool_id = intval($_GET['pool_id']);
$post = get_post($pool_id);
if (!$post || $post->post_type !== 'wpfp_pool') {
    echo '<p>❌ Pool não encontrado.</p>';
    return;
}

global $wpdb;
$titulo = get_the_title($pool_id);
$liga = get_post_meta($pool_id, '_wpfp_liga_brasileira', true);
$pais = get_post_meta($pool_id, '_wpfp_country', true);
$valor_cota = floatval(get_post_meta($pool_id, '_wpfp_pontos_por_cota', true));
$cotas_total = intval(get_post_meta($pool_id, '_wpfp_cotas_max', true));

$cancelados = get_post_meta($pool_id, '_wpfp_jogos_cancelados', true) ?: [];
$selected_games = get_post_meta($pool_id, '_wpfp_selected_games', true) ?: [];
$valid_games = array_diff($selected_games, $cancelados);

$apostadores = $wpdb->get_results($wpdb->prepare(
    "SELECT user_id, dados FROM {$wpdb->prefix}pool_apostas WHERE pool_id = %d AND status != 'reprovada'",
    $pool_id
));

$ranking = [];
foreach ($apostadores as $apostador) {
    $user_id = $apostador->user_id;
    $dados = json_decode($apostador->dados, true);
    $pontos = 0;

    foreach ($valid_games as $game_id) {
        $fx = get_transient("fixture_{$game_id}");
        if (!$fx || !isset($fx['goals'])) continue;

        $palpite = $dados[$game_id] ?? null;
        if (!$palpite || !is_array($palpite)) continue;

        $real_home = intval($fx['goals']['home']);
        $real_away = intval($fx['goals']['away']);
        $pal_home = intval($palpite['home']);
        $pal_away = intval($palpite['away']);

        if ($pal_home === $real_home && $pal_away === $real_away) {
            $pontos += 5;
        } elseif (
            ($real_home === $real_away && $pal_home === $pal_away) ||
            ($real_home > $real_away && $pal_home > $pal_away) ||
            ($real_home < $real_away && $pal_home < $pal_away)
        ) {
            $pontos += 3;
        }
    }

    $ranking[] = [
        'user_id' => $user_id,
        'pontos' => $pontos
    ];
}

usort($ranking, function($a, $b) {
    return $b['pontos'] <=> $a['pontos'];
});

$premio_total = $cotas_total * $valor_cota;
$pote_75 = $premio_total * 0.75;
$premio1 = $pote_75 * 0.7;
$premio2 = $pote_75 * 0.3;

$posicoes = [];
foreach ($ranking as $r) {
    $posicoes[$r['pontos']][] = $r['user_id'];
}

$premiados = [];
$pos_keys = array_keys($posicoes);
if (isset($pos_keys[0])) {
    $top1 = $posicoes[$pos_keys[0]];
    $premio_dividido = $premio1 / count($top1);
    foreach ($top1 as $u) {
        $premiados[$u] = ['pos' => 1, 'valor' => $premio_dividido];
    }
}
if (isset($pos_keys[1])) {
    $top2 = $posicoes[$pos_keys[1]];
    $premio_dividido = $premio2 / count($top2);
    foreach ($top2 as $u) {
        $premiados[$u] = ['pos' => 2, 'valor' => $premio_dividido];
    }
}

echo "<div class='classificacao-pool'>";
echo "<h2>🏆 Classificação - {$titulo}</h2>";
echo "<p><strong>Liga:</strong> {$liga} | <strong>País:</strong> {$pais}</p>";
echo "<table class='wp-list-table widefat striped'><thead><tr><th>Usuário</th><th>Pontos</th><th>Posição</th><th>Prêmio</th></tr></thead><tbody>";
foreach ($ranking as $r) {
    $user = get_user_by('id', $r['user_id']);
    $nome = $user ? $user->display_name : "Usuário #{$r['user_id']}";
    $ponto = $r['pontos'];
    $pos = $premiados[$r['user_id']]['pos'] ?? '-';
    $val = isset($premiados[$r['user_id']]) ? "R$ " . number_format($premiados[$r['user_id']]['valor'], 2, ',', '.') : '-';
    echo "<tr><td>{$nome}</td><td>{$ponto}</td><td>{$pos}</td><td>{$val}</td></tr>";
}
echo "</tbody></table></div>";
?>
