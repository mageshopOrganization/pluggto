<?php

class Thirdlevel_Pluggto_Model_Call
{
    protected $tries = 0;

    // get access token
    public function Autenticate($force = false)
    {
        $api = Mage::getModel('pluggto/api')->load(1);

        // recupera o refresh token do banco
        $expire = (int) $api->getExpire();
        $accesstoken = $api->getAccesstoken();

        if ($expire != null && $expire > time() && $accesstoken != null && $accesstoken != '' && $force == false) {
            return $accesstoken;
        }

        try {
            $body = array(
                'grant_type' => 'password',
                'client_id' => trim(Mage::getStoreConfig('pluggto/configuration/client_id') ?? ''),
                'client_secret' => trim(Mage::getStoreConfig('pluggto/configuration/client_secret') ?? ''),
                'username' => trim(Mage::getStoreConfig('pluggto/configuration/app_id') ?? ''),
                'password' => trim(Mage::getStoreConfig('pluggto/configuration/app_secret') ?? ''),
            );

            try {
                $result = $this->doCall('oauth/token', $body, 'field', 'POST');
                // set the access token
                // guarta o hoarario, prazo de expiracao e returna o access token
                if ($result['code'] == 200 && $result['success']) {
                    $apis = Mage::getModel('pluggto/api')->load(1);
                    $expires = time() +  $result['Body']['expires_in'] - 60;
                    $apis->setExpire($expires);
                    $apis->setAccesstoken($result['Body']['access_token']);
                    $apis->setRefreshtoken($result['Body']['refresh_token']);
                    $apis->save();
                    return $result['Body']['access_token'];
                } else {
                    return false;
                }
            } catch (Exception $e) {
                throw new Exception($e);
            }
        } catch (Exception $e) {
            throw new Exception($e);
        }
    } // end function

    public function doCall($model = null, $body = null, $type = null, $method = null, $private = true)
    {
        // buld the post data follwing the api needs
        $url = 'https://api.plugg.to/';

        if ($type == 'json') {
            $posts = json_encode($body);
            $header = array('Content-Type:application/json', 'Accept:application/json');
        } else if ($type == 'field') {
            $posts = http_build_query($body);
            $header = array('Content-Type:application/x-www-form-urlencoded');
        } else if ($type == 'query') {
            $posts = http_build_query($body);
            $header = array('Content-Type:application/json', 'Accept:application/json');
        } else {
            $header = array('Content-Type:application/json', 'Accept:application/json');
            $posts = $body;
        }

        if ($model != 'oauth/token' && $private == true) {
            $accessToken = $this->Autenticate();
            if ($accessToken) {
                $nmodel = $model . '?' . 'access_token=' . $accessToken;
            } else {
                $failReturn['Body'] = 'Authentication Fail, was not possible to authenticate to Plugg.To';
                $failReturn['code'] = 500;
                return $failReturn;
            }

            if ($type == 'field' || $type == 'query') {
                $nmodel = $nmodel . '&' . $posts;
            }
        } else {
            $nmodel = $model . '?' . $posts;
        }

        $options = array(
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_HTTPHEADER  => $header,
            CURLOPT_SSL_VERIFYPEER  => false,
            CURLOPT_URL  => $url . $nmodel,
            CURLOPT_CUSTOMREQUEST  => $method,
            // Latencia tipica de resposta e ~0,4s; chamadas acima de ~20s sao
            // anomalas e, no processamento serial da fila, uma unica delas atrasa
            // todas as seguintes. O limite de 20s contem esse impacto; o item nao
            // concluido permanece pendente e e re-tentado no proximo ciclo.
            CURLOPT_CONNECTTIMEOUT  => 10,
            CURLOPT_TIMEOUT  => 20
        );

        if ($method == 'POST' || $method == 'PUT') {
            $options[CURLOPT_POSTFIELDS] = $posts;
        }

        $call = curl_init();
        curl_setopt_array($call, $options);
        // execute the curl call
        $dados = curl_exec($call);
        // get the curl statys
        $info = curl_getinfo($call);

        // Mage::helper('pluggto')->WriteLogForModule('Debug',print_r($options,1));

        // close the call
        curl_close($call);
        $status = true;

        if ($info['http_code'] == 401 && $model != 'oauth/token' && $this->tries <= 3) {
            $this->tries++;
            $this->Autenticate(true);
            return $this->doCall($model, $body, $type, $method, $private);
        } else if (($info['http_code'] == 100 || $info['http_code'] == 0 || empty($dados))  && $this->tries <= 3) {
            $this->tries++;

            return $this->doCall($model, $body, $type, $method, $private);
        }

        // check for curl error
        if ($dados === false) {
            $status = false;
            Mage::helper('pluggto')->WriteLogForModule('Error', 'URL: ' . $model . ' chamada: ' . print_r($options, 1) . ' retorno: ' . print_r($dados, 1));
        }

        if ($info['http_code'] != 200 && $info['http_code'] != 201) {
            Mage::helper('pluggto')->WriteLogForModule('Error', 'URL: ' . $model . ' chamada: ' . print_r($options, 1) . ' retorno: ' . print_r($dados, 1));
        }

        $toReturn['Body'] = json_decode($dados, true);
        $toReturn['code'] = $info['http_code'];
        $toReturn['success'] = $status;

        return $toReturn;
    }

    /**
     * Executa varios GET autenticados em paralelo (curl_multi), com concorrencia
     * limitada e retry em http=0/100, alem de re-autenticacao em caso de 401 —
     * mesmo comportamento do doCall. Usado pelo playline para processar a fila sem
     * que uma chamada lenta bloqueie as demais.
     *
     * @param array $urlsById     mapa [id => model_path] (ex: [123 => 'products/abc'])
     * @param int   $concurrency  chamadas simultaneas (recomendado: 5)
     * @param int   $maxretry     retries por item em falha transitoria (default 3)
     * @return array  [id => ['Body' => mixed, 'code' => int, 'success' => bool]],
     *                no mesmo formato do doCall.
     */
    public function doCallMultiGet(array $urlsById, $concurrency = 5, $maxretry = 3)
    {
        $results = array();
        if (empty($urlsById)) {
            return $results;
        }

        $accessToken = $this->Autenticate();
        if (!$accessToken) {
            // Sem token: trata como API indisponivel (code 0) para manter os itens
            // pendentes e re-tentar no proximo ciclo, em vez de marca-los como falha.
            foreach ($urlsById as $id => $path) {
                $results[$id] = array('Body' => null, 'code' => 0, 'success' => false);
            }
            return $results;
        }

        $base   = 'https://api.plugg.to/';
        $header = array('Content-Type:application/json', 'Accept:application/json');
        $reauthed = false;

        // Lista sequencial preservando o id de origem de cada item.
        $items = array();
        foreach ($urlsById as $id => $path) {
            $items[] = array('id' => $id, 'path' => $path);
        }
        $n    = count($items);
        $next = 0;
        if ($concurrency < 1) {
            $concurrency = 1;
        }

        $mh = curl_multi_init();
        $inflight = array(); // spl_object_id => ['ch','i','attempt']

        // (Re)adiciona um item ao pool. $accessToken por referencia para que um
        // retry posterior a uma re-autenticacao utilize o token atualizado.
        $add = function ($i, $attempt) use (&$inflight, &$accessToken, $mh, $items, $header, $base) {
            $ch = curl_init();
            curl_setopt_array($ch, array(
                CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2,
                CURLOPT_RETURNTRANSFER => 1,
                CURLOPT_HTTPHEADER     => $header,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_URL            => $base . $items[$i]['path'] . '?access_token=' . $accessToken,
                CURLOPT_CUSTOMREQUEST  => 'GET',
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 20,
            ));
            curl_multi_add_handle($mh, $ch);
            $inflight[spl_object_id($ch)] = array('ch' => $ch, 'i' => $i, 'attempt' => $attempt);
        };

        while ($next < $n && count($inflight) < $concurrency) {
            $add($next++, 0);
        }

        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 1.0);
            while ($info = curl_multi_info_read($mh)) {
                $ch   = $info['handle'];
                $key  = spl_object_id($ch);
                $meta = $inflight[$key];
                $i    = $meta['i'];
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $body = curl_multi_getcontent($ch);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                unset($inflight[$key]);

                $transient = ($code == 0 || $code == 100 || $body === false || $body === '');

                // Token expirado no meio do lote: re-autentica uma vez e prossegue.
                if ($code == 401 && !$reauthed) {
                    $newToken = $this->Autenticate(true);
                    if ($newToken) {
                        $accessToken = $newToken;
                        $reauthed = true;
                    }
                }

                if (($transient || $code == 401) && $meta['attempt'] < $maxretry) {
                    $add($i, $meta['attempt'] + 1); // re-tenta o mesmo item
                } else {
                    $results[$items[$i]['id']] = array(
                        'Body'    => json_decode($body, true),
                        'code'    => $code,
                        'success' => !($body === false || $body === ''),
                    );
                    if ($next < $n) {
                        $add($next++, 0);
                    }
                }
            }
        } while (count($inflight) > 0 || $next < $n);

        curl_multi_close($mh);
        return $results;
    }
}