<?php
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $days = $_POST['weekdays'] ?? '';
    $daysArray = explode(",", $days); // Convert back to array

    echo "<h3>You selected:</h3>";
    echo "<ul>";
    foreach ($daysArray as $day) {
        echo "<li>" . htmlspecialchars($day) . "</li>";
    }
    echo "</ul>";
}
