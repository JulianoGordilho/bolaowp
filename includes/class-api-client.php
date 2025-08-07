<?php
class WPFP_API_Client {
    private $api_key;
    private $api_host;
    private $base_url = 'https://v3.football.api-sports.io/';

    public function __construct() {
        $this->api_key  = get_option('wpfp_api_key');
        $this->api_host = get_option('wpfp_api_host');
    }

    /**
     * Requisição genérica para a API
     */
    public function request($endpoint, $params = []) {
        $url = $this->base_url . $endpoint;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $headers = [
            'x-apisports-key' => $this->api_key,
            'x-rapidapi-host' => $this->api_host,
        ];

        $response = wp_remote_get($url, ['headers' => $headers, 'timeout' => 15]);

        if (is_wp_error($response)) {
            error_log('[WPFP_API_Client] Erro de conexão: ' . $response->get_error_message());
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!isset($data['response'])) {
            error_log('[WPFP_API_Client] Resposta inválida da API: ' . print_r($data, true));
            return null;
        }

        return $data['response'];
    }

    /**
     * Retorna ligas atuais
     */
    public function getLeagues() {
        return $this->request('leagues', ['current' => 'true']);
    }

    /**
     * Retorna temporadas disponíveis para uma liga
     */
    public function getSeasons($league_id) {
        return $this->request('leagues/seasons', ['id' => intval($league_id)]);
    }

    /**
     * Retorna jogos (fixtures) de uma liga, país e ano
     */
    public function getFixtures($league_id, $season, $country = null) {
        $params = [
            'league' => intval($league_id),
            'season' => intval($season),
        ];

        if (!empty($country)) {
            $params['country'] = $country;
        }

        return $this->request('fixtures', $params);
    }

    /**
     * Retorna um jogo específico pelo fixture_id
     */
    public function getFixtureById($fixture_id) {
        $result = $this->request('fixtures', ['id' => intval($fixture_id)]);
        return is_array($result) && count($result) > 0 ? $result[0] : null;
    }

    /**
     * Verifica se a API está respondendo (teste básico)
     */
    public function testConnection() {
        $res = $this->getLeagues();
        return is_array($res);
    }
}
