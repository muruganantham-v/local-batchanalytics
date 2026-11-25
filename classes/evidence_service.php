<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_batchanalytics;

defined('MOODLE_INTERNAL') || die();

/**
 * Service for Class & Lab compliance photo and video evidence management.
 * Normalized two-table architecture:
 * 1. local_batchanalytics_evidence: Session metadata, week index, date, topic.
 * 2. local_batchanalytics_evidence_media: Child table for photos and videos.
 *
 * @package    local_batchanalytics
 * @copyright  2026 Emertxe Information Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class evidence_service {

    const TABLE = 'local_batchanalytics_evidence';
    const TABLE_MEDIA = 'local_batchanalytics_evidence_media';
    const TABLE_WEEKS = 'local_batchanalytics_evidence_weeks';
    const FILEAREA = 'class_evidence';
    const COMPONENT = 'local_batchanalytics';

    /**
     * Get photos and compliance progress for a section.
     * Loads session records and joins child media files.
     *
     * @param int $sectionid
     * @param int $courseid
     * @return array
     */
    public static function get_evidence_data(int $sectionid, int $courseid = 0): array {
        global $DB;

        $session_cards = [];
        $dbman = $DB->get_manager();

        if ($dbman->table_exists(self::TABLE)) {
            $params = ['sectionid' => $sectionid];
            $sql = "sectionid = :sectionid";
            if ($courseid > 0) {
                $params['courseid'] = $courseid;
                $sql .= " AND (courseid = :courseid OR courseid = 0 OR courseid IS NULL)";
            }
            $sessions = $DB->get_records_select(self::TABLE, $sql, $params, 'timemodified DESC, id DESC');

            if (!empty($sessions)) {
                $session_ids = array_keys($sessions);
                $media_by_session = [];

                if ($dbman->table_exists(self::TABLE_MEDIA)) {
                    list($in_sql, $in_params) = $DB->get_in_or_equal($session_ids);
                    $media_records = $DB->get_records_select(
                        self::TABLE_MEDIA,
                        "evidenceid $in_sql",
                        $in_params,
                        'sortorder ASC, id ASC'
                    );

                    foreach ($media_records as $m) {
                        $media_by_session[$m->evidenceid][] = $m;
                    }
                }

                foreach ($sessions as $s) {
                    $media_items = $media_by_session[$s->id] ?? [];
                    $items = [];
                    $photo_cnt = 0;
                    $video_cnt = 0;
                    $cover_url = '';
                    $cover_is_vid = false;

                    // If modern media child records exist, load them
                    if (!empty($media_items)) {
                        foreach ($media_items as $m) {
                            $is_vid = ($m->media_type === 'video') || (bool)preg_match('/\.(mp4|webm|mov|m4v|ogg)$/i', $m->filename);
                            if ($is_vid) {
                                $video_cnt++;
                            } else {
                                $photo_cnt++;
                            }

                            if ($cover_url === '') {
                                $cover_url = $m->fileurl ?: '';
                                $cover_is_vid = $is_vid;
                            } else if ($cover_is_vid && !$is_vid && !empty($m->fileurl)) {
                                // Prefer image as album cover
                                $cover_url = $m->fileurl;
                                $cover_is_vid = false;
                            }

                            $items[] = [
                                'id' => (int)$m->id,
                                'evidenceid' => (int)$s->id,
                                'filename' => $m->filename,
                                'fileurl' => $m->fileurl ?: '',
                                'caption' => $s->topic ?: ($s->caption ?: $s->session_type),
                                'session_type' => $s->session_type,
                                'session_date' => $s->session_date,
                                'is_video' => $is_vid,
                                'timecreated' => (int)$m->timecreated,
                            ];
                        }
                    } else if (!empty($s->fileurl)) {
                        // Legacy flat table fallback
                        $fn = $s->filename ?: basename($s->fileurl);
                        $is_vid = (bool)preg_match('/\.(mp4|webm|mov|m4v|ogg)$/i', $fn);
                        if ($is_vid) {
                            $video_cnt = 1;
                            $cover_is_vid = true;
                        } else {
                            $photo_cnt = 1;
                        }
                        $cover_url = $s->fileurl;
                        $items[] = [
                            'id' => (int)$s->id,
                            'evidenceid' => (int)$s->id,
                            'filename' => $fn,
                            'fileurl' => $s->fileurl,
                            'caption' => $s->topic ?: $s->caption,
                            'session_type' => $s->session_type,
                            'session_date' => $s->session_date,
                            'is_video' => $is_vid,
                            'timecreated' => (int)$s->timecreated,
                        ];
                    }

                    $raw_date = trim((string)$s->session_date);
                    $ts = strtotime($raw_date);
                    $norm_date = $ts ? date('d M', $ts) : $raw_date;
                    $iso_date = $ts ? date('Y-m-d', $ts) : date('Y-m-d');
                    $topic_clean = !empty($s->topic) ? $s->topic : (!empty($s->caption) ? $s->caption : ($s->session_type . ' session'));

                    $session_cards[] = [
                        'id' => (int)$s->id,
                        'session_key' => 'sess_' . $s->id,
                        'session_type' => $s->session_type ?: 'Class',
                        'session_date' => $norm_date,
                        'raw_date' => $raw_date,
                        'iso_date' => $iso_date,
                        'topic' => $topic_clean,
                        'caption' => $topic_clean,
                        'week_index' => (int)$s->week_index,
                        'cover_url' => $cover_url,
                        'cover_is_video' => $cover_is_vid,
                        'is_prototype' => false,
                        'items_count' => count($items),
                        'photo_count' => $photo_cnt,
                        'video_count' => $video_cnt,
                        'items' => $items,
                    ];
                }
            }
        }

        // No dummy or hardcoded cards: only real uploaded evidence session records are returned.
        // Determine current active week from module timeline
        $current_week = self::calculate_current_module_week($sectionid, $courseid);

        // Load dynamically configured weeks for this module
        $configured_weeks = self::get_configured_weeks($sectionid, $courseid);
        $weekly_tracker = [];

        foreach ($configured_weeks as $cw) {
            $w = (int)$cw->week_index;
            $cnt = 0;
            foreach ($session_cards as $s) {
                if ((int)($s['week_index'] ?? 0) === $w) {
                    $cnt++;
                }
            }
            $target = (int)($cw->target_count ?? 2);
            $status = $cnt >= $target ? 'complete' : ($cnt > 0 ? 'partial' : 'due');
            $is_current = ($w === $current_week);
            $weekly_tracker[] = [
                'week' => $w,
                'label' => $is_current ? ('WEEK ' . $w . ' (CURRENT)') : ('WEEK ' . $w),
                'completed' => $cnt,
                'target' => $target,
                'status' => $status,
                'is_current' => $is_current,
            ];
        }

        return [
            'weekly_tracker' => $weekly_tracker,
            'photos' => $session_cards,
        ];
    }

    /**
     * Ensure the evidence weeks table exists.
     */
    public static function ensure_weeks_table_exists(): void {
        global $DB;
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists(self::TABLE_WEEKS)) {
            $table = new \xmldb_table(self::TABLE_WEEKS);
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('sectionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('week_index', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('target_count', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '2');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('sec_course_week_ix', XMLDB_INDEX_NOTUNIQUE, ['sectionid', 'courseid', 'week_index']);
            $dbman->create_table($table);
        }
    }

    /**
     * Get configured weeks for a module.
     * Synchronizes any existing evidence week_index values.
     *
     * @param int $sectionid
     * @param int $courseid
     * @return array Array of week records
     */
    public static function get_configured_weeks(int $sectionid, int $courseid = 0): array {
        global $DB;
        self::ensure_weeks_table_exists();

        $params = ['sec' => $sectionid];
        $sql = "sectionid = :sec";
        if ($courseid > 0) {
            $params['cid'] = $courseid;
            $sql .= " AND (courseid = :cid OR courseid = 0)";
        }

        $records = $DB->get_records_select(self::TABLE_WEEKS, $sql, $params, 'week_index ASC');

        // Check if any evidence records exist with a week_index that has not been recorded yet in TABLE_WEEKS
        if ($DB->get_manager()->table_exists(self::TABLE)) {
            $ev_params = ['sec' => $sectionid];
            $ev_sql = "sectionid = :sec AND week_index > 0";
            if ($courseid > 0) {
                $ev_params['cid'] = $courseid;
                $ev_sql .= " AND (courseid = :cid OR courseid = 0 OR courseid IS NULL)";
            }
            $existing_weeks = $DB->get_fieldset_select(self::TABLE, 'DISTINCT week_index', $ev_sql, $ev_params);
            $known_weeks = [];
            foreach ($records as $r) {
                $known_weeks[] = (int)$r->week_index;
            }
            foreach ($existing_weeks as $ew) {
                $ew = (int)$ew;
                if (!in_array($ew, $known_weeks, true)) {
                    $new_rec = (object)[
                        'sectionid' => $sectionid,
                        'courseid' => $courseid,
                        'week_index' => $ew,
                        'target_count' => 2,
                        'timecreated' => time(),
                    ];
                    $new_rec->id = $DB->insert_record(self::TABLE_WEEKS, $new_rec);
                    $records[$new_rec->id] = $new_rec;
                    $known_weeks[] = $ew;
                }
            }
        }

        uasort($records, function($a, $b) {
            return (int)$a->week_index <=> (int)$b->week_index;
        });

        return array_values($records);
    }

    /**
     * Add a new week manually to the module.
     *
     * @param int $sectionid
     * @param int $courseid
     * @param int $target_count
     * @return int The new week_index
     */
    public static function add_evidence_week(int $sectionid, int $courseid = 0, int $target_count = 2): int {
        global $DB;
        self::ensure_weeks_table_exists();

        $existing = self::get_configured_weeks($sectionid, $courseid);
        $max_week = 0;
        foreach ($existing as $w) {
            if ((int)$w->week_index > $max_week) {
                $max_week = (int)$w->week_index;
            }
        }

        $next_week = $max_week + 1;
        $rec = (object)[
            'sectionid' => $sectionid,
            'courseid' => $courseid,
            'week_index' => $next_week,
            'target_count' => $target_count > 0 ? $target_count : 2,
            'timecreated' => time(),
        ];
        $DB->insert_record(self::TABLE_WEEKS, $rec);

        return $next_week;
    }

    /**
     * Delete an empty week from the module.
     *
     * @param int $sectionid
     * @param int $courseid
     * @param int $weekindex
     * @return bool
     */
    public static function delete_evidence_week(int $sectionid, int $courseid, int $weekindex): bool {
        global $DB;
        self::ensure_weeks_table_exists();

        // Safety check: do not allow deleting a week if evidence records exist for it
        if ($DB->get_manager()->table_exists(self::TABLE)) {
            $params = ['sec' => $sectionid, 'w' => $weekindex];
            $sql = "sectionid = :sec AND week_index = :w";
            if ($courseid > 0) {
                $params['cid'] = $courseid;
                $sql .= " AND (courseid = :cid OR courseid = 0 OR courseid IS NULL)";
            }
            if ($DB->record_exists_select(self::TABLE, $sql, $params)) {
                return false;
            }
        }

        $del_params = ['sec' => $sectionid, 'w' => $weekindex];
        $del_sql = "sectionid = :sec AND week_index = :w";
        if ($courseid > 0) {
            $del_params['cid'] = $courseid;
            $del_sql .= " AND (courseid = :cid OR courseid = 0)";
        }
        $DB->delete_records_select(self::TABLE_WEEKS, $del_sql, $del_params);
        return true;
    }

    /**
     * Calculate current active week for the module.
     *
     * @param int $sectionid
     * @param int $courseid
     * @return int
     */
    public static function calculate_current_module_week(int $sectionid, int $courseid = 0): int {
        global $DB;
        $mod_start_ts = 0;

        if ($sectionid > 0 && $DB->get_manager()->table_exists('local_bm_classsection')) {
            $sec = $DB->get_record('local_bm_classsection', ['id' => $sectionid]);
            if ($sec && !empty($sec->moduledata)) {
                $mods = \local_batchanalytics\util::decode_module_data($sec->moduledata, true);
                foreach ($mods as $m) {
                    $mcid = !empty($m['moodlecourseid']) ? (int)$m['moodlecourseid'] : (!empty($m['courseid']) ? (int)$m['courseid'] : 0);
                    if (($courseid === 0 || $mcid === $courseid) && !empty($m['plannedstart'])) {
                        $mod_start_ts = is_numeric($m['plannedstart']) ? (int)$m['plannedstart'] : strtotime($m['plannedstart']);
                        break;
                    }
                }
            }
        }

        if ($mod_start_ts > 0) {
            $diff = time() - $mod_start_ts;
            if ($diff <= 0) {
                return 1;
            }
            return max(1, (int)floor($diff / (7 * 86400)) + 1);
        }

        $configured = self::get_configured_weeks($sectionid, $courseid);
        if (!empty($configured)) {
            $first = reset($configured);
            return (int)$first->week_index;
        }

        return 1;
    }

    /**
     * Save session evidence and multiple photo/video media records.
     *
     * @param int $sectionid
     * @param int $courseid
     * @param array $session_meta Session meta (session_type, session_date, topic/caption, etc.)
     * @param array $files Stored files data ([filename, fileurl, filepath, filesize, tmp_name])
     * @return array ['evidenceid' => int, 'media_count' => int]
     */
    public static function save_session_evidence(int $sectionid, int $courseid, array $session_meta, array $files): array {
        global $DB, $USER;

        $dbman = $DB->get_manager();
        if (!$dbman->table_exists(self::TABLE)) {
            return ['evidenceid' => 0, 'media_count' => 0];
        }

        $session_type = clean_param($session_meta['session_type'] ?? 'Class', PARAM_ALPHANUMEXT);
        $raw_date = clean_param($session_meta['session_date'] ?? date('Y-m-d'), PARAM_TEXT);
        $topic = clean_param($session_meta['topic'] ?? ($session_meta['caption'] ?? ''), PARAM_TEXT);

        $sess_ts = strtotime($raw_date);
        $norm_date = $sess_ts ? date('d M', $sess_ts) : $raw_date;

        // Resolve week index from session_meta or dynamic configured weeks
        $week_index = (int)($session_meta['week_index'] ?? 0);
        if ($week_index <= 0) {
            $configured = self::get_configured_weeks($sectionid, $courseid);
            if (!empty($configured)) {
                $last_cw = end($configured);
                $week_index = (int)$last_cw->week_index;
            } else {
                // First upload auto-creates Week 1
                $week_index = self::add_evidence_week($sectionid, $courseid);
            }
        } else {
            // Ensure this week_index is recorded in configured weeks
            self::ensure_weeks_table_exists();
            $params = ['sec' => $sectionid, 'w' => $week_index];
            $sql = "sectionid = :sec AND week_index = :w";
            if ($courseid > 0) {
                $params['cid'] = $courseid;
                $sql .= " AND (courseid = :cid OR courseid = 0)";
            }
            if (!$DB->record_exists_select(self::TABLE_WEEKS, $sql, $params)) {
                $rec = (object)[
                    'sectionid' => $sectionid,
                    'courseid' => $courseid,
                    'week_index' => $week_index,
                    'target_count' => 2,
                    'timecreated' => time(),
                ];
                $DB->insert_record(self::TABLE_WEEKS, $rec);
            }
        }

        // Auto-resolve topic from lesson plan if empty
        if (empty($topic) && class_exists('\\local_batchanalytics\\lesson_plan_service')) {
            $plan_days = \local_batchanalytics\lesson_plan_service::get_lesson_plan($sectionid, $courseid);
            foreach ($plan_days as $pd) {
                if (!empty($pd->planned_date) && (stripos($pd->planned_date, $norm_date) !== false || $pd->planned_date === $raw_date)) {
                    $topic = $pd->topic;
                    break;
                }
            }
        }

        if (empty($topic)) {
            $topic = $session_type . ' session';
        }

        $evidenceid = (int)($session_meta['evidenceid'] ?? 0);
        if ($evidenceid > 0) {
            $evidence = $DB->get_record(self::TABLE, ['id' => $evidenceid]);
            if ($evidence) {
                $evidence->timemodified = time();
                $DB->update_record(self::TABLE, $evidence);
            } else {
                $evidenceid = 0;
            }
        }

        if ($evidenceid <= 0) {
            // Validate date conflict: check if this session_date already exists under a DIFFERENT week
            $conflict_params = [
                'sec' => $sectionid,
                'dt'  => $norm_date,
                'wk'  => $week_index,
            ];
            $conflict_sql = "sectionid = :sec AND session_date = :dt AND week_index != :wk";
            if ($courseid > 0) {
                $conflict_params['cid'] = $courseid;
                $conflict_sql .= " AND (courseid = :cid OR courseid = 0)";
            }
            $conflict = $DB->get_record_select(self::TABLE, $conflict_sql, $conflict_params);
            if ($conflict) {
                throw new \moodle_exception(
                    'evidence_conflict',
                    'local_batchanalytics',
                    '',
                    null,
                    "Conflict: The date '{$norm_date}' is already assigned to Week {$conflict->week_index}. A date cannot belong to multiple weeks."
                );
            }

            // Check if an evidence session already exists for this section, course, week, date, type and topic
            $existing_params = [
                'sectionid' => $sectionid,
                'courseid' => $courseid,
                'week_index' => $week_index,
                'session_date' => $norm_date,
                'session_type' => $session_type,
            ];
            if (!empty($topic) && $topic !== ($session_type . ' session')) {
                $existing_params['topic'] = $topic;
            }

            $evidence = $DB->get_record(self::TABLE, $existing_params);

            if ($evidence) {
                $evidenceid = (int)$evidence->id;
                $evidence->topic = $topic;
                $evidence->caption = $topic;
                $evidence->timemodified = time();
                $DB->update_record(self::TABLE, $evidence);
            } else {
                $new_session = (object)[
                    'sectionid' => $sectionid,
                    'courseid' => $courseid,
                    'week_index' => $week_index,
                    'day_index' => (int)($session_meta['day_index'] ?? 0),
                    'session_type' => $session_type,
                    'session_date' => $norm_date,
                    'caption' => $topic,
                    'topic' => $topic,
                    'filename' => '',
                    'fileurl' => '',
                    'userid' => (int)$USER->id,
                    'timecreated' => time(),
                    'timemodified' => time(),
                ];
                $evidenceid = (int)$DB->insert_record(self::TABLE, $new_session);
            }
        }

        // Save child media files into local_batchanalytics_evidence_media
        $media_count = 0;
        if ($evidenceid > 0 && $dbman->table_exists(self::TABLE_MEDIA)) {
            $first_fileurl = '';
            $first_filename = '';

            $current_max_sort = (int)($DB->get_field(self::TABLE_MEDIA, 'MAX(sortorder)', ['evidenceid' => $evidenceid]) ?? -1);

            foreach ($files as $idx => $f) {
                $fname = clean_param($f['filename'] ?? ($f['name'] ?? ''), PARAM_FILE);
                $furl = clean_param($f['fileurl'] ?? '', PARAM_URL);
                $fsize = (int)($f['filesize'] ?? ($f['size'] ?? 0));
                $fpath = clean_param($f['filepath'] ?? '/', PARAM_PATH);

                if (empty($fname)) {
                    continue;
                }

                // Prevent duplicate insertion of identical media within the same session
                $dup_check = [
                    'evidenceid' => $evidenceid,
                    'filename'   => $fname,
                ];
                if ($fsize > 0) {
                    $dup_check['filesize'] = $fsize;
                }
                if ($DB->record_exists(self::TABLE_MEDIA, $dup_check)) {
                    continue;
                }

                $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
                $is_video = in_array($ext, ['mp4', 'webm', 'mov', 'ogg', 'avi', 'm4v']);

                $media_rec = (object)[
                    'evidenceid'   => $evidenceid,
                    'media_type'   => $is_video ? 'video' : 'image',
                    'mimetype'     => $is_video ? 'video/' . $ext : 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext),
                    'filename'     => $fname,
                    'filepath'     => $fpath,
                    'fileurl'      => $furl,
                    'filesize'     => $fsize,
                    'sortorder'    => $current_max_sort + 1 + $idx,
                    'timecreated'  => time(),
                ];

                $DB->insert_record(self::TABLE_MEDIA, $media_rec);
                $media_count++;

                if (empty($first_fileurl) || (!$is_video && strpos($first_fileurl, '.mp4') !== false)) {
                    $first_fileurl = $furl;
                    $first_filename = $fname;
                }
            }

            // Update master record's cover fields and timemodified
            if (!empty($first_fileurl)) {
                $DB->set_field(self::TABLE, 'fileurl', $first_fileurl, ['id' => $evidenceid]);
                $DB->set_field(self::TABLE, 'filename', $first_filename, ['id' => $evidenceid]);
            }
            $DB->set_field(self::TABLE, 'timemodified', time(), ['id' => $evidenceid]);
        }

        return ['evidenceid' => $evidenceid, 'media_count' => $media_count];
    }

    /**
     * Add single photo / video evidence record (backward compatibility wrapper).
     *
     * @param int $sectionid
     * @param int $courseid
     * @param array $data
     * @return int
     */
    public static function add_evidence(int $sectionid, int $courseid, array $data): int {
        $files = [
            [
                'filename' => $data['filename'] ?? '',
                'fileurl'  => $data['fileurl'] ?? '',
                'filepath' => '/',
                'filesize' => 0,
            ]
        ];
        $res = self::save_session_evidence($sectionid, $courseid, $data, $files);
        return $res['evidenceid'];
    }

    /**
     * Delete entire evidence session and all its child media.
     *
     * @param int $evidenceid
     * @return bool
     */
    public static function delete_evidence_session(int $evidenceid): bool {
        global $DB;
        $dbman = $DB->get_manager();

        if ($evidenceid <= 0) {
            return false;
        }

        if ($dbman->table_exists(self::TABLE_MEDIA)) {
            $DB->delete_records(self::TABLE_MEDIA, ['evidenceid' => $evidenceid]);
        }

        if ($dbman->table_exists(self::TABLE)) {
            $DB->delete_records(self::TABLE, ['id' => $evidenceid]);
        }

        return true;
    }

    /**
     * Delete a single media item.
     *
     * @param int $mediaid
     * @return bool
     */
    public static function delete_evidence_media(int $mediaid): bool {
        global $DB;
        $dbman = $DB->get_manager();

        if ($mediaid <= 0 || !$dbman->table_exists(self::TABLE_MEDIA)) {
            return false;
        }

        $media = $DB->get_record(self::TABLE_MEDIA, ['id' => $mediaid]);
        if (!$media) {
            return false;
        }

        $evidenceid = (int)$media->evidenceid;
        $DB->delete_records(self::TABLE_MEDIA, ['id' => $mediaid]);

        // If no more media in this session, delete or clear master record cover
        $remaining = $DB->count_records(self::TABLE_MEDIA, ['evidenceid' => $evidenceid]);
        if ($remaining === 0) {
            $DB->delete_records(self::TABLE, ['id' => $evidenceid]);
        } else {
            $records = $DB->get_records_select(self::TABLE_MEDIA, 'evidenceid = :eid', ['eid' => $evidenceid], 'sortorder ASC, id ASC', '*', 0, 1);
            $first = !empty($records) ? reset($records) : null;
            if ($first) {
                $DB->set_field(self::TABLE, 'fileurl', $first->fileurl, ['id' => $evidenceid]);
                $DB->set_field(self::TABLE, 'filename', $first->filename, ['id' => $evidenceid]);
                $DB->set_field(self::TABLE, 'timemodified', time(), ['id' => $evidenceid]);
            }
        }

        return true;
    }

    /**
     * Edit existing session metadata (week, type, date, caption).
     *
     * @param int $evidenceid
     * @param int $weekindex
     * @param string $sessiontype
     * @param string $sessiondate
     * @param string $caption
     * @return bool
     */
    public static function edit_evidence_session(int $evidenceid, int $weekindex, string $sessiontype, string $sessiondate, string $caption): bool {
        global $DB;
        $evidence = $DB->get_record(self::TABLE, ['id' => $evidenceid]);
        if (!$evidence) {
            throw new \moodle_exception('notfound', 'local_batchanalytics', '', null, 'Evidence session not found.');
        }

        $sessiontype = clean_param($sessiontype ?: 'Class', PARAM_ALPHANUMEXT);
        $raw_date = clean_param($sessiondate, PARAM_TEXT);
        $ts = strtotime($raw_date);
        $norm_date = $ts ? date('d M', $ts) : $raw_date;
        $caption = clean_param($caption, PARAM_TEXT);

        // Check if date conflict exists with ANOTHER session in a different week
        $conflict_params = [
            'id'  => $evidenceid,
            'sec' => $evidence->sectionid,
            'dt'  => $norm_date,
            'wk'  => $weekindex,
        ];
        $conflict_sql = "id != :id AND sectionid = :sec AND session_date = :dt AND week_index != :wk";
        if ($evidence->courseid > 0) {
            $conflict_params['cid'] = $evidence->courseid;
            $conflict_sql .= " AND (courseid = :cid OR courseid = 0)";
        }
        $conflict = $DB->get_record_select(self::TABLE, $conflict_sql, $conflict_params);
        if ($conflict) {
            throw new \moodle_exception(
                'evidence_conflict',
                'local_batchanalytics',
                '',
                null,
                "Conflict: The date '{$norm_date}' is already assigned to Week {$conflict->week_index}. A date cannot belong to multiple weeks."
            );
        }

        // Ensure week_index is recorded in configured weeks
        self::ensure_weeks_table_exists();
        $params = ['sec' => $evidence->sectionid, 'w' => $weekindex];
        $sql = "sectionid = :sec AND week_index = :w";
        if ($evidence->courseid > 0) {
            $params['cid'] = $evidence->courseid;
            $sql .= " AND (courseid = :cid OR courseid = 0)";
        }
        if (!$DB->record_exists_select(self::TABLE_WEEKS, $sql, $params)) {
            $rec = (object)[
                'sectionid' => $evidence->sectionid,
                'courseid' => $evidence->courseid,
                'week_index' => $weekindex,
                'target_count' => 2,
                'timecreated' => time(),
            ];
            $DB->insert_record(self::TABLE_WEEKS, $rec);
        }

        $evidence->week_index = $weekindex;
        $evidence->session_type = $sessiontype;
        $evidence->session_date = $norm_date;
        $evidence->caption = $caption ?: ($sessiontype . ' session');
        $evidence->topic = $caption ?: ($sessiontype . ' session');
        $evidence->timemodified = time();
        $DB->update_record(self::TABLE, $evidence);

        return true;
    }
}
