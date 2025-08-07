add_action('wp_ajax_wpfp_salvar_palpites', ['WPFP_Ajax', 'salvar_palpites']);

public static function salvar_palpites() {
    global $wpdb;
    $user_id = get_current_user_id();
    $pool_id = intval($_POST['pool_id'] ?? 0);
    $palpites = json_decode(stripslashes($_POST['palpites'] ?? '{}'), true);

    if (!$user_id || !$pool_id || !is_array($palpites)) {
        wp_send_json_error(['message' => 'Dados inválidos.']);
    }

    foreach ($palpites as $fixture_id => $placares) {
        $gols_mandante = isset($placares['home']) ? intval($placares['home']) : null;
        $gols_visitante = isset($placares['away']) ? intval($placares['away']) : null;

        if ($gols_mandante === null || $gols_visitante === null) continue;

        $wpdb->replace(
            $wpdb->prefix . 'pool_palpites',
            [
                'user_id' => $user_id,
                'pool_id' => $pool_id,
                'fixture_id' => $fixture_id,
                'gols_mandante' => $gols_mandante,
                'gols_visitante' => $gols_visitante,
                'created_at' => current_time('mysql', 1),
            ],
            [
                '%d', '%d', '%d', '%d', '%d', '%s'
            ]
        );
    }

    wp_send_json_success(['message' => 'Palpites salvos com sucesso!']);
}
