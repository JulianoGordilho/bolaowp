<?php
global $wpdb;
$current_user_id = get_current_user_id();
if (!$current_user_id) {
    echo '<p>⚠️ Você precisa estar logado para ver seus jogos.</p>';
    return;
}

$apostados = $wpdb->get_results($wpdb->prepare(
    "SELECT DISTINCT pool_id FROM {$wpdb->prefix}pool_apostas WHERE user_id = %d",
    $current_user_id
));

if (empty($apostados)) {
    echo '<p>❌ Você ainda não fez palpites em nenhum pool.</p>';
    return;
}
?>

<div class="pagina-meus-jogos">
    <h2>📌 Meus Jogos</h2>

    <?php foreach ($apostados as $linha):
        $pool_id = $linha->pool_id;
        $pool = get_post($pool_id);
        if (!$pool) continue;

        $titulo = esc_html($pool->post_title);
        $liga = get_post_meta($pool_id, '_wpfp_liga_brasileira', true);
        $pais = get_post_meta($pool_id, '_wpfp_country', true);
        $selected_games = get_post_meta($pool_id, '_wpfp_selected_games', true) ?: [];
        $cancelados = get_post_meta($pool_id, '_wpfp_jogos_cancelados', true) ?: [];

        $total_pontos = 0;
        $validos = array_filter($selected_games, fn($id) => !in_array($id, $cancelados));
        ?>

        <div class="meu-pool-box">
            <h3>🏟️ <?= $titulo ?></h3>
            <p><strong>Liga:</strong> <?= $liga ?> - <?= $pais ?></p>
            <table class="tabela-palpite">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Partida</th>
                        <th>Palpite</th>
                        <th>Resultado</th>
                        <th>Pontuação</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $i = 1;
                foreach ($validos as $jogo_id):
                    $fixture = get_transient("fixture_{$jogo_id}");
                    $palpite = $wpdb->get_row($wpdb->prepare(
                        "SELECT gols_casa, gols_fora FROM {$wpdb->prefix}pool_apostas WHERE user_id = %d AND pool_id = %d AND jogo_id = %d",
                        $current_user_id, $pool_id, $jogo_id
                    ));
                    if (!$palpite || !$fixture || empty($fixture['fixture'])) continue;

                    $home = $fixture['teams']['home']['name'] ?? 'A';
                    $away = $fixture['teams']['away']['name'] ?? 'B';
                    $gols_api_home = $fixture['goals']['home'] ?? null;
                    $gols_api_away = $fixture['goals']['away'] ?? null;

                    $pontuacao = 0;
                    if ($gols_api_home !== null && $gols_api_away !== null) {
                        if ((int)$palpite->gols_casa === (int)$gols_api_home && (int)$palpite->gols_fora === (int)$gols_api_away) {
                            $pontuacao = 5;
                        } elseif (
                            ($gols_api_home > $gols_api_away && $palpite->gols_casa > $palpite->gols_fora) ||
                            ($gols_api_home < $gols_api_away && $palpite->gols_casa < $palpite->gols_fora) ||
                            ($gols_api_home == $gols_api_away && $palpite->gols_casa == $palpite->gols_fora)
                        ) {
                            $pontuacao = 3;
                        }
                        $total_pontos += $pontuacao;
                    }
                    ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td><?= $home ?> x <?= $away ?></td>
                        <td><?= $palpite->gols_casa ?> - <?= $palpite->gols_fora ?></td>
                        <td><?= ($gols_api_home !== null && $gols_api_away !== null) ? "$gols_api_home - $gols_api_away" : '⏳' ?></td>
                        <td><?= $pontuacao ?> pts</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p><strong>🎯 Total de Pontos:</strong> <?= $total_pontos ?> pts</p>
        </div>
        <hr>
    <?php endforeach; ?>
</div>

<style>
.meu-pool-box {
    padding: 10px;
    margin-bottom: 30px;
    background-color: #f9f9f9;
    border: 1px solid #ccc;
    border-radius: 5px;
}
.tabela-palpite {
    width: 100%;
    border-collapse: collapse;
    margin-top: 10px;
}
.tabela-palpite th, .tabela-palpite td {
    padding: 8px;
    border-bottom: 1px solid #ddd;
    text-align: center;
}
</style>
