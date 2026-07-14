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

    /** @var array<string,mixed> */
    private static $student_cache = [];
    /** @var string|null */
    private static $access_token_cache = null;
    /** @var int */
    private static $access_token_expires = 0;

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
        if (!empty(self::$access_token_cache) && self::$access_token_expires > time() + 60) {
            return self::$access_token_cache;
        }

        if (empty($this->client_id) || empty($this->client_secret) || empty($this->refresh_token)) {
            debugging('Zoho CRM credentials are not fully configured for local_batchanalytics', DEBUG_DEVELOPER);
            return null;
        }

        $token = get_config('local_batchanalytics', 'zoho_access_token');
        $expires = (int)get_config('local_batchanalytics', 'zoho_token_expires');
        if (!empty($token) && $expires > time() + 60) {
            self::$access_token_cache = $token;
            self::$access_token_expires = $expires;
            return $token;
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
            debugging('Zoho CRM token acquisition failed for local_batchanalytics', DEBUG_DEVELOPER);
            return null;
        }

        $expiry = time() + (int)($json->expires_in ?? 3600);
        set_config('zoho_access_token', $json->access_token, 'local_batchanalytics');
        set_config('zoho_token_expires', $expiry, 'local_batchanalytics');
        self::$access_token_cache = $json->access_token;
        self::$access_token_expires = $expiry;
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
        $usernames = array_values(array_unique(array_filter(array_map([$this, 'normalize_username'], $usernames))));
        if (empty($usernames)) {
            return [];
        }

        $results = [];
        $tofetch = [];
        foreach ($usernames as $username) {
            $cachekey = strtolower($username);
            if (array_key_exists($cachekey, self::$student_cache)) {
                if (is_array(self::$student_cache[$cachekey])) {
                    $results[$cachekey] = self::$student_cache[$cachekey];
                }
                continue;
            }
            $tofetch[] = $username;
        }

        if (empty($tofetch)) {
            return $results;
        }

        $accessToken = $this->get_access_token();
        if (!$accessToken) {
            return $results;
        }

        $fields_to_fetch = $this->crm_fields;
        if (!in_array('Admission_Number', $fields_to_fetch, true)) {
            $fields_to_fetch[] = 'Admission_Number';
        }
        $fields_str = urlencode(implode(',', $fields_to_fetch));
        $chunks = array_chunk($tofetch, 10);

        foreach ($chunks as $chunk) {
            foreach ($chunk as $username) {
                self::$student_cache[strtolower($username)] = null;
            }

            $or_conditions = array_map(function($u) {
                return '(Admission_Number:equals:' . str_replace(',', '', $this->escape_zoho_value($u)) . ')';
            }, $chunk);

            $criteria = '(' . implode('or', $or_conditions) . ')';
            $searchUrl = $this->api_base_url . '/crm/v6/Child_Admission/search?criteria=' . urlencode($criteria) . '&fields=' . $fields_str;

            $curl = new \curl();
            $curl->setHeader([
                "Authorization: Zoho-oauthtoken {$accessToken}"
            ]);

            $response = $curl->get($searchUrl);
            $json = json_decode($response, true);

            if (!empty($json['data']) && is_array($json['data'])) {
                foreach ($json['data'] as $record) {
                    $keyField = !empty($record['Admission_Number']) ? trim((string)$record['Admission_Number']) : '';
                    if ($keyField === '') {
                        continue;
                    }
                    $cachekey = strtolower($keyField);
                    $record['username'] = $keyField;
                    self::$student_cache[$cachekey] = $record;
                    $results[$cachekey] = $record;
                }
            } else if (!empty($json['message']) && strtolower((string)$json['message']) !== 'no records found') {
                debugging('Zoho CRM search request failed for local_batchanalytics', DEBUG_DEVELOPER);
            }
        }

        foreach ($usernames as $username) {
            $cachekey = strtolower($username);
            if (isset(self::$student_cache[$cachekey]) && is_array(self::$student_cache[$cachekey])) {
                $results[$cachekey] = self::$student_cache[$cachekey];
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

        $results = $this->get_students_details([$username]);

        if (!empty($results)) {
            return reset($results);
        }

        return null;
    }
}

