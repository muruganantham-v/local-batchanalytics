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
            $cache = \cache::make('local_batchanalytics', 'zohotokendata'); // F-18: dedicated store, no TTL
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
            $cache = \cache::make('local_batchanalytics', 'zohotokendata'); // F-18: dedicated store, no TTL
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

    /**
     * Remove a rejected token from both request and application caches.
     *
     * @return void
     */
    private function clear_access_token_cache(): void {
        self::$access_token_cache = null;
        self::$access_token_expires = 0;

        try {
            $cache = \cache::make('local_batchanalytics', 'zohotokendata');
            $cache->delete('zoho_access_token');
        } catch (\Exception $e) {
            // A fresh request can still obtain a new token without the cache store.
        }
    }

    private function normalize_username($username)
    {
        return trim((string) $username);
    }

    private function escape_zoho_value($value)
    {
        return str_replace(
            ['\\', ',', '(', ')'],
            ['\\\\', '\\,', '\\(', '\\)'],
            trim((string)$value)
        );
    }

    /**
     * Execute one CRM student search request and retain its HTTP status.
     *
     * @param string $url
     * @param string $accesstoken
     * @return array{httpcode:int,body:string}
     */
    private function request_student_search(string $url, string $accesstoken): array {
        $curl = new \curl();
        $curl->setHeader([
            "Authorization: Zoho-oauthtoken {$accesstoken}"
        ]);

        $body = (string)$curl->get($url);
        $info = $curl->get_info();
        return [
            'httpcode' => (int)($info['http_code'] ?? 0),
            'body' => $body,
        ];
    }

    /**
     * Check whether a successful Zoho response explicitly confirms no records.
     *
     * @param int $httpcode
     * @param mixed $response
     * @return bool
     */
    private function is_no_records_response(int $httpcode, $response): bool {
        if ($httpcode === 204) {
            return true;
        }
        if (!is_array($response)) {
            return false;
        }

        return strtoupper((string)($response['code'] ?? '')) === 'NO_CONTENT'
            || strtolower((string)($response['message'] ?? '')) === 'no records found';
    }

    // --- UPGRADED BATCH FUNCTION USING SEARCH API ---
    public function get_students_details(array $usernames)
    {
        $requested = [];
        foreach ($usernames as $username) {
            $username = $this->normalize_username($username);
            if ($username === '') {
                continue;
            }
            $key = strtolower($username);
            if (!isset($requested[$key])) {
                $requested[$key] = $username;
            }
        }
        $usernames = array_values($requested);
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
            throw new \RuntimeException('Zoho CRM access token is unavailable.');
        }

        if ($this->student_module_api_name === '') {
            throw new \RuntimeException('Zoho CRM student module API name is unavailable.');
        }

        $fields_to_fetch = $this->crm_fields;
        if (!in_array('Admission_Number', $fields_to_fetch, true)) {
            $fields_to_fetch[] = 'Admission_Number';
        }
        $chunks = array_chunk($tofetch, 10);

        foreach ($chunks as $chunk) {
            $or_conditions = array_map(function($u) {
                return '(Admission_Number:equals:' . $this->escape_zoho_value($u) . ')';
            }, $chunk);

            $criteria = '(' . implode('or', $or_conditions) . ')';
            $searchUrl = $this->build_search_url($this->student_module_api_name, $criteria, $fields_to_fetch);

            $attempt = 0;
            do {
                $response = $this->request_student_search($searchUrl, $accessToken);
                if ($response['httpcode'] !== 401 || $attempt > 0) {
                    break;
                }

                $this->clear_access_token_cache();
                $accessToken = $this->get_access_token();
                if (!$accessToken) {
                    throw new \RuntimeException('Zoho CRM access token refresh failed.');
                }
                $attempt++;
            } while (true);

            $httpcode = $response['httpcode'];
            $json = json_decode($response['body'], true);
            if ($httpcode < 200 || $httpcode >= 300) {
                throw new \RuntimeException('Zoho CRM student search failed with HTTP status ' . $httpcode . '.');
            }

            $chunkkeys = [];
            foreach ($chunk as $username) {
                $chunkkeys[strtolower($username)] = $username;
            }

            if ($this->is_no_records_response($httpcode, $json)) {
                foreach ($chunkkeys as $cachekey => $username) {
                    self::$student_cache[$cachekey] = null;
                }
                continue;
            }

            if (!is_array($json) || !array_key_exists('data', $json) || !is_array($json['data'])) {
                throw new \RuntimeException('Zoho CRM student search returned an invalid response.');
            }

            if (!empty($json['data'])) {
                foreach ($json['data'] as $record) {
                    $keyField = !empty($record['Admission_Number']) ? trim((string)$record['Admission_Number']) : '';
                    if ($keyField === '') {
                        continue;
                    }
                    $cachekey = strtolower($keyField);
                    if (!isset($chunkkeys[$cachekey])) {
                        continue;
                    }
                    $record['username'] = $chunkkeys[$cachekey];
                    self::$student_cache[$cachekey] = $record;
                    $results[$cachekey] = $record;
                }
            }

            foreach ($chunkkeys as $cachekey => $username) {
                if (!array_key_exists($cachekey, self::$student_cache)) {
                    self::$student_cache[$cachekey] = null;
                }
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
