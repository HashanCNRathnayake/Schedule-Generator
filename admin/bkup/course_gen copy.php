<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Course Search</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        #results {
            position: absolute;
            z-index: 1000;
            width: 100%;
        }

        #results .list-group-item {
            cursor: pointer;
        }

        .table td,
        .table th {
            text-align: center;
            /* horizontal */
            vertical-align: middle;
            /* vertical */
        }
    </style>
</head>/

<body class="p-4">

    <div class="container">
        <form method="post" class="mb-3">
            <button type="submit" name="refresh" class="btn btn-primary">Refresh Course List</button>
        </form>

        <div class="mb-3 position-relative">
            <div class="input-group">
                <input type="text" id="search" class="form-control" placeholder="Search courses...">
                <button type="button" id="clearSearch" class="btn btn-outline-secondary" style="display:none;">&times;</button>
            </div>
            <div id="results" class="list-group"></div>
        </div>
        <!-- Controls to appear above the session table (keep visible from load) -->
        <div id="scheduleControls" class="row g-2 mb-3">
            <div class="col-md-3">
                <label class="form-label mb-1">Starting date</label>
                <input type="date" id="startDate" class="form-control">
            </div>

            <div class="col-md-5">
                <label class="form-label mb-1 d-block">Day pattern (allowed weekdays)</label>
                <div class="d-flex flex-wrap gap-2">
                    <!-- value uses 0..6 = Sun..Sat (JS getDay convention) -->
                    <label class="form-check form-check-inline">
                        <input class="form-check-input day-allow" type="checkbox" value="1" checked> Mon
                    </label>
                    <label class="form-check form-check-inline">
                        <input class="form-check-input day-allow" type="checkbox" value="2" checked> Tue
                    </label>
                    <label class="form-check form-check-inline">
                        <input class="form-check-input day-allow" type="checkbox" value="3" checked> Wed
                    </label>
                    <label class="form-check form-check-inline">
                        <input class="form-check-input day-allow" type="checkbox" value="4" checked> Thu
                    </label>
                    <label class="form-check form-check-inline">
                        <input class="form-check-input day-allow" type="checkbox" value="5" checked> Fri
                    </label>
                    <label class="form-check form-check-inline">
                        <input class="form-check-input day-allow" type="checkbox" value="6"> Sat
                    </label>
                    <label class="form-check form-check-inline">
                        <input class="form-check-input day-allow" type="checkbox" value="0"> Sun
                    </label>
                </div>
            </div>

            <div class="col-md-4">
                <label class="form-label mb-1">Region (for public holidays)</label>
                <select id="regionSelect" class="form-select">
                    <option value="SL">Sri Lanka</option>
                    <option value="IN">India</option>
                    <option value="PH">Philippines</option>
                    <option value="BD">Bangladesh</option>
                </select>
                <small id="holidayNote" class="text-muted d-block mt-1"></small>
            </div>
        </div>



        <div id="courseDetails" class="mt-4">
            <h3 id="courseTitle"></h3>
            <p><b>Code:</b> <span id="courseCode">—</span></p>
            <p><b>Learning Pace:</b> <span id="learningPace">—</span></p>
            <p><b>Learning Delivery Mode:</b> <span id="deliveryMode">—</span></p>

            <h4>Learning Modes</h4>
            <select id="modeSelect" class="form-select mb-2">
                <option value="">Select a mode...</option>
            </select>
            <div id="modeDetails"></div>

            <h4>Learning Modules Method 2</h4>
            <select id="moduSelect2" class="form-select mb-2">
                <option value="">Select a module...</option>
            </select>
            <div id="moduDetails">
                <h4>Session Plan</h4>
                <table class="table table-bordered table-sm">
                    <thead>
                        <tr>
                            <th>Session<br>No</th>
                            <th>Type</th>
                            <th>Short</th>
                            <th>Details</th>
                            <th>Duration<br>Hours</th>
                            <th>Session<br>Day</th>
                            <th>Date</th>
                            <th>Day</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody id="sessionPlans"></tbody>
                </table>

                <h4>Session Plan Total Hours</h4>
                <table class="table table-bordered table-sm">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Short</th>
                            <th>Type</th>
                            <th>Duration</th>
                        </tr>
                    </thead>
                    <tbody id="sessionPlanHours"></tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        // ----- helpers -----
        const fmtYMD = d => d.toISOString().slice(0, 10); // YYYY-MM-DD
        const parseYMD = s => {
            const [y, m, dd] = s.split('-').map(Number);
            const d = new Date(Date.UTC(y, m - 1, dd));
            // normalize to local date (we only care about calendar day)
            return new Date(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate());
        };
        const weekdayName = d => d.toLocaleDateString(undefined, {
            weekday: 'long'
        });

        function addDays(date, n) {
            const d = new Date(date);
            d.setDate(d.getDate() + n);
            return d;
        }

        // Minimal demo public-holiday sets (YYYY-MM-DD). Replace with real data/API later.
        function getPublicHolidays(region, year) {
            const demo = {
                SL: [`${year}-01-01`, `${year}-05-01`],
                IN: [`${year}-01-26`, `${year}-08-15`],
                PH: [`${year}-06-12`, `${year}-12-25`],
                BD: [`${year}-02-21`, `${year}-03-26`],
            };
            return new Set((demo[region] || []).concat([`${year}-12-31`])); // add a dummy extra
        }

        // ----- DOM refs (existing from your page) -----
        const searchInput = document.getElementById('search');
        const resultsBox = document.getElementById('results');
        const clearSearchBtn = document.getElementById('clearSearch');

        const courseTitle = document.getElementById('courseTitle');
        const courseCode = document.getElementById('courseCode');
        const learningPace = document.getElementById('learningPace');
        const deliveryMode = document.getElementById('deliveryMode');

        const modeSelect = document.getElementById('modeSelect');
        const modeDetails = document.getElementById('modeDetails');
        const moduleSelect = document.getElementById('moduSelect2');

        const sessionPlans = document.getElementById('sessionPlans'); // tbody
        const sessionPlanHours = document.getElementById('sessionPlanHours'); // tbody (other table)

        // New controls
        const startDate = document.getElementById('startDate');
        const regionSelect = document.getElementById('regionSelect');
        const holidayNote = document.getElementById('holidayNote');
        const allowBoxes = () => [...document.querySelectorAll('.day-allow')];

        // Keep the last fetched course details so we can recalc without refetch
        let lastCourseData = null;
        let lastModuleIdx = "";

        // ----- search -----
        searchInput.addEventListener('input', async () => {
            const q = searchInput.value.trim();
            clearSearchBtn.style.display = q ? 'block' : 'none';
            if (!q) {
                resultsBox.innerHTML = '';
                return;
            }

            const res = await fetch('search_courses.php?q=' + encodeURIComponent(q));
            const rows = await res.json();

            resultsBox.innerHTML = rows.map(r => `
      <button class="list-group-item list-group-item-action" 
              data-id="${r.course_id}" data-code="${r.course_code}">
        [${r.course_code}] ${r.course_title_external}
      </button>
    `).join('');
        });

        clearSearchBtn.addEventListener('click', () => {
            searchInput.value = '';
            resultsBox.innerHTML = '';
            clearSearchBtn.style.display = 'none';
            searchInput.focus();
        });

        // ----- after selecting a course result -----
        resultsBox.addEventListener('click', async e => {
            if (!e.target.closest('button')) return;
            const btn = e.target.closest('button');
            searchInput.value = btn.textContent.trim();
            resultsBox.innerHTML = '';

            const cid = btn.dataset.id;
            const res = await fetch('get_course_details.php?id=' + cid);
            const data = await res.json();
            lastCourseData = data;

            // fill top info
            courseTitle.textContent = btn.textContent;
            courseCode.textContent = btn.dataset.code;
            learningPace.textContent = data.data.master_learning_paces?.[0]?.pace || '—';
            deliveryMode.textContent = data.data.master_learning_delivery_modes?.[0]?.delivery_mode || '—';

            // learning modes
            modeSelect.innerHTML = '<option value="">Select a mode...</option>' +
                (data.data.master_learning_modes || []).map((mode, i) =>
                    `<option value="${i}">${mode.mode}</option>`).join('');
            modeDetails.innerHTML = '';

            // modules
            moduleSelect.innerHTML = '<option value="">Select a module...</option>' +
                (data.data.modules || []).map((m, i) =>
                    `<option value="${i}">${m.module_title}</option>`).join('');

            // reset everything for a new selection
            startDate.value = '';
            sessionPlans.innerHTML = '';
            sessionPlanHours.innerHTML = '';
            lastModuleIdx = "";
            showHolidayNote();
        });

        // Show note of how many holidays are considered for current year/region
        function showHolidayNote() {
            if (!startDate.value) {
                holidayNote.textContent = 'Select a start date to load region holidays for that year.';
                return;
            }
            const y = parseYMD(startDate.value).getFullYear();
            const set = getPublicHolidays(regionSelect.value, y);
            holidayNote.textContent = `Loaded ${set.size} public-holiday dates for ${regionSelect.value} (${y}).`;
        }

        // ----- learning mode detail toggle -----
        modeSelect.addEventListener('change', function() {
            if (!lastCourseData) return;
            const idx = this.value;
            if (idx === "") {
                modeDetails.innerHTML = "";
                return;
            }
            const mode = lastCourseData.data.master_learning_modes[idx];
            modeDetails.innerHTML = `
      <div class="card card-body mb-2">
        <b>Mode:</b> ${mode.mode || ''}<br>
        <b>Course Duration:</b> ${mode.course_duration || ''}<br>
        <b>Days per Week:</b> ${mode.days_per_week || ''}<br>
        <b>Hours per Day:</b> ${mode.hours_per_day || ''}<br>
        <b>Hours per Week:</b> ${mode.hours_per_week || ''}
      </div>`;
        });

        // ----- module change: render rows, then (if start date given) compute schedule -----
        moduleSelect.addEventListener('change', function() {
            if (!lastCourseData) return;
            const idx = this.value;
            lastModuleIdx = idx;
            sessionPlans.innerHTML = '';
            sessionPlanHours.innerHTML = '';
            if (idx === "") return;

            const module = lastCourseData.data.modules[idx];

            // Render session plan rows with editable Date & Time
            sessionPlans.innerHTML = (module.session_plans || []).map((s, i) => `
      <tr data-row="${i}">
        <td class="text-center align-middle">${i + 1}</td>
        <td class="align-middle">${s.session_type_name ?? ''}</td>
        <td class="align-middle">${s.session_short_name ?? ''}</td>
        <td class="align-middle">${s.description ?? ''}</td>
        <td class="text-center align-middle">${s.duration ?? ''}</td>
        <td class="text-center align-middle">${s.day ?? ''}</td>
        <td class="align-middle">
          <input type="date" class="form-control form-control-sm date-input">
        </td>
        <td class="align-middle weekday-cell">—</td>
        <td class="align-middle">
          <input type="time" class="form-control form-control-sm time-input">
        </td>
      </tr>
    `).join('');

            // Also render total-hours table (unchanged, editable not required unless you want inputs)
            sessionPlanHours.innerHTML = (module.session_plan_total_hours || []).map((s, i) => `
      <tr>
        <td class="text-center align-middle">${i + 1}</td>
        <td class="align-middle">${s.session_short_name ?? ''}</td>
        <td class="align-middle">${s.session_type_name ?? ''}</td>
        <td class="text-center align-middle">${s.duration ?? ''}</td>
      </tr>
    `).join('');

            // If a start date already chosen, compute the whole schedule now
            if (startDate.value) {
                recomputeFromRow(0);
            }
        });

        // ----- schedule computation -----
        function allowedWeekdays() {
            // returns a Set of numbers 0..6 (Sun..Sat)
            return new Set(allowBoxes().filter(b => b.checked).map(b => Number(b.value)));
        }

        function isAllowed(date, region) {
            const w = date.getDay(); // 0..6
            const allowed = allowedWeekdays();
            if (!allowed.has(w)) return false;

            const y = date.getFullYear();
            const hol = getPublicHolidays(region, y);
            if (hol.has(fmtYMD(date))) return false;

            return true;
        }

        function nextValidDate(startExclusive, region) {
            let d = addDays(startExclusive, 1);
            // skip until allowed and not holiday
            for (let guard = 0; guard < 800; guard++) {
                if (isAllowed(d, region)) return d;
                d = addDays(d, 1);
            }
            return d;
        }

        // Core rule: "always use the above cell as reference"
        // - Row 0 takes startDate
        // - Row k>0 takes the next valid date after row k-1's date
        // - If the user edits any date in row r, rows below reflow from r
        function recomputeFromRow(startRowIdx) {
            if (lastModuleIdx === "") return;
            const region = regionSelect.value || 'SL';
            const rows = [...sessionPlans.querySelectorAll('tr')];

            if (rows.length === 0) return;

            // Ensure the starting row has a date to anchor on; if not, set it sensibly
            if (startRowIdx === 0) {
                if (!startDate.value) return; // nothing to compute without anchor
                let d0 = parseYMD(startDate.value);
                // If d0 is not allowed, push forward to first allowed
                if (!isAllowed(d0, region)) {
                    // Use a virtual -1 day, then nextValidDate will find the first allowed
                    d0 = nextValidDate(addDays(d0, -1), region);
                }
                const r0 = rows[0];
                r0.querySelector('.date-input').value = fmtYMD(d0);
                r0.querySelector('.weekday-cell').textContent = weekdayName(d0);
                startRowIdx = 1; // subsequent rows will flow from row 0
            }

            for (let i = startRowIdx; i < rows.length; i++) {
                const prevRow = rows[i - 1];
                const prevDateInput = prevRow.querySelector('.date-input');
                if (!prevDateInput.value) break; // stop if previous row has no anchor

                const anchor = parseYMD(prevDateInput.value);
                const d = nextValidDate(anchor, region);

                const row = rows[i];
                const dateInput = row.querySelector('.date-input');
                const dayCell = row.querySelector('.weekday-cell');

                dateInput.value = fmtYMD(d);
                dayCell.textContent = weekdayName(d);
            }
        }

        // ----- listeners for cascading behavior -----
        // 1) When start date changes, recompute from row 0
        startDate.addEventListener('change', () => {
            showHolidayNote();
            recomputeFromRow(0);
        });

        // 2) When allowed weekdays change, recompute from row 0 (pattern changed)
        document.getElementById('scheduleControls').addEventListener('change', (e) => {
            if (e.target.classList.contains('day-allow')) {
                recomputeFromRow(0);
            }
        });

        // 3) When region changes, recompute from row 0 (holiday set changed)
        regionSelect.addEventListener('change', () => {
            showHolidayNote();
            recomputeFromRow(0);
        });

        // 4) If user edits a specific row's date, reflow rows below using that row as the new anchor
        sessionPlans.addEventListener('change', (e) => {
            if (!e.target.classList.contains('date-input')) return;
            const rowEl = e.target.closest('tr');
            const idx = Number(rowEl.dataset.row || '0');
            // also update the weekday cell for this row
            if (e.target.value) {
                const d = parseYMD(e.target.value);
                rowEl.querySelector('.weekday-cell').textContent = weekdayName(d);
            }
            // reflow rows below
            recomputeFromRow(idx + 1);
        });

        // NOTE:
        // You can add a Save button to collect current values to persist.
        // Example:
        // const payload = [...sessionPlans.querySelectorAll('tr')].map(tr => ({
        //   row: Number(tr.dataset.row),
        //   date: tr.querySelector('.date-input').value,
        //   time: tr.querySelector('.time-input').value
        // }));
        // fetch('save_schedule.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload) });

        // OPTIONAL mode: If you ever want to respect a "session day" number in data (s.day) to force week cycles,
        // keep this current default (pure sequential flow) and add a toggle later; your current requirement prioritizes
        // "use above cell as reference" so we keep strictly sequential scheduling.
    </script>

</body>

</html>