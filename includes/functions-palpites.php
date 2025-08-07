<?php
function wpfpool_calcular_pontuacao($palpite_home, $palpite_away, $real_home, $real_away) {
    if ($palpite_home === null || $palpite_away === null || $real_home === null || $real_away === null) return 0;

    if ($palpite_home == $real_home && $palpite_away == $real_away) return 5;

    $resultado_palpite = $palpite_home > $palpite_away ? 'home' : ($palpite_home < $palpite_away ? 'away' : 'draw');
    $resultado_real    = $real_home > $real_away ? 'home' : ($real_home < $real_away ? 'away' : 'draw');

    return ($resultado_palpite === $resultado_real) ? 3 : 0;
}
