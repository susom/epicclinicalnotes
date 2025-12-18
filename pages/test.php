<?php
/** @var \Stanford\EpicClinicalNotes\EpicClinicalNotes $module */

try{
    $client = $module->getClient();
    #$accessToken = $client->getToken();
    $module->cronSyncEpicClinicalNotesBatchProcess();
    echo "<pre>";
    $response = $client->getSmartDataElementValues('', 'REDCAP#008', );
    print_r($response);
    echo "</pre>";
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
