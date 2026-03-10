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

    public function __construct()
    {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $this->client_id = trim(get_config('local_batchanalytics', 'zoho_client_id') ?: '');
        $this->client_secret = trim(get_config('local_batchanalytics', 'zoho_client_secret') ?: '');
        $this->refresh_token = trim(get_config('local_batchanalytics', 'zoho_refresh_token') ?: '');
        $this->accounts_url = trim(get_config('local_batchanalytics', 'zoho_accounts_url') ?: 'https://accounts.zoho.com');
        $this->api_base_url = trim(get_config('local_batchanalytics', 'zoho_api_base_url') ?: 'https://www.zohoapis.com');
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

    // --- ONE MASTER FUNCTION TO FETCH EVERYTHING ---
    public function get_student_details($username)
    {
        if (empty($username))
            return null;
        $accessToken = $this->get_access_token();
        if (!$accessToken)
            return null;

        try {
            // 1. Define ALL fields needed for BOTH Filter and PTF Tab
            $fields = [
                // Filter Field
                'Placement_Company',
                // PTF Data Fields
                'Class_X_Score',
                'Class_XII_Score',
                'BE_BTech_Branch',
                'BE_BTech_Score',
                'BE_BTech_YoP',
                'College_Name',
                'Other_College_Name',
                'ME_MTech_Score',
                'ME_MTech_Branch',
                'ME_MTech_YoP',
                'Home_State',
                'Total_Applied',
                'Last_Applied_Date',
                'Total_Shortlisted',
                'Last_Shortlisted_Date',
                'Total_Technical_Interview_Cleared',
                'Total_Written_Test_Cleared',
                'Total_L1_Cleared',
                'Total_L2_Cleared',
                'Advanced_C_Score',
                'Mentor_Name_C_Mock',
                'C_Score',
                'Mentor_Name_C_Mock1',
                'DS_Score',
                'Mentor_Name_DS',
                'Linux_Internals_Score',
                'Mentor_Name_LI',
                'MC_Mock_Score3',
                'Mentor_Name_MC_Mock',
                'Coach_Rating',
                'MAAC_Rating',
                'Placement_Eli',
                'Placement_Company',
                'CTC'
            ];

            $select_str = implode(',', $fields);

            // 2. Build Query
            $searchUrl = $this->api_base_url . "/crm/v2/Child_Admission/search?criteria=(Admission_Number:equals:{$username})";
            // If you need to select specific fields via COQL, use that method. 
            // The standard search endpoint returns all fields by default, which is easier.
            // If you MUST use COQL for field selection, uncomment below:
            // $query = "select " . $select_str . " from Child_Admission where Admission_Number = '" . $username . "' limit 1";
            // And use your COQL execution logic.

            // For standard search (easiest):
            $curl = new \curl();
            $curl->setHeader(["Authorization: Zoho-oauthtoken {$accessToken}"]);
            $response = $curl->get($searchUrl);
            $json = json_decode($response, true); // Return as Array

            if (!empty($json['data']) && count($json['data']) > 0) {
                return $json['data'][0];
            }
            return null;

        } catch (\Exception $e) {
            return null;
        }
    }
}