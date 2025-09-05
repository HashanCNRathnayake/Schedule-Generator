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


        <div id="courseDetails" class="mt-4"></div>
    </div>

    <script>
        const searchInput = document.getElementById('search');
        const resultsBox = document.getElementById('results');
        const detailsBox = document.getElementById('courseDetails');
        const clearSearchBtn = document.getElementById('clearSearch');

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

        // Clear search input and results
        clearSearchBtn.addEventListener('click', () => {
            searchInput.value = '';
            resultsBox.innerHTML = '';
            clearSearchBtn.style.display = 'none';
            searchInput.focus();
        });

        // when user clicks result → fetch details
        resultsBox.addEventListener('click', async e => {
            if (!e.target.closest('button')) return;
            const btn = e.target.closest('button');
            searchInput.value = btn.textContent.trim();
            resultsBox.innerHTML = '';

            const cid = btn.dataset.id;
            const res = await fetch('get_course_details.php?id=' + cid);
            const data = await res.json();

            detailsBox.innerHTML = renderCourseDetails(data, btn);

            const modeSelect = document.getElementById('modeSelect');
            const modeDetails = document.getElementById('modeDetails');

            if (modeSelect) {
                modeSelect.addEventListener('change', function() {
                    const idx = this.value;
                    if (idx === "") {
                        modeDetails.innerHTML = "";
                        return;
                    }
                    const mode = data.data.master_learning_modes[idx];
                    modeDetails.innerHTML = `
                        <div class="card card-body mb-2">
                            <!--<b>Learning Mode ID:</b> ${mode.learning_mode_id || ''}<br>-->
                            <b>Mode:</b> ${mode.mode || ''}<br>
                            <b>Course Duration:</b> ${mode.course_duration || ''}<br>
                            <b>Days per Week:</b> ${mode.days_per_week || ''}<br>
                            <b>Hours per Day:</b> ${mode.hours_per_day || ''}<br>
                            <b>Hours per Week:</b> ${mode.hours_per_week || ''}
                        </div>
                    `;
                });
            }

            const moduleSelect = document.getElementById('moduSelect2');
            const moduleDetails = document.getElementById('moduDetails');
            if (moduleSelect) {
                moduleSelect.addEventListener('change', function() {
                    const idx = this.value;
                    if (idx === "") {
                        moduleDetails.innerHTML = "";
                        return;
                    }
                    const module = data.data.modules[idx];

                    moduleDetails.innerHTML = `
                    <h4>Session Plan</h4>


                        <table class="table table-bordered table-sm">
                            <tr><th>No</th><th>Type</th><th>Short</th><th>Details</th><th>Duration</th><th>Day</th></tr>
                            ${(module.session_plans || []).map((s, i) => `
                                <tr>
                                    <td>${i + 1}</td>
                                    <td>${s.session_type_name}</td>
                                    <td>${s.session_short_name}</td>
                                    <td>${s.description}</td>
                                    <td>${s.duration}</td>
                                    <td>${s.day}</td>
                                </tr>
                            `).join('') || ''}
                        </table>


                        <h4>Session Plan Total Hours</h4>
                        <table class="table table-bordered table-sm">
                            <tr>
                                <th>No</th>
                                <!--<th>Standard ID</th> -->
                                <th>Short</th>
                                <th>Type</th>
                                <th>Duration</th>
                            </tr>
                            ${(module.session_plan_total_hours || []).map((s, i) => `
                                <tr>
                                    <td>${i + 1}</td>
                                    <!-- <td>${s.session_plan_standard_id}</td> -->
                                    <td>${s.session_short_name}</td>
                                    <td>${s.session_type_name}</td>
                                    <td>${s.duration}</td>
                                </tr>
                            `).join('')}
                        </table>

                        `;
                });
            }

        });

        function renderCourseDetails(data, btn) {
            return `
                <h3>${btn.textContent}</h3>
                <p><b>Code:</b> ${btn.dataset.code}</p>
                <p><b>Learning Pace:</b> ${data.data.master_learning_paces?.[0]?.pace || '—'}</p>
                <p><b>Learning Delivery Mode:</b> ${data.data.master_learning_delivery_modes?.[0]?.delivery_mode || '—'}</p>



                <h4>Learning Modes</h4>
                <select id="modeSelect" class="form-select mb-2">
                    <option value="">Select a mode...</option>
                    ${
                        (data.data.master_learning_modes || []).map((mode, i) => `
                            <option value="${i}">${mode.mode}</option>
                        `).join('')
                    }
                </select>
                <div id="modeDetails"></div>

                <h4>Learning Modules Method 2</h4>
                <select id="moduSelect2" class="form-select mb-2">
                    <option value="">Select a mode...</option>
                    ${
                        (data.data.modules || []).map((modu, i) => `
                            <option value="${i}">${modu.module_title}</option>
                        `).join('')
                    }
                </select>
                <div id="moduDetails"></div>



                
            `;
        }
    </script>

</body>

</html>