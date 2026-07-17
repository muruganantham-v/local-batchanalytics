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

        $subjectkey = $type === 'raise' ? 'cliq_ticket_raise_subject' : 'cliq_ticket_update_subject';
        $bodykey = $type === 'raise' ? 'cliq_ticket_raise_body' : 'cliq_ticket_update_body';
        $defaultsubject = $type === 'raise'
            ? self::get_default_ticket_raise_subject()
            : self::get_default_ticket_update_subject();
        $defaultbody = $type === 'raise'
            ? self::get_default_ticket_raise_body()
            : self::get_default_ticket_update_body();

        $subjecttemplate = trim((string)get_config('local_batchanalytics', $subjectkey));
        $bodytemplate = trim((string)get_config('local_batchanalytics', $bodykey));
        if ($subjecttemplate === '') {
            $subjecttemplate = $defaultsubject;
        }
        if ($bodytemplate === '') {
            $bodytemplate = $defaultbody;
        }

        $variables = $this->build_ticket_variables($ticket, $extra);
        $subject = $this->render_template($subjecttemplate, $variables);
        $body = $this->render_template($bodytemplate, $variables);
        $text = trim($subject . "\n\n" . $body);

        foreach ($this->normalise_recipients($recipients) as $recipient) {
            $this->send_message($boturl, $type, $ticket, $recipient, $subject, $text);
        }
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
            'userids' => $recipient['email'],
        ];
        $botkey = trim((string)get_config('local_batchanalytics', 'cliq_bot_key'));
        $requesturl = $this->build_request_url($boturl, $botkey);

        $status = 'failed';
        $responsebody = '';
        $error = '';
        try {
            $curl = new \curl();
            $curl->setHeader(['Content-Type: application/json']);
            $responsebody = (string)$curl->post($requesturl, json_encode($payload));
            $info = $curl->get_info();
            $httpcode = (int)($info['http_code'] ?? 0);
            if ($httpcode >= 200 && $httpcode < 300) {
                $status = 'success';
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
                'ticketid' => (int)($ticket->id ?? 0),
                'courseid' => (int)($ticket->courseid ?? 0),
                'recipientuserid' => (int)($recipient['id'] ?? 0),
                'recipientemail' => \core_text::substr((string)($recipient['email'] ?? ''), 0, 255),
                'messagetype' => \core_text::substr($type, 0, 30),
                'subject' => \core_text::substr($subject, 0, 255),
                'messagebody' => $text,
                'status' => \core_text::substr($status, 0, 20),
                'responsebody' => $responsebody,
                'errormessage' => $error,
                'timecreated' => time(),
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
        $student = $extra['student'] ?? $this->get_user((int)($ticket->studentuserid ?? 0));
        $raisedby = $extra['raisedby'] ?? $this->get_user((int)($ticket->createdby ?? 0));
        $updatedby = $extra['updatedby'] ?? null;
        $ssteam = $extra['ssteam'] ?? $this->get_user((int)($ticket->ssteamuserid ?? 0));
        $batchmanager = $extra['batchmanager'] ?? $this->get_user((int)($ticket->batchmanageruserid ?? 0));

        $coursefullname = $course ? format_string($course->fullname) : '';
        $courseshortname = $course ? format_string($course->shortname) : '';

        return [
            'ticket_id' => (string)((int)($ticket->id ?? 0)),
            'ticket_title' => (string)($ticket->tickettitle ?? ''),
            'ticket_reason' => (string)($ticket->ticketreason ?? ''),
            'ticket_status' => ucfirst(str_replace('_', ' ', (string)($ticket->status ?? 'open'))),
            'ticket_priority' => ucfirst((string)($ticket->priority ?? 'low')),
            'student_name' => $student ? fullname($student) : '',
            'student_email' => $student->email ?? '',
            'student_username' => $student->username ?? '',
            'course_name' => $coursefullname,
            'course_shortname' => $courseshortname,
            'batch_name' => $this->get_batch_name($coursefullname, $courseshortname),
            'raised_by_name' => $raisedby ? fullname($raisedby) : '',
            'raised_by_email' => $raisedby->email ?? '',
            'ss_team_name' => $ssteam ? fullname($ssteam) : '',
            'ss_team_email' => $ssteam->email ?? '',
            'batch_manager_name' => $batchmanager ? fullname($batchmanager) : '',
            'batch_manager_email' => $batchmanager->email ?? '',
            'resolution_feedback' => (string)($ticket->resolutionfeedback ?? ''),
            'updated_by_name' => $updatedby ? fullname($updatedby) : '',
            'updated_by_email' => $updatedby->email ?? '',
            'site_url' => $CFG->wwwroot,
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
                    'id' => (int)($recipient->id ?? 0),
                    'email' => (string)($recipient->email ?? ''),
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
                'id' => (int)($recipient['id'] ?? 0),
                'email' => $email,
                'fullname' => (string)($recipient['fullname'] ?? ''),
            ];
        }
        return array_values($clean);
    }
}
