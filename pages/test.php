<?php
/** @var \Stanford\EpicClinicalNotes\EpicClinicalNotes $module */

try{
    $client = $module->getClient();
    #$accessToken = $client->getToken();
    $module->cronSyncEpicClinicalNotesBatchProcess();
    echo "<pre>";
    $param = [
        'project_id' => PROJECT_ID,
        'records' => [1],
    ];
    $records = \REDCap::getData($param);
    $mrnField = $module->getProjectSetting('redcap-mrn-field');
    $mrn = $records[1][$module->getFirstEventId()][$mrnField];
    $response = $client->getSmartDataElementValues($mrn, '', );
    print_r($response);
    echo "</pre>";
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
