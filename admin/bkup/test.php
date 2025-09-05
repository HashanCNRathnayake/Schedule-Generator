<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Weekday Multi-Select</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .selected-days {
            margin-top: 10px;
        }

        .day-badge {
            margin: 3px;
            cursor: pointer;
        }

        .dropdown-menu li {
            list-style: none;
            margin: 2px 0;
        }
    </style>
</head>

<body class="container mt-5">

    <h3>Select Weekdays</h3>
    <div class="dropdown">
        <button class="btn btn-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
            Choose Weekdays
        </button>
        <ul class="dropdown-menu p-2" id="weekdayDropdown">
            <li><input type="checkbox" id="selectWeekdays" value="">Select Weekdays (Mon–Fri)</li>
            <li><input type="checkbox" value="Monday"> Monday</li>
            <li><input type="checkbox" value="Tuesday"> Tuesday</li>
            <li><input type="checkbox" value="Wednesday"> Wednesday</li>
            <li><input type="checkbox" value="Thursday"> Thursday</li>
            <li><input type="checkbox" value="Friday"> Friday</li>

            <li><input type="checkbox" id="selectWeekend" value="">Select Weekend (Sat–Sun)</li>
            <li><input type="checkbox" value="Saturday"> Saturday</li>
            <li><input type="checkbox" value="Sunday"> Sunday</li>
        </ul>
    </div>

    <!-- Selected days preview -->
    <div id="selectedDays" class="selected-days"></div>

    <!-- Hidden field for backend (PHP will receive this) -->
    <form method="POST" action="process.php" class="mt-3">
        <input type="hidden" name="weekdays" id="weekdaysInput">
        <button type="submit" class="btn btn-success">Submit</button>
    </form>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const checkboxes = document.querySelectorAll("#weekdayDropdown input[type='checkbox']");
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