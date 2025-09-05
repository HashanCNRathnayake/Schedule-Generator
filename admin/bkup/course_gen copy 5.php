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
            vertical-align: middle;
        }
    </style>
</head>

<body class="p-4">

    <div class="container">
        <a href="test.php">test</a>
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

            <div class="col-md-5" id="weekdayDropdown">
                <label class="form-label mb-1 d-block">Allowed Days</label>
                <div class="d-flex  gap-2">
                    <label class="form-check form-check-inline">
                        <input class="form-check-input day-allow" type="checkbox" id="selectWeekdays" value="">Select Weekdays (Mon–Fri)
                    </label>
                </div>
                <div class="d-flex  gap-2">
                    <!-- value uses 0..6 = Sun..Sat (JS getDay convention) -->
                    <label class="form-check form-check-inline">
                        <input class="form-check-input day-allow" type="checkbox" value="Monday" checked> Mon
                    </label>
                    <label class="form-check form-check-inline">
                        <input class="form-check-input day-allow" type="checkbox" value="Tuesday" checked> Tue
                    </label>
                    <label class="form-check form-check-inline">
                        <input class="form-check-input day-allow" type="checkbox" value="Wednesday" checked> Wed
                    </label>
                    <label class="form-check form-check-inline">
                        <input class="form-check-input day-allow" type="checkbox" value="Thursday" checked> Thu
                    </label>
                    <label class="form-check form-check-inline">
                        <input class="form-check-input day-allow" type="checkbox" value="Friday" checked> Fri
                    </label>
                </div>

                <div class="d-flex  gap-2">
                    <label class="form-check form-check-inline">
                        <input class="form-check-input day-allow" type="checkbox" id="selectWeekend" value="">Select Weekend (Sat–Sun)
                    </label>
                </div>

                <div class="d-flex  gap-2">
                    <!-- value uses 0..6 = Sun..Sat (JS getDay convention) -->
                    <label class="form-check form-check-inline">
                        <input class="form-check-input day-allow" type="checkbox" value="Saturday"> Sat
                    </label>
                    <label class="form-check form-check-inline">
                        <input class="form-check-input day-allow" type="checkbox" value="Sunday"> Sun
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
            <!-- <p><b>Code:</b> <span id="courseCode">—</span></p>
            <p><b>Learning Pace:</b> <span id="learningPace">—</span></p>
            <p><b>Learning Delivery Mode:</b> <span id="deliveryMode">—</span></p> -->

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
        // ----- DOM refs (existing from your page) -----
        const searchInput = document.getElementById('search');
        const resultsBox = document.getElementById('results');
        const clearSearchBtn = document.getElementById('clearSearch');

        const courseTitle = document.getElementById('courseTitle');
        // const courseCode = document.getElementById('courseCode');
        // const learningPace = document.getElementById('learningPace');
        // const deliveryMode = document.getElementById('deliveryMode');

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
            // courseCode.textContent = btn.dataset.code;
            // learningPace.textContent = data.data.master_learning_paces?.[0]?.pace || '—';
            // deliveryMode.textContent = data.data.master_learning_delivery_modes?.[0]?.delivery_mode || '—';

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
                    <p><b>Mode:</b> ${mode.mode || ''}</p>
                    <p><b>Course Duration:</b> ${mode.course_duration || ''}</p>
                    <p><b>Days per Week:</b> ${mode.days_per_week || ''}</p>
                    <p><b>Hours per Day:</b> ${mode.hours_per_day || ''}</p>
                    <p><b>Hours per Week:</b> ${mode.hours_per_week || ''}</p>
                </div>`;
        });


        const checkboxes = document.querySelectorAll("#weekdayDropdown div lable input[type='checkbox']");
        const selectedDaysDiv = document.getElementById("selectedDays");
        const weekdaysInput = document.getElementById("weekdaysInput");

        // Update badges + hidden input
        function updateSelectedDays() {
            selectedDaysDiv.innerHTML = "";
            let selected = [];
            checkboxes.forEach(cb => {
                if (cb.checked && cb.value) {
                    selected.push(cb.value);
                    const badge = document.createElement("span");
                    badge.className = "badge bg-info text-dark day-badge";
                    badge.textContent = cb.value + " ✕";
                    badge.onclick = () => {
                        cb.checked = false;
                        updateSelectedDays();
                    };
                    selectedDaysDiv.appendChild(badge);
                }
            });
            weekdaysInput.value = selected.join(","); // Store for backend
        }

        // Attach change event to all checkboxes
        checkboxes.forEach(cb => cb.addEventListener("change", updateSelectedDays));

        // Quick select for weekdays
        document.getElementById("selectWeekdays").addEventListener("change", function(event) {
            if (event.target.checked) {
                checkboxes.forEach(cb => {
                    if (["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"].includes(cb.value)) {
                        cb.checked = true;
                    }
                });

            } else {
                checkboxes.forEach(cb => {
                    if (["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"].includes(cb.value)) {
                        cb.checked = false;
                    }
                });
            }
            updateSelectedDays();
        });

        // Quick select for weekend
        document.getElementById("selectWeekend").addEventListener("change", function(event) {
            if (event.target.checked) {
                checkboxes.forEach(cb => {
                    if (["Saturday", "Sunday"].includes(cb.value)) {
                        cb.checked = true;
                    }
                });
            } else {
                checkboxes.forEach(cb => {
                    if (["Saturday", "Sunday"].includes(cb.value)) {
                        cb.checked = false;
                    }
                });
            }

            updateSelectedDays();
        });
    </script>



</body>

</html>