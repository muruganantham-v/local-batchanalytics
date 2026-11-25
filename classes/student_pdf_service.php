<?php
/**
 * Dedicated Student Profile PDF Generation Service.
 *
 * Encapsulates all PDF layout building, avatar rendering, and TCPDF export
 * for the student profile export. Contains only: Basic Info, Academic,
 * Placement-Critical, and Course Performance.
 *
 * @package    local_batchanalytics
 * @copyright  2026 Emertxe Information Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_batchanalytics;

defined('MOODLE_INTERNAL') || die();

class student_pdf_service {

    /**
     * Generate and force-download the student profile PDF.
     *
     * @param int $studentid
     * @param int $batchid
     * @param int $viewerid
     */
    public static function generate_student_pdf(int $studentid, int $batchid = 0, int $viewerid = 0): void {
        global $CFG;
        require_once($CFG->libdir . '/pdflib.php');

        $data = student_page_service::get_student_details($studentid, $batchid, $viewerid);

        $pdf = new \pdf();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(12, 12, 12);
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 9);

        $html = self::build_student_pdf_html($data);
        $pdf->writeHTML($html, true, false, true, false, '');

        $clean_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $data['fullname'] ?? 'student');
        $filename = 'Student_Profile_' . $clean_name . '_' . ($data['admission_id'] ?? 'profile') . '.pdf';

        $pdf->Output($filename, 'D');
        exit;
    }

    /**
     * Generate a crisp circular avatar PNG with brand gradient encoded as base64 for TCPDF rendering.
     *
     * @param string $initials
     * @param int $size
     * @return string
     */
    public static function get_circular_avatar_base64(string $initials = 'DB', int $size = 128): string {
        if (!extension_loaded('gd')) {
            return '';
        }
        $im = @imagecreatetruecolor($size, $size);
        if (!$im) {
            return '';
        }
        imagealphablending($im, false);
        imagesavealpha($im, true);
        $trans = imagecolorallocatealpha($im, 0, 0, 0, 127);
        imagefill($im, 0, 0, $trans);
        imagealphablending($im, true);

        // Circular mask with linear gradient from #cc0066 (204, 0, 102) to #663399 (102, 51, 153)
        $r1 = 204; $g1 = 0;   $b1 = 102;
        $r2 = 102; $g2 = 51;  $b2 = 153;
        $radius = $size / 2;
        $cx = $radius;
        $cy = $radius;

        for ($y = 0; $y < $size; $y++) {
            $t = $y / ($size - 1);
            $r = (int)($r1 + $t * ($r2 - $r1));
            $g = (int)($g1 + $t * ($g2 - $g1));
            $b = (int)($b1 + $t * ($b2 - $b1));
            $col = imagecolorallocate($im, $r, $g, $b);

            $dy = $y - $cy;
            $distSq = $radius * $radius - $dy * $dy;
            if ($distSq >= 0) {
                $dx = sqrt($distSq);
                $x1 = (int)($cx - $dx);
                $x2 = (int)($cx + $dx);
                imageline($im, $x1, $y, $x2, $y, $col);
            }
        }

        // Text: bold white
        $white = imagecolorallocate($im, 255, 255, 255);
        $font_path = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
        if (file_exists($font_path)) {
            $font_size = (int)($size * 0.34);
            $bbox = imagettfbbox($font_size, 0, $font_path, $initials);
            $text_w = abs($bbox[4] - $bbox[0]);
            $text_h = abs($bbox[5] - $bbox[1]);
            $x = (int)(($size - $text_w) / 2);
            $y = (int)(($size + $text_h) / 2) - 3;
            imagettftext($im, $font_size, 0, $x, $y, $white, $font_path, $initials);
        } else {
            $font_size = 5;
            $fw = imagefontwidth($font_size) * strlen($initials);
            $fh = imagefontheight($font_size);
            imagestring($im, $font_size, (int)(($size - $fw) / 2), (int)(($size - $fh) / 2), $initials, $white);
        }

        ob_start();
        imagepng($im);
        $png = ob_get_clean();
        imagedestroy($im);
        return base64_encode($png);
    }

    /**
     * Build clean, structured TCPDF-compatible HTML for the student profile PDF.
     * Uses the exact design, tab headings, and details from the prototype file.
     * Contains only: Basic Info, Academic, Placement-Critical, Course Performance.
     *
     * @param array $data
     * @return string
     */
    public static function build_student_pdf_html(array $data): string {
        $avatar_b64 = self::get_circular_avatar_base64($data['initials'] ?? 'ST');

        // Standard spacer row providing vertical breathing room between cards
        $card_spacer = '<table cellpadding="0" cellspacing="0" border="0" style="width: 100%; height: 9px;"><tr><td style="height: 9px; font-size: 1px; line-height: 9px;">&nbsp;</td></tr></table>';

        $today_str = date('d M Y');

        ob_start();
        ?>
        <style>
            .proto-card {
                width: 100%;
                border-collapse: collapse;
                border: 1px solid #e3e6ea;
                background-color: #ffffff;
            }
            .proto-ch {
                background-color: #fbfcfd;
                color: #3a3f44;
                font-size: 10pt;
                font-weight: bold;
                padding: 7px 10px;
                border-bottom: 1px solid #e3e6ea;
            }
            .src-crm {
                background-color: #eef1f4;
                color: #5a6066;
                font-size: 6.5pt;
                font-weight: bold;
                border: 1px solid #cfd6dc;
                padding: 1px 6px;
            }
            .src-lms {
                background-color: #e7f4ec;
                color: #3a7d4a;
                font-size: 6.5pt;
                font-weight: bold;
                border: 1px solid #9ec9ac;
                padding: 1px 6px;
            }
            .proto-cell {
                border-right: 1px solid #eef1f4;
                border-bottom: 1px solid #eef1f4;
                padding: 6px 10px;
                vertical-align: top;
                background-color: #ffffff;
            }
            .proto-cell-last {
                border-bottom: 1px solid #eef1f4;
                padding: 6px 10px;
                vertical-align: top;
                background-color: #ffffff;
            }
            .proto-k {
                font-size: 6.5pt;
                font-weight: bold;
                color: #999999;
                text-transform: uppercase;
                letter-spacing: 0.3px;
                line-height: 1.1;
            }
            .proto-v {
                font-size: 9pt;
                font-weight: 600;
                color: #444444;
                line-height: 1.25;
            }
            .proto-v-muted {
                font-size: 9pt;
                font-weight: normal;
                color: #bbbbbb;
            }
            .pb-pet {
                background-color: #e7f4ec;
                color: #15803d;
                font-size: 7pt;
                font-weight: bold;
                border: 1px solid #9ec9ac;
                padding: 2px 7px;
                text-align: center;
            }
            .pb-pet-green {
                background-color: #e7f4ec;
                color: #15803d;
                font-size: 7pt;
                font-weight: bold;
                border: 1px solid #9ec9ac;
                padding: 2px 7px;
                text-align: center;
            }
            .pb-pet-orange {
                background-color: #ffedd5;
                color: #ea580c;
                font-size: 7pt;
                font-weight: bold;
                border: 1px solid #fed7aa;
                padding: 2px 7px;
                text-align: center;
            }
            .pb-pet-red {
                background-color: #fee2e2;
                color: #dc2626;
                font-size: 7pt;
                font-weight: bold;
                border: 1px solid #fca5a5;
                padding: 2px 7px;
                text-align: center;
            }
            .pb-pet-neutral {
                background-color: #f1f5f9;
                color: #64748b;
                font-size: 7pt;
                font-weight: bold;
                border: 1px solid #cbd5e1;
                padding: 2px 7px;
                text-align: center;
            }
            .pb-spot {
                background-color: #fbf2d9;
                color: #b8860b;
                font-size: 7pt;
                font-weight: bold;
                border: 1px solid #e6d19a;
                padding: 2px 7px;
                text-align: center;
            }
            .pb-pt {
                background-color: #f0ecf6;
                color: #663399;
                font-size: 7pt;
                font-weight: bold;
                border: 1px solid #c9b8e0;
                padding: 2px 7px;
                text-align: center;
            }
        </style>

        <!-- ==================== HERO PROFILE CARD ==================== -->
        <table cellpadding="8" cellspacing="0" class="proto-card" style="page-break-inside: avoid; border: 1px solid #e3e6ea; background-color: #ffffff;">
            <tr>
                <td style="width: 11%; vertical-align: middle; text-align: center;">
                    <?php if (!empty($avatar_b64)): ?>
                        <img src="@<?= $avatar_b64 ?>" width="46" height="46" />
                    <?php else: ?>
                        <table cellpadding="0" cellspacing="0" border="0" style="width: 44px; height: 44px; background-color: #663399;">
                            <tr>
                                <td style="text-align: center; vertical-align: middle; color: #ffffff; font-size: 13pt; font-weight: bold; height: 44px; line-height: 44px;">
                                    <?= s($data['initials'] ?? 'ST') ?>
                                </td>
                            </tr>
                        </table>
                    <?php endif; ?>
                </td>
                <td style="width: 66%; vertical-align: middle;">
                    <span style="font-size: 15pt; font-weight: 800; color: #2e2e2e; line-height: 1.2;"><?= s($data['fullname'] ?? '') ?></span><br/>
                    <span style="font-size: 7.5pt; color: #6e7377; line-height: 1.3;">
                        Admission ID <?= s($data['admission_id'] ?? '') ?> &nbsp;·&nbsp; Batch <?= s($data['batch_code'] ?? '') ?> &nbsp;·&nbsp; <?= s($data['course_name'] ?? '') ?> &nbsp;·&nbsp; <?= s($data['delivery_mode'] ?? '') ?>
                    </span><br/>
                    <div style="margin-top: 6px; line-height: 22px;">
                        <?php if (!empty($data['hero_badges'])): ?>
                            <?php foreach ($data['hero_badges'] as $b): ?>
                                <?php
                                $bcls = 'pb-pet';
                                if ($b['type'] === 'pet') {
                                    $bcls = !empty($b['pdf_class']) ? ('pb-pet-' . str_replace('badge-', '', $b['pdf_class'])) : 'pb-pet-green';
                                } else if ($b['type'] === 'spot') {
                                    $bcls = 'pb-spot';
                                } else if ($b['type'] === 'power_track') {
                                    $bcls = 'pb-pt';
                                }
                                ?>
                                <span class="<?= $bcls ?>" style="margin-right: 6px; margin-bottom: 6px;">&nbsp;<?= s($b['label']) ?>&nbsp;</span>&nbsp;&nbsp;
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="pb-pet">&nbsp;PET: <?= s($data['placement_critical']['pet_status'] ?? '-') ?>&nbsp;</span>
                        <?php endif; ?>
                    </div>
                </td>
                <td style="width: 23%; text-align: right; vertical-align: middle;">
                    <div style="font-size: 20pt; font-weight: 800; color: #333333; line-height: 1;"><?= s($data['maac_rating'] ?? '-') ?></div>
                    <div style="font-size: 7.5pt; font-weight: 600; color: #6e7377; margin-top: 2px;">MAAC rating</div>
                    <div style="font-size: 6.8pt; color: #6e7377; margin-top: 3px;"><?= s($data['overall_attendance'] ?? '-') ?> attendance &nbsp;·&nbsp; <?= s($data['total_projects'] ?? '-') ?> projects</div>
                </td>
            </tr>
        </table>

        <!-- ==================== SECTION 1: BASIC INFO ==================== -->
        <table cellpadding="0" cellspacing="0" border="0" style="width: 100%; margin-top: 15px; margin-bottom: 8px;">
            <tr>
                <td style="width: 100%; font-size: 12pt; font-weight: bold; color: #663399; border-bottom: 2px solid #cc0066; padding-bottom: 5px;">
                    Basic Info
                </td>
            </tr>
        </table>

        <!-- Basic Information Card -->
        <table cellpadding="6" cellspacing="0" class="proto-card" style="page-break-inside: avoid;">
            <tr>
                <td colspan="3" class="proto-ch">
                    <b>Basic Information</b>
                </td>
            </tr>
            <tr>
                <td style="width: 33.33%;" class="proto-cell">
                    <span class="proto-k">FIRST NAME</span><br/>
                    <span class="proto-v"><?= s($data['basic_info']['first_name'] ?? '-') ?></span>
                </td>
                <td style="width: 33.33%;" class="proto-cell">
                    <span class="proto-k">LAST NAME</span><br/>
                    <span class="proto-v"><?= s($data['basic_info']['last_name'] ?? '-') ?></span>
                </td>
                <td style="width: 33.34%;" class="proto-cell-last">
                    <span class="proto-k">EMAIL ID</span><br/>
                    <span class="proto-v"><?= s($data['basic_info']['email'] ?? '-') ?></span>
                </td>
            </tr>
            <tr>
                <td class="proto-cell">
                    <span class="proto-k">MOBILE NUMBER</span><br/>
                    <span class="proto-v"><?= s($data['basic_info']['mobile'] ?? '-') ?></span>
                </td>
                <td class="proto-cell">
                    <span class="proto-k">WHATSAPP NUMBER</span><br/>
                    <span class="proto-v"><?= s($data['basic_info']['whatsapp'] ?? '-') ?></span>
                </td>
                <td class="proto-cell-last">
                    <span class="proto-k">HOME STATE</span><br/>
                    <span class="proto-v"><?= s($data['basic_info']['home_state'] ?? '-') ?></span>
                </td>
            </tr>
            <tr>
                <td class="proto-cell">
                    <span class="proto-k">PERMANENT ADDRESS</span><br/>
                    <span class="proto-v"><?= s($data['basic_info']['permanent_address'] ?? '-') ?></span>
                </td>
                <td class="proto-cell">
                    <span class="proto-k">PIN CODE</span><br/>
                    <span class="proto-v"><?= s($data['basic_info']['pin_code'] ?? '-') ?></span>
                </td>
                <td class="proto-cell-last">
                    <span class="proto-k">PARENTS NAME</span><br/>
                    <span class="proto-v"><?= s($data['basic_info']['parents_name'] ?? '-') ?></span>
                </td>
            </tr>
            <tr>
                <td class="proto-cell" style="border-bottom: none;">
                    <span class="proto-k">PARENTS PHONE</span><br/>
                    <span class="proto-v"><?= s($data['basic_info']['parents_phone'] ?? '-') ?></span>
                </td>
                <td class="proto-cell" style="border-bottom: none;">
                    <span class="proto-k">INSTAGRAM</span><br/>
                    <span class="proto-v"><?= s($data['basic_info']['instagram'] ?? '-') ?></span>
                </td>
                <td class="proto-cell-last" style="border-bottom: none;">
                    <span class="proto-k">LINKEDIN</span><br/>
                    <span class="proto-v"><?= s($data['basic_info']['linkedin'] ?? '-') ?></span>
                </td>
            </tr>
        </table>

        <!-- ==================== SECTION 2: ACADEMIC ==================== -->
        <table cellpadding="0" cellspacing="0" border="0" style="width: 100%; margin-top: 15px; margin-bottom: 8px;">
            <tr>
                <td style="width: 100%; font-size: 12pt; font-weight: bold; color: #663399; border-bottom: 2px solid #cc0066; padding-bottom: 5px;">
                    Academic
                </td>
            </tr>
        </table>

        <!-- School & Pre-University Card -->
        <table cellpadding="6" cellspacing="0" class="proto-card" style="page-break-inside: avoid;">
            <tr>
                <td colspan="3" class="proto-ch">
                    <b>School &amp; Pre-University</b>
                </td>
            </tr>
            <tr>
                <td style="width: 33.33%; border-bottom: none;" class="proto-cell">
                    <span class="proto-k">CLASS X</span><br/>
                    <span class="<?= empty($data['academic_info']['class_x']) || $data['academic_info']['class_x'] === '-' ? 'proto-v-muted' : 'proto-v' ?>"><?= s($data['academic_info']['class_x'] ?? '-') ?></span>
                </td>
                <td style="width: 33.33%; border-bottom: none;" class="proto-cell">
                    <span class="proto-k">CLASS XII</span><br/>
                    <span class="<?= empty($data['academic_info']['class_xii']) || $data['academic_info']['class_xii'] === '-' ? 'proto-v-muted' : 'proto-v' ?>"><?= s($data['academic_info']['class_xii'] ?? '-') ?></span>
                </td>
                <td style="width: 33.34%; border-bottom: none;" class="proto-cell-last">
                    <span class="proto-k">DIPLOMA</span><br/>
                    <span class="<?= empty($data['academic_info']['diploma']) || $data['academic_info']['diploma'] === '-' ? 'proto-v-muted' : 'proto-v' ?>"><?= s($data['academic_info']['diploma'] ?? '-') ?></span>
                </td>
            </tr>
        </table>

        <?= $card_spacer ?>

        <!-- B.E / B.Tech Card -->
        <table cellpadding="6" cellspacing="0" class="proto-card" style="page-break-inside: avoid;">
            <tr>
                <td colspan="3" class="proto-ch">
                    <b>B.E / B.Tech</b>
                </td>
            </tr>
            <tr>
                <td style="width: 33.33%;" class="proto-cell">
                    <span class="proto-k">SCORE</span><br/>
                    <span class="<?= empty($data['academic_info']['btech_score']) || $data['academic_info']['btech_score'] === '-' ? 'proto-v-muted' : 'proto-v' ?>"><?= s($data['academic_info']['btech_score'] ?? '-') ?></span>
                </td>
                <td style="width: 33.33%;" class="proto-cell">
                    <span class="proto-k">BRANCH</span><br/>
                    <span class="<?= empty($data['academic_info']['btech_branch']) || $data['academic_info']['btech_branch'] === '-' ? 'proto-v-muted' : 'proto-v' ?>"><?= s($data['academic_info']['btech_branch'] ?? '-') ?></span>
                </td>
                <td style="width: 33.34%;" class="proto-cell-last">
                    <span class="proto-k">YEAR OF PASSING</span><br/>
                    <span class="<?= empty($data['academic_info']['btech_yop']) || $data['academic_info']['btech_yop'] === '-' ? 'proto-v-muted' : 'proto-v' ?>"><?= s($data['academic_info']['btech_yop'] ?? '-') ?></span>
                </td>
            </tr>
            <tr>
                <td class="proto-cell" style="border-bottom: none;">
                    <span class="proto-k">COLLEGE</span><br/>
                    <span class="<?= empty($data['academic_info']['btech_college']) || $data['academic_info']['btech_college'] === '-' ? 'proto-v-muted' : 'proto-v' ?>"><?= s($data['academic_info']['btech_college'] ?? '-') ?></span>
                </td>
                <td class="proto-cell" style="border-bottom: none;">
                    <span class="proto-k">LOCATION</span><br/>
                    <span class="<?= empty($data['academic_info']['btech_location']) || $data['academic_info']['btech_location'] === '-' ? 'proto-v-muted' : 'proto-v' ?>"><?= s($data['academic_info']['btech_location'] ?? '-') ?></span>
                </td>
                <td class="proto-cell-last" style="border-bottom: none;">
                    <span class="proto-k">STATE</span><br/>
                    <span class="<?= empty($data['academic_info']['btech_state']) || $data['academic_info']['btech_state'] === '-' ? 'proto-v-muted' : 'proto-v' ?>"><?= s($data['academic_info']['btech_state'] ?? '-') ?></span>
                </td>
            </tr>
        </table>

        <?= $card_spacer ?>

        <!-- M.E / M.Tech Card -->
        <table cellpadding="6" cellspacing="0" class="proto-card" style="page-break-inside: avoid;">
            <tr>
                <td colspan="3" class="proto-ch">
                    <b>M.E / M.Tech</b>
                </td>
            </tr>
            <tr>
                <td style="width: 33.33%; border-bottom: none;" class="proto-cell">
                    <span class="proto-k">SCORE</span><br/>
                    <span class="<?= empty($data['academic_info']['mtech_score']) || $data['academic_info']['mtech_score'] === '-' ? 'proto-v-muted' : 'proto-v' ?>"><?= s($data['academic_info']['mtech_score'] ?? '-') ?></span>
                </td>
                <td style="width: 33.33%; border-bottom: none;" class="proto-cell">
                    <span class="proto-k">BRANCH</span><br/>
                    <span class="<?= empty($data['academic_info']['mtech_branch']) || $data['academic_info']['mtech_branch'] === '-' ? 'proto-v-muted' : 'proto-v' ?>"><?= s($data['academic_info']['mtech_branch'] ?? '-') ?></span>
                </td>
                <td style="width: 33.34%; border-bottom: none;" class="proto-cell-last">
                    <span class="proto-k">YEAR</span><br/>
                    <span class="<?= empty($data['academic_info']['mtech_year']) || $data['academic_info']['mtech_year'] === '-' ? 'proto-v-muted' : 'proto-v' ?>"><?= s($data['academic_info']['mtech_year'] ?? '-') ?></span>
                </td>
            </tr>
        </table>

        <!-- ==================== EXPLICIT PAGE BREAK TO PAGE 2 ==================== -->
        <div style="page-break-before: always;"></div>

        <!-- ==================== SECTION 3: PLACEMENT-CRITICAL ==================== -->
        <table cellpadding="0" cellspacing="0" border="0" style="width: 100%; margin-top: 6px; margin-bottom: 8px;">
            <tr>
                <td style="width: 100%; font-size: 12pt; font-weight: bold; color: #663399; border-bottom: 2px solid #cc0066; padding-bottom: 5px;">
                    Placement-Critical
                </td>
            </tr>
        </table>

        <!-- Placement Eligibility Test (PET) Card -->
        <table cellpadding="6" cellspacing="0" class="proto-card" style="page-break-inside: avoid;">
            <tr>
                <td colspan="3" class="proto-ch">
                    <b>Placement Eligibility Test (PET)</b>
                </td>
            </tr>
            <tr>
                <td style="width: 33.33%; border-bottom: none;" class="proto-cell">
                    <span class="proto-k">PET STATUS</span><br/>
                    <span class="<?= s($data['placement_critical']['pet_pdf_class'] ?? 'pb-pet-green') ?>">&nbsp;<?= s($data['placement_critical']['pet_status'] ?? '-') ?>&nbsp;</span>
                </td>
                <td style="width: 33.33%; border-bottom: none;" class="proto-cell">
                    <span class="proto-k">PET SCORE</span><br/>
                    <span class="<?= empty($data['placement_critical']['pet_score']) || $data['placement_critical']['pet_score'] === '-' ? 'proto-v-muted' : 'proto-v' ?>"><?= s($data['placement_critical']['pet_score'] ?? '-') ?></span>
                </td>
                <td style="width: 33.34%; border-bottom: none;" class="proto-cell-last">
                    <span class="proto-k">ATTEMPT</span><br/>
                    <span class="<?= empty($data['placement_critical']['pet_attempt']) || $data['placement_critical']['pet_attempt'] === '-' ? 'proto-v-muted' : 'proto-v' ?>"><?= s($data['placement_critical']['pet_attempt'] ?? '-') ?></span>
                </td>
            </tr>
        </table>

        <?= $card_spacer ?>

        <!-- Merit — Spot Award & Power Track Card -->
        <table cellpadding="6" cellspacing="0" class="proto-card" style="page-break-inside: avoid;">
            <tr>
                <td class="proto-ch">
                    <b>Merit — Spot Award &amp; Power Track</b>
                </td>
            </tr>
            <tr>
                <td style="padding: 9px 12px;">
                    <div>
                        <?php if (!empty($data['placement_critical']['spot_awards'])): ?>
                            <?php foreach ($data['placement_critical']['spot_awards'] as $saw): ?>
                                <?php if (stripos($saw, 'power track') !== false || stripos($saw, 'powertrack') !== false): ?>
                                    <span class="pb-pt">&nbsp;<?= s($saw) ?>&nbsp;</span>&nbsp;&nbsp;
                                <?php else: ?>
                                    <span class="pb-spot">&nbsp;★ <?= s(ltrim($saw, '★ ')) ?>&nbsp;</span>&nbsp;&nbsp;
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span style="font-size: 8pt; color: #888888; font-weight: bold;">-</span>
                        <?php endif; ?>
                    </div>

                </td>
            </tr>
        </table>

        <?= $card_spacer ?>

        <!-- Mock Interview Scores Card -->
        <table cellpadding="6" cellspacing="0" class="proto-card" style="page-break-inside: avoid;">
            <tr>
                <td colspan="<?= !empty($data['placement_critical']['mock_scores']) ? count($data['placement_critical']['mock_scores']) : 3 ?>" class="proto-ch">
                    <b>Mock Interview Scores</b>
                </td>
            </tr>
            <tr>
                <?php
                $mocks = $data['placement_critical']['mock_scores'] ?? [];
                $mock_count = count($mocks);
                $col_pct = ($mock_count > 0) ? (100.0 / $mock_count) : 33.33;
                $m_idx = 0;
                ?>
                <?php if ($mock_count > 0): ?>
                    <?php foreach ($mocks as $mock): ?>
                        <?php
                        $m_idx++;
                        $is_last = ($m_idx === $mock_count);
                        $cls = $is_last ? 'proto-cell-last' : 'proto-cell';
                        $is_muted = empty($mock['score']) || $mock['score'] === '-';
                        ?>
                        <td style="width: <?= sprintf('%.2f', $col_pct) ?>%; border-bottom: none;" class="<?= $cls ?>">
                            <span class="proto-k"><?= s(strtoupper($mock['name'])) ?></span><br/>
                            <span class="<?= $is_muted ? 'proto-v-muted' : 'proto-v' ?>"><?= s($mock['score']) ?></span>
                        </td>
                    <?php endforeach; ?>
                <?php else: ?>
                    <td style="width: 100%; border-bottom: none;" class="proto-cell-last">
                        <span class="proto-k">MOCK SCORES</span><br/>
                        <span class="proto-v-muted">-</span>
                    </td>
                <?php endif; ?>
            </tr>
        </table>

        <!-- ==================== SECTION 4: COURSE PERFORMANCE ==================== -->
        <table cellpadding="0" cellspacing="0" border="0" style="width: 100%; margin-top: 15px; margin-bottom: 8px;">
            <tr>
                <td style="width: 100%; font-size: 12pt; font-weight: bold; color: #663399; border-bottom: 2px solid #cc0066; padding-bottom: 5px;">
                    Course Performance
                </td>
            </tr>
        </table>

        <!-- Level 1 — Overall Card -->
        <table cellpadding="6" cellspacing="0" class="proto-card" style="page-break-inside: avoid;">
            <tr>
                <td colspan="3" class="proto-ch">
                    <b>Level 1 — Overall</b>
                </td>
            </tr>
            <tr>
                <td style="width: 33.33%; border-bottom: none;" class="proto-cell">
                    <span class="proto-k">MAAC RATING</span><br/>
                    <span class="<?= empty($data['course_performance']['overall']['maac_rating']) || $data['course_performance']['overall']['maac_rating'] === '-' ? 'proto-v-muted' : 'proto-v' ?>"><?= s($data['course_performance']['overall']['maac_rating'] ?? '-') ?></span>
                </td>
                <td style="width: 33.33%; border-bottom: none;" class="proto-cell">
                    <span class="proto-k">ATTENDANCE</span><br/>
                    <span class="<?= empty($data['course_performance']['overall']['attendance']) || $data['course_performance']['overall']['attendance'] === '-' ? 'proto-v-muted' : 'proto-v' ?>"><?= s($data['course_performance']['overall']['attendance'] ?? '-') ?></span>
                </td>
                <td style="width: 33.34%; border-bottom: none;" class="proto-cell-last">
                    <span class="proto-k">PROJECTS</span><br/>
                    <span class="<?= empty($data['course_performance']['overall']['projects']) || $data['course_performance']['overall']['projects'] === '-' ? 'proto-v-muted' : 'proto-v' ?>"><?= s($data['course_performance']['overall']['projects'] ?? '-') ?></span>
                </td>
            </tr>
        </table>

        <?= $card_spacer ?>

        <!-- Level 2 — Module-wise Card (Dynamic matrix) -->
        <?php
        $modules = $data['course_performance']['modules'] ?? [];
        $mod_num = count($modules);
        $metric_width = 24.0;
        $col_width = ($mod_num > 0) ? ((100.0 - $metric_width) / $mod_num) : 10.0;
        ?>
        <?php if ($mod_num > 0): ?>
        <table cellpadding="5" cellspacing="0" class="proto-card" style="page-break-inside: avoid;">
            <tr>
                <td colspan="<?= $mod_num + 1 ?>" class="proto-ch">
                    <b>Level 2 — Module-wise</b>
                </td>
            </tr>
            <tr style="background-color: #f4f6f8;">
                <th style="font-weight: bold; font-size: 7pt; color: #565c62; border-bottom: 1px solid #e3e6ea; width: <?= sprintf('%.1f', $metric_width) ?>%; padding: 5px 8px; text-align: left;">
                    METRIC
                </th>
                <?php foreach ($modules as $mod): ?>
                    <?php
                    $mcode = is_array($mod) ? ($mod['code'] ?? '') : (string)$mod;
                    $msec = is_array($mod) && !empty($mod['section']) ? ' (' . trim($mod['section'], '() ') . ')' : '';
                    ?>
                    <th style="font-weight: bold; font-size: 7.5pt; text-align: center; border-bottom: 1px solid #e3e6ea; width: <?= sprintf('%.1f', $col_width) ?>%; padding: 5px 3px; color: #565c62;">
                        <?= s($mcode . $msec) ?>
                    </th>
                <?php endforeach; ?>
            </tr>
            <?php
            $matrix_rows = $data['course_performance']['matrix'] ?? [];
            $r_count = 0;
            $r_total = count($matrix_rows);
            foreach ($matrix_rows as $metric_label => $vals):
                $r_count++;
                $b_border = ($r_count === $r_total) ? '' : 'border-bottom: 1px solid #eef1f4;';
            ?>
                <tr>
                    <td style="font-size: 7pt; font-weight: 600; color: #2e2e2e; <?= $b_border ?> padding: 4px 8px; text-align: left;">
                        <?= s($metric_label) ?>
                    </td>
                    <?php foreach ($vals as $v): ?>
                        <td style="text-align: center; font-size: 7pt; color: #444444; <?= $b_border ?> padding: 4px 2px;">
                            <?= s($v) ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </table>
        <?php else: ?>
        <table cellpadding="6" cellspacing="0" class="proto-card" style="page-break-inside: avoid;">
            <tr>
                <td class="proto-ch">
                    <b>Level 2 — Module-wise</b>
                </td>
            </tr>
            <tr>
                <td style="text-align: center; font-size: 8pt; color: #888888; padding: 12px;">
                    -
                </td>
            </tr>
        </table>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }
}
