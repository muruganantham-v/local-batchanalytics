<?php
namespace local_batchanalytics;

defined('MOODLE_INTERNAL') || die();

/**
 * Sends Cliq bot messages and records message history.
 *
 * @package local_batchanalytics
 */
class cliq_service {
    /** @var array */
    private const PLACEHOLDERS = [
        'ticket_id',
        'ticket_title',
        'ticket_reason',
        'ticket_status',
        'ticket_priority',
        'student_name',
        'student_email',
        'student_username',
        'course_name',
        'course_shortname',
        'batch_name',
        'raised_by_name',
        'raised_by_email',
        'ss_team_name',
        'ss_team_email',
        'batch_manager_name',
        'batch_manager_email',
        'resolution_feedback',
        'updated_by_name',
        'updated_by_email',
        'site_url',
    ];

    /**
     * @return array
     */
    public static function get_placeholders(): array {
        return self::PLACEHOLDERS;
    }

    /** Return complete template tokens that are not supported placeholders. */
    public static function get_invalid_placeholders(string $template): array {
        preg_match_all('/{{[^{}]*}}/', $template, $matches);
        $invalid = [];
        foreach ($matches[0] as $token) {
            $placeholder = substr($token, 2, -2);
            if (!in_array($placeholder, self::PLACEHOLDERS, true)) {
                $invalid[] = $token;
            }
        }

        return array_values(array_unique($invalid));
    }

    /**
     * @return string
     */
    public static function get_default_ticket_raise_subject(): string {
        return 'Notification: MAAC ticket raised - {{course_name}}';
    }

    /**
     * @return string
     */
    public static function get_default_ticket_raise_body(): string {
        return "*A new MAAC ticket has been raised.*\n\n"
            . "- Batch: {{batch_name}}\n"
            . "- Course: {{course_name}}\n"
            . "- Student: {{student_name}}\n"
            . "- Title: {{ticket_title}}\n"
            . "- Reason: {{ticket_reason}}\n"
            . "- Status: {{ticket_status}}\n"
            . "- Priority: {{ticket_priority}}\n"
            . "- Raised by: {{raised_by_name}}";
    }

    /**
     * @return string
     */
    public static function get_default_ticket_update_subject(): string {
        return 'Notification: MAAC ticket updated - {{ticket_title}}';
    }

    /**
     * @return string
     */
    public static function get_default_ticket_update_body(): string {
        return "*Your MAAC ticket has been updated.*\n\n"
            . "- Batch: {{batch_name}}\n"
            . "- Course: {{course_name}}\n"
            . "- Student: {{student_name}}\n"
            . "- Title: {{ticket_title}}\n"
            . "- Status: {{ticket_status}}\n"
            . "- Priority: {{ticket_priority}}\n"
            . "- Feedback: {{resolution_feedback}}\n"
            . "- Updated by: {{updated_by_name}}";
    }

    /**
     * @return string
     */
    public static function get_default_ticket_resolved_subject(): string {
        return 'Notification: MAAC ticket resolved - {{ticket_title}}';
    }

    public static function get_default_ticket_resolved_body(): string {
        return '*Your MAAC ticket has been resolved.*';
    }

    public static function get_default_ticket_auto_pm_subject(): string {
        return 'Notification: MAAC ticket assigned to PM - {{ticket_title}}';
    }

    /**
     * @return string
     */
    public static function get_default_ticket_auto_pm_body(): string {
        return "*Assign ticket to PM manager.*\n\n"
            . "- Batch: {{batch_name}}\n"
            . "- Course: {{course_name}}\n"
            . "- Student: {{student_name}}\n"
            . "- Title: {{ticket_title}}\n"
            . "- Status: {{ticket_status}}\n"
            . "- Priority: {{ticket_priority}}\n"
            . "- Batch manager: {{batch_manager_name}}";
    }

    /**
     * @return string
     */
    public static function get_default_ticket_student_escalation_email_subject(): string {
        return 'Your MAAC ticket has been escalated - {{ticket_title}}';
    }

    /**
     * @return string
     */
    public static function get_default_ticket_student_escalation_email_body(): string {
        return "Your MAAC ticket has been escalated to the Program Manager.\n\n"
            . "Course: {{course_name}}\n"
            . "Ticket: {{ticket_title}}\n"
            . "Status: {{ticket_status}}\n"
            . "Program Manager: {{batch_manager_name}}";
    }

    /**
     * Render a ticket subject and body using the shared template placeholders.
     *
     * @param string $subjecttemplate
     * @param string $bodytemplate
     * @param \stdClass $ticket
     * @param array $extra
     * @return array
     */
    public function render_ticket_template(string $subjecttemplate, string $bodytemplate, \stdClass $ticket,
            array $extra = []): array {
        $variables = $this->build_ticket_variables($ticket, $extra);
        return [
            'subject' => $this->render_template($subjecttemplate, $variables),
            'body' => $this->render_template($bodytemplate, $variables),
        ];
    }

    /**
     * @param string $type
     * @param \stdClass $ticket
     * @param array $recipients
     * @param array $extra
     * @return void
     */
    public function send_ticket_message(string $type, \stdClass $ticket, array $recipients, array $extra = []): void {
        $boturl = trim((string)get_config('local_batchanalytics', 'cliq_bot_url'));
        if ($boturl === '' || empty($recipients)) {
            return;
        }

        $templateconfig = $this->get_ticket_template_config($type);
        $subjectkey = $templateconfig['subjectkey'];
        $bodykey = $templateconfig['bodykey'];
        $defaultsubject = $templateconfig['defaultsubject'];
        $defaultbody = $templateconfig['defaultbody'];
        $subjecttemplate = trim((string)get_config('local_batchanalytics', $subjectkey));
        $bodytemplate = trim((string)get_config('local_batchanalytics', $bodykey));
        if ($subjecttemplate === '') {
            $subjecttemplate = $defaultsubject;
        }
        if ($bodytemplate === '') {
            $bodytemplate = $defaultbody;
        }

        $rendered = $this->render_ticket_template($subjecttemplate, $bodytemplate, $ticket, $extra);
        $subject = $rendered['subject'];
        $body = $rendered['body'];
        $text = trim($subject . "\n\n" . $body);

        foreach ($this->normalise_recipients($recipients) as $recipient) {
            $this->send_message($boturl, $type, $ticket, $recipient, $subject, $text);
        }
    }

    /**
     * @param string $type
     * @return array
     */
    private function get_ticket_template_config(string $type): array {
        if ($type === 'raise') {
            return [
                'subjectkey' => 'cliq_ticket_raise_subject',
                'bodykey' => 'cliq_ticket_raise_body',
                'defaultsubject' => self::get_default_ticket_raise_subject(),
                'defaultbody' => self::get_default_ticket_raise_body(),
            ];
        }
        if ($type === 'auto_pm') {
            return [
                'subjectkey' => 'cliq_ticket_auto_pm_subject',
                'bodykey' => 'cliq_ticket_auto_pm_body',
                'defaultsubject' => self::get_default_ticket_auto_pm_subject(),
                'defaultbody' => self::get_default_ticket_auto_pm_body(),
            ];
        }

        if ($type === 'resolved') {
            return [
                'subjectkey' => 'cliq_ticket_resolved_subject',
                'bodykey' => 'cliq_ticket_resolved_body',
                'defaultsubject' => self::get_default_ticket_resolved_subject(),
                'defaultbody' => self::get_default_ticket_resolved_body(),
            ];
        }

        return [
            'subjectkey' => 'cliq_ticket_update_subject',
            'bodykey' => 'cliq_ticket_update_body',
            'defaultsubject' => self::get_default_ticket_update_subject(),
            'defaultbody' => self::get_default_ticket_update_body(),
        ];
    }

    /**
     * @param string $boturl
     * @param string $type
     * @param \stdClass $ticket
     * @param array $recipient
     * @param string $subject
     * @param string $text
     * @return void
     */
    private function send_message(string $boturl, string $type, \stdClass $ticket, array $recipient, string $subject, string $text): void {
        global $CFG;

        require_once($CFG->libdir . '/filelib.php');

        $payload = [
            'text' => $text,
            // Note: Zoho Cliq API uses the key 'userids' but we pass mapped email addresses.
            'userids' => $recipient['email'],
        ];
        $botkey = trim((string)get_config('local_batchanalytics', 'cliq_bot_key'));
        $requesturl = $this->build_request_url($boturl, $botkey);

        $status = 'failed';
        $responsebody = '';
        $error = '';
        try {
            $curl = new \curl();
            $curl->setopt(['CURLOPT_CONNECTTIMEOUT' => 5, 'CURLOPT_TIMEOUT' => 15]);
            $curl->setHeader(['Content-Type: application/json']);
            $responsebody = (string)$curl->post($requesturl, json_encode($payload));
            $info = $curl->get_info();
            $httpcode = (int)($info['http_code'] ?? 0);
            if ($httpcode >= 200 && $httpcode < 300) {
                $response = json_decode($responsebody, true);
                $userids = is_array($response) && isset($response['user_ids']) && is_array($response['user_ids'])
                    ? $response['user_ids'] : null;
                $users = is_array($response) && isset($response['users']) && is_array($response['users'])
                    ? $response['users'] : null;
                if ($userids === [] && $users === []) {
                    $status = 'no user found';
                } else if ((!empty($userids) && is_array($userids)) || (!empty($users) && is_array($users))) {
                    $status = 'success';
                } else if (is_array($response)) {
                    $detail = $response['message'] ?? $response['error'] ?? $response['code'] ?? '';
                    if (is_scalar($detail) && trim((string)$detail) !== '') {
                        $error = 'Cliq response did not confirm delivery: ' . trim((string)$detail);
                    } else {
                        $error = 'Cliq response did not confirm delivery.';
                    }
                } else {
                    $error = 'Cliq returned a non-JSON response.';
                }
            } else {
                $error = trim('HTTP ' . $httpcode . ' ' . $responsebody);
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $this->record_history($ticket, $type, $recipient, $subject, $text, $status, $responsebody, $error);
    }

    /**
     * @param string $boturl
     * @param string $botkey
     * @return string
     */
    private function build_request_url(string $boturl, string $botkey): string {
        if ($botkey === '' || stripos($boturl, 'zapikey=') !== false) {
            return $boturl;
        }

        $separator = strpos($boturl, '?') === false ? '?' : '&';
        if (substr($boturl, -1) === '?' || substr($boturl, -1) === '&') {
            $separator = '';
        }

        return $boturl . $separator . 'zapikey=' . rawurlencode($botkey);
    }

    /**
     * @param \stdClass $ticket
     * @param string $type
     * @param array $recipient
     * @param string $subject
     * @param string $text
     * @param string $status
     * @param string $responsebody
     * @param string $error
     * @return void
     */
    private function record_history(\stdClass $ticket, string $type, array $recipient, string $subject, string $text,
            string $status, string $responsebody, string $error): void {
        global $DB;

        try {
            $dbman = $DB->get_manager();
            if (!$dbman->table_exists(new \xmldb_table('local_batchanalytics_cliq_history'))) {
                return;
            }
            $record = (object)[
                'ticketid'        => (int)($ticket->id ?? 0),
                'courseid'        => (int)($ticket->courseid ?? 0),
                'recipientuserid' => (int)($recipient['id'] ?? 0),
                'recipientemail'  => \core_text::substr((string)($recipient['email'] ?? ''), 0, 255),
                'messagetype'     => \core_text::substr($type, 0, 30),
                'subject'         => \core_text::substr($subject, 0, 255),
                'messagebody'     => $text,
                'status'          => \core_text::substr($status, 0, 20),
                'responsebody'    => $responsebody,
                'errormessage'    => $error,
                'timecreated'     => time(),
            ];
            $DB->insert_record('local_batchanalytics_cliq_history', $record);
        } catch (\Throwable $e) {
            // Cliq history must never block ticket workflows.
        }
    }

    /**
     * @param string $template
     * @param array $variables
     * @return string
     */
    private function render_template(string $template, array $variables): string {
        $replace = [];
        foreach (self::PLACEHOLDERS as $key) {
            $replace['{{' . $key . '}}'] = (string)($variables[$key] ?? '');
        }
        return strtr($template, $replace);
    }

    /**
     * @param \stdClass $ticket
     * @param array $extra
     * @return array
     */
    private function build_ticket_variables(\stdClass $ticket, array $extra): array {
        global $CFG, $DB;

        $course = $extra['course'] ?? null;
        if (!$course && !empty($ticket->courseid)) {
            $course = $DB->get_record('course', ['id' => (int)$ticket->courseid], 'id, fullname, shortname', IGNORE_MISSING);
        }
        $student      = $extra['student']      ?? $this->get_user((int)($ticket->studentuserid ?? 0));
        $raisedby     = $extra['raisedby']     ?? $this->get_user((int)($ticket->createdby ?? 0));
        $updatedby    = $extra['updatedby']    ?? null;
        $ssteam       = $extra['ssteam']       ?? $this->get_user((int)($ticket->ssteamuserid ?? 0));
        $batchmanager = $extra['batchmanager'] ?? $this->get_user((int)($ticket->batchmanageruserid ?? 0));

        $coursefullname  = $course ? format_string($course->fullname)  : '';
        $courseshortname = $course ? format_string($course->shortname) : '';

        return [
            'ticket_id'           => (string)($ticket->id ?? ''),
            'ticket_title'        => (string)($ticket->tickettitle ?? ''),
            'ticket_reason'       => (string)($ticket->ticketreason ?? ''),
            'ticket_status'       => (string)($ticket->status ?? ''),
            'ticket_priority'     => (string)($ticket->priority ?? ''),
            'student_name'        => $student      ? fullname($student)           : '',
            'student_email'       => $student      ? (string)$student->email      : '',
            'student_username'    => $student      ? (string)$student->username   : '',
            'course_name'         => $coursefullname,
            'course_shortname'    => $courseshortname,
            'batch_name'          => $this->get_batch_name($coursefullname, $courseshortname),
            'raised_by_name'      => $raisedby     ? fullname($raisedby)          : '',
            'raised_by_email'     => $raisedby     ? (string)$raisedby->email     : '',
            'ss_team_name'        => $ssteam       ? fullname($ssteam)            : '',
            'ss_team_email'       => $ssteam       ? (string)$ssteam->email       : '',
            'batch_manager_name'  => $batchmanager ? fullname($batchmanager)      : '',
            'batch_manager_email' => $batchmanager ? (string)$batchmanager->email : '',
            'resolution_feedback' => (string)($extra['resolution_feedback'] ?? $ticket->resolutionfeedback ?? ''),
            'updated_by_name'     => $updatedby    ? fullname($updatedby)         : '',
            'updated_by_email'    => $updatedby    ? (string)$updatedby->email    : '',
            'site_url'            => $CFG->wwwroot,
        ];
    }

    /**
     * @param int $userid
     * @return \stdClass|null
     */
    private function get_user(int $userid): ?\stdClass {
        if ($userid <= 0) {
            return null;
        }
        $user = \core_user::get_user($userid, 'id, firstname, lastname, username, email', IGNORE_MISSING);
        return $user ?: null;
    }

    /**
     * @param string $coursefullname
     * @param string $courseshortname
     * @return string
     */
    private function get_batch_name(string $coursefullname, string $courseshortname): string {
        $source = $coursefullname !== '' ? $coursefullname : $courseshortname;
        if (preg_match('/:\s*([^:]+)$/', $source, $matches)) {
            return trim($matches[1]);
        }
        return $courseshortname !== '' ? $courseshortname : $coursefullname;
    }

    /**
     * @param array $recipients
     * @return array
     */
    private function normalise_recipients(array $recipients): array {
        $clean = [];
        foreach ($recipients as $recipient) {
            if (is_object($recipient)) {
                $recipient = [
                    'id'       => (int)($recipient->id ?? 0),
                    'email'    => (string)($recipient->email ?? ''),
                    'fullname' => fullname($recipient),
                ];
            }
            if (!is_array($recipient)) {
                continue;
            }
            $email = trim((string)($recipient['email'] ?? ''));
            if ($email === '' || !validate_email($email)) {
                continue;
            }
            $clean[strtolower($email)] = [
                'id'       => (int)($recipient['id'] ?? 0),
                'email'    => $email,
                'fullname' => (string)($recipient['fullname'] ?? ''),
            ];
        }
        return array_values($clean);
    }
}
