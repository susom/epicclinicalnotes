<?php

namespace Stanford\EpicClinicalNotes;

use ExternalModules\ExternalModules;
use GuzzleHttp\Exception\GuzzleException;

final class Client
{
    const ENTITY_ID_TYPE = 'LPCHSAMRN';
    private $token;

    private \GuzzleHttp\Client $client;

    public \Stanford\EpicAuthenticator\EpicAuthenticator $epicAuthenticator;

    private \Stanford\EpicClinicalNotes\EpicClinicalNotes $module;
    /**
     * @param \Stanford\EpicClinicalNotes\EpicClinicalNotes $module
     */
    public function __construct($module)
    {
        $this->module = $module;
        if ($this->module->getSystemSetting('epic-authenticator-prefix')) {
            $this->epicAuthenticator = \ExternalModules\ExternalModules::getModuleInstance($this->module->getSystemSetting('epic-authenticator-prefix'));
        }

        $this->client = new \GuzzleHttp\Client();
    }

    /**
     * @throws \Exception
     */
    public function getToken(): string
    {
        $timestamp = $this->module->getSystemSetting('epic-access-token-timestamp');
        $token = $this->module->getSystemSetting('epic-access-token');
        if(!$this->token){
            if(!$timestamp || time() > $timestamp || !$token) {
                $this->token = $this->epicAuthenticator->getEpicAccessToken();
                $this->module->setSystemSetting('epic-access-token', $this->token);
                // Set expiration to 1 hour from now
                $this->module->setSystemSetting('epic-access-token-timestamp', time() + 3600);
            }else{
                $this->token = $token;
            }
        }
        return $this->token;
    }
    /**
     * Set an Epic SmartData Element (SDE) value using Epic Interconnect SETSMARTDATAVALUES.
     *
     * Uses Bearer token from getToken() and sends a PUT request with JSON payload.
     *
     * @param string $fhirPatientId The patient identifier (typically FHIR ID).
     * @param string $smartDataId   The SmartData ID (e.g., "REDCAP#008").
     * @param string $value         The value to write.
     * @param array $opts           Optional overrides:
     *                             - smartDataUrl (string) full endpoint URL
     *                             - contextName (string) default PATIENT
     *                             - entityIdType (string) default FHIR
     *                             - contactId (string) default ""
     *                             - contactIdType (string) default DAT
     *                             - userId (string) default "1"
     *                             - userIdType (string) default External
     *                             - source (string) default Web Service
     *                             - smartDataIdType (string) default SDI
     *
     * @return array Decoded JSON response (or ['raw' => ..., 'http_code' => ...] if non-JSON)
     * @throws \Exception
     */
    public function setSmartDataElementValue(string $fhirPatientId, string $smartDataId, string $value, array $opts = []): array
    {
        // Endpoint URL must be configured (prefer module system setting)
        $smartDataUrl = rtrim($this->module->getEpicBaseUrl(), '/') . '/api/epic/2013/Clinical/Utility/SETSMARTDATAVALUES/SmartData/Values';

        if (!$smartDataUrl) {
            throw new \Exception('Missing Epic SmartData endpoint URL. Set system setting epic-smartdata-url or pass opts[smartDataUrl].');
        }

        if(!$smartDataId){
            throw new \Exception('Missing required parameter: smartDataId.');
        }

        if(!$fhirPatientId){
            throw new \Exception('Missing required parameter: fhirPatientId.');
        }

        $payload = [
            'ContextName'   => $opts['contextName']   ?? 'PATIENT',
            'EntityID'      => $fhirPatientId,
            'EntityIDType'  => $opts['entityIdType']  ?? self::ENTITY_ID_TYPE,
            'ContactID'     => $opts['contactId']     ?? '',
            'ContactIDType' => $opts['contactIdType'] ?? 'DAT',
            'UserID'        => $opts['userId']        ?? '1',
            'UserIDType'    => $opts['userIdType']    ?? 'External',
            'Source'        => $opts['source']        ?? 'Web Service',
            'SmartDataValues' => [[
                'SmartDataID'     => $smartDataId,
                'SmartDataIDType' => $opts['smartDataIdType'] ?? 'SDI',
                'Values'          => [$value],
                'Comments'        => [],
            ]],
        ];

        $token = $this->getToken();

        try {
            $resp = $this->client->request('PUT', $smartDataUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                ],
                'json' => $payload,
                'timeout' => 45,
            ]);

            $status = $resp->getStatusCode();
            $body   = (string) $resp->getBody();

            $json = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $json['_http_code'] = $status;
                return $json;
            }

            return [
                '_http_code' => $status,
                'raw' => $body,
            ];
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $status = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
            $body   = $e->getResponse() ? (string)$e->getResponse()->getBody() : '';

            // Bubble a useful error message up to callers
            throw new \Exception($body, 0, $e);
        } catch (GuzzleException $e) {
            throw new \Exception($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Get Epic SmartData Element (SDE) values using Epic Interconnect GETSMARTDATAVALUES.
     *
     * If $smartDataId is provided, the request will include SmartDataIDs to fetch that SDE.
     * If $smartDataId is null/empty, Epic will return all available SDE values for the entity.
     *
     * @param string      $entityId     The patient/entity identifier.
     * @param string|null $smartDataId  Optional SmartData ID (e.g., "REDCAP#002"); pass null to fetch all.
     * @param array       $opts         Optional overrides:
     *                                 - contextName (string) default PATIENT
     *                                 - entityIdType (string) default FHIR
     *                                 - contactId (string) default ""
     *                                 - contactIdType (string) default DAT
     *                                 - userId (string) default "1"
     *                                 - userIdType (string) default External
     *                                 - source (string) default Web Service
     *                                 - smartDataIdType (string) default SDI
     *                                 - smartDataUrl (string) override full endpoint URL
     *
     * @return array Decoded JSON response (or ['raw' => ..., '_http_code' => ...] if non-JSON)
     * @throws \Exception
     */
    public function getSmartDataElementValues(string $entityId, ?string $smartDataId = null, array $opts = []): array
    {
        $smartDataUrl = rtrim($this->module->getEpicBaseUrl(), '/') . '/api/epic/2013/Clinical/Utility/GETSMARTDATAVALUES/SmartData/Values';

        if (!$smartDataUrl) {
            throw new \Exception('Missing Epic SmartData endpoint URL for GETSMARTDATAVALUES.');
        }

        $payload = [
            'ContextName'   => $opts['contextName']   ?? 'PATIENT',
            'EntityID'      => $entityId,
            'EntityIDType'  => $opts['entityIdType']  ?? self::ENTITY_ID_TYPE,
            'ContactID'     => $opts['contactId']     ?? '',
            'ContactIDType' => $opts['contactIdType'] ?? 'DAT',
            'UserID'        => $opts['userId']        ?? '1',
            'UserIDType'    => $opts['userIdType']    ?? 'External',
            'Source'        => $opts['source']        ?? 'Web Service',
        ];

        $smartDataId = is_string($smartDataId) ? trim($smartDataId) : '';
        if ($smartDataId !== '') {
            $payload['SmartDataIDs'] = [[
                'ID'   => $smartDataId,
                'Type' => $opts['smartDataIdType'] ?? 'SDI',
            ]];
        }

        $token = $this->getToken();

        try {
            // Epic Interconnect expects POST for GETSMARTDATAVALUES in this API family
            $resp = $this->client->request('POST', $smartDataUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                ],
                'json' => $payload,
                'timeout' => 45,
            ]);

            $status = $resp->getStatusCode();
            $body   = (string) $resp->getBody();

            $json = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $json['_http_code'] = $status;
                return $json;
            }

            return [
                '_http_code' => $status,
                'raw' => $body,
            ];
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $status = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
            $body   = $e->getResponse() ? (string)$e->getResponse()->getBody() : '';
            throw new \Exception($body, 0, $e);
        } catch (GuzzleException $e) {
            throw new \Exception($e->getMessage(), $e->getCode(), $e);
        }
    }
}
