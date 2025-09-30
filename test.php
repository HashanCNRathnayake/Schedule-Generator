<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <title>Modern Full-Width Schedule Generator</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css?family=Montserrat:700,600,500|Roboto:400,500&display=swap" rel="stylesheet" />
    <!-- Animate.css for animations -->
    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" />
    <!-- Material Icons -->
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet" />
    <style>
        :root {
            --brand: #941D63;
            --dark-brand: #790f4a;
            --deep: #2B2E4A;
            --accent: #FCE4EC;
            --bg: #F7F7FB;
            --text: #191919;
            --radius: 1.4rem;
            --shadow: 0 10px 30px rgba(148, 29, 99, 0.15);
            --transition: 0.3s ease;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: 'Roboto', 'Montserrat', Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
            min-height: 100vh;
            overflow-x: hidden;
        }

        header {
            background: white;
            box-shadow: var(--shadow);
            padding: 0.8rem 3%;
            display: flex;
            align-items: center;
            gap: 1rem;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .logo {
            width: 52px;
            height: 52px;
            border-radius: 0.5rem;
            box-shadow: var(--shadow);
            object-fit: contain;
            cursor: pointer;
        }

        .title {
            color: var(--brand);
            font-family: 'Montserrat', sans-serif;
            font-weight: 700;
            font-size: 2.8rem;
            letter-spacing: 0.1rem;
            user-select: none;
        }

        nav {
            margin-left: auto;
            font-weight: 500;
            font-family: 'Roboto', sans-serif;
            font-size: 1rem;
        }

        nav a {
            margin-left: 1.8rem;
            color: var(--deep);
            text-decoration: none;
            transition: color var(--transition);
            font-weight: 600;
            letter-spacing: 0.05rem;
        }

        nav a:hover {
            color: var(--brand);
        }

        main {
            width: 94vw;
            max-width: 1600px;
            margin: 3rem auto 4rem auto;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 3rem;
            animation: slideIn 1s ease forwards;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(40px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        form {
            background: #fff;
            padding: 3rem 2.5rem 3rem 2.5rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            display: flex;
            flex-direction: column;
            gap: 2.4rem;
            height: fit-content;
        }

        .section-title {
            font-family: 'Montserrat', sans-serif;
            font-size: 2.2rem;
            color: var(--brand);
            font-weight: 700;
            border-left: 6px solid var(--brand);
            padding-left: 1rem;
            margin-bottom: 0.5rem;
            letter-spacing: 0.03em;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 1.9rem 2rem;
        }

        label {
            font-weight: 600;
            color: var(--brand);
            margin-bottom: 0.6rem;
            display: inline-block;
            font-size: 1.15rem;
            user-select: none;
        }

        input[type="text"],
        input[type="date"],
        input[type="time"],
        select {
            width: 100%;
            padding: 1.1rem 1.2rem;
            border-radius: var(--radius);
            border: 1.5px solid var(--accent);
            background-color: var(--accent);
            font-size: 1.1rem;
            font-weight: 500;
            color: var(--deep);
            box-shadow: 0 6px 15px rgba(148, 29, 99, 0.05);
            transition: border-color var(--transition), box-shadow var(--transition), background-color var(--transition);
            cursor: pointer;
        }

        input[type="text"]:focus,
        input[type="date"]:focus,
        input[type="time"]:focus,
        select:focus {
            outline: none;
            border-color: var(--dark-brand);
            background-color: #fdf0f8;
            box-shadow: 0 7px 20px rgba(148, 29, 99, 0.16);
        }

        .days {
            display: flex;
            flex-wrap: wrap;
            gap: 1.3rem 1rem;
            padding: 0.5rem 0;
        }

        .days label {
            font-weight: 600;
            color: var(--deep);
            font-size: 1.05rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
            cursor: pointer;
            user-select: none;
            transition: color var(--transition);
        }

        .days input[type='checkbox'] {
            transform: scale(1.3);
            cursor: pointer;
            accent-color: var(--brand);
            transition: accent-color var(--transition);
        }

        .days input[type='checkbox']:hover {
            accent-color: var(--dark-brand);
        }

        button.submit-btn {
            background: linear-gradient(45deg, var(--brand), var(--dark-brand));
            color: white;
            border: none;
            padding: 1.2rem 2.8rem;
            font-family: 'Montserrat', sans-serif;
            font-weight: 700;
            font-size: 1.3rem;
            border-radius: var(--radius);
            box-shadow: 0 10px 25px rgba(148, 29, 99, 0.45);
            cursor: pointer;
            user-select: none;
            align-self: start;
            transition: background-color 0.25s, box-shadow 0.25s, transform 0.15s;
        }

        button.submit-btn:hover {
            background: linear-gradient(45deg, var(--dark-brand), var(--brand));
            box-shadow: 0 12px 40px rgba(148, 29, 99, 0.60);
            transform: scale(1.05);
        }

        button.submit-btn:active {
            transform: scale(0.98);
            box-shadow: 0 8px 20px rgba(148, 29, 99, 0.6);
        }

        /* Session Plan Table Panel */
        .session-plan {
            background: white;
            border-radius: var(--radius);
            padding: 2rem 1.8rem;
            box-shadow: var(--shadow);
            display: flex;
            flex-direction: column;
            overflow-x: auto;
        }

        .session-plan-header {
            font-family: 'Montserrat', sans-serif;
            font-size: 1.9rem;
            font-weight: 700;
            color: var(--brand);
            margin-bottom: 1.1rem;
            border-left: 8px solid var(--brand);
            padding-left: 1rem;
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 8px;
            min-width: 720px;
            font-size: 1.1rem;
            color: var(--deep);
        }

        thead th {
            text-align: left;
            font-weight: 700;
            padding: 1rem 1.3rem;
            background: var(--accent);
            color: var(--brand);
            letter-spacing: 0.04em;
            border-top-left-radius: var(--radius);
            border-top-right-radius: var(--radius);
            border-bottom: 3px solid var(--brand);
            user-select: none;
        }

        tbody tr {
            background: #fff;
            box-shadow: 0 3px 10px rgba(148, 29, 99, 0.07);
            transition: box-shadow 0.3s ease, background-color 0.3s ease;
            border-radius: var(--radius);
        }

        tbody tr:hover {
            background: var(--accent);
            box-shadow: 0 6px 20px rgba(148, 29, 99, 0.20);
            cursor: pointer;
        }

        tbody td {
            padding: 1.2rem 1.4rem;
            border-top: none;
            border-bottom: none;
            font-weight: 500;
        }

        /* Bottom actions */
        .bottom-actions {
            margin-top: 2.5rem;
            display: flex;
            gap: 1.25rem;
            justify-content: flex-end;
            flex-wrap: wrap;
        }

        .action-btn {
            background: var(--brand);
            color: white;
            border: none;
            padding: 0.9rem 1.8rem;
            border-radius: var(--radius);
            box-shadow: 0 8px 25px rgba(148, 29, 99, 0.3);
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: background-color 0.2s ease, transform 0.1s ease;
            user-select: none;
        }

        .action-btn:hover {
            background: var(--dark-brand);
            transform: scale(1.06);
        }

        .action-btn:active {
            transform: scale(0.96);
        }

        /* Scrollbar for session plan */
        .session-plan::-webkit-scrollbar {
            height: 10px;
        }

        .session-plan::-webkit-scrollbar-track {
            background: var(--bg);
        }

        .session-plan::-webkit-scrollbar-thumb {
            background: var(--brand);
            border-radius: 10px;
        }

        /* Responsive */
        @media screen and (max-width: 1120px) {
            main {
                grid-template-columns: 1fr;
                width: 90vw;
            }

            table {
                min-width: 100%;
                font-size: 1rem;
            }

            tbody td,
            thead th {
                padding: 0.9rem 1rem;
            }

            button.submit-btn {
                width: 100%;
                font-size: 1.2rem;
            }

            .days {
                justify-content: flex-start;
            }
        }

        @media (prefers-reduced-motion: reduce) {

            *,
            *::before,
            *::after {
                animation: none !important;
                transition: none !important;
                scroll-behavior: auto !important;
            }
        }
    </style>
</head>

<body>
    <header>
        <img src="favicon.jpg" alt="Brand Logo" class="logo" />
        <div class="title">Schedule Generator</div>
        <nav aria-label="Main navigation">
            <a href="#">Templates</a>
            <a href="#">Schedules</a>
            <a href="#">Schedules List</a>
            <a href="#">Manage Users</a>
        </nav>
    </header>

    <main>
        <form id="scheduleForm" aria-label="Generate schedule form" autocomplete="off" novalidate>
            <h2 class="section-title">Generate Schedule</h2>

            <div class="form-row">
                <div class="field">
                    <label for="course">Search Courses</label>
                    <input type="text" id="course" name="course" placeholder="Search for your course..." autocomplete="off" />
                </div>
                <div class="field">
                    <label for="module">Learning Modules</label>
                    <select id="module" name="module" aria-describedby="moduleHelp">
                        <option value="HESG-HDSE-WDF">HESG-HDSE-WDF</option>
                        <option value="HESG-HDSE-DSA">HESG-HDSE-DSA</option>
                        <option value="HESG-HDSE-AI">HESG-HDSE-AI</option>
                    </select>
                </div>
                <div class="field">
                    <label for="mode">Learning Modes</label>
                    <select id="mode" name="mode" aria-describedby="modeHelp">
                        <option value="Full-time">Full-time</option>
                        <option value="Part-time">Part-time</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="field">
                    <label for="cohortSuffix">Cohort Suffix</label>
                    <input type="text" id="cohortSuffix" name="cohortSuffix" placeholder="e.g. MMYY (0825)" autocomplete="off" />
                </div>
                <div class="field">
                    <label for="cohortCode">Cohort Code (auto-generated)</label>
                    <input type="text" id="cohortCode" name="cohortCode" readonly placeholder="auto-generated" />
                </div>
                <div class="field">
                    <label for="date">Start Date</label>
                    <input type="date" id="date" name="date" />
                </div>
                <div class="field">
                    <label>Day Pattern</label>
                    <div class="days" role="group" aria-label="Choose days">
                        <label><input type="checkbox" name="days" value="Mon" /> Mon</label>
                        <label><input type="checkbox" name="days" value="Tue" /> Tue</label>
                        <label><input type="checkbox" name="days" value="Wed" /> Wed</label>
                        <label><input type="checkbox" name="days" value="Thu" /> Thu</label>
                        <label><input type="checkbox" name="days" value="Fri" /> Fri</label>
                        <label><input type="checkbox" name="days" value="Sat" /> Sat</label>
                        <label><input type="checkbox" name="days" value="Sun" /> Sun</label>
                    </div>
                </div>
                <div class="field">
                    <label for="country">Country (Public Holidays)</label>
                    <select id="country" name="country">
                        <option value="Singapore">Singapore</option>
                        <option value="Malaysia">Malaysia</option>
                        <option value="UK">United Kingdom</option>
                        <option value="Australia">Australia</option>
                    </select>
                </div>
                <div class="field">
                    <label for="startTime">Start Time</label>
                    <input type="time" id="startTime" name="startTime" />
                </div>
                <div class="field">
                    <label for="endTime">End Time</label>
                    <input type="time" id="endTime" name="endTime" />
                </div>
            </div>

            <button type="submit" class="submit-btn" aria-live="polite" aria-atomic="true">
                Generate Schedule
            </button>
        </form>

        <section class="session-plan" aria-label="Session Plan">
            <h2 class="session-plan-header">Session Plan</h2>
            <table role="table" aria-describedby="sessionDesc" aria-live="polite" aria-relevant="additions removals" aria-atomic="true">
                <caption id="sessionDesc" class="sr-only">List of generated sessions</caption>
                <thead>
                    <tr>
                        <th scope="col">Session No</th>
                        <th scope="col">Session Type-Mode</th>
                        <th scope="col">Session Details</th>
                        <th scope="col">Duration Hr</th>
                        <th scope="col">Faculty Name</th>
                        <th scope="col">Date</th>
                        <th scope="col">Day</th>
                        <th scope="col">Time</th>
                    </tr>
                </thead>
                <tbody id="sessionData"></tbody>
            </table>

            <div class="bottom-actions" role="region" aria-label="Download and Copy actions">
                <button type="button" class="action-btn" id="btnDownloadPDF">Download PDF</button>
                <button type="button" class="action-btn" id="btnDownloadExcel">Download Excel</button>
                <button type="button" class="action-btn" id="btnViewHTML">View HTML</button>
                <button type="button" class="action-btn" id="btnCopyMessages">Copy to Messages</button>
            </div>
        </section>
    </main>

    <script>
        const form = document.getElementById("scheduleForm");
        const sessionTable = document.getElementById("sessionData");
        const cohortCodeInput = document.getElementById("cohortCode");
        const cohortSuffixInput = document.getElementById("cohortSuffix");

        // Auto-fill cohort code based on suffix (example logic)
        cohortSuffixInput.addEventListener("input", () => {
            const suffix = cohortSuffixInput.value.trim().toUpperCase();
            cohortCodeInput.value = suffix ? `HESG-HDSE-${suffix}` : "";
        });

        form.addEventListener("submit", (e) => {
            e.preventDefault();
            const submitBtn = form.querySelector("button[type=submit]");
            submitBtn.disabled = true;
            submitBtn.textContent = "Generating...";

            // Clear old data
            sessionTable.innerHTML = "";

            // Simulated session data creation (replace with backend logic)
            const daysSelected = Array.from(form.querySelectorAll("input[name='days']:checked")).map(
                (d) => d.value
            );
            const startDate = new Date(form.date.value);
            if (!startDate.getTime()) {
                alert("Please enter a valid start date.");
                submitBtn.disabled = false;
                submitBtn.textContent = "Generate Schedule";
                return;
            }

            // Generate up to 7 sessions, distributing across selected days cyclically
            const sessionsCount = 7;
            for (let i = 0; i < sessionsCount; i++) {
                const day = daysSelected.length > 0 ? daysSelected[i % daysSelected.length] : "Mon";
                const sessionDate = new Date(startDate);
                sessionDate.setDate(startDate.getDate() + i);
                const dateStr = sessionDate.toISOString().slice(0, 10);
                const timeStr = `${form.startTime.value || "09:00"} - ${form.endTime.value || "11:00"}`;

                const tr = document.createElement("tr");
                tr.classList.add("animate__animated", "animate__fadeInUp");
                tr.innerHTML = `
          <td>${i + 1}</td>
          <td>Lecture - ${form.mode.value}</td>
          <td>${form.module.value} Fundamentals</td>
          <td>2</td>
          <td>Dr. Jane Doe</td>
          <td>${dateStr}</td>
          <td>${day}</td>
          <td>${timeStr}</td>
        `;
                sessionTable.appendChild(tr);
            }
            submitBtn.disabled = false;
            submitBtn.textContent = "Generate Schedule";
        });

        // Button feedback microinteractions
        document.querySelectorAll(".action-btn").forEach((btn) => {
            btn.addEventListener("mousedown", () =>
                btn.classList.add("animate__animated", "animate__headShake")
            );
            btn.addEventListener("mouseup", () =>
                setTimeout(() => btn.classList.remove("animate__animated", "animate__headShake"), 500)
            );
        });

        // Placeholder button actions
        document.getElementById("btnDownloadPDF").addEventListener("click", () =>
            alert("Downloading PDF...")
        );
        document.getElementById("btnDownloadExcel").addEventListener("click", () =>
            alert("Downloading Excel...")
        );
        document.getElementById("btnViewHTML").addEventListener("click", () =>
            alert("Showing HTML preview...")
        );
        document.getElementById("btnCopyMessages").addEventListener("click", () =>
            alert("Copied schedule to messages!")
        );
    </script>
</body>

</html>