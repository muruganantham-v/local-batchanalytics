<<<<<<< HEAD
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
    private $student_module_api_name;

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
        $this->student_module_api_name = crm_fields_helper::get_student_module_api_name();

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

        try {
            $cache = \cache::make('local_batchanalytics', 'batchdata');
            $cached_token = $cache->get('zoho_access_token');
            if (is_array($cached_token) && $cached_token['expires'] > time() + 60) {
                self::$access_token_cache = $cached_token['token'];
                self::$access_token_expires = $cached_token['expires'];
                return $cached_token['token'];
            }
        } catch (\Exception $e) {
            // Fallback if cache fails
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

        try {
            $cache = \cache::make('local_batchanalytics', 'batchdata');
            $cache->set('zoho_access_token', [
                'token' => $json->access_token,
                'expires' => $expiry
            ]);
        } catch (\Exception $e) {
            // Ignore cache failure
        }

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
        return substr(preg_replace('/[^A-Za-z0-9_\-\.@]/', '', $value), 0, 100);
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
        $chunks = array_chunk($tofetch, 10);

        foreach ($chunks as $chunk) {
            foreach ($chunk as $username) {
                self::$student_cache[strtolower($username)] = null;
            }

            $or_conditions = array_map(function($u) {
                return '(Admission_Number:equals:' . str_replace(',', '', $this->escape_zoho_value($u)) . ')';
            }, $chunk);

            $criteria = '(' . implode('or', $or_conditions) . ')';
            $searchUrl = $this->build_search_url($this->student_module_api_name, $criteria, $fields_to_fetch);

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
    /**
     * Fetch mentor CRM details by the Moodle batch group name.
     *
     * @param string $batchgroup
     * @return array|null
     */
    public function get_mentor_details_by_batch_group(string $batchgroup): ?array {
        $batchgroup = trim($batchgroup);
        $module = crm_fields_helper::get_mentor_module_api_name();
        $batchfield = preg_replace('/[^A-Za-z0-9_]/', '', crm_fields_helper::get_mentor_batch_field_key());
        $fields = crm_fields_helper::get_mentor_api_field_keys();

        if ($batchgroup === '' || $module === '' || $batchfield === '') {
            return null;
        }

        if (!in_array($batchfield, $fields, true)) {
            $fields[] = $batchfield;
        }

        $accessToken = $this->get_access_token();
        if (!$accessToken) {
            return null;
        }

        $criteria = '(' . $batchfield . ':equals:' . $this->escape_zoho_value($batchgroup) . ')';
        $searchUrl = $this->build_search_url($module, $criteria, $fields);

        $curl = new \curl();
        $curl->setHeader([
            "Authorization: Zoho-oauthtoken {$accessToken}"
        ]);

        $response = $curl->get($searchUrl);
        $json = json_decode($response, true);

        if (!empty($json['data']) && is_array($json['data'])) {
            return reset($json['data']) ?: null;
        }

        if (!empty($json['message']) && strtolower((string)$json['message']) !== 'no records found') {
            debugging('Zoho CRM mentor search request failed for local_batchanalytics', DEBUG_DEVELOPER);
        }

        return null;
    }

    /**
     * Backward-compatible wrapper for older callers.
     *
     * @param string $batchname
     * @return array|null
     */
    public function get_mentor_details_by_batch(string $batchname): ?array {
        return $this->get_mentor_details_by_batch_group($batchname);
    }
    /**
     * Build a Zoho CRM search URL.
     *
     * @param string $module
     * @param string $criteria
     * @param array $fields
     * @return string
     */
    private function build_search_url(string $module, string $criteria, array $fields): string {
        $module = preg_replace('/[^A-Za-z0-9_]/', '', $module);
        $fieldkeys = array_values(array_unique(array_filter(array_map(static function($field): string {
            return preg_replace('/[^A-Za-z0-9_]/', '', (string)$field);
        }, $fields))));

        $url = $this->api_base_url . '/crm/v6/' . $module . '/search?criteria=' . urlencode($criteria);
        if (!empty($fieldkeys)) {
            $url .= '&fields=' . urlencode(implode(',', $fieldkeys));
        }
        return $url;
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

    /** Update configured fields on a student CRM record. */
    public function update_student_fields(string $recordid, array $fields): bool {
        $recordid = preg_replace('/[^A-Za-z0-9]/', '', $recordid);
        $fields = array_filter($fields, static function($value, $key): bool {
            return preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', (string)$key) && is_numeric($value);
        }, ARRAY_FILTER_USE_BOTH);
        if ($recordid === '' || empty($fields) || $this->student_module_api_name === '') {
            return false;
        }

        $token = $this->get_access_token();
        if (!$token) {
            return false;
        }

        $url = rtrim($this->api_base_url, '/') . '/crm/v6/'
            . rawurlencode($this->student_module_api_name) . '/' . rawurlencode($recordid);
        $curl = new \curl();
        $curl->setHeader([
            "Authorization: Zoho-oauthtoken {$token}",
            'Content-Type: application/json',
        ]);
        $response = $curl->put($url, json_encode(['data' => [$fields]]));
        $decoded = json_decode($response, true);
        return !empty($decoded['data'][0]['status']) && strtolower((string)$decoded['data'][0]['status']) === 'success';
    }
}

=======
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
>>>>>>> origin/main
