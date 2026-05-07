<?php
namespace local_batchanalytics;

defined('MOODLE_INTERNAL') || die();

class crmapi
{
    private $client_id;
    private $client_secret;
    private $refresh_token;
    private $accounts_url;
    private $api_base_url;

    // Field list is loaded from admin config (local_batchanalytics/crm_fields_config).
    // Falls back to the defaults defined in crm_fields_helper if not yet configured.
    private $crm_fields = [];

    public function __construct()
    {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $this->client_id = trim(get_config('local_batchanalytics', 'zoho_client_id') ?: '');
        $this->client_secret = trim(get_config('local_batchanalytics', 'zoho_client_secret') ?: '');
        $this->refresh_token = trim(get_config('local_batchanalytics', 'zoho_refresh_token') ?: '');
        $this->accounts_url = trim(get_config('local_batchanalytics', 'zoho_accounts_url') ?: 'https://accounts.zoho.com');
        $this->api_base_url = trim(get_config('local_batchanalytics', 'zoho_api_base_url') ?: 'https://www.zohoapis.com');

        // Load API field keys from admin config (excludes computed CALC_* / placed_company).
        $this->crm_fields = crm_fields_helper::get_api_field_keys();
    }

    private function get_access_token()
    {
        global $SESSION;

        if (empty($this->client_id) || empty($this->client_secret) || empty($this->refresh_token)) {
            debugging('Zoho CRM credentials not configured for local_batchanalytics', DEBUG_DEVELOPER);
            return null;
        }

        if (!empty($SESSION->zoho_access_token) && !empty($SESSION->zoho_token_expires) && $SESSION->zoho_token_expires > time() + 60) {
            return $SESSION->zoho_access_token;
        }

        $curl = new \curl();
        $url = rtrim($this->accounts_url, '/') . '/oauth/v2/token';
        $params = [
            'refresh_token' => $this->refresh_token,
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            'grant_type' => 'refresh_token'
        ];

        $response = $curl->post($url, $params);
        $json = json_decode($response);

        if (empty($json->access_token)) {
            debugging('Zoho CRM token acquisition failed: ' . $response, DEBUG_DEVELOPER);
            return null;
        }

        $SESSION->zoho_access_token = $json->access_token;
        $SESSION->zoho_token_expires = time() + ($json->expires_in ?? 3600);
        return $json->access_token;
    }

    private function normalize_username($username)
    {
        return trim((string) $username);
    }

    private function escape_zoho_value($value)
    {
        return preg_replace('/[^A-Za-z0-9_\-\.@]/', '', $value);
    }

    // --- UPGRADED BATCH FUNCTION USING SEARCH API ---
    public function get_students_details(array $usernames)
    {
        $usernames = array_filter(array_map([$this, 'normalize_username'], $usernames));
        if (empty($usernames)) {
            return [];
        }

        $accessToken = $this->get_access_token();
        if (!$accessToken) {
            return [];
        }

        $results = [];
        
        // Ensure Admission_Number is fetched
        $fields_to_fetch = $this->crm_fields;
        if (!in_array('Admission_Number', $fields_to_fetch)) {
            $fields_to_fetch[] = 'Admission_Number';
        }
        $fields_str = urlencode(implode(',', $fields_to_fetch));

        // Search API "in" criteria supports up to 10 values per request
        $chunks = array_chunk($usernames, 10);

        foreach ($chunks as $chunk) {
            $or_conditions = array_map(function($u) {
                return "(Admission_Number:equals:" . str_replace(',', '', $this->escape_zoho_value($u)) . ")";
            }, $chunk);

            $criteria = "(" . implode("or", $or_conditions) . ")";
            $searchUrl = $this->api_base_url . '/crm/v6/Child_Admission/search?criteria=' . urlencode($criteria) . '&fields=' . $fields_str;

            $curl = new \curl();
            $curl->setHeader([
                "Authorization: Zoho-oauthtoken {$accessToken}"
            ]);

            $response = $curl->get($searchUrl);
            $json = json_decode($response, true);

            if (!empty($json['data'])) {
                foreach ($json['data'] as $record) {
                    $keyField = !empty($record['Admission_Number']) ? $record['Admission_Number'] : '';
                    if (!empty($keyField)) {
                        // Inject debug information into each record so the frontend can read it!
                        $record['_debug_api_url'] = $searchUrl;
                        $record['_debug_api_response'] = $response;
                        $results[strtolower($keyField)] = $record;
                    }
                }
            } else {
                 // Even if it failed, attach debug info to the first username in the chunk so we can see it
                 $firstUser = !empty($chunk[0]) ? $chunk[0] : 'failed_query';
                 $results[strtolower($firstUser)] = [
                     '_debug_api_url' => $searchUrl,
                     '_debug_api_response' => $response
                 ];
            }
        }

        return $results;
    }

    // --- REFACTORED SINGLE LOOKUP FUNCTION ---
    public function get_student_details($username)
    {
        if (empty($username)) {
            return null;
        }

        // Instead of writing a separate API call, we route the single request 
        // through the batch method. This keeps your code DRY (Don't Repeat Yourself) 
        // and ensures the exact same fields are returned.
        $results = $this->get_students_details([$username]);

        if (!empty($results)) {
            // Since we might get back data keyed by Admission_Number instead of Username, 
            // just return the first (and only) result we got back.
            return reset($results);
        }

        return null;
    }
}