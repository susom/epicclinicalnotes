<?php
namespace Stanford\EpicClinicalNotes;

require_once 'classes/Client.php';
class EpicClinicalNotes extends \ExternalModules\AbstractExternalModule {

    const EPIC_CLIENT_ID = 'epic-client-id';
    const EPIC_CLIENT_SECRET = 'epic-client-secret';
    const EPIC_FHIR_URL = 'epic-fhir-url';

    private Client $client;


    public function __construct() {
        parent::__construct();
        // Other code to run when object is instantiated

        $this->setClient(new Client($this->PREFIX));
    }

    public function injectJSMO($data = null, $init_method = null) {
        echo $this->initializeJavascriptModuleObject();
        $cmds = [
            "const module = " . $this->framework->getJavascriptModuleObjectName()
        ];
        if (!empty($data)) $cmds[] = "module.data = " . json_encode($data);
        if (!empty($init_method)) $cmds[] = "module.afterRender(module." . $init_method . ")";
        ?>
        <script>
            <script src="<?=$this->getUrl("assets/jsmo.js",true)?>"></script>
            $(function() { <?php echo implode(";\n", $cmds) ?> })
        </script>
        <?php
    }

    public function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance,
        $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id)
    {
        switch($action) {
            case "TestAction":
                \REDCap::logEvent("Test Action Received");
                $result = [
                    "success"=>true,
                    "user_id"=>$user_id
                ];
                break;
            default:
                // Action not defined
                throw new Exception ("Action $action is not defined");
        }

        // Return is left as php object, is converted to json automatically
        return $result;
    }

    /**
     * @return Client
     */
    public function getClient(): Client
    {
        return $this->client;
    }

    /**
     * @param Client $client
     */
    public function setClient(Client $client): void
    {
        $this->client = $client;
    }

}
