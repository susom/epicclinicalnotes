<?php

namespace Stanford\EpicClinicalNotes;

use ExternalModules\ExternalModules;

final class Client
{
    const string CLIENT_CREDENTIALS = "client_credentials";

    private $token;


    private $prefix;

    private $client_id;

    private $client_secret;

    private \GuzzleHttp\Client $client;

    public function __construct($prefix)
    {
        $this->prefix = $prefix;

        $this->client = new \GuzzleHttp\Client([
                'timeout' => 30,
                'connect_timeout' => 5,
                'verify' => false,
            ]
        );
    }

    /**
     * @return mixed
     */
    public function getClientSecret()
    {
        if (!$this->client_secret) {
            $this->setClientSecret(ExternalModules::getSystemSetting($this->prefix, EpicClinicalNotes::EPIC_CLIENT_SECRET));
        }
        return $this->client_secret;
    }

    /**
     * @param mixed $client_secret
     */
    public function setClientSecret($client_secret): void
    {
        $this->client_secret = $client_secret;


    }

    /**
     * @return mixed
     */
    public function getClientId()
    {
        if (!$this->client_id) {
            $this->setClientId(ExternalModules::getSystemSetting($this->prefix, EpicClinicalNotes::EPIC_CLIENT_ID));
        }
        return $this->client_id;
    }

    /**
     * @param mixed $client_id
     */
    public function setClientId($client_id): void
    {
        $this->client_id = $client_id;
    }


    public function authenticate()
    {
        $url = 'https://fhir.epic.com/interconnect-fhir-oauth/oauth2/token';
        $client_id = $this->getClientId();
        $client_secret = $this->getClientSecret();

        $data = [
            'grant_type' => 'client_credentials',
            'client_id' => $client_id,
            'client_secret' => $client_secret
        ];

        $response = $this->client->post($url, $data);
        if (isset($response['access_token'])) {
            $this->token = $response['access_token'];
        } else {
            throw new \Exception('Authentication failed: ' . json_encode($response));
        }
    }
}
