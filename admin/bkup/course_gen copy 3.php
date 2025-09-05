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
</head>

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
        const searchInput = document.getElementById('search');
        const resultsBox = document.getElementById('results');
        const clearSearchBtn = document.getElementById('clearSearch');

        // placeholders inside #courseDetails
        const courseTitle = document.getElementById('courseTitle');
        const courseCode = document.getElementById('courseCode');
        const learningPace = document.getElementById('learningPace');
        const deliveryMode = document.getElementById('deliveryMode');
        const modeSelect = document.getElementById('modeSelect');
        const modeDetails = document.getElementById('modeDetails');
        const moduleSelect = document.getElementById('moduSelect2');
        const sessionPlans = document.getElementById('sessionPlans');
        const sessionPlanHours = document.getElementById('sessionPlanHours');

        // search input listener
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

        // clear button
        clearSearchBtn.addEventListener('click', () => {
            searchInput.value = '';
            resultsBox.innerHTML = '';
            clearSearchBtn.style.display = 'none';
            searchInput.focus();
        });

        // when user clicks a search result
        resultsBox.addEventListener('click', async e => {
            if (!e.target.closest('button')) return;
            const btn = e.target.closest('button');
            searchInput.value = btn.textContent.trim();
            resultsBox.innerHTML = '';

            const cid = btn.dataset.id;
            const res = await fetch('get_course_details.php?id=' + cid);
            const data = await res.json();

            // fill placeholders
            courseTitle.textContent = btn.textContent;
            courseCode.textContent = btn.dataset.code;
            learningPace.textContent = data.data.master_learning_paces?.[0]?.pace || '—';
            deliveryMode.textContent = data.data.master_learning_delivery_modes?.[0]?.delivery_mode || '—';

            // populate learning modes
            modeSelect.innerHTML = '<option value="">Select a mode...</option>' +
                (data.data.master_learning_modes || []).map((mode, i) =>
                    `<option value="${i}">${mode.mode}</option>`
                ).join('');
            modeDetails.innerHTML = '';

            // populate modules
            moduleSelect.innerHTML = '<option value="">Select a module...</option>' +
                (data.data.modules || []).map((modu, i) =>
                    `<option value="${i}">${modu.module_code} ${modu.module_title}</option>`
                ).join('');
            sessionPlans.innerHTML = '';
            sessionPlanHours.innerHTML = '';

            // handle learning mode change
            modeSelect.onchange = function() {
                const idx = this.value;
                if (idx === "") {
                    modeDetails.innerHTML = "";
                    return;
                }
                const mode = data.data.master_learning_modes[idx];
                modeDetails.innerHTML = `
                <div class="card card-body mb-2">
                    <p><b>Mode:</b> ${mode.mode || ''}<br></p>
                    <p><b>Course Duration:</b> ${mode.course_duration || ''}<br></p>
                    <p><b>Days per Week:</b> ${mode.days_per_week || ''}<br></p>
                    <p><b>Hours per Day:</b> ${mode.hours_per_day || ''}<br></p>
                    <p><b>Hours per Week:</b> ${mode.hours_per_week || ''}</p>
                </div>
            `;
            };

            // handle module change
            moduleSelect.onchange = function() {
                const idx = this.value;
                if (idx === "") {
                    sessionPlans.innerHTML = '';
                    sessionPlanHours.innerHTML = '';
                    return;
                }
                const module = data.data.modules[idx];

                // fill session plans
                sessionPlans.innerHTML =
                    (module.session_plans || []).map((s, i) => `
                    <tr>
                        <td>${i + 1}</td>
                        <td>${s.session_type_name}</td>
                        <td>${s.session_short_name}</td>
                        <td>${s.description}</td>
                        <td>${s.duration}</td>
                        <td>${s.day}</td>
                    </tr>
                `).join('');

                // fill session plan total hours
                sessionPlanHours.innerHTML =
                    (module.session_plan_total_hours || []).map((s, i) => `
                    <tr>
                        <td>${i + 1}</td>
                        <td>${s.session_short_name}</td>
                        <td>${s.session_type_name}</td>
                        <td>${s.duration}</td>
                    </tr>
                `).join('');
            };
        });
    </script>

</body>

</html>