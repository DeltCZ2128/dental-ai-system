# Access guide for the IT expert respondents

Section 3.12 of the manuscript limits the respondents of the evaluation to
people who have accessed the system and performed at least one task in it.
This guide gives the researchers everything needed to satisfy that condition
before the questionnaire is answered.

## 1. Where the system is

| Environment | Address | Who can reach it |
| --- | --- | --- |
| Local machine (XAMPP) | `http://localhost/dental-ai-system-fixed/login.php` | A respondent seated at the computer that runs XAMPP |
| Local network | `http://<ip-of-the-host>/dental-ai-system-fixed/login.php` | A respondent on the same Wi-Fi |
| Public walk-in page | see `..\qr_public_url.txt` | Anyone who scans the QR code; no account needed |

Start Apache and MySQL in the XAMPP control panel before either address will
answer. The database must be the one named in `config.php`.

## 2. Accounts to hand out

The system already contains accounts for the three roles. The researchers
must supply the password for each one, because the passwords are stored only
as one-way hashes and cannot be read back.

| Role | Username in the system | Password |
| --- | --- | --- |
| Administrator | `admin@dental.com` | ____________________ |
| Dentist | `dentist@gmail.com` | ____________________ |
| Dentist | `dentist@example.com` | ____________________ |
| Patient | `roceltagudar1@gmail.com` | ____________________ |
| Patient | `lizelle@gmail.com` | ____________________ |

If a password has been lost, it can be replaced from **Admin > Settings >
User Accounts**, or a fresh account can be created from **Admin > Patients >
Register** for a patient and from the settings page for an administrator or a
dentist. Use a temporary password for the evaluation period and change it
afterwards.

## 3. Tasks to perform, per role

Ask each expert to perform the tasks of **one** role thoroughly rather than
skimming all three, so that the ratings rest on real use. Ten to fifteen
minutes per role is enough.

### Administrator

1. Sign in at `login.php` with the administrator account.
2. Open **Dashboard** and read the appointment counts and the seven-day
   demand forecast.
3. Open **Patients**, register a new patient, then edit that patient.
4. Open **Appointments**, create an appointment, then move it to
   **Confirmed**, then to **Completed**.
5. Attempt to book the **same dentist, date, and time** twice, and confirm
   that the second attempt is refused.
6. Open **Queue** and add a walk-in patient, then call the next patient.
7. Open **Reports** and change the reporting period.
8. Open **Settings** and change the clinic opening time, then change it back.

### Dentist

1. Sign in with the dentist account.
2. Open the dashboard and read today's appointments.
3. Open **Schedule** and mark a rest day, then remove it.
4. Open **Appointments** and change the status of one appointment to
   **Completed**.
5. Open **Queue** and start, then finish, a consultation.
6. Open a patient record and read the treatment history.
7. Open **Profile** and correct a contact detail.

### Patient

1. Sign in with the patient account, or register a new one.
2. Open **Book Appointment**, choose a service, a dentist, and a date, and
   confirm that the free slots are listed.
3. Take a slot and complete the booking; note the reference number.
4. Open **My Appointments** and confirm the booking appears as **Pending**.
5. Raise a concern, or confirm attendance, for that booking.
6. Open **Profile** and correct a detail, then open **Settings**.
7. Sign out.

### Walk-in visitor (no account)

1. Open the public walk-in page, or scan the QR code with a phone.
2. Enter a name, choose a dental service, and submit.
3. Confirm that a queue number is issued and shown on screen.

## 4. What to tell the experts about the security items

Section F of the questionnaire asks about five specific controls. The
researchers should not coach the answers, but it is fair to state that the
items are about the source and the request handling, not only about the
screens, so that a respondent knows a short review of the code is in scope.
The table below records what is in the delivered system, so that the
researchers can discuss the findings honestly once the ratings are in.

| Control | Where to look | State in the delivered system |
| --- | --- | --- |
| Password storage | `login.php`, `config.php`, `admin/register.php` | `password_hash()` and `password_verify()` are used; no MD5 and no SHA1 |
| SQL injection | any `->prepare()` call site | 233 parameterised statements; every `->query()` and `->exec()` call is a static statement that takes no input |
| Output encoding | the `e()` helper in `config.php` | 58 `htmlspecialchars()` call sites, with `e()` as the shared helper |
| Anti-forgery token | `csrfField()` and `requireCsrf()` in `config.php` | present on 45 forms across 21 files; a submission without a valid token is refused with HTTP 403 |
| Session renewal | `login.php`, `logout.php`, `config.php` | the identifier is renewed at sign-in, the cookie is HttpOnly and SameSite=Lax, and the cookie is cleared at sign-out |
| Error disclosure | the `catch` block in `config.php` | the technical message goes to the server log and the visitor receives a generic message |

Two places are deliberately outside the anti-forgery check, and the
researchers should be ready to explain why:

* `walkin_qr.php` is the anonymous walk-in kiosk. It acts for no signed-in
  user, so there is no privileged session for a forged request to borrow, and
  it must keep working for a visitor whose browser does not keep cookies.
* `patient/patient_dashboard.php` and `dentist/dentist_dashboard.php` hold a
  POST handler but carry no form at all, so no token could reach them. The
  same actions are live in `patient/my_appointments.php`, `dentist/queue.php`,
  and `dentist/appointments.php`, and those pages are all checked. These two
  files are recorded here as legacy code for follow-up work.

## 5. Order of work

1. Start Apache and MySQL.
2. Set the passwords of the accounts that will be handed out.
3. Walk through the tasks of Section 3 to confirm that each one still works.
4. Send each respondent the address of Section 1, the account for that
   respondent's role, and the form for that respondent's group:

   | Respondent group | Form to send | Items |
   | --- | --- | --- |
   | Information Technology experts | `IT_Expert_Evaluation_Form.docx`, or the link built from `google_apps_script.js` | 38 |
   | Clinic administrator or receptionist, dentist, and patients | `EndUser_Evaluation_Form.docx`, or the link built from `google_apps_script_enduser.js` | 36 |

5. Collect the Google Forms responses and paste the ratings into
   `System_Evaluation_Scoring_Sheet.xlsx`: IT expert ratings on
   **IT Expert Responses** and end-user ratings on **End User Responses**.
   The mean, summary and comparison sheets then fill themselves in.


