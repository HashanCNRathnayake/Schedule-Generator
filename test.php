<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <title>Date Picker (dd/mm/yyyy)</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Flatpickr CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
</head>

<body class="p-4">

    <form method="post">
        <div class="mb-3 col-md-4">
            <label for="start_date" class="form-label">Start Date</label>
            <input type="text" id="start_date" name="start_date" class="form-control" placeholder="dd/mm/yyyy" required>
        </div>
        <button class="btn btn-primary" type="submit">Submit</button>
    </form>

    <!-- Bootstrap Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Flatpickr JS -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

    <script>
        flatpickr("#start_date", {
            dateFormat: "d/m/Y", // dd/mm/yyyy
            allowInput: true // allow typing as well
        });
    </script>
</body>

</html>