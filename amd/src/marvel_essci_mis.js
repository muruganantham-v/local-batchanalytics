/**
 * JavaScript AMD module for Marvell ESSCI Program MIS screens.
 * Supports both standalone single-page and multi-page decoupled view architectures.
 *
 * @package    local_batchanalytics
 * @copyright  2026 Emertxe Information Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([], function() {
    'use strict';

    /* ---------- ROUTER ---------- */
    var PAGE_URLS = {
        home: 'marvel_essci_mis.php',
        batch: 'marvel_essci_mis_batch.php',
        module: 'marvel_essci_mis_module.php',
        student: 'marvel_essci_mis_profile.php'
    };

    var CRUMBS = {
        home: '<b>Home</b>',
        batch: '<a href="marvel_essci_mis.php">Home</a> &nbsp;›&nbsp; <b>MVL26001 · Bangalore</b>',
        module: '<a href="marvel_essci_mis.php">Home</a> &nbsp;›&nbsp; <a href="marvel_essci_mis_batch.php">MVL26001 · Bangalore</a> &nbsp;›&nbsp; <b>Advanced C</b>',
        student: '<a href="marvel_essci_mis.php">Home</a> &nbsp;›&nbsp; <a href="marvel_essci_mis_batch.php">MVL26001 · Bangalore</a> &nbsp;›&nbsp; <a href="marvel_essci_mis_batch.php">Students</a> &nbsp;›&nbsp; <b>Chaitra K</b>'
    };

    function go(v) {
        var targetEl = document.getElementById('v-' + v);
        if (targetEl) {
            document.querySelectorAll('.view').forEach(function(x) { x.classList.remove('on'); });
            targetEl.classList.add('on');
            var crumbEl = document.getElementById('crumb');
            if (crumbEl && CRUMBS[v]) {
                crumbEl.innerHTML = CRUMBS[v];
            }
            window.scrollTo(0, 0);
        } else if (PAGE_URLS[v]) {
            window.location.href = PAGE_URLS[v];
        }
    }

    /* ---------- HOME (Dynamic Cohorts & Program Window) ---------- */
    var DEFAULT_COHORTS = [
        ["26001","Embedded Systems (Marvell)","Bangalore","var(--blue-d)","04 Aug 2026","Advanced C",60,"On schedule"],
        ["26002","Embedded Systems (Marvell)","Hyderabad","var(--brand-d)","11 Aug 2026","Advanced C",57,"On schedule"],
        ["27001","Embedded Systems (Marvell)","Bangalore","var(--blue-d)","03 Aug 2027","Planned",60,"Upcoming"],
        ["27002","Embedded Systems (Marvell)","Hyderabad","var(--brand-d)","10 Aug 2027","Planned",60,"Upcoming"],
        ["28001","Embedded Systems (Marvell)","Bangalore","var(--blue-d)","01 Aug 2028","Planned",60,"Upcoming"],
        ["28002","Embedded Systems (Marvell)","Hyderabad","var(--brand-d)","08 Aug 2028","Planned",60,"Upcoming"]
    ];

    var ALL_COHORTS = [];

    /**
     * Compute the Program Window label and key dynamically from a cohort batch start date string.
     * Option 2: Standard Financial Year (Apr to Mar)
     * e.g. "04 Aug 2026" => key: "2026", label: "Apr 2026 – Mar 2027"
     */
    function computeWindowFromStartDate(dateStr) {
        if (!dateStr) return { key: '2026', label: 'Apr 2026 – Mar 2027' };
        var d = new Date(dateStr);
        if (isNaN(d.getTime())) {
            var parts = String(dateStr).trim().split(/\s+/);
            if (parts.length >= 3) {
                var day = parseInt(parts[0], 10);
                var monthStr = parts[1];
                var year = parseInt(parts[2], 10);
                var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                var mIdx = months.indexOf(monthStr);
                if (mIdx !== -1) {
                    d = new Date(year, mIdx, isNaN(day) ? 1 : day);
                }
            }
        }
        if (isNaN(d.getTime())) {
            return { key: '2026', label: 'Apr 2026 – Mar 2027' };
        }

        var m = d.getMonth() + 1; // 1-12
        var y = d.getFullYear();

        // Indian Financial Year: April (month 4) to March
        var fyStart = (m >= 4) ? y : (y - 1);
        var fyEnd   = fyStart + 1;

        return {
            key: String(fyStart),
            fyStart: fyStart,
            fyEnd: fyEnd,
            label: 'Apr ' + fyStart + ' – Mar ' + fyEnd
        };
    }

    /**
     * Dynamically populate the Program Window select element based on the cohort batch start dates.
     */
    function renderProgramWindowDropdown(cohorts) {
        var sel = document.getElementById('programWindowSelect');
        if (!sel) return;

        var windowsMap = {};
        cohorts.forEach(function(c) {
            var startDate = Array.isArray(c) ? c[4] : (c.start || '');
            var w = computeWindowFromStartDate(startDate);
            if (!windowsMap[w.key]) {
                windowsMap[w.key] = w.label;
            }
        });

        // If no cohorts detected, provide default 2026 cycle
        if (Object.keys(windowsMap).length === 0) {
            windowsMap['2026'] = 'Apr 2026 – Mar 2027';
        }

        var keys = Object.keys(windowsMap).sort();
        var savedVal = '';
        try {
            savedVal = localStorage.getItem('marvel_essci_selected_window') || '';
        } catch (e) {}

        var currentVal = (savedVal && windowsMap[savedVal]) ? savedVal : (sel.value && windowsMap[sel.value] ? sel.value : keys[0]);

        sel.innerHTML = keys.map(function(k) {
            var isSel = (k === currentVal) ? ' selected' : '';
            return '<option value="' + k + '"' + isSel + '>' + windowsMap[k] + '</option>';
        }).join('');

        sel.value = currentVal;
    }

    function renderCohorts(selectedYear) {
        var el = document.getElementById('cohortBody');
        if (!el) return;
        var yr = selectedYear;
        if (!yr) {
            var pw = document.getElementById('programWindowSelect');
            yr = pw ? pw.value : '2026';
        }

        // Filter cohort batches dynamically based on cohort batch start date
        var cohorts = ALL_COHORTS.filter(function(c) {
            var startDate = Array.isArray(c) ? c[4] : (c.start || '');
            var w = computeWindowFromStartDate(startDate);
            return w.key === String(yr);
        });

        el.innerHTML = cohorts.map(function(c) {
            var bid = Array.isArray(c) ? c[0] : c.id;
            var course = Array.isArray(c) ? c[1] : c.course;
            var site = Array.isArray(c) ? c[2] : c.site;
            var siteColor = Array.isArray(c) ? c[3] : (c.site_color || 'var(--blue-d)');
            var start = Array.isArray(c) ? c[4] : c.start;
            var cmod = Array.isArray(c) ? c[5] : (c.current_module || 'Advanced C');
            var enrolled = Array.isArray(c) ? c[6] : (c.enrolled || 60);
            var status = Array.isArray(c) ? c[7] : (c.status || 'On schedule');
            var stClass = status === 'On schedule' ? 'st-g' : (status === 'Upcoming' ? 'st-a' : 'st-r');
            var batchParam = (c && c.sectionid) ? c.sectionid : ((c && c.batchid) ? c.batchid : 5);
            var batchUrl = 'batch.php?id=' + encodeURIComponent(batchParam) + '&mode=Offline';

            return '<tr>' +
                '<td><span class="bid">' + bid + '</span></td>' +
                '<td>' + course + '</td>' +
                '<td><span class="sitepill"><i style="background:' + siteColor + '"></i>' + site + '</span></td>' +
                '<td class="col-start">' + start + '</td>' +
                '<td class="col-module">' + cmod + '</td>' +
                '<td class="col-enrolled">' + enrolled + '</td>' +
                '<td><span class="st ' + stClass + '">' + status + '</span></td>' +
                '<td class="actioncell"><a href="' + batchUrl + '" class="viewbtn">View Batch</a></td>' +
                '</tr>';
        }).join('');

        var infoEl = document.getElementById('cohortCountInfo');
        if (infoEl) {
            infoEl.textContent = cohorts.length + ' sponsored cohorts · offline & online';
        }
    }

    /* ---------- BATCH ---------- */
    function bTab(el, id) {
        if (!el) return;
        var ptabs = el.closest('.ptabs');
        if (ptabs) {
            ptabs.querySelectorAll('.ptab').forEach(function(t) { t.classList.remove('active'); });
            el.classList.add('active');
        }
        var batchView = document.getElementById('v-batch') || document;
        batchView.querySelectorAll('.panel').forEach(function(p) { p.classList.remove('active'); });
        var panel = document.getElementById(id);
        if (panel) panel.classList.add('active');
    }

    var SCHED = [
        ["Linux Systems","Meera R","—","04 Aug","14 Aug","04 Aug","14 Aug",0,1],
        ["Advanced C","Suresh P","Kiran V","16 Aug","08 Nov","16 Aug","—",0,1],
        ["C++","—","—","10 Nov","24 Nov","—","—",null,0],
        ["Data Structures","—","—","25 Nov","22 Dec","—","—",null,0],
        ["STM32 MicroControllers","—","—","23 Dec","30 Jan","—","—",null,0],
        ["RTOS","—","—","31 Jan","14 Feb","—","—",null,0],
        ["Linux Internals","—","—","15 Feb","20 Mar","—","—",null,0],
        ["ELARM","—","—","21 Mar","31 Mar","—","—",null,0]
    ];

    function delayCell(d) {
        if (d === null) return '<span class="muted">—</span>';
        if (d <= 0) return '<span class="st st-g">On track</span>';
        if (d <= 3) return '<span class="st st-a">+' + d + 'd</span>';
        return '<span class="st st-r">+' + d + 'd</span>';
    }

    function renderSched() {
        var el = document.getElementById('schedBody');
        if (!el) return;
        el.innerHTML = SCHED.map(function(r) {
            return '<tr>' +
                '<td><b>' + r[0] + '</b></td><td>' + r[1] + '</td><td>' + r[2] + '</td><td>' + r[3] + '</td><td>' + r[4] + '</td><td>' + r[5] + '</td><td>' + r[6] + '</td>' +
                '<td>' + delayCell(r[7]) + '</td>' +
                '<td class="actioncell">' + (r[8] ? '<button class="viewbtn" onclick="go(\'module\')">View Module</button>' : '<span class="muted" style="font-size:11.5px;">—</span>') + '</td></tr>';
        }).join('');
    }

    var SS = [
        ["Program Kick-Off","04 Aug","04 Aug","04 Aug","04 Aug","g","Done"],
        ["Student Counseling Framework","07 Aug","07 Aug","07 Aug","07 Aug","g","Done"],
        ["Placement Induction - Part I","20 Aug","20 Aug","20 Aug","20 Aug","g","Done"],
        ["Placement Induction - Part II","10 Sep","10 Sep","—","—","a","Due"],
        ["Placement Induction - Part III","01 Oct","01 Oct","—","—","grey","Pending"],
        ["LinkedIn Profile Building Workshop","15 Oct","15 Oct","—","—","grey","Pending"],
        ["LinkedIn Profile Building - Review","22 Oct","22 Oct","—","—","grey","Pending"],
        ["Resume Prep Workshop","12 Nov","12 Nov","—","—","grey","Pending"],
        ["Resume Review","19 Nov","19 Nov","—","—","grey","Pending"],
        ["Interview Facing Workshop","10 Dec","10 Dec","—","—","grey","Pending"]
    ];

    function ssStat(c, t) {
        if (c === 'g') return '<span class="st st-g">' + t + '</span>';
        if (c === 'a') return '<span class="st st-a">' + t + '</span>';
        return '<span class="pill pill-completed">' + t + '</span>';
    }

    function renderSS() {
        var el = document.getElementById('ssBody');
        if (!el) return;
        el.innerHTML = SS.map(function(r) {
            return '<tr><td><b>' + r[0] + '</b></td><td>' + r[1] + '</td><td>' + r[2] + '</td><td>' + r[3] + '</td><td>' + r[4] + '</td><td>' + ssStat(r[5], r[6]) + '</td></tr>';
        }).join('');
    }

    var NAMES = ["Abhay D","Abijith P","Ajith M","Akshay M","Anitha S","Anushka K","Arjun R","Badulla J","Chaitra K","Dipashree B","Gowtham N","Harish V"];
    var GRADE = [82,58,36,44,74,61,32,39,88,91,66,71];
    var MERIT = [true,false,false,false,true,true,false,false,true,true,false,true];

    function meritCell(s) {
        return s ? '<span class="merit"><span class="m spot">Spot</span></span>' : '<span class="muted">—</span>';
    }

    var perfMode = 'grade';
    function setMode(m) {
        perfMode = m;
        var tgGrade = document.getElementById('tg-grade');
        var tgPct = document.getElementById('tg-pct');
        if (tgGrade) tgGrade.classList.toggle('on', m === 'grade');
        if (tgPct) tgPct.classList.toggle('on', m === 'percentile');
        renderPerf();
    }

    function computeBands() {
        if (perfMode === 'grade') {
            return GRADE.map(function(g) { return g > 70 ? 'top' : (g >= 40 ? 'mid' : 'bot'); });
        }
        var order = GRADE.map(function(_, idx) { return idx; }).sort(function(a, b) { return GRADE[b] - GRADE[a]; });
        var n = GRADE.length, topN = Math.round(n * 0.15), botN = Math.round(n * 0.15);
        var band = new Array(n);
        for (var i = 0; i < n; i++) band[i] = 'mid';
        order.forEach(function(idx, rank) {
            if (rank < topN) band[idx] = 'top';
            else if (rank >= n - botN) band[idx] = 'bot';
        });
        return band;
    }

    var DOT = { top: 'var(--green)', mid: 'var(--amber)', bot: 'var(--red)' };

    function renderPerf() {
        var bandrow = document.getElementById('bandrow');
        var stuBody = document.getElementById('stuBody');
        if (!bandrow || !stuBody) return;

        var bands = computeBands();
        var counts = { top: 0, mid: 0, bot: 0 };
        bands.forEach(function(b) { counts[b]++; });

        var labels = perfMode === 'grade'
            ? { top: ['Top Performers', 'grade &gt; 70'], mid: ['Middle Performers', 'grade 40–70'], bot: ['Low Performers', 'grade &lt; 40'] }
            : { top: ['Top 15%', 'by rank'], mid: ['Middle 70%', 'by rank'], bot: ['Bottom 15%', 'by rank'] };

        bandrow.innerHTML =
            '<div class="band"><span class="dotband" style="background:var(--green)"></span> <span class="pct">' + counts.top + '</span><div class="lbl">' + labels.top[0] + '</div><div class="cnt">' + labels.top[1] + '</div></div>' +
            '<div class="band"><span class="dotband" style="background:var(--amber)"></span> <span class="pct">' + counts.mid + '</span><div class="lbl">' + labels.mid[0] + '</div><div class="cnt">' + labels.mid[1] + '</div></div>' +
            '<div class="band"><span class="dotband" style="background:var(--red)"></span> <span class="pct">' + counts.bot + '</span><div class="lbl">' + labels.bot[0] + '</div><div class="cnt">' + labels.bot[1] + '</div></div>';

        stuBody.innerHTML = NAMES.map(function(n, i) {
            return '<tr>' +
                '<td><span class="dotband" style="background:' + DOT[bands[i]] + '"></span></td>' +
                '<td><span class="linkname" onclick="go(\'student\')">' + n + '</span></td>' +
                '<td>' + (70 + i % 25) + '%</td><td>' + (60 + i % 35) + '%</td><td>' + (i % 3 ? (55 + i % 40) + '%' : '—') + '</td><td>' + (62 + i % 30) + '%</td>' +
                '<td>' + meritCell(MERIT[i]) + '</td></tr>';
        }).join('');
    }

    var NOTES = [
        { date: '12 Sep 2026, 4:30 PM', who: 'Ravi Kumar', body: 'Advanced C running to plan. Flagged 4 middle-band students for extra counselling touchpoints; Counsellor to follow up on 2 red cases.' },
        { date: '05 Sep 2026, 5:10 PM', who: 'Ravi Kumar', body: 'Assignment evaluation slipped by 2 days last week — mentor notified, now caught up. Spot Award nominations to be finalised by module end.' },
        { date: '29 Aug 2026, 4:45 PM', who: 'Ravi Kumar', body: 'Batch healthy overall. Attendance at 82%. Placement induction scheduled for D35.' }
    ];

    function renderNotes() {
        var el = document.getElementById('noteList');
        if (!el) return;
        el.innerHTML = NOTES.map(function(nt) {
            return '<div class="nitem"><div class="meta"><b>' + nt.who + '</b> · ' + nt.date + '</div><div class="bd">' + nt.body + '</div></div>';
        }).join('');
    }

    function addNote() {
        var input = document.getElementById('noteText');
        if (!input) return;
        var t = input.value.trim();
        if (!t) return;
        var d = new Date();
        var ds = d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) + ', ' + d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
        NOTES.unshift({ date: ds, who: 'Ravi Kumar', body: t });
        input.value = '';
        renderNotes();
    }

    /* ---------- MODULE ---------- */
    function mTab(el, id) {
        if (!el) return;
        var ptabs = el.closest('.ptabs');
        if (ptabs) {
            ptabs.querySelectorAll('.ptab').forEach(function(t) { t.classList.remove('active'); });
            el.classList.add('active');
        }
        var modView = document.getElementById('v-module') || document;
        modView.querySelectorAll('.panel').forEach(function(p) { p.classList.remove('active'); });
        var panel = document.getElementById(id);
        if (panel) panel.classList.add('active');
    }

    var LP = [
        [1, "17 Aug 2026", "C intro, LMS orientation · language types (high/low) · features, keywords, datatypes · number systems & conversions · signed/unsigned char range", "17 Aug 2026", "done", "Present", "Present"],
        [2, "18 Aug 2026", "printf / scanf, format specifiers · compilation & execution, compiler vs interpreter · operators · if family · practice programs", "18 Aug 2026", "done", "Present", "Present"],
        [3, "19 Aug 2026", "switch-case (all properties) · loops: while / do-while / for · pre & post ++ / -- · 7-8 programs", "19 Aug 2026", "done", "Present", "Present"],
        [4, "20 Aug 2026", "factorial, 2^n series · break / continue · prime with optimization · nested loops · 2-3 assignments", "20 Aug 2026", "done", "Present", "Present"],
        [5, "21 Aug 2026", "VS Code setup · nested-loop 2D patterns (11-12) · assignment: perfect number", "21 Aug 2026", "done", "Present", "Present"],
        [6, "22 Aug 2026", "pattern problems · operator precedence & associativity · ternary (4 problems) · goto-label · assignments", "22 Aug 2026", "done", "Present", "Present"],
        [7, "24 Aug 2026", "swap integers · arrays: declare/init, print/scan · sum/avg, search, largest/smallest, reverse · armstrong, n primes", "24 Aug 2026", "done", "Present", "Present"],
        [8, "25 Aug 2026", "n primes solution · bubble sort · more array problems · surprise test 1 + evaluation & discussion", "25 Aug 2026", "done", "Present", "Sub"],
        [9, "26 Aug 2026", "type modifiers (signed/unsigned/short/long) · typecasting, promotion/demotion · rank order · SDLC, flowchart", "26 Aug 2026", "done", "Present", "Present"],
        [10, "27 Aug 2026", "functions: basics, prototypes, implicit int · problems (add, x^y, reverse, palindrome) · 2-3 assignments", "27 Aug 2026", "done", "Present", "Present"],
        [11, "28 Aug 2026", "RAM/process, kernel vs user space · stack frame · pass by value vs address · pointer basics · endianness", "28 Aug 2026", "done", "Absent", "Present"],
        [12, "29 Aug 2026", "pointer arithmetic · pointer in arrays · pass by reference · swap, pass array to function · assignments", "29 Aug 2026", "done", "Present", "Present"],
        [13, "31 Aug 2026", "pass by value vs reference · recursion (n hello, factorial, sum of n) · stack-frame create/delete", "", "in-progress", "—", "—"],
        [14, "01 Sep 2026", "print_binary (recursion) · strings intro: declaration, properties, printing, length/size, literals", "", "pending", "—", "—"],
        [15, "02 Sep 2026", "nth largest in array · string reading (3 ways) · string problems (length, reverse, vowels) · revision", "", "pending", "—", "—"],
        [16, "03 Sep 2026", "string inbuilt: strlen/strcmp/strcpy/strchr/strcat/strstr · validation program · assignments", "", "pending", "—", "—"],
        [17, "04 Sep 2026", "GCD / LCM · string problem · group meeting · bridge class started · atoi()", "", "pending", "—", "—"],
        [18, "05 Sep 2026", "memory segments (stack/heap/data/text) · storage classes (auto/register/static) · quiz · itoa()", "", "pending", "—", "—"],
        [19, "07 Sep 2026", "global variables (normal/static/extern) · pattern problems · surprise test 2 · bridge course", "", "pending", "—", "—"],
        [20, "08 Sep 2026", "structures · array of structures (2 problems) · test discussion · bridge class · assignments", "", "pending", "—", "—"]
    ];

    function stClass(s) { return s === 'done' ? 'done' : (s === 'in-progress' ? 'inp' : 'pend'); }
    function attClass(a) { return a === 'Present' ? 'p' : (a === 'Absent' ? 'a' : (a === 'Sub' ? 's' : 'n')); }
    function fmtDate(v) {
        if (!v) return '';
        if (/^\d{4}-\d{2}-\d{2}$/.test(v)) {
            var d = new Date(v + 'T00:00:00');
            return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
        }
        return v;
    }

    function renderLP() {
        var el = document.getElementById('lpBody');
        if (!el) return;
        el.innerHTML = LP.map(function(r, i) {
            return '<tr class="' + (r[7] ? 'lp-added' : '') + '">' +
                '<td class="lp-day">' + r[0] + (r[7] ? '<div class="addtag">added</div>' : '') + '</td>' +
                '<td>' + r[1] + '</td>' +
                '<td class="lp-topic">' + r[2] + '</td>' +
                '<td><span class="stp ' + stClass(r[4]) + '">' + (r[4] === 'in-progress' ? 'In progress' : (r[4] === 'done' ? 'Done' : 'Pending')) + '</span></td>' +
                '<td class="lp-date"><input type="text" value="' + r[3] + '" placeholder="dd mmm yyyy" onchange="LP[' + i + '][3]=this.value"></td>' +
                '<td><span class="att ' + attClass(r[5]) + '">' + r[5] + '</span></td>' +
                '<td><span class="att ' + attClass(r[6]) + '">' + r[6] + '</span></td>' +
                '<td><div class="mv"><button onclick="moveDay(' + i + ',-1)" title="Move up">&#9650;</button><button onclick="moveDay(' + i + ',1)" title="Move down">&#9660;</button></div></td>' +
                '</tr>';
        }).join('');
    }

    function moveDay(i, dir) {
        var j = i + dir;
        if (j < 0 || j >= LP.length) return;
        var temp = LP[i];
        LP[i] = LP[j];
        LP[j] = temp;
        renderLP();
    }

    function addDay() {
        var sel = document.getElementById('d_after');
        if (sel) {
            sel.innerHTML = '<option value="end">— At the end —</option>' + LP.map(function(r) {
                return '<option value="' + r[0] + '">After day ' + r[0] + '</option>';
            }).join('');
        }
        var dPlan = document.getElementById('d_plan');
        if (dPlan) dPlan.value = '';
        var dTopic = document.getElementById('d_topic');
        if (dTopic) dTopic.value = '';
        var dActual = document.getElementById('d_actual');
        if (dActual) dActual.value = '';
        var dStatus = document.getElementById('d_status');
        if (dStatus) dStatus.value = 'pending';
        var dayOv = document.getElementById('dayOv');
        if (dayOv) dayOv.classList.add('show');
    }

    function closeDayModal() {
        var dayOv = document.getElementById('dayOv');
        if (dayOv) dayOv.classList.remove('show');
    }

    function saveDay() {
        var after = document.getElementById('d_after').value;
        var plan = fmtDate(document.getElementById('d_plan').value);
        var topic = document.getElementById('d_topic').value.trim() || '(new session — enter topic)';
        var actual = fmtDate(document.getElementById('d_actual').value);
        var status = document.getElementById('d_status').value;
        var row = [0, plan, topic, actual, status, '—', '—', true];
        var pos = LP.length;
        if (after !== 'end') {
            var idx = LP.findIndex(function(r) { return r[0] == after; });
            if (idx >= 0) pos = idx + 1;
        }
        LP.splice(pos, 0, row);
        LP.forEach(function(r, k) { r[0] = k + 1; });
        renderLP();
        closeDayModal();
        okModal('Day added successfully', 'Highlighted in the lesson plan.');
    }

    var MSTU = [
        ["top","Abhay D","70%","60%","62%",true],
        ["mid","Abijith P","71%","61%","63%",false],
        ["bot","Ajith M","72%","62%","64%",false],
        ["mid","Akshay M","73%","63%","65%",false],
        ["top","Anitha S","74%","64%","66%",true],
        ["top","Chaitra K","88%","80%","84%",true],
        ["mid","Dipashree B","69%","58%","61%",false],
        ["bot","Gowtham N","41%","40%","44%",false]
    ];
    var MDOT = { top: 'var(--green)', mid: 'var(--amber)', bot: 'var(--red)' };

    function renderMPerf() {
        var el = document.getElementById('mPerfBody');
        if (!el) return;
        el.innerHTML = MSTU.map(function(s) {
            return '<tr><td><span class="dotband" style="background:' + MDOT[s[0]] + '"></span></td>' +
                '<td><span class="linkname" onclick="go(\'student\')">' + s[1] + '</span></td>' +
                '<td>' + s[2] + '</td><td>' + s[3] + '</td><td>' + s[4] + '</td>' +
                '<td>' + (s[5] ? '<span class="merit"><span class="m spot">Spot</span></span>' : '<span class="muted">—</span>') + '</td></tr>';
        }).join('');
    }

    /* ---------- INFO & SUCCESS MODALS ---------- */
    function infoModal(title, msg) {
        var infoH = document.getElementById('info_h');
        var infoT = document.getElementById('info_t');
        var infoM = document.getElementById('info_m');
        var infoOv = document.getElementById('infoOv');
        if (infoH) infoH.innerHTML = 'Notice';
        if (infoT) infoT.innerHTML = title;
        if (infoM) infoM.innerHTML = msg;
        if (infoOv) infoOv.classList.add('show');
    }

    function closeInfo() {
        var infoOv = document.getElementById('infoOv');
        if (infoOv) infoOv.classList.remove('show');
    }

    function okModal(title, sub) {
        var okT = document.getElementById('ok_t');
        var okS = document.getElementById('ok_s');
        var okOv = document.getElementById('okOv');
        if (okT) okT.textContent = title;
        if (okS) {
            okS.innerHTML = sub || '';
            okS.style.display = sub ? '' : 'none';
        }
        if (okOv) okOv.classList.add('show');
    }

    function closeOk() {
        var okOv = document.getElementById('okOv');
        if (okOv) okOv.classList.remove('show');
    }

    /* ---------- CLASS EVIDENCE UPLOAD MODAL ---------- */
    var upFiles = [];
    function openUpload() {
        upFiles = [];
        var stage1 = document.getElementById('up_stage1');
        var stage2 = document.getElementById('up_stage2');
        var upList = document.getElementById('up_list');
        var upCap = document.getElementById('up_cap');
        var upDate = document.getElementById('up_date');
        var upType = document.getElementById('up_type');
        var upOv = document.getElementById('upOv');
        if (stage1) stage1.style.display = '';
        if (stage2) stage2.style.display = 'none';
        if (upList) upList.innerHTML = '';
        if (upCap) upCap.value = '';
        if (upDate) upDate.value = new Date().toISOString().slice(0, 10);
        if (upType) upType.value = 'Class';
        setUpGo();
        if (upOv) upOv.classList.add('show');
    }

    function closeUpload() {
        var upOv = document.getElementById('upOv');
        if (upOv) upOv.classList.remove('show');
    }

    function setUpGo() {
        var g = document.getElementById('up_go');
        if (!g) return;
        var on = upFiles.length > 0;
        g.style.opacity = on ? '1' : '.5';
        g.style.pointerEvents = on ? 'auto' : 'none';
    }

    function renderUpList() {
        var el = document.getElementById('up_list');
        if (!el) return;
        el.innerHTML = upFiles.map(function(f, i) {
            return '<div class="fileitem"><span class="thumb"></span><span>' + f + '</span><button class="rm" onclick="rmFile(' + i + ')">&times;</button></div>';
        }).join('');
        setUpGo();
    }

    function pickFiles(files) {
        if (!files) return;
        for (var i = 0; i < files.length; i++) upFiles.push(files[i].name);
        renderUpList();
    }

    function rmFile(i) {
        upFiles.splice(i, 1);
        renderUpList();
    }

    function doUpload() {
        var n = upFiles.length;
        if (!n) return;
        var type = document.getElementById('up_type').value;
        var msg = document.getElementById('up_msg');
        if (msg) msg.innerHTML = n + ' ' + type.toLowerCase() + ' photo' + (n > 1 ? 's' : '') + ' added to the class-evidence gallery.';
        var s1 = document.getElementById('up_stage1');
        var s2 = document.getElementById('up_stage2');
        if (s1) s1.style.display = 'none';
        if (s2) s2.style.display = '';
    }

    /* ---------- STUDENT ATTENDANCE MATRIX ---------- */
    var ANAMES = ["Aarthi K", "Bhavana S", "Chaitra R", "Deepika M", "Divya N", "Gowri B", "Harini P", "Indira V", "Jyothi D", "Kavya G", "Lakshmi K", "Meena S", "Nandini R", "Pooja M", "Rekha N", "Sahana B", "Tanvi P", "Usha V", "Vani D", "Yamini G", "Anitha K", "Bhuvana S", "Charita R", "Deepa M", "Esha N", "Farida B", "Geetha P", "Hema V", "Ishita D", "Jaya G", "Keerthi K", "Latha S", "Manasa R", "Nisha M", "Priya N", "Ramya B", "Sneha P", "Trisha V", "Uma D", "Varsha G"];
    function buildSessions(nn) {
        var out = [];
        var d = new Date('2026-08-16T00:00:00');
        while (out.length < nn) {
            if (d.getDay() !== 0) {
                out.push({ n: out.length + 1, d: d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short' }) });
            }
            d.setDate(d.getDate() + 1);
        }
        return out;
    }
    var ASESS = buildSessions(18);
    var ARATE = [0.94, 0.88, 0.69, 1.0, 0.81, 0.78, 0.63, 0.91, 0.97, 0.72];
    function present(i, j) {
        var h = ((i * 29 + j * 53 + i * j * 7) % 100);
        return h < ARATE[i % ARATE.length] * 100;
    }
    var ATTMAT = [], ATT_PCT = [], attPage = 0, ATT_PER = 10;
    function buildAttData() {
        ATTMAT = ANAMES.map(function(_, i) { return ASESS.map(function(_, j) { return present(i, j); }); });
        ATT_PCT = ATTMAT.map(function(row) { return Math.round(row.filter(Boolean).length / ASESS.length * 100); });
    }

    function renderAtt() {
        var attHead = document.getElementById('attHead');
        var attBody = document.getElementById('attBody');
        var attFoot = document.getElementById('attFoot');
        var attPager = document.getElementById('attPager');
        if (!attHead || !attBody || !attFoot) return;

        var S = ASESS.length, N = ANAMES.length;
        attHead.innerHTML = '<th class="nm">Student</th><th>Att %</th>' + ASESS.map(function(s) {
            return '<th>S' + s.n + '<span class="dt">' + s.d + '</span></th>';
        }).join('');

        var start = attPage * ATT_PER, end = Math.min(start + ATT_PER, N);
        var body = '';
        for (var i = start; i < end; i++) {
            var pct = ATT_PCT[i];
            var pcol = pct < 75 ? 'var(--red)' : (pct < 85 ? 'var(--amber)' : 'var(--green)');
            body += '<tr><td class="nm">' + ANAMES[i] + '</td><td class="pctc" style="color:' + pcol + '">' + pct + '%</td>' +
                ATTMAT[i].map(function(v) { return '<td><span class="acell ' + (v ? 'p' : 'a') + '">' + (v ? 'P' : 'A') + '</span></td>'; }).join('') + '</tr>';
        }
        attBody.innerHTML = body;

        var sessP = ASESS.map(function(_, j) { return ATTMAT.reduce(function(a, row) { return a + (row[j] ? 1 : 0); }, 0); });
        attFoot.innerHTML = '<td class="nm">Present / ' + N + '</td><td></td>' + sessP.map(function(c) { return '<td>' + c + '/' + N + '</td>'; }).join('');

        if (attPager) {
            var pages = Math.ceil(N / ATT_PER);
            var pg = '<span class="pginfo">Showing ' + (start + 1) + '–' + end + ' of ' + N + ' students</span><span class="pgbtns">' +
                '<button ' + (attPage === 0 ? 'disabled' : '') + ' onclick="attGo(' + (attPage - 1) + ')">&lsaquo; Prev</button>';
            for (var k = 0; k < pages; k++) {
                pg += '<button class="' + (k === attPage ? 'on' : '') + '" onclick="attGo(' + k + ')">' + (k + 1) + '</button>';
            }
            pg += '<button ' + (attPage === pages - 1 ? 'disabled' : '') + ' onclick="attGo(' + (attPage + 1) + ')">Next &rsaquo;</button></span>';
            attPager.innerHTML = pg;
        }
    }

    function attGo(k) {
        var pages = Math.ceil(ANAMES.length / ATT_PER);
        if (k < 0 || k >= pages) return;
        attPage = k;
        renderAtt();
    }

    /* ---------- STUDENT PROFILE ---------- */
    function sTab(el, id) {
        if (!el) return;
        var ptabs = el.closest('.ptabs');
        if (ptabs) {
            ptabs.querySelectorAll('.ptab').forEach(function(t) { t.classList.remove('active'); });
            el.classList.add('active');
        }
        var stuView = document.getElementById('v-student') || document;
        stuView.querySelectorAll('.panel').forEach(function(p) { p.classList.remove('active'); });
        var panel = document.getElementById(id);
        if (panel) panel.classList.add('active');
    }

    var MET = ["Attendance %","Classwork %","Module test %","Objective test %","Programming test %","Quiz %","Assignments %","Projects"];
    var MODS = ["LS","C","C++","DSA","STM32","RTOS","LI","ELARM"];

    function renderStudentCourseMatrix() {
        var el = document.getElementById('sModBody');
        if (!el) return;
        el.innerHTML = MET.map(function(m) {
            return '<tr><td style="text-align:left;font-weight:600;color:#565c62;">' + m + '</td>' +
                MODS.map(function(_, mi) {
                    var v = (m === 'Projects') ? (1 + mi % 3) : (62 + ((mi * 7 + m.length) % 34) + '%');
                    return '<td style="text-align:center;">' + v + '</td>';
                }).join('') + '</tr>';
        }).join('');
    }

    var SNT = [
        { d: '18 Sep 2026, 5:20 PM', w: 'Anitha S · Student Counsellor', b: 'Discussed placement goals — keen on embedded firmware roles. Confident, well-prepared.' },
        { d: '02 Sep 2026, 4:10 PM', w: 'Anitha S · Student Counsellor', b: 'Mid-module check-in. Doing well; mild exam anxiety flagged — shared prep resources.' },
        { d: '14 Aug 2026, 3:30 PM', w: 'Ravi Kumar · Program Manager', b: 'Batch review: strong candidate, consistent attendance and assignment quality.' }
    ];

    function renderStudentNotes() {
        var el = document.getElementById('sNotes');
        if (!el) return;
        el.innerHTML = SNT.map(function(n) {
            return '<div class="nitem"><div class="meta"><b>' + n.w + '</b> · ' + n.d + '</div><div class="bd">' + n.b + '</div></div>';
        }).join('');
    }

    var SPL = [
        ["Marvell Semiconductor","Embedded Firmware Trainee","Placed","₹ 6.5 LPA","st-g"],
        ["Volt Systems","Firmware Intern","Selected","₹ 5.0 LPA","st-g"],
        ["NxGen Electronics","QA Engineer","Not selected","—","pill-completed"],
        ["Kaynes Technology","Test Engineer","In process","—","st-a"]
    ];

    function renderPlacement() {
        var el = document.getElementById('sPlBody');
        if (!el) return;
        el.innerHTML = SPL.map(function(p) {
            return '<tr><td style="text-align:left;"><b>' + p[0] + '</b></td><td style="text-align:left;">' + p[1] + '</td>' +
                '<td style="text-align:left;">' + (p[4].startsWith('st') ? '<span class="st ' + p[4] + '">' + p[2] + '</span>' : '<span class="pill pill-completed">' + p[2] + '</span>') + '</td>' +
                '<td style="text-align:left;">' + p[3] + '</td></tr>';
        }).join('');
    }

    function openDoc() {
        var docOv = document.getElementById('docOv');
        if (docOv) docOv.classList.add('show');
    }

    function closeDoc() {
        var docOv = document.getElementById('docOv');
        if (docOv) docOv.classList.remove('show');
    }

    /* ---------- GLOBAL WINDOW EXPORTS FOR INLINE ONCLICK ---------- */
    window.go = go;
    window.bTab = bTab;
    window.mTab = mTab;
    window.sTab = sTab;
    window.setMode = setMode;
    window.computeBands = computeBands;
    window.renderPerf = renderPerf;
    window.addNote = addNote;
    window.renderNotes = renderNotes;
    window.addDay = addDay;
    window.closeDayModal = closeDayModal;
    window.saveDay = saveDay;
    window.moveDay = moveDay;
    window.renderLP = renderLP;
    window.infoModal = infoModal;
    window.closeInfo = closeInfo;
    window.okModal = okModal;
    window.closeOk = closeOk;
    window.openUpload = openUpload;
    window.closeUpload = closeUpload;
    window.doUpload = doUpload;
    window.pickFiles = pickFiles;
    window.rmFile = rmFile;
    window.attGo = attGo;
    window.renderAtt = renderAtt;
    window.openDoc = openDoc;
    window.closeDoc = closeDoc;
    window.LP = LP;

    /* ---------- INITIALIZATION ---------- */
    function init(args) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() { setup(args); });
        } else {
            setup(args);
        }
    }

    function setup(args) {
        // 1. Home components (Program window dropdown & cohorts dynamically based on cohort batch start date)
        if (args && args.cohorts && Array.isArray(args.cohorts) && args.cohorts.length > 0) {
            ALL_COHORTS = args.cohorts;
        } else {
            var cdataEl = document.getElementById('marvel_essci_cohorts_data');
            if (cdataEl) {
                try {
                    ALL_COHORTS = JSON.parse(cdataEl.textContent);
                } catch (e) {
                    ALL_COHORTS = DEFAULT_COHORTS;
                }
            } else {
                ALL_COHORTS = DEFAULT_COHORTS;
            }
        }

        renderProgramWindowDropdown(ALL_COHORTS);

        var pwSelect = document.getElementById('programWindowSelect');
        if (pwSelect) {
            pwSelect.addEventListener('change', function() {
                var selected = this.value;
                try {
                    localStorage.setItem('marvel_essci_selected_window', selected);
                } catch (e) {}
                renderCohorts(selected);
            });
            try {
                var cached = localStorage.getItem('marvel_essci_selected_window');
                if (cached && pwSelect.querySelector('option[value="' + cached + '"]')) {
                    pwSelect.value = cached;
                }
            } catch (e) {}
        }
        renderCohorts(pwSelect ? pwSelect.value : '2026');

        // 2. Batch components
        renderSched();
        renderSS();
        renderPerf();
        renderNotes();

        // 3. Module components
        renderLP();
        renderMPerf();
        buildAttData();
        var attAvgEl = document.getElementById('attAvg');
        if (attAvgEl) {
            attAvgEl.textContent = Math.round(ATT_PCT.reduce(function(a, b) { return a + b; }, 0) / ATT_PCT.length) + '%';
        }
        var attLowEl = document.getElementById('attLow');
        if (attLowEl) {
            attLowEl.textContent = ATT_PCT.filter(function(p) { return p < 75; }).length;
        }
        var attSessEl = document.getElementById('attSess');
        if (attSessEl) attSessEl.textContent = ASESS.length;
        var attStuEl = document.getElementById('attStu');
        if (attStuEl) attStuEl.textContent = ANAMES.length;
        renderAtt();

        // Dropzone event listeners
        var dz = document.getElementById('dropzone');
        if (dz) {
            ['dragover', 'dragenter'].forEach(function(ev) {
                dz.addEventListener(ev, function(e) { e.preventDefault(); dz.classList.add('drag'); });
            });
            ['dragleave', 'drop'].forEach(function(ev) {
                dz.addEventListener(ev, function(e) { e.preventDefault(); dz.classList.remove('drag'); });
            });
            dz.addEventListener('drop', function(e) {
                if (e.dataTransfer && e.dataTransfer.files) pickFiles(e.dataTransfer.files);
            });
        }

        // 4. Student Profile components
        renderStudentCourseMatrix();
        renderStudentNotes();
        renderPlacement();

        // Backdrop click listeners for modals
        ['dayOv', 'infoOv', 'okOv', 'upOv', 'docOv'].forEach(function(modalId) {
            var m = document.getElementById(modalId);
            if (m) {
                m.addEventListener('click', function(e) {
                    if (e.target.id === modalId) {
                        m.classList.remove('show');
                    }
                });
            }
        });

        // Set initial view if running in single-page mode
        if (args && args.page) {
            var viewEl = document.getElementById('v-' + args.page);
            if (viewEl) {
                document.querySelectorAll('.view').forEach(function(x) { x.classList.remove('on'); });
                viewEl.classList.add('on');
            }
        }
    }

    return {
        init: init,
        go: go
    };
});
