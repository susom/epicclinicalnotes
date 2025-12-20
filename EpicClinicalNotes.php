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
     * Prepare a payload of Epic SDE field => formatted text value for a single REDCap record.
     *
     * Each map entry (from getMaps()) is expected to include:
     *  - epic-sde-field (string)
     *  - redcap-fields (array of REDCap field names)
     *
     * Output format per Epic SDE:
     *   "Field Label 1 : Value 1 | Field Label 2 : Value 2 | ..."
     *
     * @param string|int $recordID
     * @return array<string,string>  epicSdeField => formattedValue
     */
    public function prepareEpicSdeValues($recordID): array
    {
        global $Proj;

        // Fetch record data for the first event
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

            $rcFields = $map['redcap-fields'];

            if (!$epicField) {
                continue;
            }

            // Normalize fields list
            if (is_string($rcFields)) {
                // allow comma-separated strings just in case
                $rcFields = array_values(array_filter(array_map('trim', explode(',', $rcFields))));
            }
            if (!is_array($rcFields)) {
                $rcFields = [];
            }

            $parts = [];

            foreach ($rcFields as $rcField) {
                $rcField = array_pop($rcField);
                if (!is_string($rcField) || $rcField === '') {
                    continue;
                }

                // Metadata must exist
                if (!isset($Proj->metadata[$rcField])) {
                    continue;
                }

                $label = (string) ($Proj->metadata[$rcField]['element_label'] ?? $rcField);
                $label = \Piping::replaceVariablesInLabel($label, $recordID, $firstEventId);
                $label = $this->normalizeLabel($label);

                $type  = (string) ($Proj->metadata[$rcField]['element_type'] ?? '');
                $enum  = (string) ($Proj->metadata[$rcField]['element_enum'] ?? '');

                // Raw value from record data
                $raw = $data[$rcField] ?? null;

                // Checkbox values come back as an array of code => 0/1
                if ($type === 'checkbox') {
                    if (!is_array($raw) || empty($raw)) {
                        continue;
                    }
                    $checkedLabels = $this->getCheckedCheckboxLabels($enum, $raw);
                    if (empty($checkedLabels)) {
                        continue;
                    }
                    $display = implode(', ', $checkedLabels);
                    $parts[] = $label . ' : ' . $display;
                    continue;
                }

                // Everything else is scalar
                $rawStr = is_scalar($raw) ? (string) $raw : '';
                $rawStr = trim($rawStr);

                if ($rawStr === '') {
                    continue;
                }

                // For coded fields, convert stored value to display label
                if (in_array($type, ['select', 'radio', 'yesno', 'truefalse'], true)) {
                    $display = $this->getCodedValueLabel($type, $enum, $rawStr);
                    $parts[] = $label . ' : ' . $display;
                } else {
                    // Treat as free text
                    $parts[] = $label . ' : ' . $rawStr;
                }
            }

            if (!empty($parts)) {
                $out[$epicField] = implode(' && ', $parts);
            }
        }

        return $out;
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

    public function cronSyncEpicClinicalNotesBatchProcess()
    {
        $projects = ExternalModules::getEnabledProjects($this->PREFIX);
        while($project = $projects->fetch_assoc()){
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
                $preparedData = $this->prepareEpicSdeValues($recordID);
                $mrn = $record[$this->getFirstEventId()][$mrnField];
                foreach ($preparedData as $SDEField => $value) {
                    $existingData = $this->getClient()->getSmartDataElementValues($mrn, $SDEField);
                    // only sets value if empty never overwrite existing value
                    if(empty($existingData['SmartDataValues'][0]['Value'])){
                        $this->getClient()->setSmartDataElementValue($mrn, $SDEField, $value);
                        \REDCap::logEvent('Epic Clinical Notes Sync', "Set SDE Field '$SDEField' for MRN '$mrn'", null, $recordID);
                    }

                }

            }
        }
    }
}
