<?php

function wpfp_obter_fixture_com_fallback($fixture_id) {
    // 1. Verifica cache
    $fixture = get_transient("fixture_{$fixture_id}");
    if ($fixture) return $fixture;

    // 2. Busca via API diretamente
    if (class_exists('WPFP_API_Client')) {
        $client = new WPFP_API_Client();
        $fixture = $client->getFixtureById($fixture_id);
        if (!empty($fixture)) {
            set_transient("fixture_{$fixture_id}", $fixture, HOUR_IN_SECONDS);
            return $fixture;
        }
    }

    return null; // Nenhum dado encontrado
}

