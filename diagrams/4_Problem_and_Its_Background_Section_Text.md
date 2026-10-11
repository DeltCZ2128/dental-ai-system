# The Problem and Its Background (Chapter 4) — paste-ready text for Chapter_12345.docx

**What this file is:** **CHAPTER 4 THE PROBLEM AND ITS BACKGROUND** of the
capstone documentation of the *AI-Based Intelligent Dental Appointment
Scheduling System* for **Abalos Dental Clinic**. It is written as prose that can
be copied straight into the manuscript.

**Where to paste:** immediately after the last section of Chapter 3, §3.13
Ethical Standards and Considerations, which states that it "sits immediately
before the heading of Chapter 4".

**Sections written:** 4.1 Background of the Study, 4.2 Statement of the
Problem, 4.3 Objectives of the Study, 4.4 Significance of the Study, 4.5 Scope
and Delimitation, 4.6 Definition of Terms, plus one table (Table 4.1) that maps
each specific problem to its objective and to the system feature that answers
it. 4.5 is written as a single combined section; if your panel requires Scope
and Delimitation as two separate sections, split it at the sentence that begins
"While the system is in use…" and renumber Definition of Terms to 4.7.

**Insert:** no figure is required, so the figure sequence that ends at the Gantt
chart of §3.10 is not disturbed.

**Two consequences of placing The Problem and Its Background in Chapter 4:**

1. **Your Table of Contents must change.** It currently lists Chapter 4 as
   *Results and Discussions* with 4.1 Development and Testing (4.1.1 Unit
   Testing, 4.1.2 Integration Testing, 4.1.3 User Acceptance Testing), 4.2
   Evaluation Results and 4.3 Data Interpretation. A replacement block is
   provided in the notes at the end of this file, together with the Chapter 5
   renumbering that follows from it.
2. **Two cross-references in §3.13 become incorrect.** §3.13 says that the
   evaluation "reported in Chapter 4 measures the acceptability of the system
   against the six ISO/IEC 25010 characteristics of Table 18 as perceived by the
   respondents of Section 3.12", and it also reports figures "in Sections 4.2 and
   4.3". Once Chapter 4 is The Problem and Its Background, §4.2 is the Statement
   of the Problem and §4.3 is the Objectives of the Study, so those two
   references must point to the chapter that now carries the evaluation results.
   The exact edits are listed in the notes at the end of this file. §3.13 itself
   was **not** modified by this file.

**Consistency note:** the chapter reuses the wording already used in the
manuscript. The three actors are the **Administrator**, the **Dentist** and the
**Patient**, as in the use case diagrams of §3.8. The one artificial
intelligence feature of the system is the **Linear Regression** appointment
demand forecast of `admin/demand_forecast.php`, which reads the previous ninety
days of appointment records and forecasts the following seven days, and this
chapter is kept inside that feature, in agreement with the Delimitation of
Chapter 1 and with §3.13.

This chapter therefore does **not** claim appointment no-show prediction, Random
Forest classification, neural networks, or any other model that the delivered
system does not run. `assets/ai/train_model.py` is a standalone training script
that is not called by any page of the application, no trained model file is
present in the project, and the `ai_predictions` table is only counted in
`admin/reports.php` and is never written to. No-show handling in the system is a
manual administrative record — a staff member marks the appointment and the
patient is notified — and §4.1 and §4.2 present it as exactly that, because
claiming a prediction that the system does not make would contradict the
Delimitation of Chapter 1 and §3.13.

No statistic is stated anywhere in this text, so no invented figure has to be
defended before the panel.

---

<!-- BEGIN SUBMITTABLE -->

# CHAPTER 4

## THE PROBLEM AND ITS BACKGROUND

### 4.1 Background of the Study

Dental care in the Philippines is delivered almost entirely through
appointment-based consultations. A patient is given a specific date and a
specific hour on which a dentist will be available, the chair is prepared for
that hour, and the treatment time of one patient is sized against the treatment
time of the next. The entire working day of a dental clinic therefore depends on
the accuracy of one record: the appointment. When an appointment is recorded
twice, forgotten, or unattended, the loss is not limited to a missing name in a
list. It is a chair that stays empty, a dentist who waits, and a patient who
leaves without being treated while another patient, who could have been served

Most small and medium dental clinics in the country still manage this record by
hand. This is the situation of **Abalos Dental Clinic**, the clinic at the
center of this study. Booking is accepted over the counter or by telephone and
written into an appointment logbook, patient information is kept in paper record
folders, dentist availability such as rest days and day-offs is confirmed
verbally, and the appointment is confirmed to the patient through a written slip
or through a call or text message. The daily schedule is read from the logbook,
the queue of walk-in patients is managed by calling names in the waiting area,
and the monthly appointment summary and the list of patients who failed to
attend are compiled by hand from the same logbook.

The data flow diagrams of §3.5 describe this process as it actually operates,
and the context diagram of §3.4 already summarizes why it is difficult to
scale. The same appointment is written in more than one place, so a schedule
conflict can only be noticed by reading the logbook line by line. Dentist
availability is known only as far as someone remembers to ask. The attendance
history of a patient is never evaluated before a new slot is given, so a patient
who has repeatedly failed to attend is treated exactly like a first-time
patient. Nothing in the process looks forward, so the clinic learns that a day
has been overbooked only when the day itself becomes crowded, and it discovers
that a day will be light only when it is already half over.

Two consequences of this arrangement repeat themselves in daily clinic
operation. The first is the **schedule conflict**, in which two patients are
given the same hour, or a patient is booked on a day when the dentist is not
available, and the error surfaces only during the day itself. The second is the
**no-show**, in which a patient who confirmed an appointment does not appear. A
no-show is discovered only when the patient fails to arrive, which means the
clinic has already spent the slot preparing for a visit that does not happen,
and it has already turned away other patients who asked for that hour. Because
the visit is recorded only after it is over, both problems are always identified
after the fact. The clinic is reactive by construction: it can describe what
went wrong last week, but it cannot see it coming this week.

Information technology has been applied to this problem for years in the form of
electronic appointment systems, which replace the logbook with a database and
remove duplicate entries. The limitation of a purely electronic schedule is that
it answers questions about the present and the past — who is booked, when, and
with whom — but it does not answer questions about the future. A record of
appointments becomes far more useful when it is also treated as evidence. The
attendance history of a patient is evidence about the likelihood that the
patient will attend again, and the number of appointments taken each day is
evidence about how much demand the clinic will receive next week. This is the
point at which **artificial intelligence** becomes relevant to a scheduling
problem that is, on its face, purely administrative.

Artificial intelligence, specifically the machine learning method used in this
study, allows a system to learn a pattern from the records that the clinic
already owns and to use that pattern to produce an estimate for a case that has
not happened yet. The pattern that this study acts upon is the volume of demand
the clinic receives, which is learned from how many appointments were taken on
each past day. That pattern is not visible in a logbook, because reading one line
of a logbook at a time reveals nothing about the volume of the coming week.

The same records also show how often each patient has attended, and the
attendance history of a patient is therefore kept and shown to the clinic so
that a patient who has repeatedly failed to attend can be recognized by the
personnel who handle the appointment. The system does not, however, compute a
prediction of which patient will fail to attend, and this study does not claim
one. A no-show is recorded as the outcome of a visit that has already passed, not
forecast before it, and the demand forecast described below is the only machine
learning feature carried by this study.


This study therefore proposes the **AI-Based Intelligent Dental Appointment
Scheduling System**, a web-based system that centralizes the appointment,
patient, queue and reporting functions of Abalos Dental Clinic in one database
with role-based access for the Administrator, the Dentist and the Patient, and
that adds an appointment demand forecast on top of the same records. The system
also records the outcome of every visit, so that attendance, cancellations and
no-shows are held in the same record as the appointment itself instead of being
remembered by the staff.

The intelligent feature of the system is the **demand forecast**. The system
trains a Linear Regression model on the appointment records of the previous
ninety days, in which cancelled and no-show appointments are not counted as
demand and days with no appointment are still included as zero, and it estimates
the expected number of appointments for each of the following seven days. Every
forecasted day is then labelled against the historical average: a forecast of at
least 125% of the average is a high demand day, a forecast of at least 75% is a
medium demand day, and a forecast below 75% is a low demand day. The
administrator can therefore see a coming heavy day before it arrives and prepare
the schedules and the resources of the clinic, and can recognize a light day
instead of discovering it in the middle of the afternoon.

The forecast is advisory. It is presented to the clinic as planning information,
it does not confirm, reject, prioritise or cancel any appointment, it does not
decide which patient is served, and it does not by itself change any record in
the system or replace the judgement of the clinic personnel or of a licensed
dentist.

The system also removes the manual dependencies that produce the two recurring
failures. Booking becomes a check of availability performed by the system, so a
schedule conflict is detected while the appointment is being made rather than
during the clinic day. Dentist schedules, rest days and day-offs are maintained
in the system, so availability no longer depends on a verbal confirmation. A
public QR form allows a walk-in patient to write their own details into the
queue of the day without waiting to be registered by hand. Appointment
concerns, which a patient raises when the patient cannot attend, are captured in
the system and returned to the administrator as notifications, so the clinic
learns of an emergency cancellation in advance instead of losing the hour. The
status of every visit, whether completed, cancelled or not attended, is written
back into the same record that feeds the forecast, so the history behind the
forecast is a by-product of ordinary clinic operation and requires no additional
paperwork from the clinic staff. When a patient does not appear, the clinic
records the appointment as a no-show in the system, and the patient is notified
through the notification features of the system.

Because the system stores the personal information of patients, its entire
design is bounded by the requirements of **Republic Act No. 10173, the Data
Privacy Act of 2012**. Only the information needed for appointment and patient
record management is collected, access is limited by the role of the account,
the demand forecast is computed from daily appointment counts and never from the
identity or the dental history of a patient, and the system performs no
diagnosis, no treatment planning and no prescription.

This study was therefore undertaken to determine whether such a system can
address the scheduling problems of Abalos Dental Clinic in a form that the
clinic's own personnel find acceptable and useful, and to determine how
acceptable the developed system is when it is evaluated against the software
product quality characteristics used in this study. It is against that problem
that the description, the development and the evaluation reported in the
succeeding chapters are organized.


### 4.2 Statement of the Problem

This study aims to develop and evaluate an AI-Based Intelligent Dental
Appointment Scheduling System for Abalos Dental Clinic, and to determine the
extent to which the developed system addresses the appointment scheduling
problems of the clinic as perceived by the clinic administrator, the dentists
and the patients.

Specifically, this study seeks to answer the following questions:

1. What is the profile of the existing appointment scheduling process of Abalos
   Dental Clinic in terms of its booking, scheduling, confirmation, queueing and
   record-keeping activities, and what problems are encountered in each of these
   activities?

2. What is the profile of the respondents in terms of their role in the clinic
   and their length of experience in using the existing process?

3. To what extent does the developed system address the identified problems of
   the clinic in terms of the following:

   1. **Real-time schedule conflict detection**, in which the system checks the
      availability of the dentist and the existing appointments before a new
      appointment is saved, so that a double booking or a booking on a day when
      the dentist is not available is prevented at the point of booking rather
      than discovered during the clinic day?

   2. **Centralized appointment and patient records**, in which the appointment
      logbook and the paper record folders are replaced by a single database
      from which the daily schedule, the patient record, the appointment history
      and the monthly appointment summary can be retrieved without manual
      compilation?

   3. **No-show recording and notification**, in which the clinic records an
      appointment that was not attended as a no-show in the system, the patient
      is notified through the notification features of the system, and the
      attendance history of the patient is kept and shown to the clinic so that
      a patient who has repeatedly failed to attend can be recognized by the
      personnel who handle the appointment?

   4. **Appointment demand forecasting**, in which the system trains a Linear
      Regression model on the appointment records of the previous ninety days
      and forecasts the expected number of appointments for the following seven
      days, labelling each day as a low, medium or high demand day, so that the
      clinic can plan its schedules and resources in advance?

   5. **Walk-in and queue management**, in which a walk-in patient is registered
      through a public QR form and receives a queue number for the queue of the
      day, and the dentist and the administrator can see the queue status and
      serve the patients in order without calling names in the waiting area?

   6. **Role-based access and notification**, in which the functions available
      to a user are limited to the role of the account, and appointment
      concerns, appointment updates and reminders reach the correct user through
      the notification features of the system?

4. What is the level of acceptability of the developed system as evaluated by the
   respondents in terms of the software product quality characteristics of
   ISO/IEC 25010 adopted in §3.12 and listed in Table 18 of this study?

5. Is there a significant difference in the level of acceptability of the
   developed system as evaluated by the administrator, the dentists and the
   patients?

6. What recommendations may be proposed to further improve the developed system
   based on the results of the evaluation?


### 4.3 Objectives of the Study

**General Objective.** This study aims to develop and evaluate an AI-Based
Intelligent Dental Appointment Scheduling System that centralizes the
appointment, patient, queue and reporting functions of Abalos Dental Clinic and
applies machine learning to the clinic's own appointment records, in order to
reduce schedule conflicts and no-shows and to provide the clinic with advance
information on appointment demand.

**Specific Objectives.** Specifically, this study aims to:

1. Determine the profile of the existing appointment scheduling process of
   Abalos Dental Clinic and identify the problems encountered in its booking,
   scheduling, confirmation, queueing and record-keeping activities.

2. Determine the profile of the respondents in terms of their role in the clinic
   and their length of experience in using the existing process.

3. Design and develop a web-based system that:

   1. detects schedule conflicts at the point of booking by validating the
      availability of the dentist, the rest days and day-offs, and the existing
      appointments before an appointment is saved;
   2. centralizes the appointment, patient, queue and reporting records of the
      clinic in a single database with role-based access for the administrator,
      the dentist and the patient;
   3. records the outcome of every visit, including the appointments that were
      not attended, so that the no-show is written into the same record as the
      appointment, the patient is notified through the notification features of
      the system, and the attendance history of the patient is available to the
      personnel who handle the appointment;
   4. forecasts the appointment demand of the following seven days using a
      Linear Regression model trained on the appointment records of the previous
      ninety days, and classifies each day as a low, medium or high demand day;
   5. manages walk-in patients through a public QR registration form that writes
      the walk-in details into the queue of the day, and maintains the queue
      status of scheduled and walk-in patients; and
   6. notifies the correct user of appointment concerns, appointment updates and
      reminders, and limits the functions of each account to its role.

4. Determine the level of acceptability of the developed system as evaluated by
   the respondents in terms of the software product quality characteristics of
   ISO/IEC 25010 adopted in §3.12 and listed in Table 18.

5. Determine whether a significant difference exists in the level of
   acceptability of the developed system as evaluated by the administrator, the
   dentists and the patients.

6. Propose recommendations for the further improvement of the developed system
   based on the results of the evaluation.

**Table 4.1: Mapping of the Specific Problems, Objectives and System Features**

| Specific Problem | Specific Objective | System Feature |
|---|---|---|
| Two patients are given the same hour, or a patient is booked on a day when the dentist is not available, and the conflict surfaces only during the clinic day. | To detect schedule conflicts at the point of booking. | Availability and schedule conflict validation during booking and rescheduling (`patient/book_appointment.php`, `admin/appointments.php`) |
| Appointments, patient information, queue status and monthly summaries are recorded and compiled by hand. | To centralize the clinic records in a single database with role-based access. | Appointment, patient, queue and report modules of the three dashboards (`admin/`, `dentist/`, `patient/`) |
| A no-show is discovered only when the patient fails to arrive, and the attendance history of the patient is never considered. | To record the outcome of every visit, notify the patient, and keep the attendance history available to the personnel. | No-show recording with automatic patient notification and attendance history in the patient record (`admin/no_show.php`, `admin/notifications.php`) |
| Nothing in the process looks forward, so the clinic cannot prepare for a light or a heavy day. | To forecast the appointment demand of the following seven days. | Linear Regression demand forecast with low, medium and high demand levels (`admin/demand_forecast.php`) |
| Walk-in patients wait to be registered by hand and the queue is managed by calling names. | To manage walk-in patients through a public QR form and a queue of the day. | Public QR walk-in registration and daily queue management (`walkin_qr.php`, `admin/queue.php`, `dentist/queue.php`) |
| Appointment concerns and appointment changes are relayed verbally, and the functions of the system are not separated by user. | To notify the correct user and limit the functions by role. | Appointment concerns and notification modules with role-based access (`admin/notifications.php`, `patient/appointment_concern.php`) |


### 4.4 Significance of the Study

The results of this study are significant to the following:

**Clinic Management.** The study provides the clinic with a single, centralized
record of its appointments, patients and queue, and with a planning tool that
the manual process cannot produce: a forecast of the appointment demand of the
coming week. Management can therefore prepare the schedules and the resources of
the clinic in advance and can observe the pattern of missed appointments over
time instead of reading it from a hand-compiled list at the end of the month.

**Clinic Administrators.** The study gives the administrator a dashboard that
shows the appointments, the queue, the dentists and the patients of the clinic in
one place, together with the demand forecast of the coming week. The
administrator can also generate the reports of the clinic from the same records
and can maintain the dentist schedules, the dental services, the clinic operating
hours and the user accounts through the system.

**Dentists.** The study gives the dentist a schedule that reflects the
availability actually recorded in the system and a queue that shows the patients
waiting to be served, together with the patient record and the appointment
history of the patient being served. The dentist is spared the interruptions
caused by confirming availability verbally and by searching for a paper record
folder, and the clinical judgement of the dentist remains unaffected because the
system performs no diagnosis, no treatment planning and no prescription.

**Patients.** The study gives the patient the ability to book an appointment at
a time that is actually available, to see the confirmed appointments in one
place, to confirm attendance or request a reschedule or a cancellation, and to
raise an appointment concern when an emergency prevents attendance. A walk-in
patient is registered through a QR form and receives a queue number without
waiting to be listed by hand, which shortens the waiting time and reduces the
chance of being turned away because the hour had already been taken.

**Clinic Staff and Front Desk.** The study relieves the staff of the manual
logbook, of the verbal confirmation of dentist availability and of the
hand-compiled monthly summary, and it replaces the manual detection of a double
booking with a check performed by the system while the appointment is being
made.

**Future Researchers.** The study contributes a documented case in which the
appointment demand of a single dental clinic is forecast with a Linear
Regression model trained on the appointment records of the clinic and evaluated
with the software product quality characteristics of ISO/IEC 25010. The
description of the development, the evaluation procedure and the limitations
stated in Chapter 3 provide a reference for future work on appointment demand
forecasting and clinic information systems in similar settings, and the
appointment records that the system accumulates over time form the dataset that
a later study would need in order to work on the prediction of missed
appointments.

**Researchers and Developers.** The study provides the researchers with
practical experience in the design, development and evaluation of a web-based
information system, in the preparation of appointment data for machine learning,
and in the administration of an acceptability evaluation.

**Readers and Students.** The study serves as a demonstration of the
application of artificial intelligence and web-based system development to a
community health service, and it forms part of the body of student research on
information technology and computer studies.


### 4.5 Scope and Delimitation

**Scope.** This study focuses on the development and evaluation of a web-based
AI-Based Intelligent Dental Appointment Scheduling System for a single dental
clinic, Abalos Dental Clinic, in order to improve the way appointments, patients
and the daily queue of the clinic are managed and monitored.

The system covers the appointment scheduling and clinic operational management
of the clinic through the following modules:

1. **User Authentication and Role Management**, which provides the account
   authentication of the system and limits the functions available to a user
   according to the role assigned to the account. The roles implemented in the
   system are the Administrator, the Dentist and the Patient.

2. **Dashboard**, which presents an overview of the clinic activities. For the
   administrator it presents the appointments, the queue, the counts of the
   appointments by status and the demand forecast of the coming week; for the
   dentist it presents the schedule of the day and the patients waiting to be
   served; for the patient it presents the appointments of that patient.

3. **Appointment Management**, which supports the creation, viewing, updating,
   rescheduling, cancellation and monitoring of appointments.

4. **Appointment Validation**, which checks the requirements of a booking before
   the appointment is saved. The checks cover the required appointment
   information, the validity of the date and time, the availability of the
   dentist, the clinic operating conditions and operating hours, the rest days
   and the day-offs of the dentist, the duration of the requested dental service,
   and the appointment conflicts of that dentist.

5. **Patient Information Management**, which stores the patient number, the name,
   the contact information, the e-mail address, the address and the related
   records of the patient, together with the appointment history of that patient.

6. **Dentist Schedule Management**, which maintains the dentist information, the
   availability, the working schedules, the rest days and the specific day-offs
   of each dentist.

7. **Queue Management**, which organizes the scheduled and walk-in patients of the
   day, assigns a queue number, and tracks the queue status of each patient.

8. **Walk-In Registration**, which allows a walk-in patient to submit their own
   details through a public QR form that writes the patient record and issues the
   queue number of the day without requiring the patient to log in.

9. **Notification**, which delivers appointment-related notifications and updates
   to the correct user, including the notification sent to a patient whose
   appointment has been confirmed, rescheduled or cancelled, and the notification
   sent to a patient whose appointment was recorded as a no-show.

10. **Reports and Analytics**, which summarizes the clinic information such as the
    appointment volume, the completed, cancelled, pending and confirmed
    appointments, the patient information, the appointments of each dentist, and
    the appointment demand pattern of the clinic.


11. **Demand Forecasting**, which uses Linear Regression on the appointment
    records of the previous ninety days and produces a seven-day expected
    appointment demand forecast. The forecast uses the equation

    y = a + bx

    where **y** is the predicted appointment demand, **a** is the regression
    intercept, **b** is the regression slope and **x** is the day number. The
    predicted demand is rounded to a whole number and prevented from becoming
    negative, and each forecasted day is classified as low, medium or high
    against the historical average. Cancelled and no-show appointments are not
    counted as demand, and days with zero appointments are included in the
    historical dataset so that the ninety-day sequence is complete.

12. **Settings**, which maintains the clinic information, the dental services,
    the user accounts and the clinic operating hours.

The system is implemented as a web-based application using PHP, MySQL, HTML, CSS,
JavaScript and Bootstrap, and it is evaluated by the respondents described in
§3.12 through the evaluation procedure of that section.

**Delimitation.** The study is limited to a single dental clinic, Abalos Dental
Clinic. The system is not designed for multiple branches or for multiple dental
clinics, and it is not offered as a service to other clinics or to the public.

The system is limited to appointment scheduling and clinic operational
management. It does not include clinical diagnosis, automated treatment
planning, electronic prescriptions, or any other clinical decision-making
function, so the clinical judgement of a licensed dentist is never replaced by
the system.

The system does not include online payment processing, billing, accounting,
insurance processing or financial management, and no financial information of any
patient is collected or stored.

The artificial intelligence of the system is limited to the appointment demand
forecast using Linear Regression. The system does not include patient no-show
prediction, Random Forest classification, deep learning, neural networks, or any
other advanced predictive model, and no such prediction is claimed or evaluated
in this study. No-show handling in the system is limited to recording the outcome
of an appointment that was not attended and notifying the patient; the
recognition of a patient who has repeatedly failed to attend is left to the
personnel who read the attendance history shown in the patient record.

The system does not claim to make clinical or administrative decisions
automatically. The demand forecast is advisory information that may assist the
clinic personnel in planning and scheduling: it does not confirm, reject,
prioritise or cancel any appointment, it does not decide which patient is served,
and it does not by itself change any record in the system. The accuracy of the
forecast is described for the clinic and the period covered by its appointment
records, and the researchers do not claim that the system will behave identically
in every clinic or under every workload.

The system is a web-based application, and a separate native mobile application
is not included in the scope of the study. The evaluation of the system is
limited to the acceptability perceived by the respondents of §3.12 against the
software product quality characteristics listed in Table 18; it is not a
laboratory measurement of performance, and it does not establish that the system
will behave identically in every deployment.


### 4.6 Definition of Terms

The following terms are defined conceptually and operationally as they are used
in this study. The operational definitions describe how each term is implemented
in the developed system.

**Appointment.** Conceptually, a scheduled meeting between a patient and a
dentist for a particular dental service at a specified date and time.
Operationally, a record in the `appointments` table of the system, carrying the
patient, the dentist, the date, the start and end time, the service type and the
status of the visit.

**Appointment Concern.** Conceptually, a message from a patient about a scheduled
appointment. Operationally, a record submitted by the patient through the
system stating the reason, which may be an emergency or another conflict, why the
patient cannot attend a confirmed appointment; the record is returned to the
administrator as a notification and is used by the clinic to reschedule the
appointment.

**Appointment Conflict.** Conceptually, a situation in which a requested
appointment overlaps an existing appointment or falls outside the availability of
the dentist. Operationally, the condition detected by the system during the
validation of a booking, in which case the appointment is not saved.

**Appointment Status.** Conceptually, the current state of an appointment.
Operationally, the value stored by the system for an appointment, which is one of
**Pending**, **Confirmed**, **Completed**, **Cancelled** or **No-Show**.

**Demand Forecast.** Conceptually, an estimate of the number of appointments a
clinic will receive in a future period. Operationally, the estimate produced by
the Linear Regression model of the system from the appointment records of the
previous ninety days, covering the following seven days and labelling each day as
a low, medium or high demand day.

**Demand Level.** Conceptually, the classification of a forecasted day according
to the expected volume of appointments. Operationally, the label assigned by the
system to each forecasted day, in which a forecast of at least 125% of the
historical average is a high demand day, a forecast of at least 75% of the
average is a medium demand day, and a forecast below 75% of the average is a low
demand day.

**Historical Appointment Data.** Conceptually, the previous appointment records
used as the input of a demand analysis. Operationally, the appointment records of
the previous ninety days used by the system to train the demand forecast, in
which cancelled and no-show appointments are not counted as demand and days with
zero appointments are included as zero so that the sequence is complete.

**Linear Regression.** Conceptually, a statistical method that models the
relationship between a dependent variable and one or more independent variables
by fitting a straight line. Operationally, the method used by the system to fit a
trend on the appointment records of the previous ninety days and to project that
trend over the following seven days, following the equation y = a + bx.


**No-Show.** Conceptually, a confirmed appointment that the patient fails to
attend. Operationally, an appointment whose status is recorded by the system as
**No-Show** when the patient does not appear for the confirmed slot. The system
records the no-show; it does not predict it.

**Notification.** Conceptually, an alert or message provided by the system
regarding an appointment or another relevant update. Operationally, a record
created by the system for a specific user, such as the notification of a
confirmed, rescheduled or cancelled appointment, an appointment concern submitted
by a patient, and the notification sent to a patient whose appointment was
recorded as a no-show.

**Patient Record / Patient Information.** Conceptually, the personal and
dental-related information stored for a patient. Operationally, the record
maintained by the system holding the patient number, the name, the contact
information, the e-mail address, the address and the related records of the
patient, together with the appointment history of that patient.

**Queue Management.** Conceptually, the process of organizing patients according
to their order of service. Operationally, the maintenance by the system of the
scheduled and walk-in patients of the day, in which each patient receives a queue
number and the status of the queue entry is updated as the patient is served.

**Role-Based Access Control.** Conceptually, a security mechanism that gives a
user access to system functions according to the role of that user.
Operationally, the restriction applied by the system to the functions of an
account according to its role as Administrator, Dentist or Patient, so that the
appointment information of one patient is not reachable by another.

**Schedule Conflict.** See **Appointment Conflict**.

**Service Duration.** Conceptually, the amount of time allocated for a particular
dental service. Operationally, the duration used by the system to size an
appointment and to determine the time slots that the appointment will occupy.

**System Acceptability.** Conceptually, the extent to which a system satisfies
the needs of its users. Operationally, the weighted mean rating given by the
respondents on the evaluation instrument of §3.12, interpreted against the
software product quality characteristics listed in Table 18 of this study.

**Time Slot / Suggested Time Slot.** Conceptually, a period available for a
dental appointment. Operationally, the period that the system presents as
available for a chosen dentist and date after the availability of the dentist,
the operating hours of the clinic and the existing appointments have been
checked.

**Walk-In Patient.** Conceptually, a patient who arrives at the clinic without a
prior appointment. Operationally, a patient registered through the public QR form
of the system, which writes the patient details into the patient records and
issues a queue number for the current date without requiring the patient to log
in.

**Web-Based System.** Conceptually, an application accessed through a web browser
and connected to a server and a database. Operationally, the developed system,
implemented with PHP, MySQL, HTML, CSS, JavaScript and Bootstrap and accessed
through a browser.


<!-- END SUBMITTABLE -->

---

### Replacement Table of Contents block for Chapter 4 (not for submission)

Replace the Chapter 4 entries of your Table of Contents with the following, so
that the TOC agrees with the chapter:

```
CHAPTER 4 – THE PROBLEM AND ITS BACKGROUND
4.1 Background of the Study ……………………………………………….………
4.2 Statement of the Problem …………………………………………….………
4.3 Objectives of the Study ……………………………………………….………
4.4 Significance of the Study …………………………………………….………
4.5 Scope and Delimitation ……………………………………………….………
4.6 Definition of Terms …………………………………………………….………
```

The entries that the TOC currently carries under Chapter 4 — *4.1 Development
and Testing*, *4.1.1 Unit Testing*, *4.1.2 Integration Testing*, *4.1.3 User
Acceptance Testing*, *4.2 Evaluation Results* and *4.3 Data Interpretation* —
belong to the chapter that carries the evaluation. If that chapter becomes
Chapter 5, those entries move there and the present Chapter 5 (Summary,
Conclusions and Recommendations) becomes Chapter 6. Decide this before the final
printing, because the page numbers of the TOC change with it.

### Corrections required in §3.13 (not performed by this file)

Section 3.13 currently contains two statements that point to Chapter 4 as the
chapter of the evaluation. Once Chapter 4 is The Problem and Its Background,
change them as follows. If the evaluation moves to Chapter 5, use "Chapter 5"
and "Sections 5.2 and 5.3" below; otherwise use the number of whichever chapter
finally carries the evaluation.

| Location | Current wording | Replacement wording |
|---|---|---|
| §3.13, first paragraph of the section | "This is the last section of Chapter 3, so it sits immediately before the heading of Chapter 4." | "This is the last section of Chapter 3, so it sits immediately before the heading of Chapter 4, The Problem and Its Background." |
| §3.13.4, limitation on the evaluation | "The evaluation reported in Chapter 4 measures the acceptability of the system against the six ISO/IEC 25010 characteristics of Table 18 as perceived by the respondents of Section 3.12" | "The evaluation reported in Chapter 5 measures the acceptability of the system against the software product quality characteristics of Table 18 as perceived by the respondents of Section 3.12" |
| §3.13.1, honesty and integrity | "No response, score, or result reported in Chapter 4 was invented, altered, or estimated. The evaluation figures reported in Sections 4.2 and 4.3…" | "No response, score, or result reported in Chapter 5 was invented, altered, or estimated. The evaluation figures reported in Sections 5.2 and 5.3…" |

### Note on the no-show module (not for submission)

The chapter deliberately does **not** claim appointment no-show prediction, and
the reason is a fact about the delivered system rather than a matter of wording:

- `assets/ai/train_model.py` trains a `RandomForestClassifier` and writes
  `model.pkl`. No page of the application calls this script.
- No `model.pkl` exists anywhere in the project.
- The `ai_predictions` table is defined in `dental_ai.sql` and is referenced by
  exactly one line of the application, `admin/reports.php:133`, which only counts
  its rows. Nothing ever inserts into it, so the count is always zero.
- `admin/no_show.php` states in its own comments that the page shows the no-show
  context "walang prediction" (without prediction), and that the list of patients
  considered for a no-show was removed on request. The page records a no-show
  manually and notifies the patient.
- The only machine learning feature that runs is the Linear Regression demand
  forecast of `admin/demand_forecast.php`.

If you intend to claim a no-show prediction in the manuscript, the module has to
be wired into the application and demonstrated first, and the Delimitation of
Chapter 1 would then have to be rewritten, because it currently states in plain
terms that the system "does not include patient no-show prediction, Random Forest
classification, deep learning, neural networks, or other advanced predictive
models". The chapters would also have to be re-evaluated with the module running.


### Consistency checklist (not for submission)

1. **Table numbering.** This chapter carries **Table 4.1**. If your Chapter 4
   tables are numbered in a continuous chapter sequence instead, rename the
   caption to Table 1 of Chapter 4 or to the next free number in that sequence,
   and add the entry to the List of Tables.
2. **List of Figures.** No figure is added by this chapter, so the figure
   sequence that ends at the Gantt chart of §3.10 is not disturbed.
3. **Section 4.5.** It is written as one combined section. If your panel requires
   Scope and Delimitation as two separate sections, split it at the paragraph
   that begins "**Delimitation.**", number the two parts 4.5 and 4.6, and renumber
   Definition of Terms to 4.7.
4. **Section 4.6.** Kept as a list of terms in alphabetical order, with a
   conceptual and an operational half for each entry, because the panel checks
   that both halves are present. **Schedule Conflict** is defined as a cross
   reference to **Appointment Conflict** so that the same condition is not
   defined twice with two different wordings.
5. **Cross-references used in this chapter.** §3.4, §3.5, §3.8, §3.10, §3.12,
   §3.13, Table 18 and Chapter 3. Confirm that these still resolve to the
   sections of your final Chapter 3 numbering before printing.
6. **Repetition with Chapter 1.** This chapter covers the same ground as Chapter
   1 of your present manuscript, which is already titled *The Problem and Its
   Background* and already carries 1.1 Project Context, 1.2 Background of Study,
   1.3 Statement of the Problem, 1.4 Objectives of the Study, Scope, Delimitation,
   1.6 Significance of Study and 1.7 Definition of Terms. The two chapters cannot
   both be submitted as they stand. Either drop Chapter 1 and keep this chapter,
   or keep Chapter 1 and use this chapter only as the source of the corrections
   listed below. This file does not modify Chapter 1.
7. **Corrections worth making in Chapter 1 whatever you decide** — listed only so
   that they are not lost, and **not** performed by this file:
   - "1.2 Background of Study" is Normal text, not a Heading 2, so it does not
     build a heading entry.
   - "Scope" and "Delimitation" are Heading 1 rather than one "1.5 Scope and
     Delimitation".
   - "Demand Level" is defined twice in 1.7.
   - The Table of Contents says "1.2 Background of the Study" while the body says
     "1.2 Background of Study"; the TOC says "2.1 REVIEW OF RELATED LITERATURE"
     while the body says "2.2 Review of Related Literature"; and the TOC says
     "2.3 SYNTHESIS" while the body says "2.4 Synthesis".
   - The Chapter 3 body heading reads "RESEARCH METHODOLOGY" without the words
     "CHAPTER 3".

### Verification notes (not for submission)

Every technical statement in this chapter was checked against the source code of
the system before it was written, and only functions that are actually
implemented are described:

- The **Linear Regression** forecast, the **ninety-day** historical window
  (`$historicalStart->modify('-89 days')` gives ninety days inclusive), the
  exclusion of **Cancelled** and **No-Show** appointments from demand
  (`status NOT IN ('Cancelled','No-Show')`), the inclusion of zero-appointment
  days, the seven-day projection, and the high/medium/low thresholds of **125%**
  and **75%** of the historical average were all read from
  `admin/demand_forecast.php`.
- The **module list** of §4.5 follows the modules of the application as they exist
  in `admin/`, `dentist/` and `patient/`, and matches the module list already used
  in the Scope of Chapter 1, so the two descriptions of the system agree.
- The **public QR walk-in registration** that writes the patient record and
  issues a queue number for the current date was read from `walkin_qr.php`.
- The **no-show handling** was read from `admin/no_show.php`: the page sets the
  appointment status to `No-Show` and inserts a notification for the patient.
  Its own comments state that there is no prediction on the page. This is why
  §4.1, §4.2, §4.3 and §4.5 describe no-show handling as recording and
  notification, and why §4.5 states in the Delimitation that the system does not
  include no-show prediction or Random Forest classification.
- The **absence of a no-show prediction** was confirmed by three independent
  checks: `ai_predictions` is referenced by one single line of the application
  (`admin/reports.php:133`, a `COUNT(*)`), no `model.pkl` exists in the project,
  and `assets/ai/train_model.py` is not called from any page.
- **No statistic, percentage or benchmark figure** appears in the submittable
  text, because the study reports only the figures actually gathered in the
  evaluation described in §3.12.

in that hour, was told that no slot was available.
