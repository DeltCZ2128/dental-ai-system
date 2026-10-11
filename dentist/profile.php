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

        /*
        |--------------------------------------------------------------------------
        | PROFILE PAGE
        |--------------------------------------------------------------------------
        */

        .profile-layout {

            display: grid;

            grid-template-columns:
                minmax(0, 1fr);

            gap: 20px;

            align-items: start;
        }


        /*
        |--------------------------------------------------------------------------
        | FORM
        |--------------------------------------------------------------------------
        */

        .profile-form {

            max-width: 100%;
        }


        .profile-form-row {

            display: grid;

            grid-template-columns:
                1fr 1fr;

            gap: 16px;

            align-items: start;
        }


        .profile-3col {

            display: grid;

            grid-template-columns:
                1fr 1fr 1fr;

            gap: 20px;

            align-items: start;

            margin-bottom: 6px;
        }


        .account-overview {

            margin-top: 0;

            margin-bottom: 0;

            padding: 16px;

            border: 1px solid #e8eef2;

            border-radius: 10px;

            background: #f8fafc;
        }


        .account-overview-title {

            margin-bottom: 14px;

            font-size: 14px;

            font-weight: 800;

            color: #293b4a;
        }


        .overview-badge {

            display: inline-block;

            padding: 6px 14px;

            border-radius: 20px;

            font-size: 12px;

            font-weight: 700;

            background: #e8f7ee;

            border: 1px solid #c7ead4;

            color: #247443;
        }


        .overview-badge.role {

            background: #eaf5ff;

            border-color: #c9e2f5;

            color: #2675b9;

            text-transform: capitalize;
        }


        .profile-form-group {

            margin-bottom: 18px;
        }


        .profile-form-label {

            display: block;

            margin-bottom: 7px;

            font-size: 13px;

            font-weight: 700;

            color: #334554;
        }


        .profile-form-label i {

            color: #0E6B5E;

            margin-right: 6px;
        }


        .profile-form-input {

            width: 100%;

            padding: 11px 13px;

            border: 1px solid #d9e3ea;

            border-radius: 8px;

            background: #ffffff;

            color: #263746;

            font-family: inherit;

            font-size: 14px;

            outline: none;

            transition: border-color 0.2s ease,
                        box-shadow 0.2s ease;
        }


        .profile-form-input:focus {

            border-color: #5b9bd5;

            box-shadow:
                0 0 0 3px
                rgba(91, 155, 213, 0.12);
        }


        .profile-form-input:disabled {

            background: #f3f6f8;

            color: #788694;

            cursor: not-allowed;
        }


        .profile-help {

            margin-top: 5px;

            font-size: 11px;

            color: #8a98a5;
        }


        /*
        |--------------------------------------------------------------------------
        | PASSWORD
        |--------------------------------------------------------------------------
        */

        .password-section {

            margin-top: 25px;

            padding-top: 22px;

            border-top: 1px solid #e8eef2;
        }


        .password-title {

            margin-bottom: 15px;

            font-size: 15px;

            font-weight: 800;

            color: #293b4a;
        }


        /*
        |--------------------------------------------------------------------------
        | BUTTON
        |--------------------------------------------------------------------------
        */

        .profile-save-button {

            border: none;

            padding: 11px 18px;

            border-radius: 8px;

            background: #3b82c4;

            color: #ffffff;

            font-family: inherit;

            font-size: 13px;

            font-weight: 700;

            cursor: pointer;
        }


        .profile-save-button:hover {

            background: #2f72ad;
        }


        /*
        |--------------------------------------------------------------------------
        | SUCCESS MESSAGE
        |--------------------------------------------------------------------------
        */

        .profile-message {

            padding: 11px 14px;

            margin-bottom: 18px;

            border-radius: 8px;

            background: #e8f7ee;

            border: 1px solid #c7ead4;

            color: #247443;

            font-size: 13px;

            font-weight: 600;
        }


        /*
        |--------------------------------------------------------------------------
        | ERROR MESSAGE
        |--------------------------------------------------------------------------
        */

        .profile-error {

            padding: 11px 14px;

            margin-bottom: 18px;

            border-radius: 8px;

            background: #fdecec;

            border: 1px solid #f3cccc;

            color: #a72a2a;

            font-size: 13px;

            font-weight: 600;
        }


        /*
        |--------------------------------------------------------------------------
        | PROFILE SUMMARY
        |--------------------------------------------------------------------------
        */

        .profile-summary {

            background: #ffffff;

            border: 1px solid #e2e9ee;

            border-radius: 12px;

            padding: 22px;

            text-align: center;
        }


        .profile-avatar {

            width: 70px;

            height: 70px;

            margin: 0 auto 12px;

            border-radius: 50%;

            background: #eaf5ff;

            color: #2675b9;

            display: flex;

            align-items: center;

            justify-content: center;

            font-size: 29px;
        }


        .profile-summary-name {

            font-size: 16px;

            font-weight: 800;

            color: #263746;

            margin-bottom: 4px;
        }


        .profile-summary-role {

            font-size: 11px;

            color: #7b8997;

            text-transform: uppercase;

            letter-spacing: 0.5px;
        }


        /* Header card + avatar — gaya ng patient profile-header-card */
        .profile-header-card {
            background: linear-gradient(135deg, #0E6B5E, #0A4A41);
            border-radius: 18px;
            padding: 27px 30px;
            color: #ffffff;
            margin: 0 24px 20px;
            display: flex;
            align-items: center;
            gap: 20px;
            box-shadow: 0 8px 24px rgba(14,107,94,.18);
        }
        .profile-layout {
            margin: 0 24px;
        }
        .large-profile-circle {
            width: 75px;
            height: 75px;
            border-radius: 50%;
            background: #ffffff;
            color: #0E6B5E;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 27px;
            font-weight: 800;
            flex-shrink: 0;
            overflow: hidden;
            position: relative;
        }
        .large-profile-circle img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .profile-photo-wrap {
            position: relative;
            flex-shrink: 0;
        }
        .profile-edit-photo-btn {
            position: absolute;
            right: -6px;
            bottom: -6px;
            width: 38px;
            height: 38px;
            border-radius: 50%;
            border: 2px solid #ffffff;
            background: #C9A227;
            color: #0A4A41;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
            cursor: pointer;
            box-shadow: 0 4px 10px rgba(0,0,0,.25);
            padding: 0;
        }
        .profile-edit-photo-btn:hover {
            filter: brightness(.92);
        }
        .profile-photo-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }
        .profile-photo-edit-text {
            border: 1px solid rgba(255,255,255,.45);
            background: rgba(255,255,255,.15);
            color: #ffffff;
            font-size: 13px;
            font-weight: 700;
            padding: 10px 18px;
            border-radius: 20px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-family: inherit;
        }
        .profile-photo-edit-text:hover {
            background: rgba(255,255,255,.28);
        }
        .profile-photo-remove {
            border: 1px solid rgba(255,255,255,.55);
            background: #ffffff;
            color: #b42318;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            font-family: inherit;
            padding: 10px 18px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: .2s ease;
            box-shadow: 0 4px 12px rgba(0,0,0,.12);
        }
        .profile-photo-remove:hover {
            background: #b42318;
            border-color: #b42318;
            color: #ffffff;
        }
        .profile-header-right {
            margin-left: auto;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 8px;
            flex-shrink: 0;
        }
        @media (max-width: 600px) {
            .profile-header-card {
                flex-wrap: wrap;
            }
            .profile-header-right {
                margin-left: 0;
                width: 100%;
                align-items: flex-start;
            }
        }
        .profile-info-name {
            font-family: "Plus Jakarta Sans", sans-serif;
            font-size: 20px;
            font-weight: 800;
        }
        .profile-info-email {
            font-size: 12px;
            opacity: 0.85;
            margin-top: 3px;
        }
        .profile-info-role {
            display: inline-block;
            margin-top: 8px;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 1px;
            background: rgba(255,255,255,.18);
            border: 1px solid rgba(255,255,255,.35);
            padding: 4px 12px;
            border-radius: 20px;
        }
        section.portal-card {
            border: 1px solid #C9E9DA !important;
            border-radius: 16px !important;
            box-shadow: 0 8px 24px rgba(14,107,94,.08) !important;
        }
        .profile-form-input:focus {
            border-color: #0E6B5E !important;
            box-shadow: 0 0 0 3px rgba(14,107,94,.12) !important;
        }
        .profile-save-button {
            background: linear-gradient(135deg, #149A72, #0A4A41) !important;
            border-radius: 10px !important;
            box-shadow: 0 6px 14px rgba(14,107,94,.22);
        }
        .profile-save-button:hover {
            filter: brightness(.93);
        }
        .profile-avatar {
            background: #EAF7F0 !important;
            color: #0E6B5E !important;
        }

        /*
        |--------------------------------------------------------------------------
        | RESPONSIVE
        |--------------------------------------------------------------------------
        */

        @media (max-width: 850px) {

            .profile-layout {

                grid-template-columns: 1fr;
            }


            .profile-summary {

                max-width: 700px;
            }


            .profile-3col {

                grid-template-columns:
                    1fr;
            }

        }

        @media (max-width: 600px) {

            .profile-header-card,
            .profile-layout {

                margin-left: 12px;
                margin-right: 12px;
            }


            .profile-form-row {

                grid-template-columns:
                    1fr;
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

            <div class="profile-header-card">
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
                        title="Edit photo"
                        aria-label="Edit profile photo"
                    >
                        <i class="bi bi-camera-fill"></i>
                    </button>
                </div>
                <div style="flex:1;min-width:0;">
                    <div class="profile-info-name"><?= dentistProfileEscape($user['full_name']) ?></div>
                    <div class="profile-info-email"><?= dentistProfileEscape($user['username']) ?></div>
                    <span class="profile-info-role">DENTIST</span>
                </div>
                <div class="profile-header-right">
                    <div class="profile-photo-actions" style="margin-top:0;">
                        <button
                            type="button"
                            class="profile-photo-edit-text"
                            id="dentistEditPhotoTextBtn"
                        >
                            <i class="bi bi-pencil-square"></i>
                            Edit Photo
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

                        // Instant preview bago mag-upload.
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

            <div class="profile-layout">


                <!-- =================================================
                     PROFILE FORM
                ================================================== -->

                <section class="portal-card">


                    <h2>
                        Profile Information
                    </h2>


                    <p
                        style="
                            color:#7b8997;
                            font-size:13px;
                            margin-top:-8px;
                            margin-bottom:22px;
                        "
                    >

                        Basic account information of the dentist.

                    </p>



                    <!-- SUCCESS -->

                    <?php if ($message !== ''): ?>

                        <div class="profile-message">

                            <i class="bi bi-check-circle-fill"></i>

                            <?= dentistProfileEscape(
                                $message
                            ) ?>

                        </div>

                    <?php endif; ?>



                    <!-- ERROR -->

                    <?php if ($error !== ''): ?>

                        <div class="profile-error">

                            <i class="bi bi-exclamation-circle-fill"></i>

                            <?= dentistProfileEscape(
                                $error
                            ) ?>

                        </div>

                    <?php endif; ?>



                    <!-- FORM -->

                    <form
                        method="POST"
                        class="profile-form"
                    >
<?= csrfField() ?>


                        <!-- 3-COLUMN: Col1 Name+Contact | Col2 Email+Spec | Col3 Account Overview -->

                        <div class="profile-3col">

                            <!-- COLUMN 1 -->

                            <div>

                                <!-- NAME -->

                                <div class="profile-form-group">

                                    <label
                                        class="profile-form-label"
                                        for="full_name"
                                    >

                                        <i class="bi bi-person-fill"></i>Name

                                    </label>


                                    <input
                                        type="text"
                                        id="full_name"
                                        name="full_name"
                                        class="profile-form-input"
                                        value="<?= dentistProfileEscape(
                                            $user['full_name']
                                        ) ?>"
                                        required
                                    >

                                </div>


                                <!-- CONTACT -->

                                <div class="profile-form-group">

                                    <label
                                        class="profile-form-label"
                                        for="contact_number"
                                    >

                                        <i class="bi bi-telephone-fill"></i>Contact

                                    </label>


                                    <input
                                        type="text"
                                        id="contact_number"
                                        name="contact_number"
                                        class="profile-form-input"
                                        value="<?= dentistProfileEscape(
                                            $dentist[
                                                'contact_number'
                                            ]
                                        ) ?>"
                                        placeholder="Enter contact number"
                                    >


                                    <?php if (
                                        $contactColumn === null
                                    ): ?>

                                        <div class="profile-help">

                                            Contact field is not available
                                            in the dentist table yet.

                                        </div>

                                    <?php endif; ?>

                                </div>

                            </div>


                            <!-- COLUMN 2 -->

                            <div>

                                <!-- EMAIL -->

                                <div class="profile-form-group">

                                    <label
                                        class="profile-form-label"
                                        for="email"
                                    >

                                        <i class="bi bi-envelope-fill"></i>Email

                                    </label>


                                    <input
                                        type="email"
                                        id="email"
                                        class="profile-form-input"
                                        value="<?= dentistProfileEscape(
                                            $user['username']
                                        ) ?>"
                                        disabled
                                    >


                                    <div class="profile-help">

                                        Your registered account email.

                                    </div>

                                </div>


                                <!-- SPECIALIZATION -->

                                <div class="profile-form-group">

                                    <label
                                        class="profile-form-label"
                                        for="specialization"
                                    >

                                        <i class="bi bi-award-fill"></i>Specialization

                                    </label>


                                    <input
                                        type="text"
                                        id="specialization"
                                        name="specialization"
                                        class="profile-form-input"
                                        value="<?= dentistProfileEscape(
                                            $dentist[
                                                'specialization'
                                            ]
                                        ) ?>"
                                        placeholder="e.g. General Dentistry"
                                    >


                                    <?php if (
                                        $specializationColumn === null
                                    ): ?>

                                        <div class="profile-help">

                                            Specialization field is not
                                            available in the dentist table yet.

                                        </div>

                                    <?php endif; ?>

                                </div>

                            </div>


                            <!-- COLUMN 3: ACCOUNT OVERVIEW -->

                            <div class="account-overview">

                                <div class="account-overview-title">

                                    <i class="bi bi-person-badge"></i>

                                    Account Overview

                                </div>


                                <div class="profile-form-group">

                                    <label class="profile-form-label">
                                        <i class="bi bi-activity"></i>Account Status
                                    </label>

                                    <div>
                                        <span class="overview-badge">
                                            <?= dentistProfileEscape($dentist['status'] ?? 'Active') ?>
                                        </span>
                                    </div>

                                </div>


                                <div class="profile-form-group" style="margin-bottom:0;">

                                    <label class="profile-form-label">
                                        <i class="bi bi-person-badge-fill"></i>Role
                                    </label>

                                    <div>
                                        <span class="overview-badge role">
                                            <?= dentistProfileEscape($user['role'] ?? 'dentist') ?>
                                        </span>
                                    </div>

                                </div>

                            </div>

                        </div>



                        <!-- =================================================
                             PASSWORD
                        ================================================== -->

                        <div class="password-section">


                            <div class="password-title">

                                <i class="bi bi-shield-lock"></i>

                                Change Password

                            </div>


                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                            <div class="profile-form-group">

                                <label
                                    class="profile-form-label"
                                    for="password"
                                >

                                    <i class="bi bi-key-fill"></i>New Password

                                </label>


                                <input
                                    type="password"
                                    id="password"
                                    name="password"
                                    class="profile-form-input"
                                    minlength="6"
                                    placeholder="Enter new password"
                                >


                                <div class="profile-help">

                                    Leave blank if you do not want
                                    to change your password.

                                </div>

                            </div>

                            <div class="profile-form-group">

                                <label
                                    class="profile-form-label"
                                    for="password_confirm"
                                >

                                    <i class="bi bi-key-fill"></i>Confirm Password

                                </label>


                                <input
                                    type="password"
                                    id="password_confirm"
                                    name="password_confirm"
                                    class="profile-form-input"
                                    minlength="6"
                                    placeholder="Confirm new password"
                                >


                                <div class="profile-help">

                                    Re-type the new password to confirm.

                                </div>

                            </div>
                            </div>

                        </div>



                        <!-- =================================================
                             SAVE BUTTON
                        ================================================== -->

                        <button
                            type="submit"
                            class="profile-save-button"
                        >

                            <i class="bi bi-check-lg"></i>

                            Save Profile

                        </button>


                    </form>


                </section>


            </div>


        </div>


        <?php if (($_GET['nosidebar'] ?? '') === '1'): ?>
        <style>.portal-sidebar{display:none!important;}.portal-main{margin-left:0!important;width:100%!important;}</style>
        <script>
        function autoCloseSubTab(){
            try{ if(window.opener && !window.opener.closed){ window.opener.focus(); } }catch(e){}
            /* Hindi na nagsasara ang tab — same-tab navigation. */
            if (!window.opener || window.opener.closed) {
                window.location.href='dentist_dashboard.php';
            }
            return false;
        }
        </script>
        <?php endif; ?>
    </main>

<script>
/* Itago ang sidebar kapag binuksan mula sa dashboard menu (?nosidebar=1) */
(function(){
    try {
        if (new URLSearchParams(window.location.search).get('nosidebar') === '1') {
            document.querySelectorAll('.portal-sidebar').forEach(function(el){ el.style.display = 'none'; });
            document.querySelectorAll('.portal-main').forEach(function(el){ el.style.marginLeft = '0'; el.style.width = '100%'; });
        }
    } catch(e){}
})();
</script>


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
