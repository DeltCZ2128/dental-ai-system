<?php

require_once __DIR__ . '/../config.php';


/*
|--------------------------------------------------------------------------
| DENTIST AUTHENTICATION
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['user_id']) ||
    strtolower(trim($_SESSION['role'] ?? '')) !== 'dentist'
) {
    header('Location: ../login.php');
    exit;
}


$userId = (int) $_SESSION['user_id'];

$message = '';
$error = '';



/*
|--------------------------------------------------------------------------
| HELPER FUNCTION
|--------------------------------------------------------------------------
*/

function dentistProfileEscape($value)
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}


/*
|--------------------------------------------------------------------------
| PROFILE PHOTO (file-based, no DB change)
|--------------------------------------------------------------------------
| Stored as: uploads/profile_photos/dentist_{userId}.jpg|png|webp
*/

function dentistProfilePhotoDir()
{
    return __DIR__ . '/../uploads/profile_photos';
}


function dentistProfilePhotoUrl($userId)
{
    $dir = dentistProfilePhotoDir();
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];

    foreach ($allowed as $ext) {
        $file = $dir . '/dentist_' . (int) $userId . '.' . $ext;

        if (is_file($file)) {
            return '../uploads/profile_photos/dentist_'
                . (int) $userId . '.' . $ext
                . '?v=' . filemtime($file);
        }
    }

    return '';
}



/*
|--------------------------------------------------------------------------
| GET USERS TABLE COLUMNS
|--------------------------------------------------------------------------
|
| This prevents errors if your users table has different optional
| columns.
|
*/

$userColumns = [];

try {

    $userColumnStmt = $pdo->query(
        "SHOW COLUMNS FROM users"
    );

    $userColumns = $userColumnStmt->fetchAll(
        PDO::FETCH_COLUMN
    );

} catch (PDOException $e) {

    $userColumns = [];
}



/*
|--------------------------------------------------------------------------
| GET DENTISTS TABLE COLUMNS
|--------------------------------------------------------------------------
|
| We check which columns actually exist before using them.
|
*/

$dentistColumns = [];

try {

    $dentistColumnStmt = $pdo->query(
        "SHOW COLUMNS FROM dentists"
    );

    $dentistColumns = $dentistColumnStmt->fetchAll(
        PDO::FETCH_COLUMN
    );

    if (!empty($dentistColumns)) {
        $hasAnyContact = false;
        foreach (['contact_number', 'contact', 'phone', 'phone_number', 'mobile_number'] as $cCol) {
            if (in_array($cCol, $dentistColumns, true)) {
                $hasAnyContact = true;
                break;
            }
        }
        if (!$hasAnyContact) {
            $pdo->exec("ALTER TABLE dentists ADD COLUMN contact_number VARCHAR(50) DEFAULT NULL");
            $dentistColumns[] = 'contact_number';
        }

        $hasAnySpec = false;
        foreach (['specialization', 'specialty'] as $sCol) {
            if (in_array($sCol, $dentistColumns, true)) {
                $hasAnySpec = true;
                break;
            }
        }
        if (!$hasAnySpec) {
            $pdo->exec("ALTER TABLE dentists ADD COLUMN specialization VARCHAR(120) DEFAULT 'General Dentistry'");
            $dentistColumns[] = 'specialization';
        }
    }

} catch (PDOException $e) {

    $dentistColumns = [];
}



/*
|--------------------------------------------------------------------------
| FIND DENTIST RECORD
|--------------------------------------------------------------------------
*/

$sessionFullName = trim(
    $_SESSION['full_name'] ?? ''
);


$dentistId = function_exists('resolveDentistId')
    ? resolveDentistId($userId, $sessionFullName)
    : 0;


/*
|--------------------------------------------------------------------------
| First: Match by user ID (lumang fallback, kapag walang helper)
|--------------------------------------------------------------------------
*/

if ($dentistId <= 0 && in_array('id', $dentistColumns, true)) {

    try {

        $dentistLookup = $pdo->prepare(
            "
            SELECT id
            FROM dentists
            WHERE id = ?
            LIMIT 1
            "
        );

        $dentistLookup->execute([
            $userId
        ]);

        $dentistId = (int) (
            $dentistLookup->fetchColumn() ?: 0
        );

    } catch (PDOException $e) {

        $dentistId = 0;
    }
}



/*
|--------------------------------------------------------------------------
| Second: Match by dentist name
|--------------------------------------------------------------------------
*/

if (
    $dentistId <= 0 &&
    in_array('dentist_name', $dentistColumns, true) &&
    $sessionFullName !== ''
) {

    try {

        $dentistLookup = $pdo->prepare(
            "
            SELECT id
            FROM dentists
            WHERE dentist_name = ?
            LIMIT 1
            "
        );

        $dentistLookup->execute([
            $sessionFullName
        ]);

        $dentistId = (int) (
            $dentistLookup->fetchColumn() ?: 0
        );

    } catch (PDOException $e) {

        $dentistId = 0;
    }
}



/*
|--------------------------------------------------------------------------
| GET CURRENT USER
|--------------------------------------------------------------------------
*/

$user = [
    'username' => '',
    'full_name' => $sessionFullName ?: 'Dentist',
    'role' => 'dentist'
];


try {

    $userStmt = $pdo->prepare(
        "
        SELECT
            username,
            full_name,
            role
        FROM users
        WHERE id = ?
        LIMIT 1
        "
    );

    $userStmt->execute([
        $userId
    ]);

    $userData = $userStmt->fetch(
        PDO::FETCH_ASSOC
    );


    if ($userData) {

        $user = array_merge(
            $user,
            $userData
        );
    }

} catch (PDOException $e) {

    $error = 'Unable to load account information.';
}



/*
|--------------------------------------------------------------------------
| GET DENTIST INFORMATION
|--------------------------------------------------------------------------
*/

$dentist = [
    'dentist_name' => $user['full_name'],
    'contact_number' => '',
    'specialization' => '',
    'status' => 'Active'
];


/*
|--------------------------------------------------------------------------
| DETECT CONTACT COLUMN
|--------------------------------------------------------------------------
*/

$contactColumn = null;

$possibleContactColumns = [
    'contact_number',
    'contact',
    'phone',
    'phone_number',
    'mobile_number'
];


foreach ($possibleContactColumns as $column) {

    if (
        in_array(
            $column,
            $dentistColumns,
            true
        )
    ) {

        $contactColumn = $column;

        break;
    }
}



/*
|--------------------------------------------------------------------------
| DETECT SPECIALIZATION COLUMN
|--------------------------------------------------------------------------
*/

$specializationColumn = null;

$possibleSpecializationColumns = [
    'specialization',
    'specialty'
];


foreach (
    $possibleSpecializationColumns
    as $column
) {

    if (
        in_array(
            $column,
            $dentistColumns,
            true
        )
    ) {

        $specializationColumn = $column;

        break;
    }
}



/*
|--------------------------------------------------------------------------
| LOAD DENTIST RECORD
|--------------------------------------------------------------------------
*/

if (
    $dentistId > 0 &&
    in_array('id', $dentistColumns, true)
) {

    try {

        $selectFields = [
            'id'
        ];


        if (
            in_array(
                'dentist_name',
                $dentistColumns,
                true
            )
        ) {

            $selectFields[] = 'dentist_name';
        }


        if ($contactColumn !== null) {

            $selectFields[] =
                $contactColumn;
        }


        if (
            $specializationColumn !== null
        ) {

            $selectFields[] =
                $specializationColumn;
        }


        if (
            in_array(
                'status',
                $dentistColumns,
                true
            )
        ) {

            $selectFields[] = 'status';
        }


        $dentistQuery = "
            SELECT "
            . implode(', ', $selectFields)
            . "
            FROM dentists
            WHERE id = ?
            LIMIT 1
        ";


        $dentistStmt = $pdo->prepare(
            $dentistQuery
        );


        $dentistStmt->execute([
            $dentistId
        ]);


        $dentistData =
            $dentistStmt->fetch(
                PDO::FETCH_ASSOC
            );


        if ($dentistData) {

            if (
                isset(
                    $dentistData[
                        'dentist_name'
                    ]
                )
            ) {

                $dentist['dentist_name'] =
                    $dentistData[
                        'dentist_name'
                    ];
            }


            if (
                $contactColumn !== null &&
                isset(
                    $dentistData[
                        $contactColumn
                    ]
                )
            ) {

                $dentist[
                    'contact_number'
                ] =
                    $dentistData[
                        $contactColumn
                    ];
            }


            if (
                $specializationColumn !== null &&
                isset(
                    $dentistData[
                        $specializationColumn
                    ]
                )
            ) {

                $dentist[
                    'specialization'
                ] =
                    $dentistData[
                        $specializationColumn
                    ];
            }


            if (
                isset(
                    $dentistData['status']
                )
                && $dentistData['status'] !== ''
            ) {

                $dentist['status'] =
                    $dentistData['status'];
            }
        }

    } catch (PDOException $e) {

        /*
        | Do not stop the whole page if optional
        | dentist information is unavailable.
        */

    }
}



/*
|--------------------------------------------------------------------------
| UPLOAD PROFILE PHOTO
|--------------------------------------------------------------------------
| action=upload_photo, input name=profile_photo
| JPG/PNG/WEBP only, max 2MB.
*/


/*
|--------------------------------------------------------------------------
| CROSS-SITE REQUEST FORGERY CHECK
|--------------------------------------------------------------------------
| Every request that changes data is compared with the token held in the
| session before any handler of this page runs. A submission that carries
| no token, or a token that does not match, is refused.
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
}
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'upload_photo'
) {
    if (!isset($_FILES['profile_photo'])) {
        $error = 'Please choose a photo to upload.';
    } else {
        $photo = $_FILES['profile_photo'];

        if (($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $error = 'Photo upload failed. Please try again.';
        } elseif (($photo['size'] ?? 0) <= 0) {
            $error = 'The selected photo is empty.';
        } elseif (($photo['size'] ?? 0) > 2 * 1024 * 1024) {
            $error = 'Photo must be 2MB or smaller.';
        } else {
            $check = @getimagesize($photo['tmp_name']);

            $allowedMimes = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
            ];

            if ($check === false || !isset($allowedMimes[$check['mime'] ?? ''])) {
                $error = 'Only JPG, PNG, or WEBP photos are allowed.';
            } else {
                $ext = $allowedMimes[$check['mime']];
                $photoDir = dentistProfilePhotoDir();

                if (!is_dir($photoDir)) {
                    @mkdir($photoDir, 0755, true);
                }

                if (!is_dir($photoDir)) {
                    $error = 'Unable to save photo. Upload folder is missing.';
                } else {
                    // Burahin ang lumang photo na ibang extension.
                    foreach (['jpg', 'jpeg', 'png', 'webp'] as $oldExt) {
                        $oldFile = $photoDir . '/dentist_' . $userId . '.' . $oldExt;

                        if (is_file($oldFile)) {
                            @unlink($oldFile);
                        }
                    }

                    $target = $photoDir . '/dentist_' . $userId . '.' . $ext;

                    if (@move_uploaded_file($photo['tmp_name'], $target)) {
                        $message = 'Profile photo updated successfully.';
                    } else {
                        $error = 'Unable to save photo. Please try again.';
                    }
                }
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| REMOVE PROFILE PHOTO
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'remove_photo'
) {
    $photoDir = dentistProfilePhotoDir();
    $removed = false;

    foreach (['jpg', 'jpeg', 'png', 'webp'] as $oldExt) {
        $oldFile = $photoDir . '/dentist_' . $userId . '.' . $oldExt;

        if (is_file($oldFile) && @unlink($oldFile)) {
            $removed = true;
        }
    }

    if ($removed) {
        $message = 'Profile photo removed.';
    } else {
        $error = 'No profile photo to remove.';
    }
}


/*
|--------------------------------------------------------------------------
| UPDATE PROFILE
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') !== 'upload_photo'
    && ($_POST['action'] ?? '') !== 'remove_photo'
) {

    $name = trim(
        $_POST['full_name'] ?? ''
    );

    $contact = trim(
        $_POST['contact_number'] ?? ''
    );

    $specialization = trim(
        $_POST['specialization'] ?? ''
    );

    $password = $_POST['password'] ?? '';

    $passwordConfirm = $_POST['password_confirm'] ?? '';



    /*
    |--------------------------------------------------------------------------
    | VALIDATE NAME
    |--------------------------------------------------------------------------
    */

    if ($name === '') {

        $error =
            'Full name is required.';

    } elseif (strlen($name) < 2) {

        $error =
            'Full name must contain at least 2 characters.';

    } else {

        try {

            /*
            |--------------------------------------------------------------------------
            | UPDATE USERS TABLE
            |--------------------------------------------------------------------------
            */

            if ($password !== '') {

                if (
                    strlen($password) < 6
                ) {

                    $error =
                        'Password must be at least 6 characters.';

                } elseif ($password !== $passwordConfirm) {

                    $error =
                        'New password and confirm password do not match.';

                } else {

                    $hashedPassword =
                        password_hash(
                            $password,
                            PASSWORD_DEFAULT
                        );


                    $updateUserStmt =
                        $pdo->prepare(
                            "
                            UPDATE users
                            SET
                                full_name = ?,
                                password = ?
                            WHERE id = ?
                            "
                        );


                    $updateUserStmt->execute([
                        $name,
                        $hashedPassword,
                        $userId
                    ]);
                }

            } else {

                $updateUserStmt =
                    $pdo->prepare(
                        "
                        UPDATE users
                        SET full_name = ?
                        WHERE id = ?
                        "
                    );


                $updateUserStmt->execute([
                    $name,
                    $userId
                ]);
            }



            /*
            |--------------------------------------------------------------------------
            | UPDATE DENTIST TABLE
            |--------------------------------------------------------------------------
            */

            if (
                $error === '' &&
                $dentistId > 0
            ) {

                $updateFields = [];
                $updateValues = [];


                /*
                | Update dentist name
                */

                if (
                    in_array(
                        'dentist_name',
                        $dentistColumns,
                        true
                    )
                ) {

                    $updateFields[] =
                        'dentist_name = ?';

                    $updateValues[] =
                        $name;
                }


                /*
                | Update contact
                */

                if (
                    $contactColumn !== null
                ) {

                    $updateFields[] =
                        $contactColumn . ' = ?';

                    $updateValues[] =
                        $contact;
                }


                /*
                | Update specialization
                */

                if (
                    $specializationColumn !== null
                ) {

                    $updateFields[] =
                        $specializationColumn . ' = ?';

                    $updateValues[] =
                        $specialization;
                }


                /*
                | Execute dentist update
                */

                if (!empty($updateFields)) {

                    $updateValues[] =
                        $dentistId;


                    $updateDentistQuery =
                        "
                        UPDATE dentists
                        SET "
                        . implode(
                            ', ',
                            $updateFields
                        )
                        . "
                        WHERE id = ?
                        ";


                    $updateDentistStmt =
                        $pdo->prepare(
                            $updateDentistQuery
                        );


                    $updateDentistStmt->execute(
                        $updateValues
                    );
                }
            }



            /*
            |--------------------------------------------------------------------------
            | UPDATE SESSION
            |--------------------------------------------------------------------------
            */

            if ($error === '') {

                $_SESSION['full_name'] =
                    $name;


                $message =
                    'Profile updated successfully.';


                /*
                | Update displayed values immediately
                */

                $user['full_name'] =
                    $name;

                $dentist['dentist_name'] =
                    $name;

                $dentist['contact_number'] =
                    $contact;

                $dentist['specialization'] =
                    $specialization;
            }

        } catch (PDOException $e) {

            $error =
                'Unable to update your profile. Please try again.';
        }
    }
}

$profilePhotoUrl = dentistProfilePhotoUrl($userId);

/* Clinical Snapshot Stats for Dentist Profile */
$statTotalAppts = 0;
$statCompletedAppts = 0;
$statUpcomingAppts = 0;
$statTodayAppts = 0;

if ($dentistId > 0) {
    try {
        $statStmt = $pdo->prepare("
            SELECT
                COUNT(*) AS total_all,
                SUM(CASE WHEN LOWER(status) = 'completed' THEN 1 ELSE 0 END) AS total_completed,
                SUM(CASE WHEN appointment_date >= CURDATE() AND LOWER(status) IN ('pending', 'confirmed') THEN 1 ELSE 0 END) AS total_upcoming,
                SUM(CASE WHEN appointment_date = CURDATE() AND LOWER(status) IN ('pending', 'confirmed') THEN 1 ELSE 0 END) AS total_today
            FROM appointments
            WHERE dentist_id = ?
        ");
        $statStmt->execute([$dentistId]);
        $statRow = $statStmt->fetch(PDO::FETCH_ASSOC);
        if ($statRow) {
            $statTotalAppts = (int) ($statRow['total_all'] ?? 0);
            $statCompletedAppts = (int) ($statRow['total_completed'] ?? 0);
            $statUpcomingAppts = (int) ($statRow['total_upcoming'] ?? 0);
            $statTodayAppts = (int) ($statRow['total_today'] ?? 0);
        }
    } catch (Throwable $e) {}
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Profile | Dentist Portal
    </title>


    <!-- ======================================================
         BOOTSTRAP ICONS
    ======================================================= -->

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >


    <!-- ======================================================
         GOOGLE FONTS
    ======================================================= -->

    <link
        rel="stylesheet"
        href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap"
    >


    <!-- ======================================================
         PORTAL CSS
    ======================================================= -->

    <link
        rel="stylesheet"
        href="portal.css?v=5"
    >

    <!-- DENTIST SIDEBAR (dashboard parity) -->
    <link rel="stylesheet" href="dentist_sidebar.css">


    <style>
        /* ======================================================
           DENTIST PROFILE — HERO BANNER & 2-COLUMN WORKSPACE
        ======================================================= */
        .dentist-hero-card {
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, #0E6B5E 0%, #059669 58%, #0A4A41 100%);
            border: 1px solid rgba(255, 255, 255, 0.26);
            border-radius: 20px;
            padding: 26px 30px;
            color: #ffffff;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 24px;
            box-shadow: 0 18px 40px rgba(6, 59, 49, 0.24);
        }

        .dentist-hero-card::before,
        .dentist-hero-card::after {
            content: "";
            position: absolute;
            border-radius: 50%;
            pointer-events: none;
        }

        .dentist-hero-card::before {
            width: 240px;
            height: 240px;
            top: -110px;
            right: -60px;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.18), rgba(255, 255, 255, 0) 70%);
        }

        .dentist-hero-card::after {
            width: 180px;
            height: 180px;
            bottom: -90px;
            left: 15%;
            background: radial-gradient(circle, rgba(201, 162, 39, 0.18), rgba(255, 255, 255, 0) 70%);
        }

        .dentist-hero-card > * {
            position: relative;
            z-index: 1;
        }

        .profile-photo-wrap {
            position: relative;
            flex-shrink: 0;
        }

        .large-profile-circle {
            width: 92px;
            height: 92px;
            border-radius: 50%;
            background: linear-gradient(135deg, #ffffff, #EAF7F0);
            color: #0E6B5E;
            border: 3px solid rgba(255, 255, 255, 0.9);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.22);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            font-weight: 800;
            overflow: hidden;
        }

        .large-profile-circle img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .profile-edit-photo-btn {
            position: absolute;
            right: -4px;
            bottom: -4px;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            border: 2.5px solid #ffffff;
            background: linear-gradient(135deg, #F59E0B, #C9A227);
            color: #0A3B34;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.28);
            padding: 0;
            transition: transform 0.18s ease, filter 0.18s ease;
        }

        .profile-edit-photo-btn:hover {
            transform: scale(1.06);
            filter: brightness(1.05);
        }

        .hero-identity {
            flex: 1;
            min-width: 0;
        }

        .hero-name-row {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 5px;
        }

        .profile-info-name {
            font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif;
            font-size: 23px;
            font-weight: 800;
            letter-spacing: -0.02em;
            color: #ffffff;
            margin: 0;
            line-height: 1.2;
        }

        .hero-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 11px;
            border-radius: 999px;
            background: rgba(16, 185, 129, 0.25);
            border: 1px solid rgba(167, 243, 208, 0.55);
            color: #ECFDF5;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.4px;
            text-transform: uppercase;
        }

        .hero-status-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #A7F3D0;
            box-shadow: 0 0 0 3px rgba(167, 243, 208, 0.28);
        }

        .hero-sub-meta {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px 16px;
            font-size: 13px;
            color: #D9F2E6;
            font-weight: 500;
            margin-bottom: 12px;
        }

        .hero-sub-meta span {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .hero-badges-row {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .hero-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 11.5px;
            font-weight: 700;
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: #ffffff;
            padding: 5px 12px;
            border-radius: 999px;
        }

        .hero-chip.gold {
            background: linear-gradient(135deg, rgba(253, 230, 138, 0.24), rgba(201, 162, 39, 0.28));
            border-color: rgba(253, 230, 138, 0.55);
            color: #FEF3C7;
        }

        .profile-header-right {
            margin-left: auto;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 8px;
            flex-shrink: 0;
        }

        .profile-photo-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .profile-photo-edit-text {
            border: 1px solid rgba(255, 255, 255, 0.45);
            background: rgba(255, 255, 255, 0.16);
            color: #ffffff;
            font-size: 12.5px;
            font-weight: 800;
            padding: 9px 16px;
            border-radius: 999px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            font-family: inherit;
            transition: all 0.18s ease;
        }

        .profile-photo-edit-text:hover {
            background: #ffffff;
            color: #0E6B5E;
            border-color: #ffffff;
        }

        .profile-photo-remove {
            border: 1px solid rgba(254, 202, 202, 0.7);
            background: #ffffff;
            color: #B91C1C;
            font-size: 12.5px;
            font-weight: 800;
            cursor: pointer;
            font-family: inherit;
            padding: 9px 16px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            transition: all 0.18s ease;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
        }

        .profile-photo-remove:hover {
            background: #DC2626;
            border-color: #DC2626;
            color: #ffffff;
        }

        .photo-hint-note {
            font-size: 11px;
            color: rgba(217, 242, 230, 0.85);
            font-weight: 500;
        }

        /* 2-Column Workspace Grid */
        .profile-workspace-grid {
            display: grid;
            grid-template-columns: 350px minmax(0, 1fr);
            gap: 20px;
            align-items: start;
        }

        .profile-panel-card {
            background: #ffffff;
            border: 1px solid #C9E9DA;
            border-radius: 18px;
            box-shadow: 0 12px 30px rgba(6, 59, 49, 0.10);
            overflow: hidden;
        }

        .profile-panel-head {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 22px;
            background: linear-gradient(135deg, #D9F2E6 0%, #EAF7F0 100%);
            border-bottom: 1px solid #C9E9DA;
        }

        .panel-head-icon {
            width: 38px;
            height: 38px;
            border-radius: 11px;
            background: linear-gradient(135deg, #0E6B5E, #0A4A41);
            color: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
            flex-shrink: 0;
            box-shadow: 0 4px 10px rgba(14, 107, 94, 0.22);
        }

        .profile-panel-head h2 {
            margin: 0;
            font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif;
            font-size: 16px;
            font-weight: 800;
            color: #143f51;
            line-height: 1.2;
        }

        .profile-panel-head p {
            margin: 2px 0 0;
            font-size: 12px;
            color: #5b747e;
            font-weight: 500;
        }

        .profile-panel-body {
            padding: 20px 22px 22px;
        }

        /* Left Column: Overview Info List & Clinical Stats */
        .overview-info-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 18px;
        }

        .overview-info-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 11px 14px;
            border-radius: 12px;
            background: #F6FBF8;
            border: 1px solid #E2ECE7;
        }

        .overview-item-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 12.5px;
            font-weight: 700;
            color: #4b6570;
        }

        .overview-item-label i {
            color: #0E6B5E;
            font-size: 14px;
        }

        .overview-item-val {
            font-size: 13px;
            font-weight: 800;
            color: #143f51;
            text-align: right;
            word-break: break-word;
        }

        .overview-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 11px;
            border-radius: 999px;
            font-size: 11.5px;
            font-weight: 800;
            background: #D1FAE5;
            border: 1px solid #A7F3D0;
            color: #047857;
        }

        .overview-badge.role {
            background: #E0F2FE;
            border-color: #BAE6FD;
            color: #0369A1;
            text-transform: capitalize;
        }

        .clinical-stats-title {
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #5b747e;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .clinical-stats-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .clinical-stat-box {
            padding: 13px 14px;
            border-radius: 13px;
            background: linear-gradient(135deg, #047857 0%, #059669 60%, #10B981 100%);
            color: #ffffff;
            box-shadow: 0 8px 18px rgba(5, 150, 105, 0.22);
        }

        .clinical-stat-box .stat-num {
            font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif;
            font-size: 24px;
            font-weight: 800;
            line-height: 1.1;
            margin-bottom: 3px;
        }

        .clinical-stat-box .stat-lbl {
            font-size: 11.5px;
            font-weight: 600;
            color: rgba(255, 255, 255, 0.9);
            display: flex;
            align-items: center;
            gap: 5px;
        }

        /* Right Column: Form Styles */
        .profile-form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .profile-form-group {
            margin-bottom: 4px;
        }

        .profile-form-label {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 7px;
            font-size: 13px;
            font-weight: 700;
            color: #1e3a47;
        }

        .profile-form-label span i {
            color: #0E6B5E;
            margin-right: 6px;
        }

        .readonly-tag {
            font-size: 10.5px;
            font-weight: 700;
            color: #64748B;
            background: #F1F5F9;
            border: 1px solid #CBD5E1;
            border-radius: 999px;
            padding: 1px 8px;
        }

        .input-icon-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }

        .profile-form-input {
            width: 100%;
            padding: 11px 14px;
            border: 1.5px solid #CFE3DA;
            border-radius: 11px;
            background: #ffffff;
            color: #143f51;
            font-family: inherit;
            font-size: 14px;
            font-weight: 600;
            outline: none;
            transition: border-color 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
        }

        .profile-form-input:focus {
            border-color: #0E6B5E !important;
            box-shadow: 0 0 0 3.5px rgba(14, 107, 94, 0.13) !important;
        }

        .profile-form-input:disabled {
            background: #F4F8F6;
            border-color: #DCE8E2;
            color: #647d87;
            cursor: not-allowed;
        }

        .pw-toggle-btn {
            position: absolute;
            right: 10px;
            background: transparent;
            border: none;
            color: #5b747e;
            font-size: 16px;
            cursor: pointer;
            padding: 4px 6px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .pw-toggle-btn:hover {
            color: #0E6B5E;
            background: #EAF7F0;
        }

        .profile-help {
            margin-top: 5px;
            font-size: 11.5px;
            color: #6c858d;
            font-weight: 500;
        }

        /* Password Box */
        .password-section {
            margin-top: 22px;
            padding: 18px 18px 16px;
            border-radius: 14px;
            background: #F8FCFA;
            border: 1px solid #D9EFE4;
        }

        .password-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 14px;
        }

        .password-title {
            font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif;
            font-size: 14.5px;
            font-weight: 800;
            color: #143f51;
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0;
        }

        .password-title i {
            color: #0E6B5E;
            font-size: 16px;
        }

        .optional-pill {
            font-size: 11px;
            font-weight: 700;
            color: #0E6B5E;
            background: #EAF7F0;
            border: 1px solid #C9E9DA;
            padding: 3px 10px;
            border-radius: 999px;
        }

        /* Form Actions Footer */
        .profile-form-footer {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 20px;
            padding-top: 16px;
            border-top: 1px solid #E6F2EC;
        }

        .profile-save-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: none;
            padding: 12px 24px;
            border-radius: 999px !important;
            background: linear-gradient(135deg, #149A72, #0A4A41) !important;
            color: #ffffff;
            font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif;
            font-size: 13.5px;
            font-weight: 800;
            cursor: pointer;
            box-shadow: 0 8px 20px rgba(14, 107, 94, 0.26);
            transition: transform 0.16s ease, box-shadow 0.16s ease, filter 0.16s ease;
        }

        .profile-save-button:hover {
            transform: translateY(-1px);
            box-shadow: 0 12px 24px rgba(14, 107, 94, 0.34);
            filter: brightness(1.04);
        }

        /* Alerts */
        .profile-message,
        .profile-error {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            margin-bottom: 18px;
            border-radius: 12px;
            font-size: 13.5px;
            font-weight: 700;
        }

        .profile-message {
            background: #ECFDF5;
            border: 1px solid #A7F3D0;
            color: #047857;
        }

        .profile-error {
            background: #FEF2F2;
            border: 1px solid #FECACA;
            color: #B91C1C;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .profile-workspace-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 720px) {
            .dentist-hero-card {
                flex-direction: column;
                align-items: flex-start;
                padding: 22px 20px;
            }
            .profile-header-right {
                margin-left: 0;
                width: 100%;
                align-items: flex-start;
            }
            .profile-form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>


</head>


<body>

<style>
body{background:linear-gradient(135deg,#022c22,#059669,#065f46,#10b981,#022c22)!important;background-size:300% 300%!important;animation:dentistDashBg 8s ease infinite!important;}
.portal-main{background:transparent!important;}
@keyframes dentistDashBg{0%{background-position:0% 50%;}50%{background-position:100% 50%;}100%{background-position:0% 50%;}}
</style>


    <!-- ======================================================
         SIDEBAR
    ======================================================= -->

    <?php
$dentistActivePage = 'profile';
include __DIR__ . '/dentist_sidebar.php';
?>



    <!-- ======================================================
         MAIN CONTENT
    ======================================================= -->

    <main class="portal-main">

        <style>
            .clinic-minibar {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                background: #0A3B34;
                color: #C9A227;
                font-size: 11px;
                font-weight: 700;
                letter-spacing: .6px;
                padding: 6px 12px;
            }
            .clinic-minibar span {
                color: #ffffff;
            }
            /* TOPBAR date + time (tulad sa appointment page) */
            .topbar-today {
                display: flex;
                align-items: center;
                gap: 10px;
                color: #244f60;
                border-left: none;
                padding-left: 0;
            }
            .topbar-today i { font-size: 22px; }
            .topbar-today strong { display: block; font-size: 13px; font-weight: 800; line-height: 1.2; }
            .topbar-today small { display: block; font-size: 11px; opacity: .85; font-weight: 600; }
            .topbar-time {
                display: flex;
                align-items: center;
                gap: 10px;
                color: #244f60;
                border-left: 1px solid #e8eef2;
                padding-left: 16px;
            }
            .topbar-time i { font-size: 22px; }
            .topbar-time strong { display: block; font-size: 13px; font-weight: 800; line-height: 1.2; }
            .topbar-time small { display: block; font-size: 11px; opacity: .85; font-weight: 600; font-variant-numeric: tabular-nums; }
        </style>

        <!-- MINI CLINIC BAR (gaya sa appointment page) -->

        <div class="clinic-minibar">
            🦷 <span>Abalos Dental Clinic</span>
        </div>


        <!-- TOPBAR -->

        <header class="portal-topbar">


            <div>

                <div class="portal-heading">

                    Profile

                </div>


                <div class="portal-subtitle">

                    Manage your dentist account information

                </div>

            </div>


            <div style="display:flex;align-items:center;gap:16px;">

                <div class="topbar-today">

                    <i class="bi bi-calendar-event-fill"></i>

                    <div>
                        <strong><?= dentistProfileEscape(date('l')) ?></strong>
                        <small><?= dentistProfileEscape(date('M d, Y')) ?></small>
                    </div>

                </div>


                <div class="topbar-time">

                    <i class="bi bi-clock-fill"></i>

                    <div>
                        <strong>Clinic Time</strong>
                        <small id="profileClinicTime">--:--:--</small>
                    </div>

                </div>

            </div>


        </header>

        <script>
        (function () {
            function tickProfileClinicTime() {
                try {
                    var el = document.getElementById('profileClinicTime');
                    if (!el) return;
                    var now = new Date();
                    var timeStr = now.toLocaleTimeString('en-PH', {
                        timeZone: 'Asia/Manila',
                        hour: '2-digit',
                        minute: '2-digit',
                        second: '2-digit',
                        hour12: true
                    });
                    el.textContent = timeStr;
                } catch (e) {}
            }
            tickProfileClinicTime();
            setInterval(tickProfileClinicTime, 1000);
        })();
        </script>



        <!-- ==================================================
             PAGE CONTENT
        =================================================== -->

        <div class="portal-content">

            <?php
            $displaySpec = trim((string) ($dentist['specialization'] ?? ''));
            if ($displaySpec === '') {
                $displaySpec = 'General Dentistry';
            }
            $displayContact = trim((string) ($dentist['contact_number'] ?? ''));
            $displayStatus = trim((string) ($dentist['status'] ?? 'Active')) ?: 'Active';
            $dentistBadgeCode = $dentistId > 0 ? sprintf('DENT-%03d', $dentistId) : ('USR-' . $userId);
            ?>

            <!-- =================================================
                 HERO PROFILE BANNER
            ================================================== -->
            <div class="dentist-hero-card">
                <div class="profile-photo-wrap">
                    <div class="large-profile-circle" id="dentistPhotoPreviewCircle">
                        <?php if ($profilePhotoUrl !== ''): ?>
                            <img
                                src="<?= dentistProfileEscape($profilePhotoUrl) ?>"
                                alt="Dentist profile photo"
                                id="dentistPhotoImg"
                            >
                        <?php else: ?>
                            <i class="bi bi-person-fill" id="dentistPhotoIcon"></i>
                        <?php endif; ?>
                    </div>
                    <button
                        type="button"
                        class="profile-edit-photo-btn"
                        id="dentistEditPhotoBtn"
                        title="Change profile photo"
                        aria-label="Change profile photo"
                    >
                        <i class="bi bi-camera-fill"></i>
                    </button>
                </div>

                <div class="hero-identity">
                    <div class="hero-name-row">
                        <h1 class="profile-info-name"><?= dentistProfileEscape($user['full_name']) ?></h1>
                        <span class="hero-status-pill">
                            <span class="hero-status-dot"></span>
                            <?= dentistProfileEscape($displayStatus) ?>
                        </span>
                    </div>

                    <div class="hero-sub-meta">
                        <span>
                            <i class="bi bi-envelope-fill"></i>
                            <?= dentistProfileEscape($user['username']) ?>
                        </span>
                        <?php if ($displayContact !== ''): ?>
                            <span>
                                <i class="bi bi-telephone-fill"></i>
                                <?= dentistProfileEscape($displayContact) ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="hero-badges-row">
                        <span class="hero-chip gold">
                            <i class="bi bi-shield-check"></i>
                            DENTIST PORTAL
                        </span>
                        <span class="hero-chip">
                            <i class="bi bi-award-fill"></i>
                            <?= dentistProfileEscape($displaySpec) ?>
                        </span>
                        <span class="hero-chip">
                            <i class="bi bi-person-Vcard-fill bi-person-badge-fill"></i>
                            ID: <?= dentistProfileEscape($dentistBadgeCode) ?>
                        </span>
                    </div>
                </div>

                <div class="profile-header-right">
                    <div class="profile-photo-actions">
                        <button
                            type="button"
                            class="profile-photo-edit-text"
                            id="dentistEditPhotoTextBtn"
                        >
                            <i class="bi bi-camera-fill"></i>
                            Change Photo
                        </button>
                        <?php if ($profilePhotoUrl !== ''): ?>
                            <form method="POST" style="display:inline;" id="dentistRemovePhotoForm">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="remove_photo">
                                <button type="button" class="profile-photo-remove" id="dentistRemovePhotoBtn">
                                    <i class="bi bi-trash3-fill"></i>
                                    Remove
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <div class="photo-hint-note">
                        <i class="bi bi-info-circle me-1"></i>JPG, PNG, or WEBP · Max 2MB
                    </div>

                    <form
                        method="POST"
                        enctype="multipart/form-data"
                        id="dentistPhotoForm"
                        style="display:none;"
                    >
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="upload_photo">
                        <input
                            type="file"
                            name="profile_photo"
                            id="dentistPhotoInput"
                            accept="image/jpeg,image/png,image/webp"
                        >
                    </form>
                </div>
            </div>

            <script>
            (function () {
                var input = document.getElementById('dentistPhotoInput');
                var form = document.getElementById('dentistPhotoForm');
                var btn = document.getElementById('dentistEditPhotoBtn');
                var textBtn = document.getElementById('dentistEditPhotoTextBtn');

                function openPicker() {
                    if (input) input.click();
                }

                if (btn) btn.addEventListener('click', openPicker);
                if (textBtn) textBtn.addEventListener('click', openPicker);

                if (input) {
                    input.addEventListener('change', function () {
                        if (!input.files || !input.files[0]) return;

                        var file = input.files[0];

                        if (file.size > 2 * 1024 * 1024) {
                            alert('Photo must be 2MB or smaller.');
                            input.value = '';
                            return;
                        }

                        try {
                            var url = URL.createObjectURL(file);
                            var circle = document.getElementById('dentistPhotoPreviewCircle');
                            if (circle) {
                                circle.innerHTML = '<img src="' + url + '" alt="Preview">';
                            }
                        } catch (e) {}

                        if (form) form.submit();
                    });
                }
            })();
            </script>


            <!-- =================================================
                 2-COLUMN WORKSPACE: OVERVIEW + EDIT FORM
            ================================================== -->
            <div class="profile-workspace-grid">

                <!-- LEFT COLUMN: ACCOUNT & CLINICAL SNAPSHOT -->
                <aside class="profile-panel-card">
                    <div class="profile-panel-head">
                        <span class="panel-head-icon">
                            <i class="bi bi-person-badge-fill"></i>
                        </span>
                        <div>
                            <h2>Account & Clinical Overview</h2>
                            <p>Verified practitioner details & workload summary</p>
                        </div>
                    </div>

                    <div class="profile-panel-body">
                        <div class="overview-info-list">
                            <div class="overview-info-item">
                                <span class="overview-item-label">
                                    <i class="bi bi-activity"></i>
                                    Account Status
                                </span>
                                <span class="overview-badge">
                                    <i class="bi bi-check-circle-fill"></i>
                                    <?= dentistProfileEscape($displayStatus) ?>
                                </span>
                            </div>

                            <div class="overview-info-item">
                                <span class="overview-item-label">
                                    <i class="bi bi-shield-lock-fill"></i>
                                    Portal Role
                                </span>
                                <span class="overview-badge role">
                                    <?= dentistProfileEscape($user['role'] ?? 'Dentist') ?>
                                </span>
                            </div>

                            <div class="overview-info-item">
                                <span class="overview-item-label">
                                    <i class="bi bi-award-fill"></i>
                                    Specialization
                                </span>
                                <span class="overview-item-val">
                                    <?= dentistProfileEscape($displaySpec) ?>
                                </span>
                            </div>

                            <div class="overview-info-item">
                                <span class="overview-item-label">
                                    <i class="bi bi-telephone-fill"></i>
                                    Contact Number
                                </span>
                                <span class="overview-item-val">
                                    <?= dentistProfileEscape($displayContact !== '' ? $displayContact : 'Not set') ?>
                                </span>
                            </div>

                            <div class="overview-info-item">
                                <span class="overview-item-label">
                                    <i class="bi bi-hospital-fill"></i>
                                    Clinic Branch
                                </span>
                                <span class="overview-item-val">
                                    Abalos Dental Clinic
                                </span>
                            </div>
                        </div>

                        <div class="clinical-stats-title">
                            <i class="bi bi-bar-chart-fill" style="color:#0E6B5E;"></i>
                            Clinical Activity Snapshot
                        </div>

                        <div class="clinical-stats-grid">
                            <div class="clinical-stat-box">
                                <div class="stat-num"><?= (int) $statTotalAppts ?></div>
                                <div class="stat-lbl">
                                    <i class="bi bi-calendar2-check-fill"></i>
                                    Total Assigned
                                </div>
                            </div>

                            <div class="clinical-stat-box">
                                <div class="stat-num"><?= (int) $statCompletedAppts ?></div>
                                <div class="stat-lbl">
                                    <i class="bi bi-check-circle-fill"></i>
                                    Completed
                                </div>
                            </div>

                            <div class="clinical-stat-box">
                                <div class="stat-num"><?= (int) $statUpcomingAppts ?></div>
                                <div class="stat-lbl">
                                    <i class="bi bi-alarm-fill"></i>
                                    Upcoming
                                </div>
                            </div>

                            <div class="clinical-stat-box">
                                <div class="stat-num"><?= (int) $statTodayAppts ?></div>
                                <div class="stat-lbl">
                                    <i class="bi bi-calendar-day-fill"></i>
                                    Today's Load
                                </div>
                            </div>
                        </div>
                    </div>
                </aside>


                <!-- RIGHT COLUMN: PROFILE INFORMATION & SECURITY FORM -->
                <section class="profile-panel-card">
                    <div class="profile-panel-head">
                        <span class="panel-head-icon">
                            <i class="bi bi-Sliders2 bi-person-gear"></i>
                        </span>
                        <div>
                            <h2>Personal & Professional Settings</h2>
                            <p>Update your practitioner name, contact details, specialization, and password</p>
                        </div>
                    </div>

                    <div class="profile-panel-body">

                        <!-- SUCCESS ALERT -->
                        <?php if ($message !== ''): ?>
                            <div class="profile-message">
                                <i class="bi bi-check-circle-fill" style="font-size:17px;"></i>
                                <span><?= dentistProfileEscape($message) ?></span>
                            </div>
                        <?php endif; ?>

                        <!-- ERROR ALERT -->
                        <?php if ($error !== ''): ?>
                            <div class="profile-error">
                                <i class="bi bi-exclamation-triangle-fill" style="font-size:17px;"></i>
                                <span><?= dentistProfileEscape($error) ?></span>
                            </div>
                        <?php endif; ?>

                        <form method="POST" class="profile-form" autocomplete="off">
                            <?= csrfField() ?>

                            <div class="profile-form-grid">
                                <!-- FULL NAME -->
                                <div class="profile-form-group">
                                    <label class="profile-form-label" for="full_name">
                                        <span><i class="bi bi-person-fill"></i>Full Name</span>
                                    </label>
                                    <input
                                        type="text"
                                        id="full_name"
                                        name="full_name"
                                        class="profile-form-input"
                                        value="<?= dentistProfileEscape($user['full_name']) ?>"
                                        placeholder="e.g. Dr. Juan Dela Cruz"
                                        required
                                    >
                                    <div class="profile-help">
                                        Displayed on patient schedules and dental records.
                                    </div>
                                </div>

                                <!-- EMAIL (READ-ONLY) -->
                                <div class="profile-form-group">
                                    <label class="profile-form-label" for="email">
                                        <span><i class="bi bi-envelope-fill"></i>Registered Email</span>
                                        <span class="readonly-tag"><i class="bi bi-lock-fill"></i> Read-only</span>
                                    </label>
                                    <input
                                        type="email"
                                        id="email"
                                        class="profile-form-input"
                                        value="<?= dentistProfileEscape($user['username']) ?>"
                                        disabled
                                    >
                                    <div class="profile-help">
                                        Used as your primary login username.
                                    </div>
                                </div>

                                <!-- CONTACT NUMBER -->
                                <div class="profile-form-group">
                                    <label class="profile-form-label" for="contact_number">
                                        <span><i class="bi bi-telephone-fill"></i>Contact Number</span>
                                    </label>
                                    <input
                                        type="text"
                                        id="contact_number"
                                        name="contact_number"
                                        class="profile-form-input"
                                        value="<?= dentistProfileEscape($dentist['contact_number']) ?>"
                                        placeholder="e.g. 0917 123 4567"
                                    >
                                    <div class="profile-help">
                                        Active mobile or clinic contact number.
                                    </div>
                                </div>

                                <!-- SPECIALIZATION -->
                                <div class="profile-form-group">
                                    <label class="profile-form-label" for="specialization">
                                        <span><i class="bi bi-award-fill"></i>Clinical Specialization</span>
                                    </label>
                                    <input
                                        type="text"
                                        id="specialization"
                                        name="specialization"
                                        class="profile-form-input"
                                        value="<?= dentistProfileEscape($dentist['specialization']) ?>"
                                        placeholder="e.g. General Dentistry, Orthodontics"
                                    >
                                    <div class="profile-help">
                                        Field of dental practice or clinical specialty.
                                    </div>
                                </div>
                            </div>

                            <!-- PASSWORD SECTION -->
                            <div class="password-section">
                                <div class="password-head">
                                    <h3 class="password-title">
                                        <i class="bi bi-shield-lock-fill"></i>
                                        Change Account Password
                                    </h3>
                                    <span class="optional-pill">Optional — Leave blank to keep current password</span>
                                </div>

                                <div class="profile-form-grid">
                                    <div class="profile-form-group">
                                        <label class="profile-form-label" for="password">
                                            <span><i class="bi bi-key-fill"></i>New Password</span>
                                        </label>
                                        <div class="input-icon-wrap">
                                            <input
                                                type="password"
                                                id="password"
                                                name="password"
                                                class="profile-form-input"
                                                style="padding-right:42px;"
                                                minlength="6"
                                                placeholder="Minimum of 6 characters"
                                                autocomplete="new-password"
                                            >
                                            <button type="button" class="pw-toggle-btn" data-toggle-pw="password" aria-label="Show or hide password">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                        <div class="profile-help">
                                            Use at least 6 characters for security.
                                        </div>
                                    </div>

                                    <div class="profile-form-group">
                                        <label class="profile-form-label" for="password_confirm">
                                            <span><i class="bi bi-check2-square"></i>Confirm New Password</span>
                                        </label>
                                        <div class="input-icon-wrap">
                                            <input
                                                type="password"
                                                id="password_confirm"
                                                name="password_confirm"
                                                class="profile-form-input"
                                                style="padding-right:42px;"
                                                minlength="6"
                                                placeholder="Re-enter new password"
                                                autocomplete="new-password"
                                            >
                                            <button type="button" class="pw-toggle-btn" data-toggle-pw="password_confirm" aria-label="Show or hide confirm password">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                        <div class="profile-help">
                                            Must match the new password entered.
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- SAVE BUTTON -->
                            <div class="profile-form-footer">
                                <button type="submit" class="profile-save-button">
                                    <i class="bi bi-check2-circle"></i>
                                    Save Profile Changes
                                </button>
                            </div>
                        </form>

                    </div>
                </section>

            </div>

            <script>
            (function(){
                document.querySelectorAll('[data-toggle-pw]').forEach(function(btn){
                    btn.addEventListener('click', function(){
                        var targetId = btn.getAttribute('data-toggle-pw');
                        var input = document.getElementById(targetId);
                        if(!input) return;
                        var isPw = input.type === 'password';
                        input.type = isPw ? 'text' : 'password';
                        var icon = btn.querySelector('i');
                        if(icon){
                            icon.className = isPw ? 'bi bi-eye-slash' : 'bi bi-eye';
                        }
                    });
                });
            })();
            </script>

        </div>


    </main>




<!-- DENTIST LOGOUT CONFIRM — "Are you sure you want to log out?" -->
<div id="dentistLogoutConfirm" class="dentist-logout-overlay" style="display:none;">
    <div class="dentist-logout-box">
        <div class="dentist-logout-head">
            <span class="dentist-logout-head-icon"><i class="bi bi-box-arrow-right"></i></span>
            <h5>Log Out</h5>
            <button type="button" class="dentist-logout-x" id="dentistLogoutClose" aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="dentist-logout-body">
            <div class="dentist-logout-icon"><i class="bi bi-exclamation-circle-fill"></i></div>
            <h5>Are you sure you want to log out?</h5>
            <p>You will need to log in again to access the dentist account.</p>
        </div>
        <div class="dentist-logout-foot">
            <button type="button" class="btn btn-light" id="dentistLogoutCancel">Cancel</button>
            <a href="../logout.php" class="btn dentist-logout-yes" id="dentistLogoutYes"><i class="bi bi-box-arrow-right me-1"></i>Log Out</a>
        </div>
    </div>
</div>
<style>
.dentist-logout-overlay { position: fixed; inset: 0; background: rgba(10,59,52,.55); z-index: 2000; padding: 20px; align-items: center; justify-content: center; }
.dentist-logout-box { background: #ffffff; border-radius: 18px; border: 1px solid #C9E9DA; box-shadow: 0 18px 45px rgba(0,0,0,.25); max-width: 440px; width: 100%; overflow: hidden; font-family: "Inter", "DM Sans", system-ui, sans-serif; animation: dentistLogoutPop .18s ease; }
@keyframes dentistLogoutPop { from { transform: scale(.96); opacity: 0; } to { transform: scale(1); opacity: 1; } }
.dentist-logout-head { display: flex; align-items: center; gap: 12px; padding: 18px 22px; background: linear-gradient(135deg, #D9F2E6 0%, #EAF7F0 100%); border-bottom: 1px solid #C9E9DA; }
.dentist-logout-head-icon { width: 38px; height: 38px; border-radius: 11px; background: linear-gradient(135deg, #0E6B5E, #0A4A41); color: #ffffff; display: inline-flex; align-items: center; justify-content: center; font-size: 18px; box-shadow: 0 4px 10px rgba(14,107,94,.25); flex-shrink: 0; }
.dentist-logout-head h5 { color: #143f51; font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif; font-weight: 800; font-size: 17px; margin: 0; flex: 1; }
.dentist-logout-x { background: #ffffff; border: 1px solid #D1E7DD; color: #57727d; width: 30px; height: 30px; border-radius: 9px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; font-size: 13px; flex-shrink: 0; }
.dentist-logout-x:hover { background: #EAF7F0; color: #0E6B5E; }
.dentist-logout-body { padding: 30px 28px 8px; text-align: center; }
.dentist-logout-icon { width: 64px; height: 64px; border-radius: 18px; background: #FDECEC; color: #C0392B; border: 1px solid #F5C6C6; display: inline-flex; align-items: center; justify-content: center; font-size: 30px; margin-bottom: 14px; }
.dentist-logout-body h5 { color: #143f51; font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif; font-weight: 800; font-size: 18px; margin: 0 0 8px; }
.dentist-logout-body p { color: #8aa0a8; font-size: 13.5px; margin: 0; }
.dentist-logout-foot { display: flex; gap: 12px; justify-content: center; padding: 18px 22px 22px; background: #F6FBF8; border-top: 1px solid #E6F2EC; margin-top: 20px; }
.dentist-logout-foot .btn { border-radius: 10px; font-weight: 700; font-size: 14px; padding: 10px 26px; }
.dentist-logout-foot .btn-light { background: #fff !important; border: 1px solid #D1E7DD !important; color: #57727d !important; }
.dentist-logout-foot .btn-light:hover { background: #EAF7F0 !important; color: #0E6B5E !important; }
.dentist-logout-yes { background: linear-gradient(135deg, #E74C3C, #922B21) !important; border: none !important; color: #ffffff !important; box-shadow: 0 6px 14px rgba(192,57,43,.3) !important; }
.dentist-logout-yes:hover { filter: brightness(.93); color: #ffffff !important; }
</style>
<script>
(function()
{
    function openDentistLogout(e)
    {
        if (e) e.preventDefault();
        var overlay = document.getElementById("dentistLogoutConfirm");
        if (!overlay) return;
        overlay.style.display = "flex";
        document.body.style.overflow = "hidden";
    }
    function closeDentistLogout()
    {
        var overlay = document.getElementById("dentistLogoutConfirm");
        if (!overlay) return;
        overlay.style.display = "none";
        document.body.style.overflow = "";
    }
    function initDentistLogout()
    {
        var overlay = document.getElementById("dentistLogoutConfirm");
        if (!overlay || overlay.dataset.bound === "1") return;
        overlay.dataset.bound = "1";
        document.querySelectorAll('a[href="../logout.php"], a[href="logout.php"]').forEach(function(a)
        {
            if (a.closest("#dentistLogoutConfirm")) return;
            if (a.dataset.logoutBound === "1") return;
            a.dataset.logoutBound = "1";
            a.addEventListener("click", openDentistLogout);
        });
        document.getElementById("dentistLogoutCancel").addEventListener("click", closeDentistLogout);
        document.getElementById("dentistLogoutClose").addEventListener("click", closeDentistLogout);
        overlay.addEventListener("click", function(e)
        {
            if (e.target === overlay) closeDentistLogout();
        });
        document.addEventListener("keydown", function(e)
        {
            if (e.key === "Escape") closeDentistLogout();
        });
    }
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initDentistLogout);
    } else {
        initDentistLogout();
    }
})();
</script>
<!-- DENTIST REMOVE PHOTO CONFIRM — gaya ng logout modal -->
<div id="dentistRemoveConfirm" class="dentist-logout-overlay" style="display:none;">
    <div class="dentist-logout-box">
        <div class="dentist-logout-head">
            <span class="dentist-logout-head-icon" style="background:linear-gradient(135deg,#E74C3C,#922B21);"><i class="bi bi-trash3-fill"></i></span>
            <h5>Remove Photo</h5>
            <button type="button" class="dentist-logout-x" id="dentistRemoveClose" aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="dentist-logout-body">
            <div class="dentist-logout-icon"><i class="bi bi-trash3-fill"></i></div>
            <h5>Are you sure you want to remove your profile photo?</h5>
            <p>Your profile will use the default avatar after removal.</p>
        </div>
        <div class="dentist-logout-foot">
            <button type="button" class="btn btn-light" id="dentistRemoveCancel">Cancel</button>
            <button type="button" class="btn dentist-logout-yes" id="dentistRemoveYes"><i class="bi bi-trash3-fill me-1"></i>Remove</button>
        </div>
    </div>
</div>
<script>
(function()
{
    function openDentistRemove()
    {
        var overlay = document.getElementById("dentistRemoveConfirm");
        if (!overlay) return;
        overlay.style.display = "flex";
        document.body.style.overflow = "hidden";
    }
    function closeDentistRemove()
    {
        var overlay = document.getElementById("dentistRemoveConfirm");
        if (!overlay) return;
        overlay.style.display = "none";
        document.body.style.overflow = "";
    }
    function initDentistRemove()
    {
        var overlay = document.getElementById("dentistRemoveConfirm");
        if (!overlay || overlay.dataset.bound === "1") return;
        overlay.dataset.bound = "1";
        var btn = document.getElementById("dentistRemovePhotoBtn");
        if (btn && btn.dataset.bound !== "1") {
            btn.dataset.bound = "1";
            btn.addEventListener("click", openDentistRemove);
        }
        document.getElementById("dentistRemoveCancel").addEventListener("click", closeDentistRemove);
        document.getElementById("dentistRemoveClose").addEventListener("click", closeDentistRemove);
        document.getElementById("dentistRemoveYes").addEventListener("click", function () {
            var form = document.getElementById("dentistRemovePhotoForm");
            if (form) form.submit();
        });
        overlay.addEventListener("click", function(e)
        {
            if (e.target === overlay) closeDentistRemove();
        });
        document.addEventListener("keydown", function(e)
        {
            if (e.key === "Escape") closeDentistRemove();
        });
    }
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initDentistRemove);
    } else {
        initDentistRemove();
    }
})();
</script>
</body>

</html>
