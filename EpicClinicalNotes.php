<?php
namespace Stanford\EpicClinicalNotes;

use ExternalModules\ExternalModules;

require_once 'classes/Client.php';
class EpicClinicalNotes extends \ExternalModules\AbstractExternalModule {


    private Client $client;

    private $maps = [];

    public function __construct() {
        parent::__construct();
        // Other code to run when object is instantiated
    }

    /**
     * @return Client
     */
    public function getClient(): Client
    {
        if(!isset($this->client)) {
            $this->client = new Client($this);
        }
        return $this->client;
    }

    public function getEpicBaseUrl()
    {
        return $this->getClient()->epicAuthenticator->getProjectSetting('epic-base-url');
    }
    public function getMaps()
    {
        if(empty($this->maps)) {
            $this->maps = $this->getSubSettings('maps');
        }
        return $this->maps;
    }

    /**
     * Prepare a payload of Epic SDE field => RTF table formatted value for a single REDCap record.
     *
     * @param string|int $recordID
     * @return array<string,string>  epicSdeField => RTF formatted table
     */
    public function prepareEpicSdeValues($recordID): array
    {
        global $Proj;

        $param = [
            'project_id' => PROJECT_ID,
            'records'    => [$recordID],
        ];

        $firstEventId = $this->getFirstEventId();
        $recordBundle = \REDCap::getData($param);
        $data         = $recordBundle[$recordID][$firstEventId] ?? [];

        $out = [];

        foreach ((array) $this->getMaps() as $map) {
            $epicField = $map['epic-sde-field'];
            $rcFields  = $map['redcap-fields'];

            if (!$epicField) {
                continue;
            }

            if (is_string($rcFields)) {
                $rcFields = array_values(array_filter(array_map('trim', explode(',', $rcFields))));
            }
            if (!is_array($rcFields)) {
                $rcFields = [];
            }

            $rows = [];

            foreach ($rcFields as $rcFieldArr) {
                $customLabel = $rcFieldArr['custom-label'] ?? null;
                $rcField = $rcFieldArr['redcap-field'] ?? null;
                if (!is_string($rcField) || $rcField === '') {
                    continue;
                }

                if (!isset($Proj->metadata[$rcField])) {
                    continue;
                }

                // Distinguish "no custom label provided" (fall back to field label)
                // from "custom label intentionally blank/whitespace" (suppress label entirely).
                $suppressLabel = false;
                if (is_null($customLabel) || $customLabel === '') {
                    $label = (string) ($Proj->metadata[$rcField]['element_label'] ?? $rcField);
                } else {
                    $label = (string) $customLabel;
                    if (trim($label) === '') {
                        $suppressLabel = true;
                    }
                }

                // Pipe smart variables / [field_name] tokens using this project's context.
                // - $replaceWithUnderlineIfMissing = false: avoids the "____" underline placeholder
                //   when a piped field has no value (e.g. [first_name] empty -> empty, not ____).
                // - $wrapValueInSpan = false: don't wrap piped values in <span> tags that
                //   normalizeLabel() would then strip.
                $label = \Piping::replaceVariablesInLabel(
                    $label,
                    $recordID,
                    $firstEventId,
                    1,
                    [],
                    false,                // replaceWithUnderlineIfMissing
                    $this->getProjectId(),
                    false                 // wrapValueInSpan
                );
                $label = $this->normalizeLabel($label);
                if ($suppressLabel) {
                    $label = '';
                }

                $type  = (string) ($Proj->metadata[$rcField]['element_type'] ?? '');
                $enum  = (string) ($Proj->metadata[$rcField]['element_enum'] ?? '');
                $raw   = $data[$rcField] ?? null;

                if ($type === 'checkbox') {
                    if (!is_array($raw) || empty($raw)) {
                        continue;
                    }
                    $checkedLabels = $this->getCheckedCheckboxLabels($enum, $raw);
                    if (empty($checkedLabels)) {
                        continue;
                    }
                    $display = implode(', ', $checkedLabels);
                    $rows[] = ['label' => $label, 'value' => $display];
                    continue;
                }

                $rawStr = is_scalar($raw) ? (string) $raw : '';
                $rawStr = trim($rawStr);

                if ($rawStr === '') {
                    continue;
                }

                if (in_array($type, ['select', 'radio', 'yesno', 'truefalse'], true)) {
                    $display = $this->getCodedValueLabel($type, $enum, $rawStr);
                    $rows[] = ['label' => $label, 'value' => $display];
                } else {
                    $rows[] = ['label' => $label, 'value' => $rawStr];
                }
            }

            if (!empty($rows)) {
                $out[$epicField] = $this->buildRtfTable($rows);
            }
        }

        return $out;
    }

    /**
     * Build an RTF table from label/value pairs.
     *
     * @param array $rows  Array of ['label' => string, 'value' => string]
     * @return string RTF formatted table
     */
    /**
     * Build RTF formatted content with bold question, colon, answer, and newline.
     *
     * @param array $rows  Array of ['label' => string, 'value' => string]
     * @return string RTF formatted content
     */
    private function buildRtfTable(array $rows): string
    {
        $rtf = '{\rtf1\ansi\deff0 ';

        foreach ($rows as $index => $row) {
            $labelRaw = (string) $row['label'];
            $value    = $this->escapeRtf($row['value']);

            if (trim($labelRaw) === '') {
                // No label -> just emit the value, no bold prefix or colon.
                $rtf .= $value;
            } else {
                $label = $this->escapeRtf($labelRaw);
                // Bold question, colon, then answer
                $rtf .= '\b ' . $label . ':\b0  ' . $value;
            }

            // Add newline after each row except the last
            if ($index < count($rows) - 1) {
                $rtf .= '\line ';
            }
        }

        $rtf .= '}';

        return $rtf;
    }

    /**
     * Escape special RTF characters.
     *
     * @param string $text
     * @return string
     */
    private function escapeRtf(string $text): string
    {
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace('{', '\{', $text);
        $text = str_replace('}', '\}', $text);
        return $text;
    }

    /**
     * Get the first event id for the current project.
     * Falls back safely if no longitudinal events are configured.
     */
    private function getFirstEventIdForProject(): int
    {
        // REDCap provides a static helper for this.
        // If project is not longitudinal, this should still return the single event id.
        return (int) \REDCap::getFirstEventId(PROJECT_ID);
    }

    /**
     * Normalize labels for compact single-line display.
     */
    private function normalizeLabel(string $label): string
    {
        $label = trim($label);
        // Replace any HTML breaks with spaces
        $label = preg_replace('/<\s*br\s*\/?>/i', ' ', $label) ?? $label;
        // Strip any remaining tags
        $label = trim(strip_tags($label));
        // Collapse whitespace
        $label = preg_replace('/\s+/', ' ', $label) ?? $label;
        return $label;
    }

    /**
     * Convert a coded stored value to its label.
     * Supports select/radio/yesno/truefalse.
     */
    private function getCodedValueLabel(string $type, string $enum, string $rawValue): string
    {
        // yesno/truefalse often do not have element_enum populated reliably
        if ($type === 'yesno') {
            return ($rawValue === '1') ? 'Yes' : 'No';
        }


        $choices = $this->safeParseEnum($enum);
        if (isset($choices[$rawValue])) {
            return (string) $choices[$rawValue];
        }

        // Fallback to raw value if not found
        return $rawValue;
    }

    /**
     * For checkbox fields: return labels for checked options (where value == '1').
     *
     * @param string $enum
     * @param array  $rawValues  code => 0/1
     * @return array<int,string>
     */
    private function getCheckedCheckboxLabels(string $enum, array $rawValues): array
    {
        $choices = $this->safeParseEnum($enum);
        $labels  = [];

        foreach ($rawValues as $code => $checked) {
            $isChecked = ((string) $checked === '1');
            if (!$isChecked) {
                continue;
            }
            $codeStr = (string) $code;
            if (isset($choices[$codeStr])) {
                $labels[] = (string) $choices[$codeStr];
            } else {
                // If enum doesn't contain it, fall back to the code
                $labels[] = $codeStr;
            }
        }

        return $labels;
    }

    /**
     * Wrapper around REDCap's parseEnum() to avoid hard failures if not available.
     *
     * @param string $enum
     * @return array<string,string>
     */
    private function safeParseEnum(string $enum): array
    {
        $enum = trim($enum);
        if ($enum === '') {
            return [];
        }

        if (function_exists('parseEnum')) {
            $parsed = parseEnum($enum);
            return is_array($parsed) ? $parsed : [];
        }

        // Fallback: attempt to parse "code, label | code, label" format
        $out = [];
        foreach (explode('|', $enum) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') continue;
            $parts = array_map('trim', explode(',', $chunk, 2));
            if (count($parts) === 2) {
                $out[(string)$parts[0]] = (string)$parts[1];
            }
        }
        return $out;
    }

    public function cronSyncEpicClinicalNotesBatchProcess($pid = null)
    {
        $projects = ExternalModules::getEnabledProjects($this->PREFIX);
        while($project = $projects->fetch_assoc()){
            // if this method is called from test page ignore other projects
            if($pid and $pid != $project['project_id']){
                continue;
            }
            $_GET['pid'] = $project['project_id'];
            $this->setProjectId($project['project_id']);
            $mrnField = $this->getProjectSetting('redcap-mrn-field');

            // Skip if no MRN field is configured
            if (empty($mrnField)) {
                continue;
            }

            $param = [
                'project_id' => $project['project_id'],
            ];
            $records = \REDCap::getData($param);
            foreach ($records as $recordID => $record) {
                // put try catch here to avoid one bad record stopping the whole batch
                try{
                    $preparedData = $this->prepareEpicSdeValues($recordID);
                    $mrn = $record[$this->getFirstEventId()][$mrnField];
                    foreach ($preparedData as $SDEField => $value) {
                        $existingData = $this->getClient()->getSmartDataElementValues($mrn, $SDEField);
                        // only sets value if empty never overwrite existing value
                        //if(empty($existingData['SmartDataValues'][0]['Values'])){
                            $this->getClient()->setSmartDataElementValue($mrn, $SDEField, $value);
                            \REDCap::logEvent('Epic Clinical Notes Sync', "Set SDE Field '$SDEField' for MRN '$mrn'", null, $recordID);
                        //}

                    }
                }catch (\Exception $e){
                    $body = $e->getMessage();
                    $parts = json_decode($body, true);
                    \REDCap::logEvent("EXCEPTION", "Error syncing record ID '$recordID': " . ($parts['Message'] ?? $body), null, $recordID);
                    continue;
                }

            }
        }
    }
}
