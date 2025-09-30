<style>
    html {
        padding: 0px 30px;
        overflow: auto;
        scrollbar-width: none;
        /* Firefox */
        -ms-overflow-style: none;
        /* IE and Edge */
    }

    html::-webkit-scrollbar {
        display: none;
        /* Chrome, Safari, Opera */
    }


    .nav-btn {
        background-color: white;
        padding: 2px 5px;
    }
</style>

<nav class="navbar navbar-expand-lg mb-2">
    <div class="container-fluid px-0">

        <a class="navbar-brand"
            href="<?= htmlspecialchars($baseUrl) ?>index.php">
            Schedule Generator
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNavDropdown">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarNavDropdown">
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0 align-items-center">
                <?php if ($me): ?>
                    <?php if (
                        hasRole($conn, 'User') ||
                        hasRole($conn, 'Admin') ||
                        hasRole($conn, 'SuperAdmin')
                    ): ?>

                        <li class="nav-item">
                            <a class="nav-link" href="<?= htmlspecialchars($baseUrl) ?>admin/course/master_temp.php">Templates</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="<?= htmlspecialchars($baseUrl) ?>schedule_gen.php">Schedules</a>
                        </li>
                    <?php endif; ?>
                <?php endif; ?>
                <li class="nav-item">
                    <a class="nav-link" href="<?= htmlspecialchars($baseUrl) ?>index.php">Schedules List</a>
                </li>
                <!-- <li class="nav-item">
                    <a class="nav-link" href="<?= htmlspecialchars($baseUrl) ?>admin/session_plans/generate.php">Session gen</a>
                </li> -->

                <?php if ($me): ?>
                    <?php if (hasRole($conn, 'Admin')): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="<?= htmlspecialchars($baseUrl) ?>admin/users.php">Manage Users</a>
                        </li>

                    <?php endif; ?>

                    <li class="nav-item ms-5">
                        <a href="<?= htmlspecialchars($baseUrl) ?>sso/logout.php" class="btn btn-danger btn-sm">Logout</a>
                    </li>


                <?php else: ?>
                    <li class="nav-item  ms-5">
                        <a href="<?= htmlspecialchars($baseUrl) ?>login.php" class="btn btn-primary btn-sm">Login</a>
                    </li>

                <?php endif; ?>

            </ul>
        </div>
    </div>
</nav>